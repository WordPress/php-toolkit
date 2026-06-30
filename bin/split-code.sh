#!/usr/bin/env bash
set -euo pipefail

# ---- Config (override with env vars) ----
# Set GH_TOKEN environment variable for CI authentication (will use HTTPS instead of SSH)
DEFAULT_BRANCH="${DEFAULT_BRANCH:-trunk}"
VISIBILITY="${VISIBILITY:-public}"   # public|private|internal (internal only for orgs)
TOPICS="${TOPICS:-php,composer,monorepo,split}"
PKG_GLOB="${PKG_GLOB:-components/*}"
# If GH_ORG is unset, we'll derive it from composer "name" vendor part.
GH_ORG="${GH_ORG:-wp-php-toolkit}"
# Optional: auto-register on Packagist if you set these:
PACKAGIST_USERNAME="${PACKAGIST_USERNAME:-}"
PACKAGIST_TOKEN="${PACKAGIST_TOKEN:-}"

need() { command -v "$1" >/dev/null 2>&1 || { echo "Missing dependency: $1"; exit 1; }; }

echo "Preflight checks…"
need git
need gh
need jq
if command -v git-filter-repo >/dev/null 2>&1; then
  USE_FILTER_REPO=1
  echo "Using git-filter-repo."
else
  USE_FILTER_REPO=0
  echo "git-filter-repo not found, will fallback to git subtree (slower)."
fi

# Ensure we're on a branch and have tags fetched
git rev-parse --is-inside-work-tree >/dev/null
git fetch --tags --prune --quiet || true

create_repo_if_needed() {
  local org="$1" name="$2" desc="$3" homepage="$4"
  if gh repo view "${org}/${name}" >/dev/null 2>&1; then
    echo "Repo exists: ${org}/${name}"
  else
    echo "Creating repo: ${org}/${name}"
    local visflag="--${VISIBILITY}"
    local create_output
    if ! create_output="$(gh repo create "${org}/${name}" ${visflag} --disable-wiki 2>&1)"; then
      if echo "$create_output" | grep -qi "name already exists"; then
        echo "Repo exists: ${org}/${name}"
      else
        echo "$create_output" >&2
        exit 1
      fi
    fi
    if [[ -n "$desc" ]]; then gh repo edit "${org}/${name}" --description "$desc" >/dev/null; fi
    if [[ -n "$homepage" ]]; then gh repo edit "${org}/${name}" --homepage "$homepage" >/dev/null; fi
    # Topics
    IFS=, read -ra arr <<<"$TOPICS"
    gh repo edit "${org}/${name}" --add-topic "${arr[@]}" >/dev/null || true
    # Default branch (after first push we'll set it again)
  fi
}

split_and_push() {
  local pkg_dir="$1" org="$2" repo_name="$3"
  local repo_url token

  # Prefer GH_TOKEN; fall back to GITHUB_TOKEN for GitHub Actions
  token="${GH_TOKEN:-${GITHUB_TOKEN:-}}"

  if [[ -n "$token" ]]; then
    git config --global url."https://x-access-token:${token}@github.com/".insteadOf "https://github.com/" || true
    repo_url="https://github.com/${org}/${repo_name}.git"
  else
    repo_url="git@github.com:${org}/${repo_name}.git"
  fi
  
  echo "==> Splitting ${pkg_dir} -> ${org}/${repo_name}"

  if [[ "$USE_FILTER_REPO" -eq 1 ]]; then
    local tmp
    tmp="$(mktemp -d)"
    git clone --no-hardlinks . "$tmp" >/dev/null
    pushd "$tmp" >/dev/null
      # Save version tag timestamps before filter-repo.  Tags that point to
      # commits not touching this component's directory will be dropped during
      # filtering — we need the timestamps to recreate them afterward.
      local tag_file
      tag_file="$(mktemp)"
      for t in $(git tag --list 'v[0-9]*'); do
        echo "$t $(git log -1 --format='%ct' "$t")" >> "$tag_file"
      done

      # Filter to that path and move it to repo root
      git filter-repo --force --path "$pkg_dir" --path-rename "${pkg_dir}/:" >/dev/null

      # Recreate version tags that filter-repo dropped.  When the tagged
      # monorepo commit only touched files outside this component (e.g. a
      # root-level VERSION file), filter-repo removes that commit and its
      # tag.  We map each missing tag to the latest surviving commit whose
      # timestamp is at or before the original tag's timestamp.
      while IFS=' ' read -r tag timestamp; do
        if ! git rev-parse --verify "refs/tags/$tag" >/dev/null 2>&1; then
          local target
          target="$(git rev-list -1 --before="@${timestamp}" HEAD 2>/dev/null || true)"
          if [[ -z "$target" ]]; then
            target="$(git rev-list --max-parents=0 HEAD 2>/dev/null || true)"
          fi
          if [[ -n "$target" ]]; then
            echo "  Recreating dropped tag: $tag -> $(git log -1 --format='%h %s' "$target")"
            git tag "$tag" "$target"
          fi
        fi
      done < "$tag_file"
      rm -f "$tag_file"

      # Prefer DEFAULT_BRANCH if present; else keep current
      if git show-ref --verify --quiet "refs/heads/${DEFAULT_BRANCH}"; then
        git checkout -q "${DEFAULT_BRANCH}"
      fi
      git remote remove origin 2>/dev/null || true
      git remote add origin "$repo_url"
      # Fetch remote default branch if exists (for safer force-with-lease in the future)
      git fetch origin "${DEFAULT_BRANCH}" --depth=1 || true
      # Push the filtered history to the remote default branch, overwriting if needed
      git push -u origin HEAD:"${DEFAULT_BRANCH}" --force
      git push origin --tags || true
    popd >/dev/null
    rm -rf "$tmp"
  else
    # Fallback: subtree split creates a synthetic branch with the path history
    local split_branch="split-$(basename "$pkg_dir")-$(date +%s)"
    git subtree split --prefix="$pkg_dir" -b "$split_branch" >/dev/null
    git push --force "$repo_url" "$split_branch:${DEFAULT_BRANCH}"
    # Push tags that include this history is non-trivial with subtree; skipping here
    git branch -D "$split_branch" >/dev/null
  fi

  # Set default branch explicitly
  gh repo edit "${org}/${repo_name}" --default-branch "${DEFAULT_BRANCH}" >/dev/null || true
}

register_packagist() {
  local org="$1" repo="$2"
  [[ -n "$PACKAGIST_USERNAME" && -n "$PACKAGIST_TOKEN" ]] || return 0
  local url="https://github.com/${org}/${repo}"
  echo "Registering on Packagist: ${url}"
  curl -fsS -X POST \
    "https://packagist.org/api/create-package?username=${PACKAGIST_USERNAME}&apiToken=${PACKAGIST_TOKEN}" \
    -H 'Content-Type: application/json' \
    -d "{\"repository\":{\"url\":\"${url}\"}}" >/dev/null || echo "Packagist create-package call failed (maybe already exists)."
}

update_packagist() {
  local org="$1" repo="$2"
  [[ -n "$PACKAGIST_USERNAME" && -n "$PACKAGIST_TOKEN" ]] || return 0
  local package_name="${org}/${repo}"
  local url="https://github.com/${org}/${repo}"
  echo "Updating Packagist package: ${package_name}"
  local attempt
  for attempt in 1 2 3; do
    if curl -fsS -X POST \
      "https://packagist.org/api/update-package?username=${PACKAGIST_USERNAME}&apiToken=${PACKAGIST_TOKEN}" \
      -H 'Content-Type: application/json' \
      -d "{\"repository\":{\"url\":\"${url}\"}}" >/dev/null 2>&1; then
      return 0
    fi
    echo "  Packagist update attempt ${attempt}/3 failed for ${package_name}, retrying in ${attempt}s..."
    sleep "$attempt"
  done
  echo "  WARNING: Packagist update failed after 3 attempts for ${package_name}"
}

# Iterate packages
shopt -s nullglob
for composer in ${PKG_GLOB}/composer.json; do
  pkg_dir="$(dirname "$composer")"
  name="$(jq -r '.name // empty' "$composer")"
  desc="$(jq -r '.description // empty' "$composer")"
  homepage="$(jq -r '.homepage // empty' "$composer")"

  if [[ -z "$name" || "$name" == "null" ]]; then
    echo "Skipping ${pkg_dir}: missing composer name."
    continue
  fi

  vendor="${name%%/*}"
  pkg="${name#*/}"

  org="${GH_ORG:-$vendor}"
  repo_name="$pkg"

  create_repo_if_needed "$org" "$repo_name" "$desc" "$homepage"
  split_and_push "$pkg_dir" "$org" "$repo_name"
  register_packagist "$org" "$repo_name"
  update_packagist "$org" "$repo_name"

  echo ""
done

echo "Done."

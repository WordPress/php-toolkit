#!/usr/bin/env bash
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
WORK_DIR="$(mktemp -d)"
DEFAULT_PORT="${WP_ORIGIN_E2E_PORT:-}"
PLAYGROUND_LOG="$ROOT_DIR/.context/wp-origin-playground.log"
BLUEPRINT_TEMPLATE="$ROOT_DIR/plugins/wp-origin/blueprint-e2e.json"

find_free_port() {
	php -r '$server = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr); if (false === $server) { fwrite(STDERR, $errstr . PHP_EOL); exit(1); } $name = stream_socket_get_name($server, false); fclose($server); echo substr(strrchr($name, ":"), 1);'
}

if [ -n "$DEFAULT_PORT" ]; then
	PORT="$DEFAULT_PORT"
else
	PORT="$(find_free_port)"
fi

CREDENTIALS_FILE="$ROOT_DIR/.context/wp-origin-e2e-$PORT.json"
BLUEPRINT_FILE="$WORK_DIR/blueprint-e2e.json"

if command -v wp-playground >/dev/null 2>&1; then
	PLAYGROUND_CMD="wp-playground"
elif command -v wp-playground-cli >/dev/null 2>&1; then
	PLAYGROUND_CMD="wp-playground-cli"
else
	PLAYGROUND_CMD="npx --no-install @wp-playground/cli"
fi

cleanup() {
	if [ -n "${PLAYGROUND_PID:-}" ] && kill -0 "$PLAYGROUND_PID" 2>/dev/null; then
		kill "$PLAYGROUND_PID" 2>/dev/null || true
		wait "$PLAYGROUND_PID" 2>/dev/null || true
	fi
	rm -f "$CREDENTIALS_FILE"
	rm -rf "$WORK_DIR"
}
trap cleanup EXIT INT TERM

mkdir -p "$ROOT_DIR/.context"
rm -f "$PLAYGROUND_LOG" "$CREDENTIALS_FILE"

cd "$ROOT_DIR"
sed "s|__WP_ORIGIN_CREDENTIALS_FILE__|/workspace/.context/$(basename "$CREDENTIALS_FILE")|g" "$BLUEPRINT_TEMPLATE" > "$BLUEPRINT_FILE"
PLAYGROUND_PHP_VERSION="$(php -r '$config = json_decode(file_get_contents($argv[1]), true); echo $config["preferredVersions"]["php"];' "$BLUEPRINT_FILE")"
PLAYGROUND_WP_VERSION="$(php -r '$config = json_decode(file_get_contents($argv[1]), true); echo $config["preferredVersions"]["wp"];' "$BLUEPRINT_FILE")"

export GIT_TERMINAL_PROMPT=0
$PLAYGROUND_CMD server \
	--port="$PORT" \
	--php="$PLAYGROUND_PHP_VERSION" \
	--wp="$PLAYGROUND_WP_VERSION" \
	--blueprint="$BLUEPRINT_FILE" \
	--mount="$ROOT_DIR:/workspace" \
	--mount="$ROOT_DIR/vendor:/wordpress/wp-content/vendor" \
	--mount="$ROOT_DIR/components:/wordpress/wp-content/components" \
	--mount="$ROOT_DIR/plugins/wp-origin:/wordpress/wp-content/plugins/wp-origin" \
	>"$PLAYGROUND_LOG" 2>&1 &
PLAYGROUND_PID=$!

for _ in $(seq 1 120); do
	if [ -f "$CREDENTIALS_FILE" ]; then
		break
	fi
	sleep 1
done

if [ ! -f "$CREDENTIALS_FILE" ]; then
	cat "$PLAYGROUND_LOG"
	echo "WP Origin e2e setup did not produce credentials." >&2
	exit 1
fi

USERNAME="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["username"];' "$CREDENTIALS_FILE")"
PASSWORD="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["password"];' "$CREDENTIALS_FILE")"

AUTH_HEADER="$(php -r 'echo base64_encode($argv[1] . ":" . $argv[2]);' "$USERNAME" "$PASSWORD")"
BASE_URL="http://127.0.0.1:$PORT"
REMOTE_AUTH_URL="http://$USERNAME:$PASSWORD@127.0.0.1:$PORT/wp-json/git/v1/md.git"
CLONE_DIR="$WORK_DIR/clone"

git -c protocol.version=2 clone "$REMOTE_AUTH_URL" "$CLONE_DIR"

test -f "$CLONE_DIR/post/hello-world.md"
test -f "$CLONE_DIR/page/sample-page.md"
grep -q 'Hello from WordPress' "$CLONE_DIR/post/hello-world.md"

POST_ID="$(curl -sS -f -H "Authorization: Basic $AUTH_HEADER" "$BASE_URL/wp-json/wp/v2/posts?slug=hello-world&context=edit" | php -r '
$posts = json_decode(stream_get_contents(STDIN), true);
echo $posts[0]["id"];
')"
PAGE_ID="$(curl -sS -f -H "Authorization: Basic $AUTH_HEADER" "$BASE_URL/wp-json/wp/v2/pages?slug=sample-page&context=edit" | php -r '
$pages = json_decode(stream_get_contents(STDIN), true);
echo $pages[0]["id"];
')"
REVISION_COUNT_BEFORE="$(curl -sS -f -H "Authorization: Basic $AUTH_HEADER" "$BASE_URL/wp-json/wp/v2/posts/$POST_ID/revisions?context=edit" | php -r '
$revisions = json_decode(stream_get_contents(STDIN), true);
echo count($revisions);
')"

cd "$CLONE_DIR"
git config user.name "WP Origin E2E"
git config user.email "wp-origin-e2e@example.com"

php -r '
$path = $argv[1];
$contents = file_get_contents($path);
$contents = str_replace("Hello from WordPress", "Updated from Git", $contents);
file_put_contents($path, $contents);
' "$CLONE_DIR/post/hello-world.md"

git add post/hello-world.md
git commit -m "Update hello world from Git"
git push origin trunk

UPDATED_CONTENT="$(curl -sS -f -H "Authorization: Basic $AUTH_HEADER" "$BASE_URL/wp-json/wp/v2/posts/$POST_ID?context=edit" | php -r '
$post = json_decode(stream_get_contents(STDIN), true);
echo $post["content"]["raw"];
')"
printf '%s' "$UPDATED_CONTENT" | grep -q 'Updated from Git'

REVISION_COUNT_AFTER_UPDATE="$(curl -sS -f -H "Authorization: Basic $AUTH_HEADER" "$BASE_URL/wp-json/wp/v2/posts/$POST_ID/revisions?context=edit" | php -r '
$revisions = json_decode(stream_get_contents(STDIN), true);
echo count($revisions);
')"
[ "$REVISION_COUNT_AFTER_UPDATE" -gt "$REVISION_COUNT_BEFORE" ]
git pull --rebase origin trunk

php -r '
$path = $argv[1];
$markdown = "---\n"
	. "type: \"post\"\n"
	. "slug: \"created-from-git\"\n"
	. "status: \"publish\"\n"
	. "title: \"Created From Git\"\n"
	. "---\n\n"
	. "Created from Git.\n";
file_put_contents($path, $markdown);
' "$CLONE_DIR/post/created-from-git.md"

php -r '
$path = $argv[1];
$markdown = "---\n"
	. "type: \"page\"\n"
	. "slug: \"page-from-git\"\n"
	. "status: \"publish\"\n"
	. "title: \"Page From Git\"\n"
	. "---\n\n"
	. "Page created from Git.\n";
file_put_contents($path, $markdown);
' "$CLONE_DIR/page/page-from-git.md"

rm "$CLONE_DIR/page/sample-page.md"
git add post/created-from-git.md page/page-from-git.md page/sample-page.md
git commit -m "Create and delete content from Git"
git push origin trunk

CREATED_POST_CONTENT="$(curl -sS -f -H "Authorization: Basic $AUTH_HEADER" "$BASE_URL/wp-json/wp/v2/posts?slug=created-from-git&context=edit" | php -r '
$posts = json_decode(stream_get_contents(STDIN), true);
echo $posts[0]["content"]["raw"];
')"
printf '%s' "$CREATED_POST_CONTENT" | grep -q 'Created from Git'

CREATED_PAGE_CONTENT="$(curl -sS -f -H "Authorization: Basic $AUTH_HEADER" "$BASE_URL/wp-json/wp/v2/pages?slug=page-from-git&context=edit" | php -r '
$pages = json_decode(stream_get_contents(STDIN), true);
echo $pages[0]["content"]["raw"];
')"
printf '%s' "$CREATED_PAGE_CONTENT" | grep -q 'Page created from Git'

TRASHED_PAGE_STATUS="$(curl -sS -f -H "Authorization: Basic $AUTH_HEADER" "$BASE_URL/wp-json/wp/v2/pages/$PAGE_ID?context=edit" | php -r '
$page = json_decode(stream_get_contents(STDIN), true);
echo $page["status"];
')"
[ "$TRASHED_PAGE_STATUS" = "trash" ]
git pull --rebase origin trunk

php -r '
$path = $argv[1];
$contents = file_get_contents($path);
$contents = str_replace("Updated from Git", "Stale local edit", $contents);
file_put_contents($path, $contents);
' "$CLONE_DIR/post/hello-world.md"

git add post/hello-world.md
git commit -m "Create stale local edit"

UPDATE_PAYLOAD='{"content":"<!-- wp:paragraph --><p>Updated in WordPress</p><!-- /wp:paragraph -->"}'
curl -sS \
	-X POST \
	-H "Authorization: Basic $AUTH_HEADER" \
	-H "Content-Type: application/json" \
	-d "$UPDATE_PAYLOAD" \
	-f \
	"$BASE_URL/wp-json/wp/v2/posts/$POST_ID?context=edit" >/dev/null

if git push origin trunk; then
	echo "Expected stale push to fail." >&2
	exit 1
fi

git fetch origin trunk
git reset --hard FETCH_HEAD >/dev/null
grep -q 'Updated in WordPress' "$CLONE_DIR/post/hello-world.md"

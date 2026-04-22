#!/usr/bin/env bash
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${WP_ORIGIN_E2E_PORT:-9409}"
PLAYGROUND_LOG="$ROOT_DIR/.context/wp-origin-playground.log"
CREDENTIALS_FILE="$ROOT_DIR/.context/wp-origin-e2e.json"
WORK_DIR="$(mktemp -d)"

if command -v wp-playground >/dev/null 2>&1; then
	PLAYGROUND_CMD="wp-playground"
else
	PLAYGROUND_CMD="npx @wp-playground/cli"
fi

cleanup() {
	if [ -n "${PLAYGROUND_PID:-}" ] && kill -0 "$PLAYGROUND_PID" 2>/dev/null; then
		kill "$PLAYGROUND_PID" 2>/dev/null || true
		wait "$PLAYGROUND_PID" 2>/dev/null || true
	fi
	rm -rf "$WORK_DIR"
}
trap cleanup EXIT INT TERM

mkdir -p "$ROOT_DIR/.context"
rm -f "$PLAYGROUND_LOG" "$CREDENTIALS_FILE"

cd "$ROOT_DIR"

$PLAYGROUND_CMD server \
	--port="$PORT" \
	--blueprint="$ROOT_DIR/plugins/wp-origin/blueprint-e2e.json" \
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
REMOTE_URL="$BASE_URL/wp-json/git/v1/md.git"
CLONE_DIR="$WORK_DIR/clone"

git -c protocol.version=2 clone "$REMOTE_AUTH_URL" "$CLONE_DIR"

test -f "$CLONE_DIR/post/hello-world.md"
test -f "$CLONE_DIR/page/sample-page.md"
grep -q 'Hello from WordPress' "$CLONE_DIR/post/hello-world.md"

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

POST_ID="$(curl -sS -H "Authorization: Basic $AUTH_HEADER" "$BASE_URL/wp-json/wp/v2/posts?slug=hello-world&context=edit" | php -r '
$posts = json_decode(stream_get_contents(STDIN), true);
echo $posts[0]["id"];
')"

UPDATED_CONTENT="$(curl -sS -H "Authorization: Basic $AUTH_HEADER" "$BASE_URL/wp-json/wp/v2/posts/$POST_ID?context=edit" | php -r '
$post = json_decode(stream_get_contents(STDIN), true);
echo $post["content"]["raw"];
')"
printf '%s' "$UPDATED_CONTENT" | grep -q 'Updated from Git'

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
	"$BASE_URL/wp-json/wp/v2/posts/$POST_ID?context=edit" >/dev/null

if git push origin trunk; then
	echo "Expected stale push to fail." >&2
	exit 1
fi

git pull --rebase origin trunk
grep -q 'Updated in WordPress' "$CLONE_DIR/post/hello-world.md"

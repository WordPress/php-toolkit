#!/usr/bin/env bash
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"

cd "$ROOT_DIR"
docker compose run --rm sandbox-wp-origin-e2e bash bin/test-wp-origin-git-actions.sh

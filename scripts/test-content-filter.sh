#!/bin/sh
set -eu

cd "$(dirname "$0")/.."

# The filter matchers are pure: no cache, no session, no database. They run
# against the container's PHP so the check matches the runtime that serves the
# game.
docker compose exec -T app php /app/tests/content-filter.php

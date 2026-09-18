#!/bin/sh
set -eu

cd "$(dirname "$0")/.."
docker compose exec -T app php /app/tests/runtime-regression.php
# The NPC execution layer seeds real accounts into the running world and
# deletes them again, so it belongs with the runtime tests rather than with
# tests/npc-*.php, which are pure and need no world at all.
docker compose exec -T app php /app/tests/runtime-npc.php

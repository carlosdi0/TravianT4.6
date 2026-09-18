#!/bin/sh
set -eu

cd "$(dirname "$0")/.."

# The NPC decision layer is pure: no database, no session, no engine. These run
# in a couple of seconds, so the loop is discovered rather than hand-listed and
# a new brain class is covered the moment its test lands.
found=0
for test in tests/npc-*.php; do
    [ -e "$test" ] || continue
    found=$((found + 1))
    docker compose exec -T app php "/app/$test"
done

if [ "$found" -eq 0 ]; then
    echo 'No NPC regression tests found; expected tests/npc-*.php.' >&2
    exit 1
fi

echo "NPC decision regressions passed ($found files)."

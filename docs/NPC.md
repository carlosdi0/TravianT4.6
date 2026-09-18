# Server-run neighbours (NPCs)

A private world is empty. Without other accounts on the map there is nobody to
raid, nobody to raid you, no reason to build defence, and no alliance to join.
Server-run neighbours exist to make a small world worth playing.

This document covers the design. It is written from a working implementation of
the same feature in another engine, so where a decision looks arbitrary it
usually encodes something that went wrong there.

## What already exists in this repository

The engine ships two kinds of automated account, and neither is what this
feature needs:

- **Fake users** (`access=3`, `Model\FakeUserModel`) build and train, driven by
  `Core\AI` from the `AIProgress` job group. They never attack, trade or settle.
- **Natars** (`id=1`, `Model\NatarsModel`) grow, expand and do attack, but only
  against newly founded villages in the grey zone.

Neighbours are kept separate from both. They get their own table and are
excluded from the fake-user pass, so the two systems cannot fight over the same
accounts and the old behaviour keeps working untouched.

## Account model

An NPC is an ordinary `users` row plus one row in `npc_player`, keyed by the
same uid. The map, rankings, combat resolution and reports treat it exactly like
a human account, which is the point: a neighbour has to be indistinguishable
from a player who simply is not very good.

The villages it owns are never recorded. They are derived from `vdata.owner`, so
a village a player conquers stops counting as NPC-owned with no extra code.

## Personality

Three independent dimensions, so two neighbours with the same blueprint still
behave differently.

**Archetype** is the village blueprint: `farm` (soft, no residence, fat
crannies), `garrison` (walls, defence, residence, so it must be sieged before it
can be taken), `warlord` (barracks and stable, hits back).

**Tier** is how much the person plays: `top`, `builder`, `casual`, `inactive`.
It drives growth rate, army ceiling, village ceiling, and who the account is
allowed to attack.

**Trait** is combat personality on top of the other two: `steady`, `raider`,
`vengeful`, `opportunist`, `erratic`, `turtle`. It modulates cooldown, burst
size, reach, sleeping hours and how long a grudge lasts.

`power` (35..85) scales strength and expansion linearly, and each account
derives a stable daily rhythm from its own uid, so neighbours do not all act at
the same moment without any of that being stored.

**Terrain follows the tier.** Only `top` hunts croppers, and everybody else
actively AVOIDS them: seeding walks the free valleys closest first, so merely
"not hunting" handed fifteen-croppers to the cows on any map where one happened
to be nearby. A non-hunter settles a cropper only when the neighbourhood has
nothing else left, which is what keeps them available for the player through
the first weeks.

## Architecture

Three layers, and the separation is the design.

### 1. Decision — `main_script/include/Game/Npc/`

Pure classes. No database handle, no session, no globals, no `Formulas`. Data
goes in, a decision comes out. This is what makes the behaviour testable without
standing up a world, and the 20 regression tests under `tests/npc-*.php` run in
seconds because of it.

Anything a brain needs to know about the ruleset — unit stats, building
ceilings, population per level, wall ids — arrives through the `NpcGameData`
interface. `NpcFormulasData` is the only class in the package that knows the
engine exists.

Two consequences worth stating, because both were bugs waiting to happen:

- **Unit slots in this layer are always tribe-relative (1..10).** The `units`
  table stores relative slots plus a `race` column, while the engine formulas
  use absolute ids where unit N of tribe T is `(T-1)*10+N`. The conversion lives
  in exactly one place, `NpcFormulasData::absoluteUnitId()`. Mixing the two
  schemes hands an NPC somebody else's army.
- **Rules that differ between rulesets are supplied by the caller, never
  assumed.** Cranny capacity and field production are passed in as tables rather
  than computed inside the decision layer, because both differ from the engine
  this design came from, and a divergent copy of a game rule is the kind of bug
  that surfaces months later as "combat feels wrong".

### 2. Execution — `main_script/include/Model/Npc*Model.php`

Reads the world, asks layer 1 what to do, and writes the result through the
existing models: `Game\Buildings\BuildingAction`, `Model\TrainingModel`,
`Model\MovementsModel`. Never through the web controllers: those depend on
`Core\Session` (backed by `$_SESSION`, which does not exist in a worker) and on
`Core\Village`, whose failure path calls `exit()`.

- **`NpcModel`** is the only class that knows what a table is. Every other pass
  goes through it, so there is exactly one place that knows an account's
  villages are derived from `vdata.owner` and never stored.
- **`NpcSeedModel`** creates accounts. Manual only; see below.
- **`NpcGrowthModel`** runs the growth pass: levels, troops and the hero.
- **`NpcExpandModel`** founds villages.

Troop movements will use `MovementsModel::addMovementWithSourceMutation()`,
which puts the troop deduction and the movement row in one transaction.

#### Buildings are applied, troops are queued

A neighbour does not run an economy. Its population comes from its own curve,
so the growth pass writes levels **straight through `BuildingAction::upgrade()`**
rather than queueing them in `building_upgrade`: that queue is a spend of
resources a neighbour does not have.

Troops go the other way. They are queued through `TrainingModel::addTraining()`,
so they take real time to appear and a raid that kills a garrison buys the
player actual hours. The one exception is seeding, which writes them directly:
an account born three weeks old has to come with the garrison those weeks would
have produced, and queueing it would leave a whole neighbourhood undefended for
hours after every seed.

`BuildingAction::upgrade()` reads `f{slot}t` to know what it is raising and
silently does nothing when the slot is empty, so the gid is written into the
slot first for a building the village does not have yet.

#### The hero grows, like a fake user's

`FakeUserModel::handleFakeUsers()` is the only other place in the engine where
a hero gains experience without going on an adventure, and neighbours borrow
the idea. The difference is the pace: a top neighbour's hero pulls ahead, a
casual's trails, and a cow's stops on the day the account does. A player who
scouts one can read the account's seriousness off its hero, exactly as they
would a real neighbour's.

### 3. Scheduling — `Core\Jobs\Launcher::AIProgress()`

Sub-jobs beside the ones the existing bots use: `AIProgress:npcGrowth` every 30
seconds and `AIProgress:npcExpand` every two minutes. Raids and upkeep are not
implemented yet.

**The job interval is not the account interval.** A job tick considers a BATCH;
how often one account acts is `npc.growthInterval`, which the model applies per
row, oldest-touched first. Keeping the two apart is what lets a world with
hundreds of neighbours stay as cheap per tick as one with ten.

One rule that is easy to get wrong: **growth scales with server speed, rhythm
does not**. The economy runs in game time, but sleeping hours and raid cooldowns
are meant to imitate a person, and a person does not wake up ten times faster on
a speed-10 world.

## Growth ceilings are frozen per account

`npc_player.max_villages` and `max_pop_per_village` are columns, not
configuration.

This is the most important operational decision here, and it comes from an
incident. Raising a global ceiling in a live world wakes up every account parked
at the old limit and they all grow at once. In a real deployment, lifting the
village caps from 9/7/4 to 15/11/6 took a world from 176 to 409 NPC villages and
from 78,000 to 365,000 NPC population within days, while the largest human
player had 2 villages and 326 population.

With per-account ceilings written at seed time, changing a limit affects only
neighbours seeded afterwards. The live world keeps the shape it was born with.

## Seeding is always manual

Neither installation nor the workers create neighbours. A world starts empty and
an administrator seeds it deliberately. Automatic seeding would mean every fresh
install silently populates itself, which is impossible to undo cleanly once the
accounts have grown.

```sh
docker compose exec app php /app/main_script/copyable/include/npc.php status
docker compose exec app php /app/main_script/copyable/include/npc.php seed 24
docker compose exec app php /app/main_script/copyable/include/npc.php seed 8 --x=0 --y=0 --radius-min=3 --radius-max=12
```

With no coordinates the ring is drawn around **the biggest human player's
capital**, because "give the player some neighbours" is what the feature is
for. The centre of the map is not used as a fallback: a world whose only player
lives in a corner would get its neighbours nowhere near them, so a world with no
player yet refuses to seed until it is told where.

`grow` and `expand` run one pass by hand, which is how a freshly seeded world is
checked without waiting for the worker.

There is deliberately no `purge`. Seeding is meant to be a decision, and an
undo button makes it a smaller one than it is.

## Settings

`config.php` has an `npc` block, read through `getNpc()`. The knobs worth
knowing: `enabled` turns the worker passes off without the neighbours leaving
the map, `seedRadiusMin`/`seedRadiusMax` set the ring, the four `tier*Pct`
values set the world's mix, and `popLead`/`villageLead` are an optional leash
back to the human player that defaults to off.

The ceilings that matter most are NOT there. See the next section.

## Deliberate limitations

- **Neighbours do not send or receive messages or battle reports.** They read
  the world directly. Giving them an inbox would mean either generating mail
  nobody reads or letting them act on information a player would have to earn.
- **Catapult attacks are off by default.** The policy that governs them is
  strict (scouted recently, hit repeatedly first, never a capital or World
  Wonder, target building must actually exist), but a neighbour that razes
  buildings changes a world's character enough that it should be a choice.
- **No trading.** Not modelled yet.

## Status

Implemented: the decision layer (`Game\Npc`, 20 classes with regression
coverage), the ruleset adapter, the schema (`005_npc_players.sql`), seeding,
the growth and expansion passes, and their scheduling.

Not implemented: raids (`NpcTargeting`, `NpcFarmList`, `NpcRealAttackPolicy`
and `NpcArmyRoles::raidWave()` are all written and tested, but nothing calls
them yet), the upkeep pass, alliances between neighbours (`NpcAlliances` is in
the same position), trading, and the admin panel.

### Test coverage

`tests/npc-*.php` cover the decision layer and need no world at all, so
`scripts/test-npc.sh` runs them in seconds. The execution layer is covered by
`tests/runtime-npc.php`, which seeds real accounts into the running world and
deletes them on the way out; it runs from `scripts/test-runtime.sh`, and its
job is the four things that break first:

- a seeded neighbour is an ordinary account in every table;
- `vdata.pop`, `users.total_pop` and what the engine recomputes from `fdata`
  all agree, which is what drifts when a pass writes levels by hand;
- a cow that has quit stays frozen through both passes;
- two seed runs never put two villages on one map square.

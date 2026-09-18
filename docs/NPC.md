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

## Architecture

Three layers, and the separation is the design.

### 1. Decision — `main_script/include/Game/Npc/`

Pure classes. No database handle, no session, no globals, no `Formulas`. Data
goes in, a decision comes out. This is what makes the behaviour testable without
standing up a world, and the 19 regression tests under `tests/npc-*.php` run in
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

### 2. Execution — planned

Reads the world, asks layer 1 what to do, and writes the result through the
existing models: `Game\Buildings\BuildingAction`, `Model\TrainingModel`,
`Model\MovementsModel`. Never through the web controllers: those depend on
`Core\Session` (backed by `$_SESSION`, which does not exist in a worker) and on
`Core\Village`, whose failure path calls `exit()`.

Troop movements use `MovementsModel::addMovementWithSourceMutation()`, which
puts the troop deduction and the movement row in one transaction.

### 3. Scheduling — planned

Sub-jobs in `Core\Jobs\Launcher::AIProgress()`, following the pattern the
existing bots already use. Four passes on independent intervals: upkeep, growth,
raids and expansion. Each pass handles a small batch, oldest-touched first, so a
tick stays cheap no matter how many neighbours exist.

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

Implemented: the decision layer (`Game\Npc`, 19 classes with regression
coverage), the ruleset adapter, and the schema (`005_npc_players.sql`).

Not implemented: seeding, the execution layer, scheduling, alliances between
neighbours, and the admin panel.

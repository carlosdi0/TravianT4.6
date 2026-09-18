-- Server-run neighbours. An NPC is an ordinary `users` row plus one row here,
-- so the map, rankings and combat treat it exactly like a human account. The
-- villages it owns are never stored: they are derived from vdata.owner, which
-- means a village a player conquers stops counting as NPC-owned for free.
--
-- The growth ceilings (max_villages, max_pop_per_village) are columns rather
-- than global config on purpose. Raising a global ceiling in a live world wakes
-- up every account parked at the old limit and they all grow at once; frozen
-- per-account ceilings confine any future change to newly seeded neighbours.

CREATE TABLE IF NOT EXISTS npc_player
(
  `uid`                  INT(11) UNSIGNED    NOT NULL,

  -- Village blueprint: farm, garrison, warlord.
  `archetype`            VARCHAR(16)         NOT NULL DEFAULT 'farm',
  -- How much the "person" plays: top, builder, casual, inactive.
  `tier`                 VARCHAR(16)         NOT NULL DEFAULT 'casual',
  -- Combat personality: steady, raider, vengeful, opportunist, erratic, turtle.
  `trait`                VARCHAR(16)         NOT NULL DEFAULT 'steady',
  -- 35..85, scales strength and expansion linearly.
  `power`                SMALLINT(5) UNSIGNED NOT NULL DEFAULT 50,

  -- Ceilings frozen when the account is seeded. See the note above.
  `max_villages`         SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
  `max_pop_per_village`  SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,

  -- Village the account was seeded around, for radius checks.
  `home_kid`             INT(6) UNSIGNED     NOT NULL DEFAULT 0,
  -- Birth date, backdatable so a fresh world can start with grown neighbours.
  `created`              INT(10) UNSIGNED    NOT NULL DEFAULT 0,
  -- When an `inactive` account stops playing for good; 0 means never.
  `quit_at`              INT(10) UNSIGNED    NOT NULL DEFAULT 0,

  `last_growth`          INT(10) UNSIGNED    NOT NULL DEFAULT 0,
  `last_attack`          INT(10) UNSIGNED    NOT NULL DEFAULT 0,
  `next_attack`          INT(10) UNSIGNED    NOT NULL DEFAULT 0,
  -- Raids left in the current burst.
  `burst`                SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
  `last_expand`          INT(10) UNSIGNED    NOT NULL DEFAULT 0,
  `expansions`           SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,

  -- Who hit this account recently, so a vengeful neighbour can hit back.
  `grudge_hits`          SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
  `grudge_since`         INT(10) UNSIGNED    NOT NULL DEFAULT 0,
  `last_raided`          INT(10) UNSIGNED    NOT NULL DEFAULT 0,
  `last_raider`          INT(11) UNSIGNED    NOT NULL DEFAULT 0,

  `raid_loot`            BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  `raid_hits`            INT(10) UNSIGNED    NOT NULL DEFAULT 0,
  `last_real_attack`     INT(10) UNSIGNED    NOT NULL DEFAULT 0,

  PRIMARY KEY (`uid`),
  KEY `growth` (`last_growth`),
  KEY `attack` (`next_attack`),
  KEY `expand` (`last_expand`),
  KEY `tier` (`tier`)
)
  ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4;

-- A raider's farm list: one row per target it keeps coming back to.
CREATE TABLE IF NOT EXISTS npc_farm
(
  `uid`           INT(11) UNSIGNED    NOT NULL,
  -- Target village.
  `kid`           INT(6) UNSIGNED     NOT NULL,
  `owner`         INT(11) UNSIGNED    NOT NULL DEFAULT 0,
  `hits`          INT(10) UNSIGNED    NOT NULL DEFAULT 0,
  `loot`          BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  `losses`        INT(10) UNSIGNED    NOT NULL DEFAULT 0,
  `last_hit`      INT(10) UNSIGNED    NOT NULL DEFAULT 0,
  -- Target came back empty or defended: skip it until this timestamp.
  `burnt_until`   INT(10) UNSIGNED    NOT NULL DEFAULT 0,
  `scouted_at`    INT(10) UNSIGNED    NOT NULL DEFAULT 0,
  `last_real_hit` INT(10) UNSIGNED    NOT NULL DEFAULT 0,

  PRIMARY KEY (`uid`, `kid`),
  KEY `owner` (`owner`),
  KEY `due` (`uid`, `burnt_until`)
)
  ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4;

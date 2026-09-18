-- Repair villages created while RegisterModel::_createVillage was writing one
-- value short: isArtifact ended up in lastVillageCheck, so isArtifact stayed 0
-- on every village and lastVillageCheck was 0 on every non-artifact one. A 0
-- there is permanent exile from NatarsModel::handleNatarVillages, which filters
-- on `lastVillageCheck > 0` -- Natar villages were created and then never grew
-- or trained a single unit.
--
-- Order matters. Artifact villages are exactly the ones that came out with
-- lastVillageCheck = 1, so they must be flagged before the second statement
-- seeds everything else; afterwards the two cases are indistinguishable.

UPDATE vdata
SET isArtifact = 1
WHERE isArtifact = 0
  AND kid IN (SELECT kid FROM artefacts);

-- 1 is the "never checked" sentinel the AI loops expect on a fresh village:
-- they claim it, stamp the current time and skip that pass, so the first real
-- pass measures elapsed time from now instead of from the epoch.
UPDATE vdata
SET lastVillageCheck = 1
WHERE lastVillageCheck = 0;

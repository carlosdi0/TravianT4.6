# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

OpenVillage 4.6: servidor de un juego de estrategia por navegador estilo T4.6.
PHP 8.3 + MariaDB 11.4 + Redis 7.4 + Apache, todo sobre Docker Compose. Es un
fork de preservación, no un proyecto nuevo: gran parte del código es legacy y la
regla del proyecto es **preservar el comportamiento** salvo que el ruleset
documente el cambio.

## Arranque

```sh
cp .env.example .env          # y sustituir TODOS los placeholders
docker compose up -d --build  # juego en http://127.0.0.1:8080
```

El registro no manda correo: `register.php` redirige directo a
`/game/activate.php?token=...`, así que no hace falta SMTP para crear cuentas.
La contraseña `INITIAL_ADMIN_PASSWORD` del `.env` es la del usuario **Support**
(id 0). Multihunter recibe una contraseña aleatoria que no se guarda.

## Verificación

`ripgrep` (`rg`) es obligatorio, los scripts fallan sin él.

```sh
set -a && . ./.env && set +a && ./scripts/verify.sh
```

**El `set -a` no es opcional.** `verify.sh` lee `GAME_DB_PASSWORD` del entorno
del shell, no del `.env`. Docker Compose sí lee el `.env` por su cuenta; el
script no. Sin exportarlo cae al default `local-game-password` y muere con
`Access denied`.

```sh
./scripts/verify-clean-install.sh   # desde volúmenes vacíos, proyecto y puerto aparte
./scripts/test-npc.sh               # solo las regresiones de NPC
docker compose exec -T app php /app/tests/npc-tiers.php   # un test suelto
```

`verify-clean-install.sh` es la única verificación fiable cuando el mundo local
ya se ha usado, y se autodestruye al terminar: no toca los volúmenes de tu
partida.

### El mundo sucio rompe tests que no tienen nada que ver

`tests/runtime-regression.php` busca casillas del mapa que no aparezcan en
ninguna de 20 tablas, incluida `blocks`, que es la caché de bloques del mapa.
**Abrir el mapa una vez la llena entera y ese test ya no vuelve a pasar en ese
mundo.** No es una regresión tuya: verifica contra `verify-clean-install.sh`
antes de buscar la causa en tu cambio.

## El código va dentro de la imagen, no montado

`compose.yaml` solo monta `runtime-data`. El árbol se copia en la imagen al
construir, así que **ningún cambio en PHP surte efecto hasta reconstruir**:

```sh
docker compose up -d --build app worker
```

Para iterar rápido sobre un archivo suelto vale `docker compose cp <archivo>
app:/app/<ruta>`, pero se pierde en el siguiente rebuild.

## Migraciones

Viven en `main_script/include/schema/migrations/` (juego) y `global-migrations/`
(base global), con prefijo `NNN_`. Se registran con checksum SHA-256: **editar
una migración ya aplicada lanza excepción**, hay que añadir otra.

Se aplican desde el servicio `bootstrap`, no ejecutando `migrate.php` a mano
(ese archivo solo define funciones):

```sh
docker compose build bootstrap && docker compose up bootstrap
```

Al añadir una migración hay que **meter su nombre en la lista de `verify.sh` y
subir el contador** de la comprobación, o la verificación empieza a fallar.

## Arquitectura

### Rutas servidas

Docroot `web/public` (la portada y el registro). `/game/` es un alias a
`main_script/copyable/public/`, y todo lo de ahí entra por su `index.php`.
`/gpack/` sirve los assets desde `sections/gpack/`.

El autoloader es PSR-0 sobre `main_script/include`, así que `Game\Npc\NpcTiers`
vive en `main_script/include/Game/Npc/NpcTiers.php`.

### Dos capas, y la diferencia importa mucho

**Controllers**: acoplados a HTTP. Leen `$_REQUEST` y `Core\Session` (que lee
`$_SESSION`, inexistente en CLI porque `bootstrap.php` se salta `session_start()`
fuera de web). `Core\Village` remata con `redirect(); exit()` en su ruta de
fallo, o sea que **mata el proceso**. No los reutilices desde un worker.

**Models y Game**: puros. `Model\TrainingModel::addTraining()`,
`Model\MovementsModel::addMovement()`, `Game\Buildings\BuildingAction::upgrade()`
reciben ids y escriben en base de datos sin tocar sesión ni petición. Es la vía
correcta desde CLI, workers y tests.

La consecuencia incómoda: **la lógica de negocio está duplicada** entre el
controlador humano y la IA. `Game\Buildings\AutoUpgradeAI` reimplementa coste y
dependencias en paralelo a `Core\Village::upgradeBuilding()`. Es el patrón
establecido, no un descuido; unificarlo es un refactor con nombre propio.

### Workers y colas

`Automation.php` arranca `AutomationEngine`, que hace `pcntl_fork()` por grupo de
jobs (`Core\Jobs\Launcher`): construcción, movimientos, entrenamiento, progreso
del mundo, rutinas y `AIProgress` (donde corren los bots existentes). Cada job
hijo tiene su propio bucle e intervalo. Si un hijo muere inesperadamente, el
padre mata a todos y sale.

Las acciones de juego son tablas-cola (`building_upgrade`, `training`,
`movement`, `research`, `send`...). `Core\Jobs\TransactionalTask` consume cada
fila con `SELECT ... FOR UPDATE`, reintenta hasta 5 veces y archiva lo que falla
en `scheduled_task_failures`. De ahí **no se reintenta solo**: es un cementerio
auditable, no una cola con backoff.

### Cuentas automáticas

`access=3` son los "fake users": construyen y entrenan vía `Core\AI`, nada más.
Los Natars (`id=1`) sí atacan, pero solo a aldeas recién fundadas en zona gris.
Los *server-run neighbours* (`npc_player`) son un sistema aparte y no comparten
cuentas con los anteriores; ver [docs/NPC.md](docs/NPC.md).

### Capa de decisión de NPC

`main_script/include/Game/Npc/` son clases puras: sin base de datos, sin sesión,
sin globals, sin `Formulas`. Todo lo que necesitan del ruleset entra por la
interfaz `NpcGameData`; `NpcFormulasData` es la única que conoce el motor.

Los slots de unidad ahí son **siempre relativos a la tribu (1..10)**. La tabla
`units` guarda slots relativos más una columna `race`, mientras que las fórmulas
usan ids absolutos `(tribu-1)*10+slot`. La conversión está en un solo sitio,
`NpcFormulasData::absoluteUnitId()`. Mezclarlas le da a un NPC el ejército de
otra tribu.

## Convenciones

- Tests: scripts PHP sueltos en `tests/`, sin framework, que salen con código
  distinto de 0 al fallar y se lanzan con un `scripts/test-*.sh` enganchado a
  `verify.sh`. Los que ejercitan el motor hacen `require bootstrap.php` (sin
  sesión, como en CLI); los de la capa de decisión de NPC no cargan nada del
  juego y por eso corren en segundos.
- PHPStan corre en **level 0** con imagen fijada por digest: detecta poco, el
  listón real son las regresiones.
- El historial del repo usa frases imperativas sin prefijo (`Harden merchant
  task processing`), no Conventional Commits.
- Al tocar gameplay o runtime hay que añadir o actualizar cobertura de
  regresión; lo pide `CONTRIBUTING.md`.

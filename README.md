# ephpm/mysqli-shim

Userland mysqli compatibility shim over [ePHPm](https://ephpm.dev)'s
in-process DB bridge (`ephpm_db_query()` / `ephpm_db_execute()`). Code
written against the mysqli API talks straight to the embedded litewire
SQLite session — no MySQL server, no socket, no wire protocol.

> **The activation rule — read this first.** A userland shim cannot
> override a loaded extension. The global `mysqli` / `mysqli_result` /
> `mysqli_stmt` classes, the `mysqli_*` functions, and the `MYSQLI_*`
> constants are defined **only when ext-mysqli is NOT loaded** (every
> definition is guarded). ePHPm's own SDK builds currently compile
> mysqli in, so under a stock ePHPm binary the real extension wins and
> the global surface of this package is inert. The namespaced API
> (`Ephpm\Mysqli\Connection` etc.) works regardless — the global
> definitions are thin aliases onto it.

Who this is for:

- **PHP embed builds without mysqli** — slimmed SDK variants, other
  embedders of the ePHPm SAPI — that want existing mysqli code (e.g.
  WordPress' `wpdb`) to run on the bridge unmodified.
- **Code that wants the bridge behind a mysqli-shaped API** under any
  build, via the always-available namespaced classes.

```php
// Global surface (only when ext-mysqli is absent):
$db = mysqli_connect();                      // host args accepted & ignored
$db->query("INSERT INTO t (name) VALUES ('a')");
$row = $db->query('SELECT * FROM t')->fetch_assoc();

// Namespaced surface (works everywhere, even with ext-mysqli loaded):
$db = new \Ephpm\Mysqli\Connection();
$stmt = $db->prepare('SELECT * FROM t WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
```

---

## Table of contents

- [Requirements](#requirements)
- [Install](#install)
- [How it connects (it doesn't)](#how-it-connects-it-doesnt)
- [Error reporting](#error-reporting)
- [Statement routing](#statement-routing)
- [Coverage matrix](#coverage-matrix)
- [Fidelity limits](#fidelity-limits)
- [Transactions](#transactions)
- [Testing without ePHPm](#testing-without-ephpm)
- [License](#license)

---

## Requirements

- **PHP 8.2+**
- **ePHPm built from current `main`** — the `ephpm_db_*` bridge merged
  in [ephpm#257](https://github.com/ephpm/ephpm/pull/257) and is **not
  in any tagged release yet**.
- **`[db.sqlite]` active** in your ePHPm config. The bridge only
  registers when an embedded SQLite backend is running; without it the
  natives throw `ephpm_db: no embedded database is active`.
- For the global mysqli surface: **a PHP build without ext-mysqli**
  (see the activation rule above). Check with
  `php -m | grep mysqli` — or at runtime:

```php
var_dump(extension_loaded('mysqli'));          // false → shim globals active
var_dump(function_exists('ephpm_db_query'));   // true  → bridge available
```

## Install

```bash
composer require ephpm/mysqli-shim
```

`src/compat/mysqli.php` (the guarded global surface) is loaded through
composer's `autoload.files` on every request; when ext-mysqli is
loaded it returns immediately without defining anything.

## How it connects (it doesn't)

There is no connection. Host, user, password, database, port, and
socket arguments on `__construct` / `mysqli_connect()` /
`real_connect()` are **accepted and ignored**; `ping()` is always true;
`connect_errno` is always 0. Every statement runs on ePHPm's per-thread
litewire session — the same backend the MySQL wire frontend serves, so
SHOW/DESCRIBE emulation, `SET NAMES` no-ops, and transaction handling
behave exactly as they do over a socket to the same server.

`get_server_info()` returns `8.0.36-litewire`, mirroring what
litewire's MySQL wire frontend advertises in its handshake. Note that
`SELECT VERSION()` through the same backend answers `8.0.0-litewire`
(a translate-layer constant) — real litewire over a socket shows the
same pair.

## Error reporting

The shim honors mysqli's report-mode semantics with the PHP 8.1+
default (`MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT`):

| Mode | Behavior on SQL error |
|---|---|
| `ERROR \| STRICT` (default) | throws `Ephpm\Mysqli\SqlException` |
| `ERROR` only | `E_USER_WARNING` + returns false |
| `OFF` | returns false silently |

In every mode, `errno` / `error` / `sqlstate` / `error_list` are set on
the connection (and statement). Errors carry the bridge's MySQL errno
(e.g. 1062) and SQLSTATE.

**Exception class caveat:** the real `mysqli_sql_exception` is final
(PHP 8.4), so the shim cannot extend it. When ext-mysqli is absent,
`mysqli_sql_exception` is aliased to `Ephpm\Mysqli\SqlException` and
`catch (mysqli_sql_exception $e)` works as expected. When ext-mysqli
IS loaded (namespaced usage), catch `Ephpm\Mysqli\SqlException` or
`\RuntimeException` — both the real and the shim exception extend
`RuntimeException`. `getSqlState()` is available on both.

Setting the mode: the global `mysqli_report()` is part of the guarded
surface, so it only exists (and only affects the shim) when ext-mysqli
is absent. Namespaced users call `Ephpm\Mysqli\Report::set()` — when
the real extension is loaded, the real `mysqli_report()` controls the
real driver, not this shim.

## Statement routing

The bridge exposes two entry points with different return shapes:
`ephpm_db_query()` (rows, no OK metadata) and `ephpm_db_execute()`
(affected_rows / last_insert_id, no rows). `mysqli_query()` is one
entry point, so the shim routes by the first significant keyword
(comments and leading parentheses skipped):

- `SELECT`, `SHOW`, `DESCRIBE`, `DESC`, `EXPLAIN`, `WITH`, `VALUES`,
  `TABLE`, or any statement containing a `RETURNING` clause →
  `ephpm_db_query()` → returns a `mysqli_result` (possibly zero-row)
- everything else → `ephpm_db_execute()` → returns true, sets
  `affected_rows` / `insert_id`

Consequences: a `WITH … INSERT` hybrid is routed to the query side and
loses its affected-row count; after a rowset statement `insert_id` is 0
and `affected_rows` equals `num_rows` (the mysqlnd buffered behavior).

## Coverage matrix

Legend: **impl** = implemented over the bridge · **no-op** = accepted,
does nothing, returns success · **throws** = not implemented, throws
`Ephpm\Mysqli\NotImplementedException` (a `BadMethodCallException`).

### `mysqli` (`Ephpm\Mysqli\Connection`)

| Member | Status |
|---|---|
| `__construct` / `connect` / `real_connect` | **no-op** — all connection args accepted and ignored |
| `query` (returns `mysqli_result`\|bool), `real_query` + `store_result` / `use_result` | **impl** (all results buffered; `$result_mode` ignored) |
| `prepare` | **impl** — but no server-side validation; errors surface at execute |
| `real_escape_string` / `escape_string` | **impl** — backslash-escapes NUL, `\n`, `\r`, `\`, `'`, `"`, Ctrl-Z |
| `errno`, `error`, `error_list`, `sqlstate`, `insert_id`, `affected_rows`, `field_count` | **impl** |
| `autocommit`, `begin_transaction`, `commit`, `rollback` | **impl** — as SQL through the bridge; see [Transactions](#transactions) |
| `savepoint`, `release_savepoint` | pass-through SQL — support depends on the backend |
| `close` | **impl** — later use throws `Error`, like the real class |
| `ping` | **impl** — always true |
| `select_db`, `set_charset`, `options` / `set_opt`, `ssl_set` | **no-op** true |
| `character_set_name` | **impl** — `'utf8mb4'` |
| `get_charset` | **impl** — static utf8mb4 charset object |
| `get_server_info` / `server_info` / `server_version` | **impl** — `8.0.36-litewire` / 80036 |
| `get_client_info`, `host_info`, `protocol_version` | **impl** — static shim values |
| `thread_id` | **impl** — stable per-connection fake (no real threads exposed) |
| `warning_count`, `get_warnings` | always 0 / false |
| `more_results`, `next_result` | always false (no multi-query) |
| `multi_query` | **throws** |
| `change_user`, `kill`, `refresh`, `stat`, `dump_debug_info`, `debug`, `stmt_init` | **throws** |
| async (`MYSQLI_ASYNC`, `poll`, `reap_async_query`) | not defined |

### `mysqli_result` (`Ephpm\Mysqli\Result`)

| Member | Status |
|---|---|
| `fetch_assoc`, `fetch_row`, `fetch_array` (ASSOC/NUM/BOTH), `fetch_object`, `fetch_all`, `fetch_column` | **impl** — native int/float/null typing from the bridge |
| `num_rows`, `field_count`, `data_seek`, `free` / `close` / `free_result`, iteration (`foreach`) | **impl** |
| `fetch_field`, `fetch_fields`, `fetch_field_direct`, `field_seek`, `current_field` | **impl, best-effort** — see [Fidelity limits](#fidelity-limits) |
| `lengths` | **impl** — byte lengths of the last-fetched row |

### `mysqli_stmt` (`Ephpm\Mysqli\Statement`)

| Member | Status |
|---|---|
| `bind_param` (`i`/`d`/`s`/`b`, by reference, coerced at execute) | **impl** |
| `execute` (incl. PHP 8.1-style `execute([$params])`, sent as strings) | **impl** |
| `get_result` | **impl** — `Result` for rowsets, false otherwise; consumed once per execute |
| `bind_result` + `fetch` | **impl** — cheap over the buffered rowset, so it's in |
| `affected_rows`, `insert_id`, `num_rows`, `param_count`, `field_count` | **impl** |
| `errno`, `error`, `error_list`, `sqlstate` | **impl** |
| `store_result` | **no-op** true (always buffered) |
| `free_result`, `close` | **impl** |
| `reset`, `send_long_data`, `result_metadata`, `attr_set`, `attr_get` | **throws** |

### Procedural wrappers

Every implemented member above has its `mysqli_*` procedural wrapper
(`mysqli_connect`, `mysqli_query`, `mysqli_fetch_assoc`,
`mysqli_stmt_bind_param`, `mysqli_report`, …) — defined only when
ext-mysqli is absent. `mysqli_connect_errno()` / `mysqli_connect_error()`
return 0 / null. See `src/compat/mysqli.php` for the exact list.

### Constants

The `MYSQLI_*` constants the shim defines (fetch modes, report flags,
field types, field flags, client/option/transaction flags) live in
`Ephpm\Mysqli\Compat::CONSTANTS` with values asserted against the real
extension in CI. `MYSQLI_TYPE_VARCHAR` is deliberately absent (the real
extension doesn't define it) and so are `MYSQLI_NO_DATA` /
`MYSQLI_DATA_TRUNCATED` (deprecated in PHP 8.4; never produced here).

## Fidelity limits

Honest list of where the shim differs from real mysqli:

- **Zero-row rowsets lose column metadata.** `ephpm_db_query()` returns
  rows only, so a SELECT with no rows yields a `Result` with
  `num_rows === 0` **and `field_count === 0`** — the real mysqli knows
  the columns. Field names/types are derived from the first row.
- **Field metadata is inferred, not declared.** `fetch_field()` reports
  `name`/`orgname` correctly; `type` is guessed from PHP value types
  (int → `LONGLONG`, float → `DOUBLE`, string → `VAR_STRING`, all-null →
  `NULL`); `table`/`orgtable`/`db` are empty, `length` is 0, `flags` is
  at most `NUM_FLAG`. Code that branches on precise column types
  (e.g. distinguishing `TINYINT` from `BIGINT`) will not get that here.
- **`prepare()` does not validate SQL** — the bridge has no server-side
  prepare, so syntax errors throw/fail at `execute()` time.
- **`insert_id` is per-statement**: 0 after any rowset statement.
- **`affected_rows` after SELECT equals `num_rows`** (mysqlnd buffered
  behavior); it does not go to -1.
- **No multi-statement support** (`multi_query`), no async, no
  `mysqli_driver`/`mysqli_warning` classes.
- **`fetch_object()` assigns properties after construction**, not
  before, as the real extension does.
- **`mysqli_report()` is per-process** (a static mode), not per-driver
  instance.

## Transactions

`begin_transaction` / `commit` / `rollback` / `autocommit(false)` run
`BEGIN` / `COMMIT` / `ROLLBACK` through the bridge as plain SQL, and
the shim tracks statements it has seen to make `commit()` with no open
transaction a no-op and to emulate autocommit-off (implicit `BEGIN`
before the next data statement).

**Important ePHPm caveat (from the bridge's own docs):** the bridge
session — and therefore any transaction left open — lives for the
**worker thread, not the request**. There is no request-end rollback.
Always `COMMIT` or `ROLLBACK` before your request ends; an unfinished
transaction stays open on that thread until its next `ephpm_db_*` call.

## Testing without ePHPm

`Ephpm\Mysqli\Connection` takes an optional `DbOpsInterface`, and
`Ephpm\Mysqli\SqliteDbOps` emulates the bridge with the `sqlite3`
extension (native REAL binding, bridge-shaped errors with best-effort
MySQL errnos, `SET …` no-ops). This is how this repo's own test suite
runs on a stock PHP CLI:

```php
use Ephpm\Mysqli\Connection;
use Ephpm\Mysqli\SqliteDbOps;

$db = new Connection(ops: new SqliteDbOps());          // per-connection
Connection::setDefaultOpsFactory(fn () => new SqliteDbOps()); // process-wide
```

`SqliteDbOps` is for tests only: it speaks SQLite dialect directly —
no MySQL translation, no SHOW/DESCRIBE emulation — and its error-code
mapping is a heuristic.

Because a normal dev PHP has the real ext-mysqli loaded, the test suite
targets the namespaced classes directly and exercises the guarded
global surface in a child `php -n` process (see
`tests/CompatSurfaceTest.php`).

## License

MIT — see [LICENSE](LICENSE).

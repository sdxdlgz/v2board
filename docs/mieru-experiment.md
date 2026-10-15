# Mieru isolated trial

This is an experimental vertical slice, disabled by default. It has not been
deployed to a production panel or node. Do not replace a working installation
with this checkout. In particular, the live panel has local changes that must
be merged separately.

## Compatibility boundary

Existing UniProxy/V2 API handlers, installation scripts, old node IDs, credentials,
traffic queues, and old protocol formatters are retained. Existing nodes do
not need an upgrade or a rerun of their install scripts. Mieru uses a new node ID
in `v2_server_v2node`, a separate adapter, and `/api/v1/server/Mieru/` endpoints.

Two shared panel writers need an atomic-increment fix: `TrafficUpdate` previously
read usage and later overwrote it, and `StatUserJob` overwrote shared daily rows.
Both could discard Mieru traffic committed between those operations. The trial
reproduces all three lost-update cases before the fix, then changes these writes
to SQL increments. The job still uses the same queue, rate/day keys, raw byte
statistics, and retry count. Updates are batched in chunks of 500. This panel
change requires additional staging validation; old node binaries remain intact.

The new accounting transaction uses locking reads for the agent and checkpoint.
Plain snapshot reads triggered a concurrent-update error in MariaDB 11.8.3;
current reads passed the same competing-writer test without changing database
settings. See MariaDB's [snapshot isolation documentation](https://mariadb.com/docs/server/server-usage/storage-engines/innodb/innodb-system-variables#innodb_snapshot_isolation).

The original subscription URL can include the new node. General/Shadowrocket
subscriptions contain an official `mierus://` simple URI. `flag=meta` adds a
Mihomo Mieru proxy to the full existing configuration. Old protocols retain their
formatters. An unsupported subscription format omits Mieru. This does not make
an incompatible client support the protocol.

The public `v2board/v2board-admin` and `v2board/v2board-user` default branches
checked during this trial contain compiled assets, not frontend source. The
admin package also differs from this fork's customized admin. Do not replace or
patch these minified packages. The trial uses the commands below; an editable
admin form needs the matching frontend source.

## Trial setup (not a production deployment procedure)

Use a disposable panel database, never the production database for these steps.
Run `php artisan mieru:setup` to create only the two additional accounting tables.
Set `MIERU_EXPERIMENTAL_ENABLED=true` in that disposable environment and refresh
its config cache using the project's usual procedure.

Create a hidden node:

```
php artisan mieru:create "Mieru trial" example.invalid 16201 --group=1 --rate=1.00
```

`--server-port` is optional when internal and public ports differ. This prototype
supports one TCP transport port per node. The internal listener defaults to
`0.0.0.0`. Use `mieru:visibility ID --show=1` only in a test user group to include
the node in subscriptions. `--show=0` hides it again. The legacy admin protocol
form cannot edit a Mieru node.

Build and configure the optional module in `V2bX/experimental/mieru`. It is a
separate process, with its own private state file. Do not attach it to an existing
node ID. Do not run NoBrand-OneClick/Mita and this adapter on the same port.

## Accounting and failure behavior

The adapter saves cumulative per-user counters before reporting. The panel pins
an agent ID to the new node and atomically records usage, daily raw statistics,
and a checkpoint in a database transaction. Retrying an accepted report adds no
usage; a failed transaction leaves no checkpoint. Rates use hundredths with
carried fractions, without repricing earlier traffic when a rate changes.

Keep both the agent state file and the two panel tables. A different agent ID is
rejected instead of silently charging old totals again. This prototype does not
provide automatic state-loss recovery or a multi-agent cluster. A panel database
restore and an agent state restore must be coordinated by an operator.

The adapter polls every five seconds. Quota/expiry/group/banned checks reuse the
panel's active user query. Any failed sync stops the new proxy. An empty user list
stops the listener. User/config changes replace the runtime and terminate all
its established sessions, including unaffected users' sessions. That is a
deliberate prototype limitation. Expiry/quota enforcement has the polling and
request delay; it is not instantaneous or byte-exact at the quota boundary.

Counters are durable at sampling boundaries. SIGKILL, machine/power failure, or
a crash can lose traffic since the last successful sample; there is no claim of
packet-level exactly-once persistence. Graceful shutdown saves pending traffic
even if the panel is unavailable. Retries cannot fix traffic never sampled.

Speed limits and device limits are not implemented. A snapshot containing an
active user with either limit returns HTTP 409 and the adapter stops. This must
be resolved before using the feature with limited plans; the prototype does not
silently bypass those restrictions. Routing/auditing/IP reports, port hopping,
UDP transport, 0-RTT and custom traffic patterns are also outside this trial.
Application UDP over the TCP Mieru transport is supported by the official handler.

## Completed isolated verification

The PHP suite covers API authorization, permission changes, billing, malformed
input, and legacy subscription regression. The real MariaDB 11.8.3 integration
test uses metadata-only copies of the deployed table definitions and synthetic
users in a disposable database. It executes the new-node creation command and
two competing PHP writers (32 iterations each), including duplicate reports,
transaction rollback, agent binding, and fractional rates. The live database
is not used for these writes. To repeat with an explicitly isolated database:

```
php tests/Integration/mieru-mysql.php /path/to/vendor/autoload.php /private/test-config.json
```

The config requires `host`, `port`, `database`, `username`, `password`,
`schema_file` (a table-name-to-DDL JSON map), and `barrier` (a fresh file path).
Only loopback, non-default database ports and `codex_mieru_trial_` databases
with a 12-character lowercase hex suffix are accepted. Never supply production
database credentials. The database must be empty before each run.

The optional Go adapter passed native tests/vet and Linux TCP, application UDP,
traffic-counter and session-revocation checks with the official Mieru SDK.
A real Mihomo client forwarded HTTP using a panel-exported proxy object; the
official Mieru client decoded the panel-exported simple URI. These local tests
do not exercise the provider network or client GUIs.

## Client and provider acceptance still required

Before rollout, use real current Shadowrocket and Karing installations to test
the existing subscription URL, Mieru URI import, connection, UDP, traffic billing,
and coexistence with old nodes. v2rayN needs a separately configured full Mihomo
custom subscription; normal mixed URI import is not the same route. These GUI
flows are not proved by an SDK/Mihomo loopback test.

The provider's mobile ingress province whitelist and allowed protocols still
apply. A locally working Mieru implementation cannot bypass those restrictions.
Memory/CPU/concurrency on the 512 MB node and the actual provider path require a
separate controlled test. Rollback hides/disables the new protocol and stops only
its adapter; existing nodes remain on their current binaries and configurations.

# High-availability WordPress

```
Clients ─HTTPS :8443─► HAProxy ─HTTP :80─► wordpress-1..N ─:6033─► ProxySQL-1/2 ─:3306─► galera1 (writer)
 (browser, ab)        TLS, L7 LB,           │      │                                       galera2, galera3
                      health checks         │      └─► Redis (object cache + sessions)     (synchronous replication)
                                            └─► wp_uploads (shared media volume)
                      wp-init (one-shot installer)    wp-cron (singleton scheduler)
```

| Network    | Members                                              | Notes                          |
|------------|------------------------------------------------------|--------------------------------|
| `frontend` | HAProxy, WordPress                                   | Normal bridge, ports published |
| `backend`  | WordPress, ProxySQL, Galera, Redis, wp-init, wp-cron | `internal: true`, no internet  |

## Run

```bash
./setup.sh            # writes .env (random secrets) and certs/site.pem (self-signed)
docker compose up -d --build
```

First start takes about a minute (Galera bootstraps, then wp-init installs WordPress).

| What              | Where                                              |
|-------------------|----------------------------------------------------|
| Site              | https://localhost:8443 (accept the self-signed cert) |
| Admin             | https://localhost:8443/wp-admin, credentials in `.env` (`WORDPRESS_ADMIN_*`) |
| HTTP redirect     | http://localhost:8081 (`HTTP_PORT` in `.env`)      |
| HAProxy stats     | http://127.0.0.1:8404/stats, credentials in `.env` (`STATS_*`) |

`HTTP_PORT` defaults to 8080 in the diagram. `setup.sh` writes 8080; change it in `.env` if
something else on your machine already uses it.

If you change `WORDPRESS_URL` or a published port, keep them consistent: WordPress builds
every redirect and link from `WORDPRESS_URL`.

## Scale

```bash
docker compose up -d --scale wordpress=4 --no-recreate
```

HAProxy re-resolves the `wordpress` service name through Docker DNS and picks the new
replicas up without a restart (up to 10, see `server-template` in `haproxy/haproxy.cfg`).
The `X-Served-By` response header shows which replica answered.

## How the pieces behave

- **HAProxy** terminates TLS (1.2+, HTTP/2), redirects 8080 to HTTPS and health-checks each replica on
  `/healthz.php`, which succeeds only if that replica can run a query through ProxySQL.
- **WordPress** nodes are identical and immutable. Code is copied from the image into a tmpfs on
  every start; only `wp-content/uploads` is shared (`wp_uploads`). Plugin and theme installs from
  the admin UI are disabled (`DISALLOW_FILE_MODS`). Add plugins in `wordpress/Dockerfile` and
  rebuild. Keys and salts come from `.env`, so logins work on every node.
- **ProxySQL** (two instances, both reachable under the DNS alias `proxysql`) uses Galera-aware
  hostgroups: one writer at a time (galera1 preferred), galera2/3 as backup writers. Docker DNS
  drops a stopped proxy, so WordPress falls through to the other one. The config is rendered from
  `proxysql/proxysql.cnf.tmpl` at every start; there is no state to back up.
- **Galera**: `galera1` bootstraps a new cluster only when no peer answers on port 4567;
  otherwise every node joins. Restarting any single node rejoins by incremental state transfer.
- **Redis** holds the object cache (Redis Object Cache plugin, baked into the image) and PHP sessions.
- **wp-cron** is the only place scheduled events run (`DISABLE_WP_CRON` on web nodes).
- **wp-init** installs WordPress if needed and exits. Re-running is harmless.

## Verified behaviour (local run)

| Test                                              | Result                                             |
|---------------------------------------------------|----------------------------------------------------|
| HTTPS, HTTP/2, TLS 1.3, HTTP→HTTPS redirect       | works                                              |
| Round-robin across replicas (2 and 3)             | even split                                         |
| `docker stop` one WordPress replica under load    | 0 failed requests, slowest request about 1.1 s     |
| Stop either ProxySQL (or each in turn)            | 0 failed requests                                  |
| `docker kill` the Galera writer (galera1)         | writes resume in about 6 s on galera3, site stays up |
| Restart galera1                                   | rejoins via IST in about 18 s, becomes writer again |
| `docker compose down` then `up` (volumes kept)    | data and uploads intact, back in about 6 s         |
| Upload written on one replica                     | readable on the other replica and in wp-cron       |
| Backend network                                   | no route to the internet                           |

## Operations

```bash
docker compose ps                                   # health of every service
docker compose logs -f galera1                      # follow one service
# Galera status
docker compose exec galera1 sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -e "SHOW STATUS LIKE \"wsrep_%\""'
# ProxySQL view of the cluster
docker compose exec proxysql-1 sh -c 'mysql -h127.0.0.1 -P6032 -uradmin -p"$PROXYSQL_ADMIN_PASSWORD" -e "SELECT * FROM runtime_mysql_servers"'
```

After editing `haproxy/haproxy.cfg`, run `docker compose restart haproxy`. A bind-mounted
config change does not recreate the container.

### All three database nodes down at once

Start the node that stopped last. `galera1` refuses to bootstrap if its `grastate.dat` says it was
not the last node (`safe_to_bootstrap: 0`) and no peer is up, to avoid losing writes. If you know
galera1 has the newest data, or accept losing the difference, set `GALERA_FORCE_BOOTSTRAP=1` in
`.env` for one start, then remove it. This recovery path is implemented but was not exercised in testing.

### Reset everything (deletes all data)

```bash
docker compose down -v
```

## Limits to know about

- Galera needs a majority: with two of three nodes down the survivor stops accepting queries to
  protect consistency.
- Redis, HAProxy and the `wp_uploads` volume are single instances, as in the diagram. They are
  single points of failure, and `wp_uploads` is a local Docker volume, so all replicas must run on
  one Docker host. Spanning hosts would need NFS or object storage for uploads and a replicated Redis.
- The TLS certificate is self-signed. For real use, replace `certs/site.pem` (certificate followed by key).
- Bind mounts carry the `z` flag so they work on SELinux hosts (Fedora/RHEL). It relabels the
  files in this directory and is ignored elsewhere.

# High-availability Drupal

```
Clients ─HTTP :8080─► HAProxy ─:80─► drupal1 ─┐
 (browser)            round robin,  drupal2 ─┼─► PostgreSQL (sessions, cache, content)
                      health checks drupal3 ─┘
                                       └──────► drupal_files / drupal_private (shared volumes)
```

Three identical `drupal:11-apache` containers run behind HAProxy. They hold no state of their own:
sessions, caches and content are in PostgreSQL, and uploaded files are on shared volumes. Any
node can answer any request, and a logged-in user stays logged in when the next request lands on
a different node.

## Run

```bash
./setup.sh                  # writes .env with random secrets
docker compose up -d
```

First start takes about a minute: `drupal1`, `drupal2` and `drupal3` start together, one of them
installs Drupal (standard profile) and the other two wait for it and skip the install.

| What          | Where                                                          |
|---------------|----------------------------------------------------------------|
| Site          | http://localhost:8080 (`HTTP_PORT` in `.env`)                  |
| Admin login   | http://localhost:8080/user/login, credentials in `.env` (`DRUPAL_ADMIN_*`) |
| HAProxy stats | http://127.0.0.1:8404/stats, credentials in `.env` (`STATS_*`) |

The `X-Served-By` response header shows which node answered.

If you reach the site under another host name, add it to `DRUPAL_TRUSTED_HOSTS` in `.env`
(comma separated). `localhost` is always allowed because the health checks use it.

## How it works

- **HAProxy** round-robins across the three nodes and checks `/user/login` every 2 s. That page
  needs the database, so a node that lost its database is removed too. A failed node leaves
  rotation after 2 failed checks and its open connections are closed.
- **Drupal nodes** share one `settings.php` (`drupal/settings.php`, read-only, values from
  environment variables). The hash salt is identical on every node, so sessions, form tokens and
  one-time login links are valid everywhere. Nodes publish no ports and sit on a private network.
- **Install**: `drupal/entrypoint.sh` takes a lock on the shared volume, runs
  `drupal/install.php` and releases the lock before Apache starts. The first node installs; the
  others see the admin user in the database and continue. Re-running is harmless.
- **PostgreSQL** is on an `internal: true` network with no route to the internet.
- **Shared volumes**: `drupal_files` (public files) and `drupal_private` (private files, config
  sync directory, install lock).

## Verified behaviour (local run)

| Test                                           | Result                                           |
|------------------------------------------------|--------------------------------------------------|
| Cold start, three nodes at once                | one install, two skips, all healthy              |
| Round robin                                    | even split across the three nodes                |
| Log in once, then 9 requests across all nodes  | all 200, session valid on every node             |
| File written on `drupal1`                      | readable on `drupal3` and through HAProxy        |
| `docker stop` one node under load              | 0 failed requests of 60                          |
| Two nodes stopped                              | 0 failed requests of 20, all served by the last  |
| Both started again                             | healthy in about 8 s, back in rotation           |
| `docker kill` one node under load              | 0 failed requests of 40                          |
| `docker compose down` then `up` (volumes kept) | site, content and login intact, back in 4 s      |

## Operations

```bash
docker compose ps                    # health of every service
docker compose logs -f drupal1       # follow one node
docker compose exec drupal1 php /opt/drupal/web/core/scripts/dr cache:rebuild   # clear caches
```

After editing `haproxy/haproxy.cfg`, run `docker compose restart haproxy`.

Reset everything (deletes the site and all data):

```bash
docker compose down -v
```

## Limits to know about

- PostgreSQL, HAProxy and the shared volumes are single instances, so they are single points of
  failure. This setup protects against losing a Drupal node, not the database. Making the
  database highly available needs a replicated setup such as Patroni, or Galera on MariaDB (see
  `../wordpress-HA-experiment`).
- The volumes are local Docker volumes, so all three nodes must run on one Docker host. Spanning
  hosts needs NFS or object storage for files.
- Modules and themes are not shared between nodes. Install them by building an image that
  contains them (for example with `composer require` in a Dockerfile) and use it for all three
  services. Installing them from the admin UI would only change one node.
- Plain HTTP only. For real use, add TLS termination in HAProxy (see the WordPress experiment).
- Do not run `drupal-stack` at the same time on port 8080.

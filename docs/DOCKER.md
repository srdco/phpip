# Running phpIP with Docker

The stack contains PHP 8.4 with Apache, MySQL 8.4, and an optional Laravel
scheduler. Composer dependencies and Vite assets are built into the image;
PHP, Composer, Node, and MySQL do not need to be installed on the host.
Use Docker Engine/Desktop with Compose v2 and BuildKit.

## First installation

From the repository root:

```sh
cp .env.docker.example .env.docker
chmod 600 .env.docker
```

Edit `.env.docker`. Set distinct random `DB_PASSWORD` and `MYSQL_ROOT_PASSWORD`
values, and set `APP_KEY` to `base64:` followed by the output of
`openssl rand -base64 32`. Keep this key across updates and restores. Set your
company name, URL, locale, and timezone. The Docker environment file is ignored
by Git and excluded from image builds; your existing `.env` is not used by the
containers.

Always pass `--env-file .env.docker`: Compose uses it both for database settings
and container configuration. Omitting it may select settings from a host `.env`.

```sh
docker compose --env-file .env.docker build app
docker compose --env-file .env.docker up -d --wait db
docker compose --env-file .env.docker run --rm app php artisan migrate --force
docker compose --env-file .env.docker run --rm app php artisan db:seed --force
docker compose --env-file .env.docker up -d --wait app
```

Open <http://localhost:8000>. A fresh seed creates the upstream initial login
`phpipuser` / `changeme`; change its password immediately. Seeding is an explicit
first-install action, never an automatic container startup action. Do not seed
an existing installation or import sample data into a real portfolio.

The web port binds to localhost by default. MySQL has no published host port.
`APP_BIND_ADDRESS` and `APP_PORT` control the web binding. For a public deployment,
configure HTTPS and the correct application URL and proxy handling before
exposing the application.

## Database details

Use MySQL 8.4 for this stack. Current migrations require `utf8mb4_0900_ai_ci` and
rebuild stored functions, procedures, views, and triggers. Old installation
documentation describing MySQL 5.7 or older MariaDB versions is not applicable.

The image removes explicit `DEFINER` clauses from its copy of the baseline
schema. Fresh database objects therefore belong to the configured importing
account, instead of the dump's `phpip@localhost` account. The checked-in schema
is unchanged. The application account has privileges on its database only;
`log_bin_trust_function_creators=1` is set on the bundled MySQL server to permit
routine/trigger creation without granting global administration privileges.

For an existing installation, restore a backup into an isolated database first,
review imported object definers, then run `migrate --force`. Do not point this
stack at the live database as a first test. Containers never automatically
migrate or reset the database.

Changing database credentials in `.env.docker` does not change accounts in an
already initialized MySQL volume. Update those accounts explicitly and recreate
the application containers when rotating credentials.

## Scheduler and integrations

Mail defaults to the application log. Configure SMTP, recipients, OPS, Renewr,
and any SharePoint settings in `.env.docker` as needed; see `.env.example` and
the integration guides for available options. Then enable the scheduler:

```sh
docker compose --env-file .env.docker --profile scheduler up -d scheduler
docker compose --env-file .env.docker exec scheduler php artisan schedule:list
```

The existing schedule syncs Renewr on Mondays at 03:00 and sends task reminders
at 06:00, using `APP_TIMEZONE`. The scheduler runs as `www-data` and shares the
application storage volume, including file cache locks and Renewr sync state.
Run one scheduler service per installation. Queues remain synchronous; a queue
worker or Redis service is not required for this configuration.

Stop it with `docker compose --env-file .env.docker stop scheduler`. Recreate
containers after environment changes; `restart` alone does not reload values.

## Updates and checks

Back up the database, storage, and environment file first. Stop the scheduler
while applying updates, rebuild the image, run migrations once, then recreate
the services:

```sh
docker compose --env-file .env.docker stop scheduler
docker compose --env-file .env.docker build app
docker compose --env-file .env.docker run --rm app php artisan migrate --force
docker compose --env-file .env.docker up -d --wait app
# If scheduling was enabled:
docker compose --env-file .env.docker --profile scheduler up -d scheduler
```

For a production update, take the app out of service during migrations and plan
rollback using the pre-update backup; rebuilding an older image does not undo
database changes.

```sh
docker compose --env-file .env.docker ps
docker compose --env-file .env.docker logs --tail=100 app
docker compose --env-file .env.docker exec app php artisan migrate:status
curl --fail http://localhost:8000/up
```

The image's `/up` health check establishes that Laravel boots; it does not prove
database or third-party integration health. Verify login, matter/event workflows,
renewal calculations, and integrations against an isolated copy before rollout.
The runtime image excludes development dependencies, including PHPUnit.

The Docker GitHub Actions workflow builds a fresh stack, migrates and seeds it,
checks HTTP login and compiled assets, and replaces the app container to check
database and storage persistence. The same HTTP check can be run against a
disposable seeded stack with `python3 tests/docker-smoke.py`. This is a container
smoke check, not the full application test suite.

If an existing installation uses a private `config/renewal.php`, mount that file
read-only at `/var/www/html/config/renewal.php` for both app and scheduler using a
local Compose override. It is deliberately excluded from the image because it
may contain installation-specific credentials and billing configuration.

## Persistence and backups

The `db-data` volume stores MySQL data. The `app-storage` volume stores application
files, file sessions/cache, and Renewr sync state. `docker compose down` preserves
them; **`down -v` deletes them**. Keep `.env.docker` in your secure backup as well.

Create a logical database backup without putting the password in shell history:

```sh
docker compose --env-file .env.docker exec -T db sh -c \
  'MYSQL_PWD="$MYSQL_PASSWORD" exec mysqldump -u"$MYSQL_USER" --no-tablespaces --single-transaction --routines --triggers "$MYSQL_DATABASE"' \
  > phpip-backup.sql
```

Keep backups outside the repository/build context. To back up storage, stop the
app and scheduler briefly so their files do not change while archiving:

```sh
docker compose --env-file .env.docker stop app scheduler
docker compose --env-file .env.docker run --rm --no-deps --entrypoint tar app \
  -czf - -C /var/www/html storage > phpip-storage.tar.gz
docker compose --env-file .env.docker up -d app
# Restart the scheduler too if it was enabled.
```

Practice restoring both backups into a separate Compose project before relying
on them. Restore the original application key with the data.

## Builds behind a managed HTTPS proxy

Build steps accept an optional BuildKit `proxy_ca` secret. Pass a combined CA
bundle when the environment requires its own CA, keeping TLS verification on:

```sh
docker build --secret id=proxy_ca,src=/etc/ssl/certs/ca-certificates.crt -t phpip:local .
```

This secret is used for Yarn and Composer and is not stored in image layers.
Use the normal Compose startup commands afterwards. Runtime API calls behind
such a proxy also need that environment's proxy and read-only CA configuration;
build-time trust does not configure runtime trust.

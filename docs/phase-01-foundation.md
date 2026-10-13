# Phase 01: Application Foundation

This phase prepares the CodeIgniter 4 application for Docker Compose with Nginx,
PHP-FPM, and MariaDB. It does not implement Theaz Cloud drive features.

## Environment Files

Use the examples as templates and keep local files out of source control:

```bash
cp docker/env/development.example docker/env/development.local
cp docker/env/production.example docker/env/production.local
```

Set these values in the local file before starting containers:

```text
MARIADB_PASSWORD=
MARIADB_ROOT_PASSWORD=
APP_BASE_URL=
```

For non-Docker local development, copy `.env.example` to `.env` and set the
database password locally.

## Commands

Build and start development:

```bash
docker compose --env-file docker/env/development.local up -d --build
```

Start without rebuild:

```bash
docker compose --env-file docker/env/development.local up -d
```

Stop containers without deleting data:

```bash
docker compose --env-file docker/env/development.local stop
```

Restart services:

```bash
docker compose --env-file docker/env/development.local restart
```

Show logs:

```bash
docker compose --env-file docker/env/development.local logs -f
```

Check service status:

```bash
docker compose --env-file docker/env/development.local ps
```

Run CodeIgniter route inspection:

```bash
docker compose --env-file docker/env/development.local exec app php spark routes
```

Check database connectivity from the app container:

```bash
docker compose --env-file docker/env/development.local exec app php spark db:table migrations
```

Check HTTP health:

```bash
curl -fsS http://localhost:8080/health
```

## Volumes

Persistent volumes:

- `db_data`: MariaDB data.
- `cloud_private_storage`: private user file storage outside webroot.
- `app_writable`: CodeIgniter writable runtime files.

Do not run `docker compose down -v` unless you intentionally want to delete
persistent data.

## Rollback

Stop containers:

```bash
docker compose --env-file docker/env/development.local stop
```

To roll back this phase in code, remove the files added for Docker, docs, health
check, CloudDrive config, and sanitized logging, then restore `app/Config/Routes.php`,
`app/Config/App.php`, and `app/Config/Logger.php` from your previous copy or VCS.

Do not remove Docker volumes during rollback unless the stored data is disposable.

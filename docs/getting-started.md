# getting started

Single repository (Laravel backend at the repo root). Everything runs inside DDEV, because `.env` points at `DB_HOST=db` and `REDIS_HOST=redis`, which only resolve in the containers. Host PHP is 8.3; the project needs 8.4.

## prerequisites

- DDEV with Docker running
- Nothing else on the host (PHP, Node and Composer all come from the web container)

## first-time install

```sh
cd /path/to/interview
ddev start                      # builds the custom db image (PostgreSQL 16 + pgvector), starts redis
ddev composer install
cp -n .env.example .env
ddev artisan key:generate
ddev npm install --ignore-scripts
ddev npm run build
ddev artisan migrate:fresh --seed   # 500 dealers, 50 000 listings (takes a short while)
```

The post-start hook in `.ddev/config.hooks.yaml` creates a `testing` database on every start (idempotent). The `vector` extension is created by a migration.

App URL: https://interview.ddev.site

### switching from the old MariaDB setup

If the project was previously started with MariaDB, the old volume is incompatible with PostgreSQL. Drop it once:

```sh
ddev delete --omit-snapshot
ddev start
```

## run

```sh
ddev start
ddev npm run dev        # Vite dev server (optional; `ddev npm run build` is enough for the detail page)
ddev launch             # open the site
```

## database

```sh
ddev artisan migrate:fresh --seed     # reset and reseed (deterministic: ids and rows are identical every run)
ddev artisan db:seed                  # not idempotent on its own; use migrate:fresh --seed to reseed
```

## tests and quality gates

Tests run on Pest against the PostgreSQL `testing` database (`phpunit.xml` sets `DB_DATABASE=testing`, array cache/session, sync queue).

```sh
ddev artisan test --compact                                   # full suite
ddev artisan test --compact tests/Feature/PingTest.php        # one file
ddev artisan test --compact --filter=BR-03                    # by name
ddev exec vendor/bin/pint --dirty --format agent              # formatter
ddev exec vendor/bin/phpstan analyse                          # Larastan (config in phpstan.neon)
```

## optimized caches (required for benchmark runs)

```sh
ddev artisan config:cache && ddev artisan route:cache && ddev artisan view:cache
# undo
ddev artisan optimize:clear
```

## versions actually installed

- Laravel 13, PHP 8.4, nginx-fpm, PostgreSQL 16 (pgvector), Redis, Node 24, Tailwind 4, Vite 8
- Pest 5.3 with pest-plugin-laravel 5 on PHPUnit 13. The design called for Pest 4 / PHPUnit 12; composer resolved the newer major versions, and they are kept. Larastan is installed and kept.

## troubleshooting

- `could not translate host name "db"`: you ran a command on the host. Prefix with `ddev`.
- `Unable to locate file in Vite manifest`: run `ddev npm run build`.
- Tests fail with a missing `testing` database: `ddev restart` (the hook creates it), or run the `CREATE DATABASE testing OWNER db` statement from the hook through `ddev psql`.
- Stale behaviour after cache commands: `ddev artisan optimize:clear`.

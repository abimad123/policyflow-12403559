# PolicyFlow

Fault-tolerant insurance issuance and reconciliation engine for the Trippers Shield engineering assignment.

## Status

Stage 1 scaffolds a Laravel 12 application on PHP 8.2+ with PostgreSQL 16 configuration. Laravel connected to the local PostgreSQL 16 container over TCP on host port 5433 using a read-only query. The policy API, pricing, issuance workflow, assignment migrations, and reliability tests are reserved for later stages. The generated Laravel example routes and tests are still present.

## Local setup

1. Install PHP 8.2+ with `pdo_pgsql`, Composer 2, and PostgreSQL 16. Create a local `policyflow` database and a role with access to it. This development setup publishes the Docker database on host port 5433.
2. Run `composer install`.
3. Copy `.env.example` to `.env`. Set `DB_USERNAME` and `DB_PASSWORD` to your local values, and adjust `DB_HOST` and `DB_PORT` if your PostgreSQL mapping differs. Keep `.env` private.
4. Run `php artisan key:generate`.
5. Check the connection with `php artisan tinker --execute="dump(DB::select('SELECT current_database() AS database, current_user AS role'))"`. This query does not modify the database.
6. Run `php artisan test` and `php artisan serve`.

Do not run migrations yet; the assignment schema has not been implemented. File sessions, file cache, and synchronous jobs keep the foundation usable without creating framework tables.

## Architecture direction

The later stages will add REST routes, request validation and deterministic pricing, explicit issuance state transitions, a mock insurer, reconciliation, and automated reliability tests. Database uniqueness and transactions will protect duplicate requests and confirmed outcomes. The exact API contract and implemented scenarios will be documented when built.

## Assumptions and limitations

The provided insurer is fictional; no real insurer or payment service will be used. Stage 1 has no policy endpoints or assignment behavior. The supplied PostgreSQL instance is local and must be provisioned separately. The local Windows PostgreSQL service occupies port 5432, so the Docker container is published on port 5433.

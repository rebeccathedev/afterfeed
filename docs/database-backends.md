# Database backends

Afterfeed supports SQLite, MySQL 8+, MariaDB 10.6+, and PostgreSQL 14+. SQLite remains the zero-configuration default. MySQL and PostgreSQL are recommended when importing very large archives or serving multiple concurrent readers.

For local evaluation, `docker compose -f compose.databases.yml up -d` starts MySQL on port 3307 and PostgreSQL on port 5433 using the documented `afterfeed` development credentials.

## MySQL

Create an empty UTF-8 database and configure `.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=afterfeed
DB_USERNAME=afterfeed
DB_PASSWORD=change-me
```

Then run `php artisan migrate --force`. Afterfeed creates an InnoDB full-text index for post search.

In Docker, use the database service name rather than `127.0.0.1` for `DB_HOST`. The Afterfeed container runs migrations automatically when it starts.

## PostgreSQL

Create an empty database and configure `.env`:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=afterfeed
DB_USERNAME=afterfeed
DB_PASSWORD=change-me
DB_SSLMODE=prefer
```

Then run `php artisan migrate --force`. Afterfeed creates a GIN index over a `simple` text-search vector so handles and archive-specific vocabulary are not stemmed as English prose.

In Docker, use the database service name rather than `127.0.0.1` for `DB_HOST`. The Afterfeed container runs migrations automatically when it starts.

## Moving an existing library

Archive media remains on the filesystem and is not stored in the database. The safest migration path is currently:

1. Back up `database/database.sqlite` and `storage/app/public`.
2. Point Afterfeed at an empty MySQL or PostgreSQL database.
3. Run the migrations.
4. Re-import the original archives. Reimports rebuild the people index and regenerate backend-native search indexes.
5. Copy any private annotations and collections separately before retiring SQLite.

Do not point two database backends at the same writable application instance during an import. Keep the old SQLite file until post counts and collection contents have been verified.

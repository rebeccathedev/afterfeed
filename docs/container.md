# Container deployment

Afterfeed publishes a PHP 8.4 FPM application image to GitHub Container Registry after the test suite passes on `main`. It does not contain a web server. The included `compose.production.yml` runs the image with Nginx, or you can connect it to an existing FastCGI proxy.

## Quick installation

Install Docker Engine with the Compose plugin, clone the repository, and choose the published image name from the repository's **Packages** page:

```bash
git clone https://github.com/rebeccathedev/afterfeed.git
cd afterfeed
export AFTERFEED_IMAGE=ghcr.io/rebeccathedev/afterfeed:latest
docker pull "$AFTERFEED_IMAGE"
docker run --rm --entrypoint php "$AFTERFEED_IMAGE" artisan key:generate --show
```

If the GHCR package is private, authenticate first with a GitHub personal access token that has `read:packages`:

```bash
echo "$GITHUB_TOKEN" | docker login ghcr.io -u rebeccathedev --password-stdin
```

Create `.env.container` and keep it out of source control:

```dotenv
AFTERFEED_IMAGE=ghcr.io/rebeccathedev/afterfeed:latest
APP_KEY=base64:paste-the-generated-key-here
APP_URL=https://afterfeed.example.com
AFTERFEED_PORT=8080
AFTERFEED_MCP_ALLOWED_ORIGINS=https://afterfeed.example.com
```

Start the application:

```bash
docker compose --env-file .env.container -f compose.production.yml up -d
docker compose --env-file .env.container -f compose.production.yml ps
```

The default host port is `8080`. In production, put the included Nginx service behind an HTTPS reverse proxy or replace it with your existing proxy. Visit the configured URL and register the first user.

## What starts automatically

The application entrypoint:

1. Requires a persistent `APP_KEY`.
2. Creates the SQLite file and writable directories when needed.
3. Applies all pending database migrations.
4. Refreshes the shared `public` volume from the current image so frontend assets update with the container.
5. Creates the public storage link and warms Laravel's configuration and Blade caches.
6. Starts PHP-FPM on `0.0.0.0:9000`.

The image has a FastCGI health check. Nginx waits for that check before it starts.

## Persistent data and backups

The production Compose file creates three named volumes:

- `afterfeed-database` contains the default SQLite database.
- `afterfeed-storage` contains imports, archived media, profile images, and other user files.
- `afterfeed-public` shares static assets with Nginx and is reproducible from the image.

Back up the database and storage volumes. The public volume does not need to be backed up. For a SQLite deployment, stop the application or use SQLite's backup API before copying the database so the backup is transactionally consistent.

Never rotate `APP_KEY` casually. Keep `.env.container` in your secret-management or backup system; changing the key invalidates sessions and anything encrypted by Laravel.

## Updates

The `latest` tag follows successful builds from `main`; `sha-COMMIT_SHA` tags are immutable and better for pinned production deployments.

```bash
docker compose --env-file .env.container -f compose.production.yml pull
docker compose --env-file .env.container -f compose.production.yml up -d
docker compose --env-file .env.container -f compose.production.yml ps
```

The entrypoint applies migrations before the new FPM process accepts traffic. Back up persistent data before upgrades.

## External MySQL or PostgreSQL

Add the appropriate variables to `.env.container` and remove no volumes unless you have backed up the old SQLite library:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=database
DB_PORT=5432
DB_DATABASE=afterfeed
DB_USERNAME=afterfeed
DB_PASSWORD=use-a-secret
```

The included production Compose file passes these database variables through to FPM. See [database backends](database-backends.md) for supported versions and search-index behavior.

## Existing FastCGI proxy

The proxy needs the contents of Afterfeed's `public` directory. The included Compose setup shares that directory through `afterfeed-public`. If your proxy runs elsewhere, copy the public assets from the image or provide an equivalent shared volume.

```nginx
root /var/www/html/public;
index index.php;
client_max_body_size 6m;

location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /var/www/html/public/index.php;
    fastcgi_param SCRIPT_NAME /index.php;
    fastcgi_param HTTP_PROXY "";
    fastcgi_read_timeout 3600s;
    fastcgi_pass afterfeed:9000;
}

location ~ /\. {
    deny all;
}
```

Afterfeed uploads archive chunks of up to 5 MB, so the proxy limit must be slightly larger. Slow imports can require generous FastCGI timeouts.

## Container commands

Choose a user by ID or email when an installation has multiple users:

```bash
docker compose --env-file .env.container -f compose.production.yml exec afterfeed \
  php artisan archive:import /path/in-the-container/archive.zip me@rebeccapeck.org

docker compose --env-file .env.container -f compose.production.yml exec afterfeed \
  php artisan archive:people me@rebeccapeck.org
```

Browser uploads are generally easier because uploaded archives are automatically owned by the signed-in user.

## Published images

The GitHub Actions workflow publishes:

- `ghcr.io/rebeccathedev/afterfeed:latest`
- `ghcr.io/rebeccathedev/afterfeed:sha-COMMIT_SHA`

It runs PHP tests and the frontend production build before publishing, uses GitHub Actions layer caching, and attaches OCI provenance plus an SBOM.

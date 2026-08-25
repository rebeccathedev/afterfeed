<div align="center">

<img src="public/brand/afterfeed-mark.svg" width="96" height="96" alt="Afterfeed logo">

# Afterfeed

### ✨ Your social history, back in your hands.

A private, local-first home for browsing, searching, and rediscovering<br>
your personal social media archives—without depending on the platforms that created them.

![PHP 8.4.1+](https://img.shields.io/badge/PHP-8.4.1%2B-777BB4?style=flat-square&logo=php&logoColor=white)
![Laravel 13](https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white)
![SQLite](https://img.shields.io/badge/SQLite-local--first-003B57?style=flat-square&logo=sqlite&logoColor=white)
![License: MIT](https://img.shields.io/badge/license-MIT-2f855a?style=flat-square)

[Features](#what-you-can-do) · [Platforms](#supported-archives) · [Quick start](#quick-start) · [Import guide](docs/importing.md) · [Product tour](docs/product-tour.md) · [API & MCP](#api--mcp)

</div>

---

Afterfeed turns disconnected export files into one calm, searchable timeline. Your archive and media stay in the installation you control, original metadata is retained, and private annotations never modify the source records. Authentication and per-user ownership keep libraries isolated when an installation has more than one user.

![Afterfeed timeline showing several connected social archives](docs/images/afterfeed-timeline.jpg)

<div align="center"><sub>Documentation screenshots use fictional accounts and synthetic archive data.</sub></div>

## ✨ What you can do

- **🗂️ Browse everything together** — normalize posts, replies, messages, and media from multiple services into a single timeline.
- **🔎 Find old moments** — use full-text search, reconstructed threads, yearly heatmaps, statistics, maps, and a cross-service *On This Day* view.
- **🏷️ Curate privately** — favorite, tag, annotate, collect, or hide posts without changing the imported archive.
- **🧭 Understand your history** — explore activity patterns and a local relationship index built from conversations, follows, friends, mentions, and reactions.
- **🎁 Share selectively** — turn one memory into a social-ready image, or export a collection as HTML, PDF, JSON, or a media bundle with granular privacy controls and metadata stripping.
- **🔌 Connect your tools** — query your own archive through a user-scoped, read-only JSON API or the bundled Model Context Protocol server.

## 📦 Supported archives

| Service | Preserved content |
| --- | --- |
| **Twitter / X** | Posts and deleted posts, media, likes, profiles and profile history, follows, lists, saved searches, timezone, and verification status |
| **Mastodon** | Posts, replies, boosts, content warnings, visibility, engagement, favorites, bookmarks, profile fields, aliases, and media |
| **Facebook** | Posts, check-ins, photos, videos, comments, reactions, friends, saved items, profile history, and media |
| **Reddit** | Submissions, comments and reply relationships, votes, saved and hidden items, communities, moderation, multireddits, and messages |
| **Instagram** | Posts, videos, comments, media, profile details, likes, saved posts, follows, and direct messages |
| **Google+** | Posts, reshares, authored and embedded comments, +1s, external link previews, visibility, check-in locations, profile identity, and post media from Google Takeout |
| **Nextdoor** | Posts, comments, reactions, private messages, For Sale & Free listings, seasonal activity, and privacy-filtered profile details |
| **LiveJournal** | Journal subjects, HTML and readable text, tags, userpics, item IDs, dates, and original entry links |
| **Jekyll** | Published Markdown posts, YAML front matter, tags, categories, original permalinks, author identity, profile image, and referenced local media |

Sensitive security and account data—such as IP history, device tokens, phone numbers, cryptographic keys, payment records, birth dates, and advertising preferences—is intentionally excluded where present.

## 🚀 Quick start

### Requirements

- PHP 8.4.1 or later
- Composer
- Node.js and npm
- SQLite (default), MySQL/MariaDB, or PostgreSQL

### Install

```bash
git clone https://github.com/rebeccathedev/afterfeed.git
cd afterfeed
composer run setup
composer run dev
```

Then open the local URL shown in your terminal and create the first account. SQLite works out of the box; see [database backend setup](docs/database-backends.md) to use MySQL/MariaDB or PostgreSQL with native full-text search. The first account adopts data from an older single-user database during an upgrade.

### Install with Docker

Requirements: Docker Engine with the Compose plugin and access to the repository's GitHub Container Registry package. The application image contains PHP-FPM only; the included production Compose file adds Nginx and persistent volumes for SQLite, archived media, and compiled public assets.

```bash
git clone https://github.com/rebeccathedev/afterfeed.git
cd afterfeed

export AFTERFEED_IMAGE=ghcr.io/rebeccathedev/afterfeed:latest
docker pull "$AFTERFEED_IMAGE"
docker run --rm --entrypoint php "$AFTERFEED_IMAGE" artisan key:generate --show
```

Copy the generated key into a new `.env.container` file. Replace the image and URL values for your deployment:

```dotenv
AFTERFEED_IMAGE=ghcr.io/rebeccathedev/afterfeed:latest
APP_KEY=base64:paste-the-generated-key-here
APP_URL=http://localhost:8080
AFTERFEED_PORT=8080
AFTERFEED_MCP_ALLOWED_ORIGINS=http://localhost:8080
```

Start Afterfeed:

```bash
docker compose --env-file .env.container -f compose.production.yml up -d
docker compose --env-file .env.container -f compose.production.yml ps
```

Open `http://localhost:8080` and create the first account. Keep `.env.container` private and backed up: changing `APP_KEY` invalidates sessions and encrypted application data. The entrypoint creates SQLite, applies migrations, refreshes public assets, and warms Laravel caches before FPM starts.

To update later:

```bash
docker compose --env-file .env.container -f compose.production.yml pull
docker compose --env-file .env.container -f compose.production.yml up -d
```

For HTTPS, external databases, backups, a custom FastCGI proxy, and troubleshooting, see [container deployment](docs/container.md).

### Import an archive

Afterfeed automatically recognizes supported Twitter/X, Mastodon, Facebook, Reddit, Instagram, Google+, Nextdoor, LiveJournal, and Jekyll archive formats. Browser uploads are attached to the signed-in user. For a command-line import:

```bash
php artisan archive:import /path/to/twitter-export.zip me@rebeccapeck.org
```

Imports are safe to repeat. A content fingerprint prevents accidental duplicate imports, while re-importing an existing archive refreshes its normalized records through the latest importer so newly supported metadata can be backfilled.

The [import guide](docs/importing.md) documents format recognition, ownership, privacy, and the Google+ Takeout layout and coverage in detail.

## 🧭 Explore and curate

The timeline is only the beginning:

- **Search** uses SQLite FTS5 or the selected database's native full-text engine.
- **Maps** combine geotagged posts and privately annotated coordinates using locally bundled Leaflet; OpenStreetMap tiles load only when the map is opened.
- **Statistics** summarize activity by year, hour, weekday, service, and photo volume, plus common words, hashtags, Reddit communities, and frequently mentioned people.
- **People** creates a relationship directory from mentions, replies, direct messages, friends, follows, and attributable reactions.

Archive imports refresh the People index automatically. To rebuild it manually:

```bash
php artisan archive:people me@rebeccapeck.org
```

## 🎁 Export with control

Collections can become a self-contained HTML album, a PDF memory book, a JSON subset, or a ZIP containing HTML, JSON, and selected media. Choose whether to include text, media, identities, dates, private annotations, original links, and precise locations.

Privacy-friendly defaults remove image EXIF, remux video losslessly with FFmpeg to strip metadata, and omit raw platform metadata. Self-contained HTML and PDF media is capped at 60 MB, and PDF books at 250 posts; use ZIP for larger collections.

Individual posts can also become square, portrait, or landscape PNG cards. Identity, platform, date, footer, and archived photo controls make it easy to share an old memory without exposing or linking to Afterfeed.

![Afterfeed share-card creator with a square archived memory preview](docs/images/afterfeed-share-card.jpg)

## 🔌 API & MCP

Afterfeed includes a read-only JSON API under `/api/v1`:

```text
GET /api/v1/status
GET /api/v1/accounts
GET /api/v1/posts?q=&account_id=&platform=&year=&month=&has_media=&page=&per_page=
GET /api/v1/posts/{id}
GET /api/v1/statistics
```

Results use bounded pagination of at most 100 records per page and omit hidden posts. Every request requires a per-user bearer token created in **Settings → API & MCP access**, and the API exposes only that user's archive.

To connect a local MCP client such as Claude Desktop or Codex, configure it to launch:

```bash
php /absolute/path/to/afterfeed/artisan afterfeed:mcp me@rebeccapeck.org
```

The stdio server provides `search_posts`, `get_post`, `list_accounts`, and `archive_statistics`. Remote clients can use the authenticated Streamable HTTP endpoint at `/api/mcp`; see [remote MCP setup and security](docs/remote-mcp.md). Each token is revocable and can access only its owner's archive.

## 🧩 How it works

Every platform importer translates its export into four shared records:

```text
SocialAccount  ──<  Archive  ──<  Post  ──<  Attachment
   identity         import        item         local media
```

- **SocialAccount** represents one identity on a platform.
- **Archive** represents one export and its source account.
- **Post** represents a normalized post, reply, message, or timeline item.
- **Attachment** represents local media associated with a post.

Original export metadata remains in JSON columns, keeping normalization lossless. Large Facebook exports use chunked database upserts and streamed ZIP media reads, avoiding full extraction and unbounded memory use.

## 🛠️ Development

```bash
# Start the application, queue worker, logs, and Vite
composer run dev

# Run the test suite
composer test

# Build production assets
npm run build
```

---

<div align="center">

🌱 Built for the version of your internet history that belongs to you.

</div>

# Importing social archives

Afterfeed recognizes supported export formats from their contents, so uploaded files do not need a special filename. Browser uploads belong to the signed-in user. Command-line imports require an explicit owner when the installation has more than one user:

```bash
php artisan archive:import /path/to/archive.zip me@rebeccapeck.org
```

Supported imports include Twitter/X, Mastodon, Facebook, Reddit, Instagram, Google+, Nextdoor, LiveJournal, and Jekyll. ZIP files can be uploaded directly; LiveJournal `.tar.gz` and `.tgz` archives are normalized to ZIP during browser upload.

## Jekyll sites

Upload a ZIP of a Jekyll source repository, including archives downloaded from GitHub. Afterfeed detects dated Markdown files under any `_posts` directory, so repositories with a generated top-level folder or a nested source directory are supported. Site identity and original links come from `_config.yml`; `_data/author.yml` or `_data/author.yaml` can provide the author name, location, email, and local avatar.

Published posts become timeline articles. Afterfeed preserves their title, subtitle, author, slug, tags, categories, complete front matter, and original Markdown as metadata. It also creates readable timeline text, retains HTTP links, reconstructs common Jekyll permalink patterns, copies referenced local images/audio/video into archive storage, and imports a bundled author avatar when present. Files under `_drafts` are deliberately excluded because they were not published.

The import is content-detected and repeatable: neither the repository name nor the uploaded ZIP filename is used as account identity, and importing the same archive again does not duplicate posts or attachments. Jekyll supports extensive plugin-defined Liquid tags; unknown tags are removed from readable timeline text while the unchanged source Markdown remains available in metadata.

Imports are transactional and safe to retry. Afterfeed fingerprints each source archive, refreshes normalized records when the same archive is imported again, and rebuilds the importing user's People index after a successful import.

## Google+ Takeout

Choose the Google+ Stream data when preparing a Google Takeout archive. Afterfeed recognizes the classic export layout containing:

```text
Takeout/Google+ Stream/Posts/
Takeout/Google+ Stream/ActivityLog/
Takeout/Google+ Stream/Photos/
```

The importer preserves:

- Posts, reshares, original timestamps, historical post URLs, and profile identity.
- External link-preview URLs and titles.
- Visibility labels and check-in names, addresses, and coordinates.
- Comments embedded on exported posts.
- Comments authored on other posts from the activity log.
- +1 activity on posts and comments.
- Photos and videos locally referenced by exported posts.

Google+ URLs are retained as historical source metadata even though the consumer service is no longer available. External links shared inside posts remain separately usable in Afterfeed. Remote-only avatar and activity-log thumbnail URLs are recorded but are not downloaded; locally included post media is copied into Afterfeed's archived-media storage.

Standalone albums, events, and circle vCards that are not attached to a post are not currently normalized as timeline items. The original Takeout archive remains the authoritative copy of those files.

## Nextdoor

Afterfeed recognizes Nextdoor's **content and activity report** ZIP by its profile, post, and comment CSV files. It imports posts, comments, reactions, private messages, For Sale & Free listings, seasonal activity, profile biography, hometown, pronouns, occupation, interests, skills, neighborhood notes, pets, account roles, and verification status.

Nextdoor media fields contain remote URLs rather than files bundled into the report. Afterfeed preserves those URLs as metadata but does not download them during import, so importing remains local and does not make requests back to Nextdoor or its media hosts.

Post, comment, and reaction timestamps include their UTC offsets. Nextdoor omits offsets from private-message, listing, and seasonal-activity timestamps, so Afterfeed interprets those using the importing user's configured display timezone and then stores them in UTC.

The report also contains unusually sensitive account data. Afterfeed intentionally excludes email addresses, birth dates, gender, street addresses, phone numbers, emergency contacts, household estimates, devices, IP-related data, notification preferences, and advertising preferences. A lowercase email is hashed locally to create a stable account identifier; the email itself is not stored in the normalized account.

## Storage and privacy

Uploaded archives and imported media remain on the Afterfeed installation. Keep the original exports backed up, include `storage/app` in server backups, and treat source archives as sensitive: they can contain data that Afterfeed intentionally does not display or import.

For container paths and commands, see [container deployment](container.md). For moving an archive library between database engines, see [database backends](database-backends.md).

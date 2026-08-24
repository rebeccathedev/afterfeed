# Importing social archives

Afterfeed recognizes supported export formats from their contents, so uploaded files do not need a special filename. Browser uploads belong to the signed-in user. Command-line imports require an explicit owner when the installation has more than one user:

```bash
php artisan archive:import /path/to/archive.zip me@rebeccapeck.org
```

Supported imports include Twitter/X, Mastodon, Facebook, Reddit, Instagram, Google+, and LiveJournal. ZIP files can be uploaded directly; LiveJournal `.tar.gz` and `.tgz` archives are normalized to ZIP during browser upload.

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

## Storage and privacy

Uploaded archives and imported media remain on the Afterfeed installation. Keep the original exports backed up, include `storage/app` in server backups, and treat source archives as sensitive: they can contain data that Afterfeed intentionally does not display or import.

For container paths and commands, see [container deployment](container.md). For moving an archive library between database engines, see [database backends](database-backends.md).

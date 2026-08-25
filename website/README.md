# Afterfeed website

The static project site is framework-free and published at `/afterfeed/`.

```bash
sh website/build.sh
npx wrangler pages deploy website/dist --project-name=afterfeed-site
```

The generated `website/dist` directory is ignored and should not be committed.

# Afterfeed website

The static project site is framework-free and published at
[rebeccapeck.dev/afterfeed/](https://rebeccapeck.dev/afterfeed/).

```bash
sh website/build.sh
npx wrangler pages deploy website/dist --project-name=afterfeed-site
```

The generated `website/dist` directory is ignored and should not be committed.

## Continuous deployment

The `Deploy website` GitHub Actions workflow rebuilds and deploys the site to
Cloudflare Pages when website files are merged to `main`. It can also be run
manually from the Actions tab.

The repository must have these Actions secrets:

- `CLOUDFLARE_API_TOKEN`, with Cloudflare Pages edit access
- `CLOUDFLARE_ACCOUNT_ID`

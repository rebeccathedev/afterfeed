# Remote MCP access

Afterfeed supports local stdio MCP and authenticated Streamable HTTP MCP. Both are user-scoped: a client only sees the archive belonging to the selected user or bearer token.

## Streamable HTTP

Sign in, open **Settings → API & MCP access**, name the client, and choose **Create token**. Copy the token immediately; Afterfeed stores only its SHA-256 hash and cannot display it again.

Use the endpoint `https://your-afterfeed.example/api/mcp` and send:

```http
Authorization: Bearer af_your_token
```

Browser clients must also have their exact origin in `AFTERFEED_MCP_ALLOWED_ORIGINS`. Native clients commonly omit `Origin`.

## Local stdio

```bash
php artisan afterfeed:mcp me@rebeccapeck.org
```

The user may be an ID or email. It is optional only when the installation has exactly one user.

## Security

- Put remote Afterfeed behind HTTPS.
- Treat tokens like passwords; never place them in URLs, screenshots, logs, or source control.
- Revoke tokens from Settings when a client is retired or a token is exposed.
- Consider a private VPN or an additional reverse-proxy authentication layer.
- MCP is read-only and suppresses posts marked hidden.

# Zent Registrar

Federation server registry. Zent instances register here to discover each other for cross-server communication.

PHP 8.3+, PDO, MySQL. Port 5000. Deployed on AMD Micro 2 (141.144.205.175) via Docker + Nginx.

## Database

**`servers`** - registered Zent instances:
- Identity: domain (PK), name, api_url, ws_url, voice_url, voice_provider, cdn_url
- Protocol: protocols (JSON), capabilities (JSON)
- Metadata: description, icon, version
- Stats: user_count, channel_count
- Trust: verified (bool)
- Status: status (default 'online'), last_seen, token_expires_at
- Auth: server_token (32-byte hex, 256-bit entropy)
- Timestamps: created_at

**`ratings`** - user ratings for servers:
- id (serial PK), domain (FK → servers ON DELETE CASCADE), user_id, rating (1-5), comment, created_at

**`rate_limits`** - per-IP sliding window:
- ip, endpoint (composite PK), window_start, request_count

## API

- `GET /servers` - list servers (search, sort, limit, offset)
- `POST /servers` - register a new instance (returns server token)
- `GET /servers/:domain` - server details
- `PUT /servers/:domain` - update server info (auth required)
- `DELETE /servers/:domain` - unregister (auth required)
- `POST /servers/:domain/heartbeat` - heartbeat with optional stats (auth required)
- `GET /servers/:domain/health` - public health status
- `POST /servers/:domain/rate` - submit a rating
- `GET /servers/:domain/ratings` - list ratings with average
- `GET /health` - service health check

Auth: Bearer token (server_token from registration). Timing-safe comparison via `hash_equals()`. Tokens expire after 1 year, extended on each heartbeat or PUT. Offline detection: server marked offline at query time if `last_seen > 5 minutes ago`.

Rate limits: registration 5/hour, ratings 10/60s, listing 30/60s per IP.

---

## Known Issues & Security

### High priority
- **X-Forwarded-For spoofing** — rate limiting uses `HTTP_X_FORWARDED_FOR` without validating it against known proxy IPs. An attacker can spoof this header to bypass all rate limits. Fix: only trust X-Forwarded-For when REMOTE_ADDR is in Cloudflare's published IP ranges (already available in env context since Nginx passes real IP).
- **Hardcoded CORS allowlist** — `['https://3aka.com', 'https://reg.3aka.com']` is hardcoded in PHP. Must redeploy to change. Move to `$_ENV` or config file.
- **No request logging** — errors and abuse are invisible. Add structured logging (at minimum, log IP + endpoint + status code to stderr, captured by Docker/journald).

### Medium priority
- **Token auto-renewal on every heartbeat** — a compromised token stays valid for a full year from last activity. Consider capping absolute expiry (e.g., re-registration required after 2 years regardless of heartbeat activity).
- **Average rating shows 0.0 when unrated** — `COALESCE(AVG(rating), 0)` returns 0 for servers with no ratings. Should return `null` so clients can display "no ratings yet" instead of a misleading 0-star score.
- **Offline status recalculated per request** — every `GET /servers` recalculates offline status for every server in the result set. Cache this in Redis or persist it via a background job that sets `status = 'offline'` after 5 minutes of no heartbeat.
- **Probabilistic cleanup of rate_limits table** — 1% chance per request triggers `DELETE FROM rate_limits WHERE window_start < NOW() - INTERVAL 1 HOUR`. Under low traffic this table grows unbounded. Add a proper scheduled cleanup (cron or MySQL event).

### Low priority
- **No API versioning** — all routes are unversioned. Any schema change is a breaking change for federated clients. Add `/v1/` prefix.
- **Single 395-line monolith** — the entire service is `php/index.php`. Hard to test or extend. Refactor into route handlers, a db layer, and middleware files.
- **Missing indexes** — `servers.created_at` for sort-by-date queries; `servers.name` FULLTEXT for search (currently full table scan with LIKE).
- **No webhook notifications** — no way for registered servers to be notified when their status changes or when ratings are submitted.

---

## Federation Roadmap

The registrar is currently a server directory (registration + discovery + health). The vision is cross-server federation: a user on `server-a.3aka.com` joins a guild hosted on `server-b.example.com` without creating a separate account.

### What's needed for true federation
1. **Identity portability** — a user's identity (`user@domain`) must be verifiable by remote servers without trusting the registrar. Options: signed JWTs with the home server's public key (published via `/.well-known/zent`), or WebFinger-style discovery.
2. **Cross-server guild membership** — when user from server-A joins a guild on server-B, server-B must verify the user identity with server-A, store a shadow member record, and propagate events back to server-A's gateway for that user.
3. **Event routing** — guild events (messages, presence, voice) must fan out to all member home servers, not just local gateway nodes. Redis pub/sub is single-cluster; cross-server events need HTTP or WebSocket push between server instances.
4. **Consistent permission enforcement** — server-B owns the guild and enforces permissions, but server-A's users need their roles resolved correctly. Roles and overwrites must be replicated or queried cross-server.

### Comparison to alternatives
- **Matrix**: true federation via homeserver HTTP protocol. Works but adds significant latency (multi-hop) and state resolution complexity. Large rooms are slow.
- **Revolt/Stoat**: no federation between instances. Each instance is an isolated silo.
- **ActivityPub**: designed for async social media, not real-time chat. No voice/video support.
- **Zent approach**: pragmatic lightweight federation via registrar (simpler than Matrix, more capable than Revolt). Prioritize guild discovery and cross-server invites first, then identity portability, then full event routing.

### Market context
Discord has zero federation — you must have a Discord account to access any Discord server. This is by design (network effect lock-in). For self-hosted communities, federation means a user on their own Zent instance can participate in any federated guild without creating accounts on every server. This is Zent's strongest differentiator vs both Discord and Revolt. No Discord-like platform has solved this cleanly.

# Zent Registrar

Federation server registry. Zent instances register here to discover each other for cross-server communication.

PHP 8.3+, PDO, PostgreSQL. Port 5000.

## Database

**`servers`** - registered Zent instances:
- Identity: domain (PK), name, api_url, ws_url, voice_url, voice_provider, cdn_url
- Protocol: protocols (JSONB), capabilities (JSONB)
- Metadata: description, icon, version
- Stats: user_count, channel_count
- Trust: verified (bool)
- Status: status (default 'online'), last_seen
- Auth: server_token
- Timestamps: created_at

**`ratings`** - user ratings for servers:
- id (serial PK), domain (FK), user_id, rating (1-5), comment, created_at

**`rate_limits`** - per-IP rate limiting:
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

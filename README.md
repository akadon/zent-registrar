# Zent Registrar

Federation server registry. Zent instances register here to discover each other for cross-server communication.

Fastify 5, PostgreSQL (Drizzle ORM), Redis.

```bash
npm install
npm run dev
```

## Database

**`registry_servers`** - registered Zent instances:
- Identity: id, name, domain (unique), apiUrl, wsUrl
- Protocol: protocols (JSON array), capabilities (JSON object)
- Metadata: description, icon, banner, tags, languages (default: `['en']`)
- Stats: userCount, channelCount
- Trust: verified (bool), publicKey, reputation (float, default 50), ratingCount, ratingSum
- Status: status (default 'online'), lastSeen, lastHealthCheck
- Registration: isPublic (default true), requiresInvite (default false), contactEmail, privacyPolicyUrl, termsOfServiceUrl
- Auth: serverToken

**`registry_ratings`** - user ratings for servers:
- serverId, userId, rating (integer), comment, createdAt

## API

- `GET /servers` - list public servers (search, filter, sort)
- `POST /servers` - register a new instance (generates server token)
- `GET /servers/:id` - server details
- `PUT /servers/:id` - update server info
- `DELETE /servers/:id` - unregister
- `POST /servers/:id/rate` - submit a rating

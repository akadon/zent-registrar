CREATE TABLE IF NOT EXISTS servers (
  domain        TEXT PRIMARY KEY,
  name          TEXT NOT NULL,
  api_url       TEXT NOT NULL,
  ws_url        TEXT NOT NULL,
  voice_url     TEXT,
  voice_provider TEXT DEFAULT 'livekit',
  cdn_url       TEXT,
  protocols     JSONB DEFAULT '["zent-v1"]',
  capabilities  JSONB DEFAULT '{}',
  description   TEXT,
  icon          TEXT,
  user_count    INTEGER DEFAULT 0,
  channel_count INTEGER DEFAULT 0,
  verified      BOOLEAN DEFAULT FALSE,
  server_token  TEXT NOT NULL,
  status        TEXT DEFAULT 'online',
  last_seen     TIMESTAMP DEFAULT NOW(),
  version       TEXT,
  created_at    TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS ratings (
  id         SERIAL PRIMARY KEY,
  domain     TEXT NOT NULL REFERENCES servers(domain) ON DELETE CASCADE,
  user_id    TEXT NOT NULL,
  rating     INTEGER NOT NULL CHECK (rating BETWEEN 1 AND 5),
  comment    TEXT,
  created_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_ratings_domain ON ratings(domain);
CREATE INDEX IF NOT EXISTS idx_servers_status ON servers(status);
CREATE INDEX IF NOT EXISTS idx_servers_user_count ON servers(user_count DESC);

CREATE TABLE IF NOT EXISTS rate_limits (
  ip TEXT NOT NULL,
  endpoint TEXT NOT NULL,
  window_start TIMESTAMP DEFAULT NOW(),
  request_count INTEGER DEFAULT 1,
  PRIMARY KEY (ip, endpoint)
);

CREATE INDEX IF NOT EXISTS idx_rate_limits_window ON rate_limits(window_start);

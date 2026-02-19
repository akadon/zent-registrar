CREATE TABLE IF NOT EXISTS servers (
  domain        VARCHAR(255) PRIMARY KEY,
  name          TEXT NOT NULL,
  api_url       TEXT NOT NULL,
  ws_url        TEXT NOT NULL,
  voice_url     TEXT,
  voice_provider VARCHAR(64) DEFAULT 'livekit',
  cdn_url       TEXT,
  protocols     JSON DEFAULT ('["zent-v1"]'),
  capabilities  JSON DEFAULT ('{}'),
  description   TEXT,
  icon          TEXT,
  user_count    INT DEFAULT 0,
  channel_count INT DEFAULT 0,
  verified      BOOLEAN DEFAULT FALSE,
  server_token  TEXT NOT NULL,
  token_expires_at DATETIME DEFAULT NULL,
  status        VARCHAR(32) DEFAULT 'online',
  last_seen     DATETIME DEFAULT CURRENT_TIMESTAMP,
  version       TEXT,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_servers_status (status),
  INDEX idx_servers_user_count (user_count DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ratings (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  domain     VARCHAR(255) NOT NULL,
  user_id    VARCHAR(255) NOT NULL,
  rating     INT NOT NULL CHECK (rating BETWEEN 1 AND 5),
  comment    TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ratings_domain (domain),
  FOREIGN KEY (domain) REFERENCES servers(domain) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  ip VARCHAR(45) NOT NULL,
  endpoint VARCHAR(255) NOT NULL,
  window_start DATETIME DEFAULT CURRENT_TIMESTAMP,
  request_count INT DEFAULT 1,
  PRIMARY KEY (ip, endpoint),
  INDEX idx_rate_limits_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

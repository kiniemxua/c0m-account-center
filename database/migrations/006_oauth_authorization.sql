-- OAuth 2.0 Authorization Code + PKCE storage.
-- Every statement is repeatable because the installer replays shipped migrations.
CREATE TABLE IF NOT EXISTS oauth_clients (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id BIGINT UNSIGNED NOT NULL,
  client_id CHAR(64) NOT NULL UNIQUE,
  client_secret_hash CHAR(64) NULL,
  client_type ENUM('public','confidential') NOT NULL DEFAULT 'confidential',
  redirect_uris JSON NOT NULL,
  allowed_scopes JSON NOT NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_oauth_client_application (application_id),
  CONSTRAINT fk_oauth_clients_application FOREIGN KEY (application_id)
    REFERENCES applications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oauth_authorization_codes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  code_hash CHAR(64) NOT NULL UNIQUE,
  redirect_uri VARCHAR(2048) NOT NULL,
  scopes JSON NOT NULL,
  code_challenge CHAR(43) NOT NULL,
  nonce VARCHAR(255) NULL,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_oauth_codes_expiry (expires_at, consumed_at),
  INDEX idx_oauth_codes_client_user (client_id, user_id),
  CONSTRAINT fk_oauth_codes_client FOREIGN KEY (client_id) REFERENCES oauth_clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_oauth_codes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oauth_access_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  scopes JSON NOT NULL,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_oauth_access_user (user_id, revoked_at, expires_at),
  INDEX idx_oauth_access_client (client_id, revoked_at, expires_at),
  CONSTRAINT fk_oauth_access_client FOREIGN KEY (client_id) REFERENCES oauth_clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_oauth_access_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oauth_refresh_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  family_id CHAR(36) NOT NULL,
  client_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  scopes JSON NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  revoked_at DATETIME NULL,
  replaced_by_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_oauth_refresh_family (family_id, revoked_at),
  INDEX idx_oauth_refresh_user (user_id, revoked_at, expires_at),
  CONSTRAINT fk_oauth_refresh_client FOREIGN KEY (client_id) REFERENCES oauth_clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_oauth_refresh_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_oauth_refresh_replacement FOREIGN KEY (replaced_by_id) REFERENCES oauth_refresh_tokens(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oauth_consents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  scopes JSON NOT NULL,
  granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at DATETIME NULL,
  UNIQUE KEY uq_oauth_consent_client_user (client_id, user_id),
  INDEX idx_oauth_consent_user (user_id, revoked_at),
  CONSTRAINT fk_oauth_consents_client FOREIGN KEY (client_id) REFERENCES oauth_clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_oauth_consents_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

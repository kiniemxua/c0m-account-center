-- Preserve the original interactive authentication time through code exchange
-- and refresh so OIDC auth_time is not incorrectly reset at the token endpoint.
SET @has_code_auth_time = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'oauth_authorization_codes' AND column_name = 'auth_time'
);
SET @ddl = IF(@has_code_auth_time = 0,
  'ALTER TABLE oauth_authorization_codes ADD COLUMN auth_time BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER nonce',
  'SELECT 1');
PREPARE c0m_stmt FROM @ddl;
EXECUTE c0m_stmt;
DEALLOCATE PREPARE c0m_stmt;

SET @has_refresh_auth_time = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'oauth_refresh_tokens' AND column_name = 'auth_time'
);
SET @ddl = IF(@has_refresh_auth_time = 0,
  'ALTER TABLE oauth_refresh_tokens ADD COLUMN auth_time BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER scopes',
  'SELECT 1');
PREPARE c0m_stmt FROM @ddl;
EXECUTE c0m_stmt;
DEALLOCATE PREPARE c0m_stmt;

UPDATE oauth_authorization_codes
SET auth_time = UNIX_TIMESTAMP(created_at)
WHERE auth_time = 0;

UPDATE oauth_refresh_tokens
SET auth_time = UNIX_TIMESTAMP(created_at)
WHERE auth_time = 0;

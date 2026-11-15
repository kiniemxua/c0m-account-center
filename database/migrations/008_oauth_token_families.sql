-- Tie access tokens to the refresh-token family so rotation replay and
-- explicit refresh-token revocation invalidate only the affected grant.
SET @has_access_family = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'oauth_access_tokens' AND column_name = 'family_id'
);
SET @ddl = IF(@has_access_family = 0,
  'ALTER TABLE oauth_access_tokens ADD COLUMN family_id CHAR(36) NULL AFTER user_id',
  'SELECT 1');
PREPARE c0m_stmt FROM @ddl;
EXECUTE c0m_stmt;
DEALLOCATE PREPARE c0m_stmt;

SET @has_access_family_index = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'oauth_access_tokens' AND index_name = 'idx_oauth_access_family'
);
SET @ddl = IF(@has_access_family_index = 0,
  'CREATE INDEX idx_oauth_access_family ON oauth_access_tokens (family_id, revoked_at)',
  'SELECT 1');
PREPARE c0m_stmt FROM @ddl;
EXECUTE c0m_stmt;
DEALLOCATE PREPARE c0m_stmt;

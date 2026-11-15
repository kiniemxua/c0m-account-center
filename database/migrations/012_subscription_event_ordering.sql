-- Idempotent on both MariaDB and MySQL: installer retries every migration.
SET @c0m_has_event_time = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions'
    AND COLUMN_NAME = 'provider_event_created_at'
);
SET @c0m_event_time_sql = IF(
  @c0m_has_event_time = 0,
  'ALTER TABLE subscriptions ADD COLUMN provider_event_created_at DATETIME(6) NULL AFTER provider_subscription_id',
  'SELECT 1'
);
PREPARE c0m_event_time_stmt FROM @c0m_event_time_sql;
EXECUTE c0m_event_time_stmt;
DEALLOCATE PREPARE c0m_event_time_stmt;

SET @c0m_has_event_idx = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions'
    AND INDEX_NAME = 'idx_subscriptions_provider_event_created'
);
SET @c0m_event_idx_sql = IF(
  @c0m_has_event_idx = 0,
  'CREATE INDEX idx_subscriptions_provider_event_created ON subscriptions (provider, provider_event_created_at)',
  'SELECT 1'
);
PREPARE c0m_event_idx_stmt FROM @c0m_event_idx_sql;
EXECUTE c0m_event_idx_stmt;
DEALLOCATE PREPARE c0m_event_idx_stmt;

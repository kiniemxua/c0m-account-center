-- Keep one current state row per provider payment while retaining every delivery
-- in payment_webhook_events. Existing non-null duplicate provider payment IDs
-- must be reconciled before this migration can create the unique index.
SET @c0m_has_payment_event_time = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_transactions'
    AND COLUMN_NAME = 'provider_event_created_at'
);
SET @c0m_payment_event_time_sql = IF(
  @c0m_has_payment_event_time = 0,
  'ALTER TABLE payment_transactions ADD COLUMN provider_event_created_at DATETIME(6) NULL AFTER payload_hash',
  'SELECT 1'
);
PREPARE c0m_payment_event_time_stmt FROM @c0m_payment_event_time_sql;
EXECUTE c0m_payment_event_time_stmt;
DEALLOCATE PREPARE c0m_payment_event_time_stmt;

SET @c0m_has_payment_identity_idx = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_transactions'
    AND INDEX_NAME = 'uq_payment_provider_payment'
);
SET @c0m_payment_identity_idx_sql = IF(
  @c0m_has_payment_identity_idx = 0,
  'CREATE UNIQUE INDEX uq_payment_provider_payment ON payment_transactions (provider, provider_payment_id)',
  'SELECT 1'
);
PREPARE c0m_payment_identity_idx_stmt FROM @c0m_payment_identity_idx_sql;
EXECUTE c0m_payment_identity_idx_stmt;
DEALLOCATE PREPARE c0m_payment_identity_idx_stmt;

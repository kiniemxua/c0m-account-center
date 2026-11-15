ALTER TABLE subscriptions
  ADD COLUMN provider_event_created_at DATETIME(6) NULL AFTER provider_subscription_id,
  ADD INDEX idx_subscriptions_provider_event_created (provider, provider_event_created_at);

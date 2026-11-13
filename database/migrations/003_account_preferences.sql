-- Make Vietnamese the default preference for newly created profiles.
ALTER TABLE user_profiles
  MODIFY language VARCHAR(12) NOT NULL DEFAULT 'vi';

-- Repair legacy accounts that predate profile creation without changing existing preferences.
INSERT IGNORE INTO user_profiles (user_id, language)
SELECT id, 'vi' FROM users;

-- Make Vietnamese the default preference for newly created profiles.
ALTER TABLE user_profiles
  MODIFY language VARCHAR(12) NOT NULL DEFAULT 'vi';

CREATE TABLE IF NOT EXISTS roles (
  id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_key VARCHAR(40) NOT NULL UNIQUE,
  label VARCHAR(80) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
  id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  permission_key VARCHAR(80) NOT NULL UNIQUE,
  label VARCHAR(120) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_roles (
  user_id BIGINT UNSIGNED NOT NULL,
  role_id SMALLINT UNSIGNED NOT NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, role_id),
  CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id SMALLINT UNSIGNED NOT NULL,
  permission_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_type ENUM('email_verification','password_reset') NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  request_ip VARBINARY(16) NULL,
  INDEX idx_auth_tokens_user_type (user_id, token_type, created_at),
  INDEX idx_auth_tokens_expiry (expires_at),
  CONSTRAINT fk_auth_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  action VARCHAR(40) NOT NULL,
  ip_address VARBINARY(16) NULL,
  identifier_hash CHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_auth_attempts_ip (action, ip_address, created_at),
  INDEX idx_auth_attempts_identifier (action, identifier_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_settings (
  setting_key VARCHAR(100) PRIMARY KEY,
  setting_value TEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO roles (role_key, label) VALUES
  ('super_admin', 'Super Admin'),
  ('admin', 'Administrator'),
  ('member', 'Member');

-- Bootstrap the first legacy account only once. The setting flag also prevents
-- later installer retries from silently restoring a Super Admin role that was
-- intentionally removed after the migration had already been applied.
INSERT IGNORE INTO app_settings (setting_key, setting_value)
SELECT 'legacy_superadmin_seeded',
       IF(EXISTS(SELECT 1 FROM user_roles), '1', '0');

INSERT IGNORE INTO user_roles (user_id, role_id)
SELECT u.id, r.id FROM users u
CROSS JOIN roles r
WHERE r.role_key = 'super_admin'
  AND (SELECT setting_value FROM app_settings WHERE setting_key = 'legacy_superadmin_seeded') = '0'
ORDER BY u.id ASC
LIMIT 1;

UPDATE app_settings
SET setting_value = '1'
WHERE setting_key = 'legacy_superadmin_seeded';

INSERT IGNORE INTO permissions (permission_key, label) VALUES
  ('users.view', 'View users'),
  ('users.manage', 'Manage users'),
  ('roles.assign', 'Assign roles'),
  ('settings.manage', 'Manage application settings'),
  ('audit.view', 'View audit logs'),
  ('apps.manage', 'Manage applications');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.role_key = 'super_admin';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
  ON p.permission_key IN ('users.view', 'users.manage', 'audit.view')
WHERE r.role_key = 'admin';

INSERT INTO app_settings (setting_key, setting_value)
VALUES ('registration_enabled', '0')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);

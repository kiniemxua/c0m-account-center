INSERT IGNORE INTO permissions (permission_key, label)
VALUES ('billing.manage', 'Manage subscription plans');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.role_key = 'super_admin'
  AND p.permission_key = 'billing.manage';

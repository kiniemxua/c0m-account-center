<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
date_default_timezone_set('UTC');
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (str_starts_with($class, $prefix)) {
        $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

function assertIntegration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$rejectWebhookTarget = static function (string $url): bool {
    try {
        App\Core\SafeWebhookHttpClient::resolvePublicTarget($url);
        return false;
    } catch (RuntimeException) {
        return true;
    }
};
assertIntegration($rejectWebhookTarget('http://1.1.1.1/hook'), 'Webhook delivery must reject non-HTTPS URLs.');
assertIntegration($rejectWebhookTarget('https://127.0.0.1/hook'), 'Webhook delivery must reject loopback IPv4.');
assertIntegration($rejectWebhookTarget('https://10.0.0.8/hook'), 'Webhook delivery must reject private IPv4.');
assertIntegration($rejectWebhookTarget('https://169.254.169.254/latest/meta-data/'), 'Webhook delivery must reject link-local metadata addresses.');
assertIntegration($rejectWebhookTarget('https://[::1]/hook'), 'Webhook delivery must reject loopback IPv6.');
assertIntegration($rejectWebhookTarget('https://127.1/hook'), 'Webhook delivery must reject abbreviated numeric IPv4 forms.');
assertIntegration($rejectWebhookTarget('https://0x7f000001/hook'), 'Webhook delivery must reject hexadecimal numeric host forms.');
assertIntegration($rejectWebhookTarget('https://example.com./hook'), 'Webhook delivery must reject trailing-dot hosts that could bypass DNS pinning.');
assertIntegration($rejectWebhookTarget('https://example.com:8443/hook'), 'Webhook delivery must reject non-standard destination ports.');
$publicWebhookTarget = App\Core\SafeWebhookHttpClient::resolvePublicTarget('https://1.1.1.1/hook');
assertIntegration($publicWebhookTarget['ip'] === '1.1.1.1', 'Webhook delivery may target a public HTTPS IPv4 endpoint.');

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST') ?: '127.0.0.1',
    getenv('DB_PORT') ?: '3306',
    getenv('DB_NAME') ?: 'c0m_test'
);
$pdo = new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec("SET time_zone = '+00:00'");
App\Core\Database::configure([
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => (int)(getenv('DB_PORT') ?: 3306),
    'name' => getenv('DB_NAME') ?: 'c0m_test',
    'user' => getenv('DB_USER') ?: 'root',
    'password' => getenv('DB_PASSWORD') ?: '',
]);

$migration = file_get_contents(BASE_PATH . '/database/migrations/001_core.sql');
assertIntegration($migration !== false, 'Core migration must exist.');
$pdo->exec($migration);
$pdo->exec($migration); // MySQL DDL is not transactional; installer retries must be idempotent.
$authMigration = file_get_contents(BASE_PATH . '/database/migrations/002_auth_foundation.sql');
assertIntegration($authMigration !== false, 'Auth migration must exist.');
$pdo->exec($authMigration);
$pdo->exec($authMigration); // RBAC and auth token migration must also be repeatable.
$authorizationRevisionMigration = file_get_contents(BASE_PATH . '/database/migrations/004_authorization_revisions.sql');
assertIntegration($authorizationRevisionMigration !== false, 'Authorization revision migration must exist.');
$pdo->exec($authorizationRevisionMigration);
$pdo->exec($authorizationRevisionMigration); // Revision storage and legacy backfill must be repeatable.
$billingMigration = file_get_contents(BASE_PATH . '/database/migrations/009_subscriptions_entitlements_payments.sql');
assertIntegration($billingMigration !== false, 'Billing migration must exist.');
$pdo->exec($billingMigration);
$outboxMigration = file_get_contents(BASE_PATH . '/database/migrations/010_transactional_outbox_webhooks.sql');
assertIntegration($outboxMigration !== false, 'Transactional outbox migration must exist.');
$pdo->exec($outboxMigration);
$billingPermissionMigration = file_get_contents(BASE_PATH . '/database/migrations/011_billing_manage_permission.sql');
assertIntegration($billingPermissionMigration !== false, 'Billing permission migration must exist.');
$pdo->exec($billingPermissionMigration);
$eventOrderingMigration = file_get_contents(BASE_PATH . '/database/migrations/012_subscription_event_ordering.sql');
assertIntegration($eventOrderingMigration !== false, 'Subscription event ordering migration must exist.');
if (!in_array('provider_event_created_at', array_column($pdo->query('SHOW COLUMNS FROM subscriptions')->fetchAll(), 'Field'), true)) { $pdo->exec($eventOrderingMigration); }

$memberGroupsMigration = file_get_contents(BASE_PATH . '/database/migrations/005_member_groups.sql');
assertIntegration($memberGroupsMigration !== false, 'Member group migration must exist.');
$pdo->exec($memberGroupsMigration);
$pdo->exec($memberGroupsMigration); // Group membership and app grants must be repeatable.
$preferencesMigration = file_get_contents(BASE_PATH . '/database/migrations/003_account_preferences.sql');
assertIntegration($preferencesMigration !== false, 'Account preferences migration must exist.');
// Existing account choices must survive the first application of migration 003.
$legacyEmail = 'locale-preserve-' . bin2hex(random_bytes(6)) . '@example.test';
$legacyUser = $pdo->prepare("INSERT INTO users (email,password_hash,status,email_verified_at) VALUES (:email,:hash,'active',NOW())");
$legacyUser->execute(['email' => $legacyEmail, 'hash' => password_hash('Legacy-Locale-42!', PASSWORD_DEFAULT)]);
$legacyUserId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO user_profiles (user_id, language) VALUES (:id, 'en')")->execute(['id' => $legacyUserId]);

$orphanEmail = 'locale-backfill-' . bin2hex(random_bytes(6)) . '@example.test';
$pdo->prepare("INSERT INTO users (email,password_hash,status,email_verified_at) VALUES (:email,:hash,'active',NOW())")
    ->execute(['email' => $orphanEmail, 'hash' => password_hash('Orphan-Locale-42!', PASSWORD_DEFAULT)]);
$orphanUserId = (int)$pdo->lastInsertId();

$pdo->exec($preferencesMigration);
$legacyPreference = $pdo->prepare('SELECT language FROM user_profiles WHERE user_id = :id');
$legacyPreference->execute(['id' => $legacyUserId]);
assertIntegration($legacyPreference->fetchColumn() === 'en', 'The first language migration must preserve an existing English preference.');
$legacyPreference->execute(['id' => $orphanUserId]);
assertIntegration($legacyPreference->fetchColumn() === 'vi', 'The language migration must backfill a missing profile with the Vietnamese default.');
$pdo->exec($preferencesMigration);
$legacyPreference->execute(['id' => $legacyUserId]);
assertIntegration($legacyPreference->fetchColumn() === 'en', 'Reapplying the language migration must not overwrite an existing English preference.');
$legacyPreference->execute(['id' => $orphanUserId]);
assertIntegration($legacyPreference->fetchColumn() === 'vi', 'Reapplying the language migration must preserve the backfilled profile.');
$pdo->prepare('DELETE FROM user_profiles WHERE user_id IN (:legacy, :orphan)')->execute(['legacy' => $legacyUserId, 'orphan' => $orphanUserId]);
$pdo->prepare('DELETE FROM users WHERE id IN (:legacy, :orphan)')->execute(['legacy' => $legacyUserId, 'orphan' => $orphanUserId]);
$pdo->exec($preferencesMigration); // Preference defaults must be safe to reapply.
$languageColumn = $pdo->query("SHOW COLUMNS FROM user_profiles LIKE 'language'")->fetch();
assertIntegration($languageColumn && $languageColumn['Default'] === 'vi', 'New account profiles must default to Vietnamese.');

$email = 'ci-' . bin2hex(random_bytes(6)) . '@example.test';
$insert = $pdo->prepare("INSERT INTO users (email,password_hash,status,email_verified_at) VALUES (:email,:hash,'active',NOW())");
$insert->execute(['email' => $email, 'hash' => password_hash('Correct-Horse-42!', PASSWORD_DEFAULT)]);
$userId = (int)$pdo->lastInsertId();
$pdo->prepare('DELETE FROM user_authorization_revisions WHERE user_id = :id')->execute(['id' => $userId]);
$pdo->exec($authorizationRevisionMigration);
assertIntegration(App\Core\AuthorizationRevision::current($pdo, $userId) === 1, 'Reapplying the revision migration must backfill a missing user revision at version 1.');

// Provider subscription events must be applied monotonically; stale events cannot downgrade entitlements.
$paidPlanKey = 'ci-paid-' . bin2hex(random_bytes(4));
$pdo->prepare(
    'INSERT INTO subscription_plans (plan_key, name, price_minor, currency, billing_interval, features, quotas, status)
     VALUES (:key, \'CI Paid Plan\', 1000, \'USD\', \'month\', :features, :quotas, \'active\')'
)->execute([
    'key' => $paidPlanKey,
    'features' => json_encode(['premium_access' => true], JSON_THROW_ON_ERROR),
    'quotas' => json_encode(['storage_gb' => 50], JSON_THROW_ON_ERROR),
]);
$subscriptionEvent = [
    'user_id' => $userId,
    'plan_key' => $paidPlanKey,
    'provider_subscription_id' => 'ci-sub-' . bin2hex(random_bytes(5)),
    'status' => 'active',
    'current_period_start' => '2026-01-01T00:00:00Z',
    'current_period_end' => '2027-01-01T00:00:00Z',
    '_event_created_at' => '2026-02-02T12:00:00Z',
];
$pdo->beginTransaction();
$appliedSubscription = App\Core\SubscriptionService::applyProviderSubscription($pdo, 'ci-provider', 'subscription.activated', $subscriptionEvent);
$pdo->commit();
assertIntegration(($appliedSubscription['status'] ?? '') === 'active', 'The newest provider subscription event must activate the subscription.');
$subscriptionId = (int)$appliedSubscription['subscription_id'];
$revisionBeforeStale = App\Core\AuthorizationRevision::current($pdo, $userId);
$entitlementsBeforeStale = App\Core\EntitlementService::forUser($pdo, $userId);
assertIntegration(isset($entitlementsBeforeStale['feature.premium_access']) && isset($entitlementsBeforeStale['quota.storage_gb']), 'The active paid subscription must grant its feature and quota entitlements.');
$pdo->beginTransaction();
$staleResult = App\Core\SubscriptionService::applyProviderSubscription($pdo, 'ci-provider', 'subscription.updated', array_merge($subscriptionEvent, [
    'status' => 'past_due',
    '_event_created_at' => '2026-02-01T12:00:00Z',
]));
$pdo->commit();
assertIntegration(!empty($staleResult['stale_event']), 'An older provider event must be explicitly rejected as stale.');
$subscriptionState = $pdo->prepare('SELECT status, provider_event_created_at FROM subscriptions WHERE id = :id');
$subscriptionState->execute(['id' => $subscriptionId]);
$subscriptionAfterStale = $subscriptionState->fetch();
assertIntegration($subscriptionAfterStale && $subscriptionAfterStale['status'] === 'active'
    && $subscriptionAfterStale['provider_event_created_at'] === '2026-02-02 12:00:00.000000',
    'A stale past_due event must not alter subscription status or the accepted event timestamp.');
assertIntegration(App\Core\AuthorizationRevision::current($pdo, $userId) === $revisionBeforeStale,
    'A stale provider event must not bump authorization revision.');
assertIntegration(App\Core\EntitlementService::forUser($pdo, $userId) === $entitlementsBeforeStale,
    'A stale provider event must not revoke or change member entitlements.');
// Equal timestamps are accepted (ordering is strictly older), and Unix timestamps normalize to UTC.
$pdo->beginTransaction();
$equalTimestampResult = App\Core\SubscriptionService::applyProviderSubscription($pdo, 'ci-provider', 'subscription.updated', array_merge($subscriptionEvent, [
    'status' => 'past_due',
    '_event_created_at' => '2026-02-02T12:00:00Z',
]));
$pdo->commit();
assertIntegration(empty($equalTimestampResult['stale_event']), 'Events with the same provider timestamp must remain eligible for processing.');
$subscriptionState->execute(['id' => $subscriptionId]);
assertIntegration($subscriptionState->fetch()['status'] === 'past_due', 'An equal-timestamp event must be applied.');
$pdo->beginTransaction();
$restoredSubscription = App\Core\SubscriptionService::applyProviderSubscription($pdo, 'ci-provider', 'subscription.updated', array_merge($subscriptionEvent, [
    '_event_created_at' => strtotime('2026-02-03T12:00:00Z'),
]));
$pdo->commit();
assertIntegration(($restoredSubscription['status'] ?? '') === 'active', 'A newer Unix-timestamp event must restore the active subscription.');
$subscriptionState->execute(['id' => $subscriptionId]);
assertIntegration($subscriptionState->fetch()['provider_event_created_at'] === '2026-02-03 12:00:00.000000', 'Unix provider timestamps must persist normalized UTC microseconds.');

// Scheduled cancellation preserves access until the paid period ends; finalization then revokes it.
$pdo->beginTransaction();
$scheduledCancellation = App\\Core\\SubscriptionService::cancel($pdo, $userId, $subscriptionId, true);
$pdo->commit();
assertIntegration(!empty($scheduledCancellation['cancel_at_period_end']), 'Scheduled cancellation must be recorded.');
assertIntegration(isset(App\\Core\\EntitlementService::forUser($pdo, $userId)['feature.premium_access']), 'Scheduled cancellation must preserve entitlements through the paid period.');
$pdo->prepare('UPDATE subscriptions SET current_period_end = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE id = :id')
    ->execute(['id' => $subscriptionId]);
$pdo->beginTransaction();
$finalizedCount = App\\Core\\SubscriptionService::finalizeScheduledCancellations($pdo, 10);
$pdo->commit();
assertIntegration($finalizedCount === 1, 'Expired scheduled cancellations must be finalized.');
$subscriptionState->execute(['id' => $subscriptionId]);
assertIntegration($subscriptionState->fetch()['status'] === 'canceled', 'Finalized subscriptions must transition to canceled.');
assertIntegration(!isset(App\\Core\\EntitlementService::forUser($pdo, $userId)['feature.premium_access']), 'Finalized cancellation must revoke paid entitlements.');

// Immediate cancellation revokes entitlements in the same transaction.
$immediateEvent = array_merge($subscriptionEvent, [
    'provider_subscription_id' => 'ci-sub-immediate-' . bin2hex(random_bytes(5)),
    '_event_created_at' => '2026-03-01T12:00:00Z',
    'current_period_end' => '2027-01-01T00:00:00Z',
]);
$pdo->beginTransaction();
$immediateSubscription = App\\Core\\SubscriptionService::applyProviderSubscription($pdo, 'ci-provider', 'subscription.activated', $immediateEvent);
$pdo->commit();
$immediateId = (int)$immediateSubscription['subscription_id'];
$pdo->beginTransaction();
$immediateCancellation = App\\Core\\SubscriptionService::cancel($pdo, $userId, $immediateId, false);
$pdo->commit();
assertIntegration(($immediateCancellation['status'] ?? '') === 'canceled', 'Immediate cancellation must transition the subscription.');
assertIntegration(!isset(App\\Core\\EntitlementService::forUser($pdo, $userId)['feature.premium_access']), 'Immediate cancellation must revoke paid entitlements.');
$pdo->prepare('DELETE FROM subscriptions WHERE id IN (:scheduled, :immediate)')
    ->execute(['scheduled' => $subscriptionId, 'immediate' => $immediateId]);
$pdo->prepare('DELETE FROM subscription_plans WHERE plan_key = :key')->execute(['key' => $paidPlanKey]);


// Account language is authoritative over stale session/cookie values; invalid saved values safely fall back.
$_SESSION = ['user_id' => $userId, 'locale' => 'en'];
$_COOKIE = ['c0m_lang' => 'en'];
App\Core\Localization::initialize();
assertIntegration(App\Core\Localization::current() === 'vi', 'A signed-in account with no saved language preference must use Vietnamese, not the guest cookie.');
$pdo->prepare('INSERT INTO user_profiles (user_id, language) VALUES (:id, \'en\')')->execute(['id' => $userId]);
$_SESSION['locale'] = 'vi';
$_COOKIE['c0m_lang'] = 'vi';
App\Core\Localization::initialize();
assertIntegration(App\Core\Localization::current() === 'en', 'A valid English account preference must override stale Vietnamese session and cookie values.');
$pdo->prepare('UPDATE user_profiles SET language = \'invalid\' WHERE user_id = :id')->execute(['id' => $userId]);
$_SESSION['locale'] = 'en';
$_COOKIE['c0m_lang'] = 'en';
App\Core\Localization::initialize();
assertIntegration(App\Core\Localization::current() === 'vi', 'An invalid stored account locale must safely fall back to Vietnamese.');
$pdo->prepare('UPDATE user_profiles SET language = \'vi\' WHERE user_id = :id')->execute(['id' => $userId]);
$_SESSION = [];
$_COOKIE = [];
App\Core\Localization::set('vi');

// RBAC is resolved from database grants, not from frontend visibility.
$memberRole = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) SELECT :id, id FROM roles WHERE role_key = 'member'");
$memberRole->execute(['id' => $userId]);
assertIntegration(!App\Core\Authorization::hasPermission($userId, 'users.manage'), 'Members must not inherit administrative permissions.');
$pdo->prepare('DELETE FROM user_roles WHERE user_id = :id')->execute(['id' => $userId]);
$adminRole = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) SELECT :id, id FROM roles WHERE role_key = 'admin'");
$adminRole->execute(['id' => $userId]);
assertIntegration(App\Core\Authorization::hasPermission($userId, 'users.manage'), 'Admins must receive their configured user-management permission.');
assertIntegration(!App\Core\Authorization::hasPermission($userId, 'roles.assign'), 'Admins must not receive super-admin role assignment permission.');
$pdo->prepare('DELETE FROM user_roles WHERE user_id = :id')->execute(['id' => $userId]);
$superRole = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) SELECT :id, id FROM roles WHERE role_key = 'super_admin'");
$superRole->execute(['id' => $userId]);
assertIntegration(App\Core\Authorization::isSuperAdmin($userId), 'Super-admin role must be recognized by the authorization service.');

// Re-running the auth migration must not silently restore an intentionally removed role.
$pdo->prepare('DELETE FROM user_roles WHERE user_id = :id')->execute(['id' => $userId]);
$pdo->prepare("INSERT INTO user_roles (user_id, role_id) SELECT :id, id FROM roles WHERE role_key = 'member'")
    ->execute(['id' => $userId]);
$pdo->exec($authMigration);
assertIntegration(!App\Core\Authorization::isSuperAdmin($userId), 'Reapplying auth migration must not restore a removed Super Admin role.');
$pdo->prepare('DELETE FROM user_roles WHERE user_id = :id')->execute(['id' => $userId]);
$superRole->execute(['id' => $userId]);

// Multiple roles must persist and render as individually selected checkboxes.
$revisionBeforeRoleChange = App\Core\AuthorizationRevision::current($pdo, $userId);
$multiRoles = App\Core\UserRoleManager::replace($pdo, $userId, ['member', 'super_admin']);
$roleRevision = App\Core\AuthorizationRevision::current($pdo, $userId);
assertIntegration($roleRevision === $revisionBeforeRoleChange + 1, 'Changing user roles must increment the authorization revision.');
$multiRoles = App\Core\UserRoleManager::replace($pdo, $userId, ['super_admin', 'member']);
assertIntegration(App\Core\AuthorizationRevision::current($pdo, $userId) === $roleRevision, 'Reapplying the same role set must not increment the authorization revision.');
$roleLookup = $pdo->prepare(
    'SELECT GROUP_CONCAT(r.role_key ORDER BY r.role_key SEPARATOR ", ")
     FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :id'
);
$roleLookup->execute(['id' => $userId]);
assertIntegration($roleLookup->fetchColumn() === 'member, super_admin', 'A member may hold multiple roles at the same time.');

session_name('c0m_roles_test');
session_start();
$_SESSION['_csrf'] = App\Core\Csrf::token();
$users = [[
    'id' => $userId,
    'email' => $email,
    'display_name' => 'Multi-role test',
    'status' => 'active',
    'roles' => 'member, super_admin',
    'role_keys' => $multiRoles,
]];
$canManage = true;
$canAssignRoles = true;
$canViewAudit = true;
ob_start();
require BASE_PATH . '/app/Views/admin-users.php';
$multiRoleHtml = ob_get_clean();
assertIntegration(str_contains($multiRoleHtml, 'name="roles[]"'), 'The admin UI must submit a role list rather than a single role.');
assertIntegration(str_contains($multiRoleHtml, 'value="member" checked') && str_contains($multiRoleHtml, 'value="super_admin" checked'), 'The admin UI must show every existing role as selected.');
session_destroy();
$_SESSION = [];
App\Core\UserRoleManager::replace($pdo, $userId, ['super_admin']);

App\Core\Authorization::audit($userId, 'ci.audit.test', 'user', (string)$userId, ['test' => true]);
$audit = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE actor_user_id = :id AND action = 'ci.audit.test'");
$audit->execute(['id' => $userId]);
assertIntegration((int)$audit->fetchColumn() === 1, 'Sensitive actions must be auditable.');

try {
    session_name('c0m_session');
    session_start();
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'c0m-account-center-ci';
    App\Core\SessionManager::establish($userId);
    $sessionId = hash('sha256', session_id());

    $find = $pdo->prepare('SELECT user_id, revoked_at, expires_at FROM sessions WHERE id = :id');
    $find->execute(['id' => $sessionId]);
    $row = $find->fetch();
    assertIntegration($row && (int)$row['user_id'] === $userId, 'Login must persist a server-side session.');
    assertIntegration($row['revoked_at'] === null, 'A new session must not be revoked.');
    assertIntegration(strtotime($row['expires_at']) > time(), 'A new session must have an absolute expiry.');

    App\Core\SessionManager::revokeCurrent();
    $find->execute(['id' => $sessionId]);
    $row = $find->fetch();
    assertIntegration($row && $row['revoked_at'] !== null, 'Logout must revoke the server-side session.');

    session_destroy();

    // Idle expiry must invalidate the PHP session and revoke its DB record.
    session_start();
    App\Core\SessionManager::establish($userId);
    $idleSessionId = session_id();
    $idleSessionHash = hash('sha256', $idleSessionId);
    $pdo->prepare('UPDATE sessions SET last_activity = DATE_SUB(NOW(), INTERVAL 31 MINUTE) WHERE id = :id')
        ->execute(['id' => $idleSessionHash]);
    session_write_close();
    session_id($idleSessionId);
    App\Core\SessionManager::start();
    assertIntegration(empty($_SESSION['user_id']), 'A session idle for more than 30 minutes must be invalidated.');
    $idleRevoked = $pdo->prepare('SELECT revoked_at FROM sessions WHERE id = :id');
    $idleRevoked->execute(['id' => $idleSessionHash]);
    assertIntegration($idleRevoked->fetchColumn() !== null, 'Idle session expiry must revoke the server-side session record.');
    session_destroy();

    // Account suspension must invalidate an otherwise live session.
    session_start();
    App\Core\SessionManager::establish($userId);
    $suspendedSessionId = session_id();
    $suspendedSessionHash = hash('sha256', $suspendedSessionId);
    $pdo->prepare("UPDATE users SET status = 'suspended' WHERE id = :id")->execute(['id' => $userId]);
    session_write_close();
    session_id($suspendedSessionId);
    App\Core\SessionManager::start();
    assertIntegration(empty($_SESSION['user_id']), 'A suspended account must not retain an authenticated session.');
    $suspendedRevoked = $pdo->prepare('SELECT revoked_at FROM sessions WHERE id = :id');
    $suspendedRevoked->execute(['id' => $suspendedSessionHash]);
    assertIntegration($suspendedRevoked->fetchColumn() !== null, 'Account suspension must revoke the persisted session.');
    $pdo->prepare("UPDATE users SET status = 'active' WHERE id = :id")->execute(['id' => $userId]);
    session_destroy();
} finally {
    $delete = $pdo->prepare('DELETE FROM users WHERE id = :id');
    $delete->execute(['id' => $userId]);
}


// Exercise registration, hashed verification token, activation and single-use semantics.
$pdo->prepare("UPDATE app_settings SET setting_value = '1' WHERE setting_key = 'registration_enabled'")->execute();
file_put_contents(BASE_PATH . '/config/app.php', "<?php return ['base_url' => 'https://accounts.example.test'];");
file_put_contents(BASE_PATH . '/config/mail.php', "<?php return ['mode' => 'development_log'];");
$registrationEmail = 'signup-' . bin2hex(random_bytes(6)) . '@example.test';
$controller = new App\Controllers\AuthController();
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
session_start();
$_SESSION = [];
$_POST = [
    '_csrf' => App\Core\Csrf::token(),
    'email' => $registrationEmail,
    'display_name' => 'CI Registration',
    'password' => 'StrongPassword42',
];
ob_start();
$controller->register();
ob_end_clean();

$lookup = $pdo->prepare('SELECT id, status, email_verified_at FROM users WHERE email = :email');
$lookup->execute(['email' => $registrationEmail]);
$registered = $lookup->fetch();
assertIntegration($registered && $registered['status'] === 'pending', 'Registration must create a pending account.');
$registeredLanguage = $pdo->prepare('SELECT language FROM user_profiles WHERE user_id = :id');
$registeredLanguage->execute(['id' => (int)$registered['id']]);
assertIntegration($registeredLanguage->fetchColumn() === 'vi', 'New accounts must inherit the Vietnamese default when no locale was selected.');
$mailLog = file_get_contents(BASE_PATH . '/storage/mail.log') ?: '';
assertIntegration(str_contains($mailLog, 'Xin chào CI Registration'), 'Verification email must use the new account language.');
preg_match_all('/verify-email\?token=([a-f0-9]{64})/', $mailLog, $matches);
assertIntegration(!empty($matches[1]), 'Registration must send a verification link through the configured mail provider.');
$rawToken = end($matches[1]);
$tokenQuery = $pdo->prepare('SELECT token_hash FROM auth_tokens WHERE user_id = :id AND token_type = "email_verification"');
$tokenQuery->execute(['id' => (int)$registered['id']]);
$storedToken = $tokenQuery->fetchColumn();
assertIntegration($storedToken === hash('sha256', $rawToken), 'Only a SHA-256 hash of the verification token may be stored.');

$_POST = ['_csrf' => App\Core\Csrf::token(), 'token' => $rawToken];
ob_start();
$controller->verifyEmail();
ob_end_clean();
$lookup->execute(['email' => $registrationEmail]);
$activated = $lookup->fetch();
assertIntegration($activated && $activated['status'] === 'active' && $activated['email_verified_at'] !== null, 'A valid verification token must activate the account.');

$_POST = ['_csrf' => App\Core\Csrf::token(), 'token' => $rawToken];
ob_start();
$controller->verifyEmail();
ob_end_clean();
$tokenQuery->execute(['id' => (int)$registered['id']]);
$tokenRow = $pdo->prepare('SELECT used_at FROM auth_tokens WHERE token_hash = :hash');
$tokenRow->execute(['hash' => hash('sha256', $rawToken)]);
assertIntegration($tokenRow->fetchColumn() !== null, 'A verification token must remain consumed after use.');

// Duplicate registration returns the same public response and creates no extra account.
$_POST = [
    '_csrf' => App\Core\Csrf::token(),
    'email' => $registrationEmail,
    'display_name' => 'Duplicate Attempt',
    'password' => 'StrongPassword42',
];
ob_start();
$controller->register();
$duplicateOutput = ob_get_clean();
$genericMessage = App\Core\Localization::translate("If registration can be completed for this address, a verification email will be sent shortly.");
assertIntegration($genericMessage !== '' && str_contains($duplicateOutput, $genericMessage), 'Duplicate registration must use the generic response.');

// Registration must honor the administrator-controlled enable/disable setting.
$pdo->prepare("UPDATE app_settings SET setting_value = '0' WHERE setting_key = 'registration_enabled'")->execute();
$closedEmail = 'closed-' . bin2hex(random_bytes(5)) . '@example.test';
http_response_code(200);
$_POST = [
    '_csrf' => App\Core\Csrf::token(),
    'email' => $closedEmail,
    'display_name' => 'Closed Registration',
    'password' => 'StrongPassword42',
];
ob_start();
$controller->register();
ob_end_clean();
assertIntegration(http_response_code() === 403, 'Registration must return 403 when disabled by an administrator.');
$closedLookup = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :email');
$closedLookup->execute(['email' => $closedEmail]);
assertIntegration((int)$closedLookup->fetchColumn() === 0, 'Disabled registration must not create a pending account.');
$pdo->prepare("UPDATE app_settings SET setting_value = '1' WHERE setting_key = 'registration_enabled'")->execute();

// Password recovery must use a hashed one-time token and revoke all persisted sessions.
$_POST = ['_csrf' => App\Core\Csrf::token(), 'email' => $registrationEmail];
ob_start();
$controller->forgotPassword();
ob_end_clean();
$mailLog = file_get_contents(BASE_PATH . '/storage/mail.log') ?: '';
assertIntegration(str_contains($mailLog, 'Sử dụng liên kết một lần'), 'Password reset email must use the recipient language preference.');
preg_match_all('/reset-password\\?token=([a-f0-9]{64})/', $mailLog, $resetMatches);
assertIntegration(!empty($resetMatches[1]), 'Password recovery must send a reset link for an active account.');
$resetToken = end($resetMatches[1]);
$resetHash = hash('sha256', $resetToken);
$tokenCheck = $pdo->prepare('SELECT token_hash FROM auth_tokens WHERE user_id = :id AND token_type = "password_reset"');
$tokenCheck->execute(['id' => (int)$registered['id']]);
assertIntegration($tokenCheck->fetchColumn() === $resetHash, 'Password reset token must be stored only as a hash.');

$sessionHash = hash('sha256', session_id());
$pdo->prepare(
    'INSERT INTO sessions (id, user_id, ip_address, user_agent, last_activity, expires_at)
     VALUES (:id, :user_id, NULL, "reset-test", NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))'
)->execute(['id' => $sessionHash, 'user_id' => (int)$registered['id']]);
$_SESSION = [];
$_POST = ['_csrf' => App\Core\Csrf::token(), 'language' => 'en', 'return_to' => '/login'];
(new App\Controllers\LanguageController())->switch();
assertIntegration(App\Core\Localization::current() === 'en', 'Guest language switch must update the current request.');
$_SESSION = [];
$_COOKIE['c0m_lang'] = 'en';
App\Core\Localization::initialize();
assertIntegration(App\Core\Localization::current() === 'en', 'Guest locale cookie must persist across a fresh session.');
$_SESSION['user_id'] = (int)$registered['id'];
$_POST = ['_csrf' => App\Core\Csrf::token(), 'token' => $resetToken, 'password' => 'NewStrongPassword84'];
ob_start();
$controller->resetPassword();
ob_end_clean();
$passwordLookup = $pdo->prepare('SELECT password_hash FROM users WHERE email = :email');
$passwordLookup->execute(['email' => $registrationEmail]);
$afterReset = $passwordLookup->fetch();
assertIntegration(password_verify('NewStrongPassword84', $afterReset['password_hash'] ?? ''), 'Password reset must replace the password hash.');
$sessionCheck = $pdo->prepare('SELECT revoked_at FROM sessions WHERE id = :id');
$sessionCheck->execute(['id' => $sessionHash]);
assertIntegration($sessionCheck->fetchColumn() !== null, 'Password reset must revoke all active sessions.');

// Replaying the consumed reset token must not change the password.
$_POST = ['_csrf' => App\Core\Csrf::token(), 'token' => $resetToken, 'password' => 'AnotherStrongPassword95'];
ob_start();
$controller->resetPassword();
ob_end_clean();
$passwordLookup->execute(['email' => $registrationEmail]);
$afterReplay = $passwordLookup->fetch();
assertIntegration(password_verify('NewStrongPassword84', $afterReplay['password_hash'] ?? ''), 'A reused reset token must not change the password.');

// Expired reset tokens must not mutate credentials.
$expiredReset = str_repeat('b', 64);
$pdo->prepare(
    "INSERT INTO auth_tokens (user_id, token_type, token_hash, expires_at)
     VALUES (:id, 'password_reset', :hash, DATE_SUB(NOW(), INTERVAL 1 MINUTE))"
)->execute(['id' => (int)$registered['id'], 'hash' => hash('sha256', $expiredReset)]);
$_POST = ['_csrf' => App\Core\Csrf::token(), 'token' => $expiredReset, 'password' => 'ExpiredTokenPassword66'];
ob_start();
$controller->resetPassword();
ob_end_clean();
$passwordLookup->execute(['email' => $registrationEmail]);
$afterExpired = $passwordLookup->fetch();
assertIntegration(password_verify('NewStrongPassword84', $afterExpired['password_hash'] ?? ''), 'An expired reset token must not change the password.');

// Expired email verification tokens must leave accounts pending.
$expiredEmail = 'expired-' . bin2hex(random_bytes(5)) . '@example.test';
$pdo->prepare("INSERT INTO users (email, password_hash, status) VALUES (:email, :hash, 'pending')")
    ->execute(['email' => $expiredEmail, 'hash' => password_hash('PendingPassword88', PASSWORD_DEFAULT)]);
$expiredUserId = (int)$pdo->lastInsertId();
$expiredVerify = str_repeat('c', 64);
$pdo->prepare(
    "INSERT INTO auth_tokens (user_id, token_type, token_hash, expires_at)
     VALUES (:id, 'email_verification', :hash, DATE_SUB(NOW(), INTERVAL 1 MINUTE))"
)->execute(['id' => $expiredUserId, 'hash' => hash('sha256', $expiredVerify)]);
$_POST = ['_csrf' => App\Core\Csrf::token(), 'token' => $expiredVerify];
ob_start();
$controller->verifyEmail();
ob_end_clean();
$pendingCheck = $pdo->prepare('SELECT status FROM users WHERE id = :id');
$pendingCheck->execute(['id' => $expiredUserId]);
assertIntegration($pendingCheck->fetchColumn() === 'pending', 'An expired verification token must not activate an account.');
$pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $expiredUserId]);

// Login must regenerate the pre-authentication session identifier and persist a new session.
$_SESSION = [];
session_regenerate_id(true);
$preAuthSessionId = session_id();
$_POST = [
    '_csrf' => App\Core\Csrf::token(),
    'email' => $registrationEmail,
    'password' => 'NewStrongPassword84',
];
ob_start();
$controller->login();
ob_end_clean();
assertIntegration(session_id() !== $preAuthSessionId, 'Successful login must rotate the pre-authentication session ID.');
assertIntegration((int)($_SESSION['user_id'] ?? 0) === (int)$registered['id'], 'Successful login must establish the authenticated user session.');
$authenticatedSessionHash = hash('sha256', session_id());
$sessionLookup = $pdo->prepare('SELECT user_id, revoked_at FROM sessions WHERE id = :id');
$sessionLookup->execute(['id' => $authenticatedSessionHash]);
$authenticatedSession = $sessionLookup->fetch();
assertIntegration($authenticatedSession && (int)$authenticatedSession['user_id'] === (int)$registered['id'] && $authenticatedSession['revoked_at'] === null, 'Successful login must persist a live server-side session.');

// Unknown account failures must be logged and independently throttled by identifier.
App\Core\SessionManager::invalidate();
$throttleEmail = 'throttle-' . bin2hex(random_bytes(5)) . '@example.test';
$_SESSION = [];
session_regenerate_id(true);
$_POST = [
    '_csrf' => App\Core\Csrf::token(),
    'email' => $throttleEmail,
    'password' => 'WrongPassword99',
];
for ($attempt = 0; $attempt < 10; $attempt++) {
    ob_start();
    $controller->login();
    ob_end_clean();
}
$failedCount = $pdo->prepare('SELECT COUNT(*) FROM login_history WHERE email_attempted = :email AND success = 0');
$failedCount->execute(['email' => $throttleEmail]);
assertIntegration((int)$failedCount->fetchColumn() === 10, 'Failed login attempts must be recorded before the identity threshold is reached.');
http_response_code(200);
ob_start();
$controller->login();
ob_end_clean();
assertIntegration(http_response_code() === 429, 'The next failed attempt must be throttled by account identifier.');
$pdo->prepare('DELETE FROM login_history WHERE email_attempted = :email')->execute(['email' => $throttleEmail]);

// IP throttling is independent of the attempted account identifier.
$ipThrottle = inet_pton('127.0.0.1');
$ipAttempt = $pdo->prepare(
    'INSERT INTO login_history (user_id, email_attempted, ip_address, user_agent, success)
     VALUES (NULL, :email, :ip, :ua, 0)'
);
for ($attempt = 0; $attempt < 30; $attempt++) {
    $ipAttempt->bindValue(':email', 'ip-throttle-' . $attempt . '@example.test');
    $ipAttempt->bindValue(':ip', $ipThrottle, PDO::PARAM_LOB);
    $ipAttempt->bindValue(':ua', 'c0m-ip-throttle-test');
    $ipAttempt->execute();
}
http_response_code(200);
$_POST = [
    '_csrf' => App\Core\Csrf::token(),
    'email' => 'another-account-' . bin2hex(random_bytes(4)) . '@example.test',
    'password' => 'WrongPassword99',
];
ob_start();
$controller->login();
ob_end_clean();
assertIntegration(http_response_code() === 429, 'Thirty failed attempts from one IP must throttle a different account identifier.');
$pdo->prepare('DELETE FROM login_history WHERE ip_address = :ip AND user_agent = :ua')
    ->execute(['ip' => $ipThrottle, 'ua' => 'c0m-ip-throttle-test']);
App\Core\SessionManager::invalidate();

// Profile preferences must validate and persist through the account controller.
http_response_code(200);
App\Core\SessionManager::establish((int)$registered['id']);
$accountController = new App\Controllers\AccountController();
$profileUsername = 'ci_' . bin2hex(random_bytes(4));
$_POST = [
    '_csrf' => App\Core\Csrf::token(),
    'display_name' => 'Updated CI Profile',
    'username' => $profileUsername,
    'language' => 'en',
    'timezone' => 'UTC',
];
ob_start();
$accountController->updateProfile();
ob_end_clean();
$profileCheck = $pdo->prepare('SELECT u.display_name, p.username, p.language, p.timezone
    FROM users u INNER JOIN user_profiles p ON p.user_id = u.id WHERE u.id = :id');
$profileCheck->execute(['id' => (int)$registered['id']]);
$profile = $profileCheck->fetch();
assertIntegration($profile && $profile['display_name'] === 'Updated CI Profile' && $profile['username'] === $profileUsername
    && $profile['language'] === 'en' && $profile['timezone'] === 'UTC', 'Account profile changes must persist validated preferences.');
$pdo->exec($preferencesMigration);
$preserved = $pdo->prepare('SELECT language FROM user_profiles WHERE user_id = :id');
$preserved->execute(['id' => (int)$registered['id']]);
assertIntegration($preserved->fetchColumn() === 'en', 'Re-running the language migration must not overwrite an existing account preference.');
$_SESSION['user_id'] = (int)$registered['id'];
$_SESSION['locale'] = 'vi';
$_POST = ['_csrf' => App\Core\Csrf::token(), 'language' => 'vi', 'return_to' => '/dashboard'];
(new App\Controllers\LanguageController())->switch();
$switched = $pdo->prepare('SELECT language FROM user_profiles WHERE user_id = :id');
$switched->execute(['id' => (int)$registered['id']]);
assertIntegration($switched->fetchColumn() === 'vi', 'Authenticated language switch must persist to the account profile.');
$_POST = ['_csrf' => App\Core\Csrf::token(), 'language' => 'en', 'return_to' => '/dashboard'];
(new App\Controllers\LanguageController())->switch();
$switched->execute(['id' => (int)$registered['id']]);
assertIntegration($switched->fetchColumn() === 'en', 'Authenticated language can be switched back to English.');
$_SESSION['locale'] = 'vi';
App\Core\Localization::initialize();
assertIntegration(App\Core\Localization::current() === 'en', 'Stored account language must remain authoritative over guest/session locale.');
$_SESSION = []; // Simulate the PHP session being cleared by logout.
App\Core\Localization::initialize();
assertIntegration(App\Core\Localization::current() === 'en', 'Guest locale must remain available after logout.');
$_SESSION['user_id'] = (int)$registered['id'];


// Password change requires the current password, revokes all sessions and sends a notification.
$_POST = [
    '_csrf' => App\Core\Csrf::token(),
    'current_password' => 'NewStrongPassword84',
    'new_password' => 'ChangedStrongPassword91',
    'confirm_password' => 'ChangedStrongPassword91',
];
ob_start();
$accountController->changePassword();
ob_end_clean();
$passwordLookup->execute(['email' => $registrationEmail]);
$changedPassword = $passwordLookup->fetch();
assertIntegration(password_verify('ChangedStrongPassword91', $changedPassword['password_hash'] ?? ''), 'Account password change must update the password hash.');
$notificationLog = file_get_contents(BASE_PATH . '/storage/mail.log') ?: '';
assertIntegration(str_contains($notificationLog, 'Your c0m.me password was changed'), 'Password change must dispatch a security notification.');
assertIntegration(str_contains($notificationLog, 'The password for your c0m.me account was changed.'), 'Security notification must use the recipient saved English preference.');
$pdo->prepare('DELETE FROM auth_attempts WHERE action = \'password_change\' AND identifier_hash = :identifier')
    ->execute(['identifier' => hash('sha256', 'password-change:' . (int)$registered['id'])]);


$pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => (int)$registered['id']]);
@unlink(BASE_PATH . '/config/app.php');
@unlink(BASE_PATH . '/config/mail.php');
@unlink(BASE_PATH . '/storage/mail.log');

echo "MariaDB integration tests passed.\n";

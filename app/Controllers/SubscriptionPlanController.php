<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Authorization;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\EntitlementService;
use PDO;
use PDOException;
use Throwable;

final class SubscriptionPlanController
{
    public function index(): void
    {
        Authorization::requirePermission('billing.manage');
        $form = $this->emptyForm();
        $editValue = $_GET['edit'] ?? null;
        if ($editValue !== null) {
            $editId = filter_var($editValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($editId === false) {
                http_response_code(400);
                exit(\App\Core\t('Subscription plan not found.'));
            }
            $stmt = Database::connection()->prepare('SELECT * FROM subscription_plans WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $editId]);
            $plan = $stmt->fetch();
            if (!$plan) {
                http_response_code(404);
                exit(\App\Core\t('Subscription plan not found.'));
            }
            $form = $this->formFromRow($plan);
        }
        $this->render($form, null);
    }

    public function save(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit(\App\Core\t('Invalid CSRF token'));
        }
        $actorId = Authorization::requirePermission('billing.manage');
        $form = $this->formFromPost($_POST);
        $decoded = $this->decodePlanObjects($form);
        $error = $this->validationError($form, $decoded);
        if ($error !== null) {
            http_response_code(422);
            $this->render($form, $error);
            return;
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            if ($form['id'] === 0) {
                $insert = $pdo->prepare(
                    'INSERT INTO subscription_plans
                        (plan_key, name, description, price_minor, currency, billing_interval, features, quotas, status, sort_order)
                     VALUES
                        (:plan_key, :name, :description, :price_minor, :currency, :billing_interval, :features, :quotas, :status, :sort_order)'
                );
                $insert->execute($this->databaseValues($form, $decoded));
                $planId = (int)$pdo->lastInsertId();
                $action = 'subscription_plan.created';
            } else {
                $existing = $pdo->prepare('SELECT plan_key FROM subscription_plans WHERE id = :id FOR UPDATE');
                $existing->execute(['id' => $form['id']]);
                $existingKey = $existing->fetchColumn();
                if ($existingKey === false) throw new \DomainException('plan_not_found');
                if (!hash_equals((string)$existingKey, $form['plan_key'])) {
                    throw new \DomainException('plan_key_immutable');
                }

                $activeSubscriptions = $pdo->prepare(
                    "SELECT id, status FROM subscriptions
                     WHERE plan_id = :plan AND status IN ('active', 'trialing', 'past_due')
                     ORDER BY id FOR UPDATE"
                );
                $activeSubscriptions->execute(['plan' => $form['id']]);
                $subscriptionRows = $activeSubscriptions->fetchAll(PDO::FETCH_ASSOC);
                if ($form['status'] === 'archived' && $subscriptionRows !== []) {
                    throw new \DomainException('plan_in_use');
                }

                $update = $pdo->prepare(
                    'UPDATE subscription_plans
                     SET name = :name, description = :description, price_minor = :price_minor,
                         currency = :currency, billing_interval = :billing_interval,
                         features = :features, quotas = :quotas, status = :status,
                         sort_order = :sort_order, updated_at = UTC_TIMESTAMP()
                     WHERE id = :id'
                );
                $updateValues = $this->databaseValues($form, $decoded);
                unset($updateValues['plan_key']);
                $update->execute($updateValues + ['id' => $form['id']]);
                $planId = $form['id'];
                $action = 'subscription_plan.updated';

                // Feature/quota edits must take effect for current members in
                // the same transaction as the plan update.
                foreach ($subscriptionRows as $subscriptionRow) {
                    if (in_array($subscriptionRow['status'], ['active', 'trialing'], true)) {
                        EntitlementService::synchronize($pdo, (int)$subscriptionRow['id']);
                    }
                }
            }

            Authorization::audit($actorId, $action, 'subscription_plan', (string)$planId, [
                'plan_key' => $form['plan_key'],
                'status' => $form['status'],
                'price_minor' => (int)$form['price_minor'],
                'currency' => $form['currency'],
                'billing_interval' => $form['billing_interval'],
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof \DomainException) {
                $reason = $e->getMessage();
                $message = match ($reason) {
                    'plan_not_found' => \App\Core\t('Subscription plan not found.'),
                    'plan_key_immutable' => \App\Core\t('Plan key cannot be changed after creation.'),
                    'plan_in_use' => \App\Core\t('A plan with current subscriptions cannot be archived.'),
                    default => \App\Core\t('Subscription plan save failed.'),
                };
                http_response_code($reason === 'plan_not_found' ? 404 : 422);
                $this->render($form, $message);
                return;
            }
            if ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062) {
                http_response_code(409);
                $this->render($form, \App\Core\t('Plan key already exists.'));
                return;
            }
            error_log('Subscription plan save failed (' . get_class($e) . ', code ' . $e->getCode() . ').');
            http_response_code(500);
            $this->render($form, \App\Core\t('Subscription plan save failed.'));
            return;
        }

        header('Location: /admin/plans', true, 303);
    }

    private function render(array $form, ?string $error): void
    {
        $plans = Database::connection()->query(
            "SELECT p.id, p.plan_key, p.name, p.description, p.price_minor, p.currency,
                    p.billing_interval, p.features, p.quotas, p.status, p.sort_order, p.updated_at,
                    (SELECT COUNT(*) FROM subscriptions s
                     WHERE s.plan_id = p.id AND s.status IN ('active', 'trialing', 'past_due')) AS active_subscriptions
             FROM subscription_plans p
             ORDER BY p.sort_order, p.price_minor, p.id"
        )->fetchAll();
        require __DIR__ . '/../Views/admin-plans.php';
    }

    private function emptyForm(): array
    {
        return [
            'id' => 0,
            'plan_key' => '',
            'name' => '',
            'description' => '',
            'price_minor' => '0',
            'currency' => 'USD',
            'billing_interval' => 'none',
            'features_json' => "{}",
            'quotas_json' => "{}",
            'status' => 'active',
            'sort_order' => '0',
        ];
    }

    private function formFromPost(array $source): array
    {
        $form = $this->emptyForm();
        $id = $source['id'] ?? '';
        if (is_string($id) && $id !== '' && ctype_digit($id)) $form['id'] = (int)$id;
        elseif (is_int($id) && $id > 0) $form['id'] = $id;
        elseif ($id !== '' && $id !== null) $form['id'] = -1;

        foreach (['plan_key', 'name', 'description', 'price_minor', 'currency', 'billing_interval', 'features_json', 'quotas_json', 'status', 'sort_order'] as $field) {
            $value = $source[$field] ?? '';
            $form[$field] = is_string($value) ? trim($value) : '';
        }
        $form['currency'] = strtoupper($form['currency']);
        return $form;
    }

    private function formFromRow(array $row): array
    {
        $features = json_decode((string)$row['features']);
        $quotas = json_decode((string)$row['quotas']);
        return [
            'id' => (int)$row['id'],
            'plan_key' => (string)$row['plan_key'],
            'name' => (string)$row['name'],
            'description' => (string)($row['description'] ?? ''),
            'price_minor' => (string)$row['price_minor'],
            'currency' => (string)$row['currency'],
            'billing_interval' => (string)$row['billing_interval'],
            'features_json' => json_encode($features instanceof \stdClass ? $features : new \stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'quotas_json' => json_encode($quotas instanceof \stdClass ? $quotas : new \stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'status' => (string)$row['status'],
            'sort_order' => (string)$row['sort_order'],
        ];
    }

    private function decodePlanObjects(array $form): ?array
    {
        $features = json_decode($form['features_json']);
        $quotas = json_decode($form['quotas_json']);
        if (!$features instanceof \stdClass || !$quotas instanceof \stdClass) return null;

        $featureValues = get_object_vars($features);
        foreach ($featureValues as $key => $value) {
            if (!is_string($key) || preg_match('/\A[a-z0-9][a-z0-9._:-]{0,100}\z/D', $key) !== 1 || !is_bool($value)) return null;
        }
        $quotaValues = get_object_vars($quotas);
        foreach ($quotaValues as $key => $value) {
            if (!is_string($key) || preg_match('/\A[a-z0-9][a-z0-9._:-]{0,100}\z/D', $key) !== 1
                || !is_int($value) || $value < 0) return null;
        }
        return ['features' => $featureValues, 'quotas' => $quotaValues];
    }

    private function validationError(array $form, ?array $decoded): ?string
    {
        if ($decoded === null
            || $form['id'] < 0
            || !preg_match('/\A[a-z0-9][a-z0-9._-]{0,79}\z/D', $form['plan_key'])
            || $form['name'] === '' || mb_strlen($form['name']) > 120
            || mb_strlen($form['description']) > 5000
            || !ctype_digit($form['price_minor']) || (float)$form['price_minor'] > 9000000000000000
            || !preg_match('/\A[A-Z]{3}\z/D', $form['currency'])
            || !in_array($form['billing_interval'], ['none', 'month', 'year'], true)
            || !in_array($form['status'], ['active', 'archived'], true)
            || filter_var($form['sort_order'], FILTER_VALIDATE_INT) === false
            || (int)$form['sort_order'] < -100000 || (int)$form['sort_order'] > 100000) {
            return \App\Core\t('Enter a valid plan name, key, currency, price, interval, feature object and quota object.');
        }
        return null;
    }

    private function databaseValues(array $form, array $decoded): array
    {
        return [
            'plan_key' => $form['plan_key'],
            'name' => $form['name'],
            'description' => $form['description'] === '' ? null : $form['description'],
            'price_minor' => (int)$form['price_minor'],
            'currency' => $form['currency'],
            'billing_interval' => $form['billing_interval'],
            'features' => json_encode((object)$decoded['features'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'quotas' => json_encode((object)$decoded['quotas'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'status' => $form['status'],
            'sort_order' => (int)$form['sort_order'],
        ];
    }
}

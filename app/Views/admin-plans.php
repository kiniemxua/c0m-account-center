<!doctype html>
<html lang="<?= \App\Core\Localization::current() ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= \App\Core\t('Subscription plans') ?> — c0m.me</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body><?php require __DIR__ . '/partials/language-switcher.php'; ?>
<main class="shell">
<nav class="nav">
<a class="brand" href="/dashboard">c0m.me</a>
<div class="admin-nav">
<a class="nav-link" href="/admin/users"><?= \App\Core\t('Members') ?></a>
<a class="nav-link" href="/admin/applications"><?= \App\Core\t('Applications') ?></a>
<a class="nav-link" href="/admin/groups"><?= \App\Core\t('Member groups') ?></a>
<a class="nav-link" href="/admin/oauth-clients"><?= \App\Core\t('OAuth clients') ?></a>
<a class="nav-link" href="/admin/plans"><?= \App\Core\t('Subscription plans') ?></a>
<a class="nav-link" href="/admin/settings"><?= \App\Core\t('Settings') ?></a>
</div>
</nav>
<section class="hero compact">
<span class="eyebrow"><?= \App\Core\t('ADMINISTRATION') ?></span>
<h1><?= \App\Core\t('Subscription plans') ?></h1>
<p><?= \App\Core\t('Manage subscription plans and the features or quotas granted to members.') ?></p>
</section>
<?php if ($error !== null): ?><div class="alert" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<section class="admin-panel">
<h2><?= $form['id'] > 0 ? \App\Core\t('Edit subscription plan') : \App\Core\t('New subscription plan') ?></h2>
<form method="post" action="/admin/plans/save">
<input type="hidden" name="_csrf" value="<?= htmlspecialchars(\App\Core\Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
<label for="plan-key"><?= \App\Core\t('Plan key') ?></label>
<input id="plan-key" name="plan_key" value="<?= htmlspecialchars($form['plan_key'], ENT_QUOTES, 'UTF-8') ?>" maxlength="80" pattern="[a-z0-9][a-z0-9._-]{0,79}" <?= $form['id'] > 0 ? 'readonly' : '' ?> required>
<?php if ($form['id'] > 0): ?><p class="muted"><?= \App\Core\t('Plan key cannot be changed after creation.') ?></p><?php endif; ?>
<label for="plan-name"><?= \App\Core\t('Plan name') ?></label>
<input id="plan-name" name="name" value="<?= htmlspecialchars($form['name'], ENT_QUOTES, 'UTF-8') ?>" maxlength="120" required>
<label for="plan-description"><?= \App\Core\t('Description') ?></label>
<textarea id="plan-description" name="description" maxlength="5000" rows="2"><?= htmlspecialchars($form['description'], ENT_QUOTES, 'UTF-8') ?></textarea>
<div class="form-grid">
<div>
<label for="plan-price"><?= \App\Core\t('Price (minor units)') ?></label>
<input id="plan-price" name="price_minor" type="number" min="0" step="1" value="<?= htmlspecialchars($form['price_minor'], ENT_QUOTES, 'UTF-8') ?>" required>
</div>
<div>
<label for="plan-currency"><?= \App\Core\t('Currency') ?></label>
<input id="plan-currency" name="currency" value="<?= htmlspecialchars($form['currency'], ENT_QUOTES, 'UTF-8') ?>" maxlength="3" pattern="[A-Z]{3}" required>
</div>
<div>
<label for="plan-interval"><?= \App\Core\t('Billing interval') ?></label>
<select id="plan-interval" name="billing_interval">
<option value="none" <?= $form['billing_interval'] === 'none' ? 'selected' : '' ?>><?= \App\Core\t('No recurring billing') ?></option>
<option value="month" <?= $form['billing_interval'] === 'month' ? 'selected' : '' ?>><?= \App\Core\t('Monthly') ?></option>
<option value="year" <?= $form['billing_interval'] === 'year' ? 'selected' : '' ?>><?= \App\Core\t('Yearly') ?></option>
</select>
</div>
<div>
<label for="plan-sort"><?= \App\Core\t('Sort order') ?></label>
<input id="plan-sort" name="sort_order" type="number" min="-100000" max="100000" step="1" value="<?= htmlspecialchars($form['sort_order'], ENT_QUOTES, 'UTF-8') ?>" required>
</div>
</div>
<label for="plan-features"><?= \App\Core\t('Feature flags (JSON object)') ?></label>
<textarea id="plan-features" name="features_json" rows="5" spellcheck="false" required><?= htmlspecialchars($form['features_json'], ENT_QUOTES, 'UTF-8') ?></textarea>
<label for="plan-quotas"><?= \App\Core\t('Quotas (JSON object)') ?></label>
<textarea id="plan-quotas" name="quotas_json" rows="5" spellcheck="false" required><?= htmlspecialchars($form['quotas_json'], ENT_QUOTES, 'UTF-8') ?></textarea>
<label for="plan-status"><?= \App\Core\t('Plan status') ?></label>
<select id="plan-status" name="status">
<option value="active" <?= $form['status'] === 'active' ? 'selected' : '' ?>><?= \App\Core\t('Active') ?></option>
<option value="archived" <?= $form['status'] === 'archived' ? 'selected' : '' ?>><?= \App\Core\t('Archived') ?></option>
</select>
<button class="button" type="submit"><?= \App\Core\t('Save plan') ?></button>
<?php if ($form['id'] > 0): ?><a class="nav-link" href="/admin/plans"><?= \App\Core\t('Cancel') ?></a><?php endif; ?>
</form>
</section>
<section>
<h2><?= \App\Core\t('Registered plans') ?></h2>
<div class="admin-list">
<?php foreach ($plans as $plan): ?>
<article>
<div class="row-between">
<div><b><?= htmlspecialchars((string)$plan['plan_key'], ENT_QUOTES, 'UTF-8') ?></b><h3><?= htmlspecialchars((string)$plan['name'], ENT_QUOTES, 'UTF-8') ?></h3></div>
<span class="status-pill"><?= \App\Core\t((string)$plan['status']) ?></span>
</div>
<p><?= htmlspecialchars((string)($plan['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
<p><?= \App\Core\t('Price (minor units)') ?>: <?= number_format((int)$plan['price_minor']) ?> <?= htmlspecialchars((string)$plan['currency'], ENT_QUOTES, 'UTF-8') ?> · <?= \App\Core\t((string)$plan['billing_interval']) ?></p>
<p><?= \App\Core\t('Active subscriptions') ?>: <?= (int)$plan['active_subscriptions'] ?></p>
<p><?= \App\Core\t('Feature flags (JSON object)') ?>: <code><?= htmlspecialchars((string)$plan['features'], ENT_QUOTES, 'UTF-8') ?></code></p>
<p><?= \App\Core\t('Quotas (JSON object)') ?>: <code><?= htmlspecialchars((string)$plan['quotas'], ENT_QUOTES, 'UTF-8') ?></code></p>
<a class="nav-link" href="/admin/plans?edit=<?= (int)$plan['id'] ?>"><?= \App\Core\t('Edit subscription plan') ?></a>
</article>
<?php endforeach; ?>
<?php if ($plans === []): ?><article><p><?= \App\Core\t('No plans found.') ?></p></article><?php endif; ?>
</div>
</section>
</main>
</body>
</html>

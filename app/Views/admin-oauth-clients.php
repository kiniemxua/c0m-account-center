<!doctype html>
<html lang="<?= \App\Core\Localization::current() ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= \App\Core\t('OAuth clients') ?> — c0m.me</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body><?php require __DIR__ . '/partials/language-switcher.php'; ?>
<main class="shell">
<nav class="nav">
<a class="brand" href="/dashboard">c0m.me</a>
<div class="admin-nav"><a class="nav-link" href="/admin/users"><?= \App\Core\t('Members') ?></a><a class="nav-link" href="/admin/groups"><?= \App\Core\t('Member groups') ?></a><a class="nav-link" href="/admin/applications"><?= \App\Core\t('Applications') ?></a><a class="nav-link" href="/admin/oauth-clients"><?= \App\Core\t('OAuth clients') ?></a><a class="nav-link" href="/admin/settings"><?= \App\Core\t('Settings') ?></a></div>
</nav>
<section class="hero compact">
<span class="eyebrow"><?= \App\Core\t('ADMINISTRATION') ?></span>
<h1><?= \App\Core\t('OAuth clients') ?></h1>
<p><?= \App\Core\t('Manage OAuth credentials for applications connected to Account Center.') ?></p>
</section>
<?php if ($error !== null): ?><div class="alert" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if ($flash !== null): ?>
<section class="admin-panel">
<h2><?= \App\Core\t('Client credentials created') ?></h2>
<p><?= \App\Core\t('Copy and store the client secret now. It will not be shown again.') ?></p>
<?php if (($flash['application_name'] ?? '') !== ''): ?><p><strong><?= htmlspecialchars((string)$flash['application_name'], ENT_QUOTES, 'UTF-8') ?></strong></p><?php endif; ?>
<label><?= \App\Core\t('Client ID') ?></label>
<div class="code-value"><?= htmlspecialchars((string)$flash['client_id'], ENT_QUOTES, 'UTF-8') ?></div>
<?php if (!empty($flash['client_secret'])): ?>
<label><?= \App\Core\t('Client secret') ?></label>
<div class="code-value"><?= htmlspecialchars((string)$flash['client_secret'], ENT_QUOTES, 'UTF-8') ?></div>
<?php else: ?><p><?= \App\Core\t('Public clients do not have a client secret. Use PKCE for every authorization request.') ?></p><?php endif; ?>
</section>
<?php endif; ?>
<section class="auth-card">
<h2><?= \App\Core\t('Create OAuth client') ?></h2>
<?php if ($applications === []): ?>
<p><?= \App\Core\t('Every active application already has a client, or no active application is available.') ?></p>
<?php else: ?>
<form method="post" action="/admin/oauth-clients/create">
<input type="hidden" name="_csrf" value="<?= htmlspecialchars(\App\Core\Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
<label for="oauth-application"><?= \App\Core\t('Application') ?></label>
<select id="oauth-application" name="application_id" required>
<?php foreach ($applications as $application): ?>
<option value="<?= (int)$application['id'] ?>"><?= htmlspecialchars((string)$application['name'], ENT_QUOTES, 'UTF-8') ?></option>
<?php endforeach; ?>
</select>
<label for="oauth-client-type"><?= \App\Core\t('Client type') ?></label>
<select id="oauth-client-type" name="client_type" required>
<option value="public"><?= \App\Core\t('Public client') ?></option>
<option value="confidential"><?= \App\Core\t('Confidential client') ?></option>
</select>
<label for="oauth-redirects"><?= \App\Core\t('Redirect URIs (one HTTPS URL per line)') ?></label>
<textarea id="oauth-redirects" name="redirect_uris" rows="4" required placeholder="https://app.example.com/auth/callback"></textarea>
<fieldset>
<legend><?= \App\Core\t('Allowed scopes') ?></legend>
<?php foreach ($scopes as $scope): ?>
<label><input type="checkbox" name="scopes[]" value="<?= htmlspecialchars($scope, ENT_QUOTES, 'UTF-8') ?>" <?= in_array($scope, ['openid', 'profile', 'email'], true) ? 'checked' : '' ?>> <?= htmlspecialchars($scope, ENT_QUOTES, 'UTF-8') ?></label>
<?php endforeach; ?>
</fieldset>
<button class="button" type="submit"><?= \App\Core\t('Create client') ?></button>
</form>
<?php endif; ?>
</section>
<section>
<h2><?= \App\Core\t('Registered OAuth clients') ?></h2>
<div class="admin-list">
<?php foreach ($clients as $client): ?>
<article>
<b><?= htmlspecialchars((string)$client['application_name'], ENT_QUOTES, 'UTF-8') ?></b>
<p><?= \App\Core\t('Client ID') ?>: <code><?= htmlspecialchars((string)$client['client_id'], ENT_QUOTES, 'UTF-8') ?></code></p>
<p><?= \App\Core\t('Client type') ?>: <?= \App\Core\t((string)$client['client_type'] === 'public' ? 'Public client' : 'Confidential client') ?></p>
<p><?= \App\Core\t('Status') ?>: <?= \App\Core\t((string)$client['status']) ?> · <?= \App\Core\t('Application') ?>: <?= \App\Core\t((string)$client['application_status']) ?></p>
<p><?= \App\Core\t('Redirect URIs') ?>:</p>
<ul><?php foreach ((json_decode((string)$client['redirect_uris'], true) ?: []) as $uri): ?><li><code><?= htmlspecialchars((string)$uri, ENT_QUOTES, 'UTF-8') ?></code></li><?php endforeach; ?></ul>
<p><?= \App\Core\t('Allowed scopes') ?>: <?= htmlspecialchars(implode(', ', json_decode((string)$client['allowed_scopes'], true) ?: []), ENT_QUOTES, 'UTF-8') ?></p>
<?php if ($client['client_type'] === 'confidential'): ?>
<form method="post" action="/admin/oauth-clients/rotate">
<input type="hidden" name="_csrf" value="<?= htmlspecialchars(\App\Core\Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="client_record_id" value="<?= (int)$client['id'] ?>">
<button class="button secondary" type="submit"><?= \App\Core\t('Rotate secret and revoke existing tokens') ?></button>
</form>
<?php endif; ?>
<form method="post" action="/admin/oauth-clients/status">
<input type="hidden" name="_csrf" value="<?= htmlspecialchars(\App\Core\Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="client_record_id" value="<?= (int)$client['id'] ?>">
<?php if ($client['status'] === 'active'): ?>
<input type="hidden" name="status" value="disabled">
<button class="button secondary" type="submit"><?= \App\Core\t('Disable client') ?></button>
<?php else: ?>
<input type="hidden" name="status" value="active">
<button class="button" type="submit"><?= \App\Core\t('Enable client') ?></button>
<?php endif; ?>
</form>
</article>
<?php endforeach; ?>
<?php if ($clients === []): ?><p><?= \App\Core\t('No OAuth clients registered.') ?></p><?php endif; ?>
</div>
</section>
</main>
</body>
</html>

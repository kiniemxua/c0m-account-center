<!doctype html>
<html lang="<?= \App\Core\Localization::current() ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= \App\Core\t('Dashboard') ?> — c0m.me</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body><?php require __DIR__ . '/partials/language-switcher.php'; ?>
<main class="shell">
<nav class="nav">
<a class="brand" href="/">c0m.me</a>
<div>
<a class="nav-link" href="/account"><?= \App\Core\t('Account') ?></a> ·
<a class="nav-link" href="/sessions"><?= \App\Core\t('Devices') ?></a> ·
<a class="nav-link" href="/login-history"><?= \App\Core\t('Login history') ?></a>
<?php if ($canViewAdmin): ?> · <a class="nav-link" href="/admin/users"><?= \App\Core\t('Admin') ?></a><?php endif; ?>
<?php if ($canManageApplications): ?> · <a class="nav-link" href="/admin/applications"><?= \App\Core\t('Manage applications') ?></a><?php endif; ?>
<?php if ($canManageGroups): ?> · <a class="nav-link" href="/admin/groups"><?= \App\Core\t('Manage groups') ?></a><?php endif; ?>
<?php if ($canViewAudit): ?> · <a class="nav-link" href="/admin/audit"><?= \App\Core\t('Audit log') ?></a><?php endif; ?>
</div>
<form method="post" action="/logout">
<input type="hidden" name="_csrf" value="<?= htmlspecialchars(\App\Core\Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
<button class="nav-link" type="submit"><?= \App\Core\t('Sign out') ?></button>
</form>
</nav>
<section class="hero compact">
<span class="eyebrow"><?= \App\Core\t('MEMBER DASHBOARD') ?></span>
<h1><?= htmlspecialchars(\App\Core\Localization::translate('Hello {name}', ['name' => $user['display_name'] ?: $user['email']]), ENT_QUOTES, 'UTF-8') ?></h1>
<p><?= \App\Core\t('Your account hub for connected applications and account security.') ?></p>
</section>
<section class="feature-grid">
<article><b><?= \App\Core\t('ACCOUNT') ?></b><h2><?= \App\Core\t('Profile') ?></h2><p><?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?></p></article>
<article><b><?= \App\Core\t('SECURITY') ?></b><h2><?= \App\Core\t('Sessions') ?></h2><p><a href="/sessions"><?= \App\Core\t('Manage active devices') ?></a> · <a href="/login-history"><?= \App\Core\t('View login history') ?></a></p></article>
</section>
<section>
<h2><?= \App\Core\t('Applications') ?></h2>
<p><?= \App\Core\t('Discover active applications available to your account.') ?></p>
<div class="feature-grid">
<?php foreach ($applications as $application): ?>
<article>
<?php if ((int)$application['featured'] === 1): ?><b><?= \App\Core\t('Featured') ?></b><?php endif; ?>
<h3><?= htmlspecialchars((string)$application['name'], ENT_QUOTES, 'UTF-8') ?></h3>
<?php if ((string)($application['description'] ?? '') !== ''): ?><p><?= htmlspecialchars((string)$application['description'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<p><?= htmlspecialchars((string)$application['category'], ENT_QUOTES, 'UTF-8') ?><?php if ((string)($application['version'] ?? '') !== ''): ?> · <?= htmlspecialchars((string)$application['version'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?></p>
<a class="button" href="<?= htmlspecialchars((string)$application['url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= \App\Core\t('Open application') ?></a>
</article>
<?php endforeach; ?>
<?php if ($applications === []): ?><p><?= \App\Core\t('No applications are available right now.') ?></p><?php endif; ?>
</div>
</section>
</main>
</body>
</html>

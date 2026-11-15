<!doctype html>
<html lang="<?= \App\Core\Localization::current() ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= \App\Core\t('Application registry') ?> — c0m.me</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body><?php require __DIR__ . '/partials/language-switcher.php'; ?>
<main class="shell">
<nav class="nav">
<a class="brand" href="/dashboard">c0m.me</a>
<div class="admin-nav"><a class="nav-link" href="/admin/users"><?= \App\Core\t('Members') ?></a><a class="nav-link" href="/admin/groups"><?= \App\Core\t('Member groups') ?></a><a class="nav-link" href="/admin/oauth-clients"><?= \App\Core\t('OAuth clients') ?></a><a class="nav-link" href="/admin/settings"><?= \App\Core\t('Settings') ?></a></div>
</nav>
<section class="hero compact">
<span class="eyebrow"><?= \App\Core\t('ADMINISTRATION') ?></span>
<h1><?= \App\Core\t('Application registry') ?></h1>
<p><?= \App\Core\t('Register and manage applications available in the c0m.me ecosystem.') ?></p>
</section>
<?php if ($error !== null): ?><div class="alert" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<section class="admin-panel">
<h2><?= $form['id'] > 0 ? \App\Core\t('Edit application') : \App\Core\t('New application') ?></h2>
<form method="post" action="/admin/applications/save">
<input type="hidden" name="_csrf" value="<?= htmlspecialchars(\App\Core\Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
<label for="app-key"><?= \App\Core\t('Application key') ?></label>
<input id="app-key" name="app_key" value="<?= htmlspecialchars($form['app_key'], ENT_QUOTES, 'UTF-8') ?>" pattern="[a-z0-9][a-z0-9._-]{0,79}" maxlength="80" required>
<label for="app-name"><?= \App\Core\t('Name') ?></label>
<input id="app-name" name="name" value="<?= htmlspecialchars($form['name'], ENT_QUOTES, 'UTF-8') ?>" maxlength="120" required>
<label for="app-description"><?= \App\Core\t('Description') ?></label>
<textarea id="app-description" name="description" maxlength="10000" rows="3"><?= htmlspecialchars($form['description'], ENT_QUOTES, 'UTF-8') ?></textarea>
<label for="app-url"><?= \App\Core\t('Launch URL (HTTPS)') ?></label>
<input id="app-url" name="url" type="url" value="<?= htmlspecialchars($form['url'], ENT_QUOTES, 'UTF-8') ?>" maxlength="500" required>
<label for="app-icon"><?= \App\Core\t('Icon URL or local path (optional)') ?></label>
<input id="app-icon" name="icon_path" value="<?= htmlspecialchars($form['icon_path'], ENT_QUOTES, 'UTF-8') ?>" maxlength="255">
<label for="app-category"><?= \App\Core\t('Category') ?></label>
<input id="app-category" name="category" value="<?= htmlspecialchars($form['category'], ENT_QUOTES, 'UTF-8') ?>" maxlength="80">
<label for="app-version"><?= \App\Core\t('Version') ?></label>
<input id="app-version" name="version" value="<?= htmlspecialchars($form['version'], ENT_QUOTES, 'UTF-8') ?>" maxlength="40">
<label for="app-status"><?= \App\Core\t('Status') ?></label>
<select id="app-status" name="status">
<?php foreach (['active', 'maintenance', 'disabled'] as $status): ?>
<option value="<?= $status ?>" <?= $form['status'] === $status ? 'selected' : '' ?>><?= \App\Core\t($status) ?></option>
<?php endforeach; ?>
</select>
<label for="app-access"><?= \App\Core\t('Access level') ?></label>
<select id="app-access" name="access_level">
<?php foreach (['public', 'members', 'restricted'] as $accessLevel): ?>
<option value="<?= $accessLevel ?>" <?= $form['access_level'] === $accessLevel ? 'selected' : '' ?>><?= \App\Core\t($accessLevel) ?></option>
<?php endforeach; ?>
</select>
<fieldset>
<legend><?= \App\Core\t('Granted groups (required for restricted applications)') ?></legend>
<?php foreach ($groups as $group): ?>
<label><input type="checkbox" name="group_ids[]" value="<?= (int)$group['id'] ?>" <?= in_array((int)$group['id'], $form['group_ids'], true) ? 'checked' : '' ?>> <?= htmlspecialchars((string)$group['name'], ENT_QUOTES, 'UTF-8') ?></label>
<?php endforeach; ?>
<?php if ($groups === []): ?><p><?= \App\Core\t('Create a member group before restricting an application.') ?></p><?php endif; ?>
</fieldset>
<label><input type="checkbox" name="featured" value="1" <?= $form['featured'] === '1' ? 'checked' : '' ?>> <?= \App\Core\t('Featured') ?></label>
<button class="button" type="submit"><?= \App\Core\t('Save application') ?></button>
<a class="nav-link" href="/admin/applications"><?= \App\Core\t('Cancel') ?></a>
</form>
</section>
<section>
<h2><?= \App\Core\t('Registered applications') ?></h2>
<div class="admin-list">
<?php foreach ($apps as $app): ?>
<article>
<b><?= htmlspecialchars((string)$app['app_key'], ENT_QUOTES, 'UTF-8') ?></b>
<h3><?= htmlspecialchars((string)$app['name'], ENT_QUOTES, 'UTF-8') ?></h3>
<p><?= htmlspecialchars((string)($app['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
<p><?= \App\Core\t('Status') ?>: <?= \App\Core\t((string)$app['status']) ?> · <?= \App\Core\t('Access level') ?>: <?= \App\Core\t((string)$app['access_level']) ?></p>
<p><?= htmlspecialchars((string)$app['category'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string)($app['version'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
<a class="nav-link" href="/admin/applications?edit=<?= (int)$app['id'] ?>"><?= \App\Core\t('Edit application') ?></a>
</article>
<?php endforeach; ?>
<?php if ($apps === []): ?><p><?= \App\Core\t('No applications registered.') ?></p><?php endif; ?>
</div>
</section>
</main>
</body>
</html>
<!doctype html>
<html lang="<?= \App\Core\Localization::current() ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>User administration — c0m.me</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body><?php require __DIR__ . '/partials/language-switcher.php'; ?>
<main class="shell">
<nav class="nav"><a class="brand" href="/dashboard">c0m.me</a><a class="nav-link" href="/admin/settings"><?= \App\Core\t("Settings") ?></a></nav>
<section class="hero compact">
<span class="eyebrow"><?= \App\Core\t("ADMINISTRATION") ?></span>
<h1><?= \App\Core\t("Members") ?></h1>
<p><?= \App\Core\t("Manage member account status and role assignments. Permissions are enforced on the server.") ?></p>
</section>
<section class="feature-grid" style="grid-template-columns:1fr">
<?php foreach ($users as $user): ?>
<article>
<b><?= htmlspecialchars(implode(', ', array_map(static fn(string $role): string => \App\Core\t($role), $user['role_keys'] ?: ['NO ROLE'])), ENT_QUOTES, 'UTF-8') ?></b>
<h2><?= htmlspecialchars($user['display_name'] ?: $user['email'], ENT_QUOTES, 'UTF-8') ?></h2>
<p><?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?><br><?= \App\Core\t("Status") ?>: <?= htmlspecialchars(\App\Core\t($user['status']), ENT_QUOTES, 'UTF-8') ?></p>
<?php if ($canManage): ?>
<form method="post" action="/admin/users/status">
<input type="hidden" name="_csrf" value="<?= htmlspecialchars(\App\Core\Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
<label><?= \App\Core\t("Status") ?></label>
<select name="status">
<?php foreach (['active','pending','suspended','banned'] as $status): ?>
<option value="<?= $status ?>" <?= $user['status'] === $status ? 'selected' : '' ?>><?= \App\Core\t($status) ?></option>
<?php endforeach; ?>
</select>
<button class="button" type="submit"><?= \App\Core\t("Update status") ?></button>
</form>
<?php endif; ?>
<?php if ($canAssignRoles): ?>
<form method="post" action="/admin/users/role">
<input type="hidden" name="_csrf" value="<?= htmlspecialchars(\App\Core\Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
<fieldset>
<legend><?= \App\Core\t("Roles (select one or more)") ?></legend>
<?php foreach (['member','admin','super_admin'] as $role): ?>
<label>
<input type="checkbox" name="roles[]" value="<?= $role ?>" <?= in_array($role, $user['role_keys'], true) ? 'checked' : '' ?>>
<?= \App\Core\t($role) ?>
</label>
<?php endforeach; ?>
</fieldset>
<button class="button" type="submit"><?= \App\Core\t("Save roles") ?></button>
</form>
<?php endif; ?>
</article>
<?php endforeach; ?>
</section>
</main>
</body>
</html>

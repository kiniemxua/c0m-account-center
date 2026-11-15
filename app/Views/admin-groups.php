<!doctype html>
<html lang="<?= \App\Core\Localization::current() ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= \App\Core\t('Member groups') ?> — c0m.me</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body><?php require __DIR__ . '/partials/language-switcher.php'; ?>
<main class="shell">
<nav class="nav"><a class="brand" href="/dashboard">c0m.me</a><div class="admin-nav"><a class="nav-link" href="/admin/users"><?= \App\Core\t('Members') ?></a><a class="nav-link" href="/admin/applications"><?= \App\Core\t('Applications') ?></a><a class="nav-link" href="/admin/groups"><?= \App\Core\t('Member groups') ?></a><a class="nav-link" href="/admin/oauth-clients"><?= \App\Core\t('OAuth clients') ?></a><?php if (\App\Core\Authorization::hasPermission((int)($_SESSION['user_id'] ?? 0), 'billing.manage')): ?><a class="nav-link" href="/admin/plans"><?= \App\Core\t('Subscription plans') ?></a><?php endif; ?><a class="nav-link" href="/admin/settings"><?= \App\Core\t('Settings') ?></a></div></nav>
<section class="hero compact"><span class="eyebrow"><?= \App\Core\t('ADMINISTRATION') ?></span><h1><?= \App\Core\t('Member groups') ?></h1><p><?= \App\Core\t('Assign active members to groups for restricted application grants.') ?></p></section>
<?php if ($error !== null): ?><div class="alert" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<section class="admin-panel">
<h2><?= \App\Core\t('Create group') ?></h2>
<form method="post" action="/admin/groups">
<input type="hidden" name="_csrf" value="<?= htmlspecialchars(\App\Core\Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="action" value="create">
<label for="group-key"><?= \App\Core\t('Group key') ?></label><input id="group-key" name="group_key" maxlength="80" pattern="[a-z0-9][a-z0-9._-]{0,79}" required>
<label for="group-name"><?= \App\Core\t('Name') ?></label><input id="group-name" name="name" maxlength="120" required>
<label for="group-description"><?= \App\Core\t('Description') ?></label><textarea id="group-description" name="description" maxlength="2000" rows="2"></textarea>
<button class="button" type="submit"><?= \App\Core\t('Create group') ?></button>
</form>
</section>
<section class="admin-list">
<?php foreach ($groups as $group): ?>
<article>
<b><?= htmlspecialchars((string)$group['group_key'], ENT_QUOTES, 'UTF-8') ?></b>
<h2><?= htmlspecialchars((string)$group['name'], ENT_QUOTES, 'UTF-8') ?></h2>
<form method="post" action="/admin/groups">
<input type="hidden" name="_csrf" value="<?= htmlspecialchars(\App\Core\Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="action" value="update">
<input type="hidden" name="group_id" value="<?= (int)$group['id'] ?>">
<label for="group-name-<?= (int)$group['id'] ?>"><?= \App\Core\t('Name') ?></label><input id="group-name-<?= (int)$group['id'] ?>" name="name" value="<?= htmlspecialchars((string)$group['name'], ENT_QUOTES, 'UTF-8') ?>" maxlength="120" required>
<label for="group-description-<?= (int)$group['id'] ?>"><?= \App\Core\t('Description') ?></label><textarea id="group-description-<?= (int)$group['id'] ?>" name="description" maxlength="2000" rows="2"><?= htmlspecialchars((string)($group['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
<label for="group-status-<?= (int)$group['id'] ?>"><?= \App\Core\t('Status') ?></label>
<select id="group-status-<?= (int)$group['id'] ?>" name="status"><option value="active" <?= $group['status'] === 'active' ? 'selected' : '' ?>><?= \App\Core\t('active') ?></option><option value="disabled" <?= $group['status'] === 'disabled' ? 'selected' : '' ?>><?= \App\Core\t('disabled') ?></option></select>
<button class="button" type="submit"><?= \App\Core\t('Save group') ?></button>
</form>
<?php if ($group['status'] === 'active'): ?>
<form method="post" action="/admin/groups">
<input type="hidden" name="_csrf" value="<?= htmlspecialchars(\App\Core\Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="action" value="members">
<input type="hidden" name="group_id" value="<?= (int)$group['id'] ?>">
<label for="group-members-<?= (int)$group['id'] ?>"><?= \App\Core\t('Group members') ?></label>
<select id="group-members-<?= (int)$group['id'] ?>" name="user_ids[]" multiple size="8">
<?php foreach ($users as $user): ?>
<option value="<?= (int)$user['id'] ?>" <?= in_array((int)$user['id'], $group['member_ids'], true) ? 'selected' : '' ?>><?= htmlspecialchars((string)($user['display_name'] ?: $user['email']), ENT_QUOTES, 'UTF-8') ?></option>
<?php endforeach; ?>
</select>
<button class="button" type="submit"><?= \App\Core\t('Save group members') ?></button>
</form>
<?php else: ?><p><?= \App\Core\t('Disabled groups do not grant application access.') ?></p><?php endif; ?>
</article>
<?php endforeach; ?>
<?php if ($groups === []): ?><p><?= \App\Core\t('No groups created.') ?></p><?php endif; ?>
</section>
</main>
</body>
</html>
<?php $sessions = $sessions ?? []; ?>
<!doctype html>
<html lang="<?= \App\Core\Localization::current() ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= \App\Core\t('Active devices') ?> — c0m.me</title><link rel="stylesheet" href="/assets/css/app.css"></head>
<body><?php require __DIR__ . '/partials/language-switcher.php'; ?><main class="shell">
<nav class="nav"><a class="brand" href="/dashboard">c0m.me</a><div><a class="nav-link" href="/dashboard"><?= \App\Core\t("Dashboard") ?></a><a class="nav-link" href="/account"><?= \App\Core\t("Account") ?></a><a class="nav-link" href="/login-history"><?= \App\Core\t("Login history") ?></a></div></nav>
<section class="hero compact"><span class="eyebrow"><?= \App\Core\t("ACCOUNT SECURITY") ?></span><h1><?= \App\Core\t("Active devices") ?></h1><p><?= \App\Core\t("Review devices signed in to your account. Device and browser details are informational and are not used as authentication proof.") ?></p>
<form method="post" action="/sessions/revoke-others"><input type="hidden" name="_csrf" value="<?= htmlspecialchars(\App\Core\Csrf::token(), ENT_QUOTES, 'UTF-8') ?>"><button class="button danger" type="submit"><?= \App\Core\t("Sign out other devices") ?></button></form></section>
<section class="feature-grid" style="grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr));align-items:start">
<?php foreach ($sessions as $item): ?><article>
<b><?= $item['is_current'] ? \App\Core\t('THIS DEVICE') : \App\Core\t('ACTIVE SESSION') ?></b>
<h2><?= htmlspecialchars($item['user_agent'] ?: \App\Core\t('Unknown browser'), ENT_QUOTES, 'UTF-8') ?></h2>
<p><?= \App\Core\t('IP:') ?> <?= htmlspecialchars($item['ip_display'], ENT_QUOTES, 'UTF-8') ?><br><?= \App\Core\t('Last activity:') ?> <?= htmlspecialchars($item['last_activity'], ENT_QUOTES, 'UTF-8') ?><br><?= \App\Core\t('Expires:') ?> <?= htmlspecialchars($item['expires_at'], ENT_QUOTES, 'UTF-8') ?></p>
<?php if ($item['is_current']): ?><span class="alert success"><?= \App\Core\t('Current session') ?></span><?php else: ?><form method="post" action="/sessions/revoke"><input type="hidden" name="_csrf" value="<?= \App\Core\Csrf::token() ? htmlspecialchars(\App\Core\Csrf::token(), ENT_QUOTES, 'UTF-8') : '' ?>"><input type="hidden" name="session_id" value="<?= htmlspecialchars($item['id'], ENT_QUOTES, 'UTF-8') ?>"><button class="button secondary" type="submit"><?= \App\Core\t("Revoke session") ?></button></form><?php endif; ?>
</article><?php endforeach; ?>
<?php if (!$sessions): ?><article><h2><?= \App\Core\t("No active sessions found.") ?></h2></article><?php endif; ?>
</section></main></body></html>

<!doctype html>
<html lang="<?= \App\Core\Localization::current() ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= \App\Core\t("Login history") ?> — c0m.me</title><link rel="stylesheet" href="/assets/css/app.css"></head>
<body><?php require __DIR__ . '/partials/language-switcher.php'; ?><main class="shell">
<nav class="nav"><a class="brand" href="/dashboard">c0m.me</a><div><a class="nav-link" href="/dashboard"><?= \App\Core\t("Dashboard") ?></a><a class="nav-link" href="/account"><?= \App\Core\t("Account") ?></a><a class="nav-link" href="/sessions"><?= \App\Core\t("Active devices") ?></a></div></nav>
<section class="hero compact"><span class="eyebrow"><?= \App\Core\t("ACCOUNT SECURITY") ?></span><h1><?= \App\Core\t("Login history") ?></h1><p><?= \App\Core\t("Recent successful sign-ins associated with this account.") ?></p></section>
<section class="feature-grid" style="grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr));align-items:start">
<?php foreach ($history as $item): ?><article><b><?= $item['success'] ? \App\Core\t('SUCCESSFUL SIGN-IN') : \App\Core\t('FAILED SIGN-IN') ?></b><h2><?= htmlspecialchars($item['success'] ? \App\Core\t('Successful sign-in') : \App\Core\t('Failed sign-in'), ENT_QUOTES, 'UTF-8') ?></h2><p><?= htmlspecialchars($item['ip_display'], ENT_QUOTES, 'UTF-8') ?><br><?= htmlspecialchars($item['user_agent'] ?: \App\Core\t('Unknown browser'), ENT_QUOTES, 'UTF-8') ?><br><?= htmlspecialchars($item['created_at'], ENT_QUOTES, 'UTF-8') ?></p></article><?php endforeach; ?>
<?php if (!$history): ?><article><h2><?= \App\Core\t("No login history is available yet.") ?></h2></article><?php endif; ?>
</section></main></body></html>

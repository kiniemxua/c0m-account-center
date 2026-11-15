<!doctype html>
<html lang="<?= \App\Core\Localization::current() ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= \App\Core\t('Audit log') ?> — c0m.me</title><link rel="stylesheet" href="/assets/css/app.css"></head>
<body><?php require __DIR__ . '/partials/language-switcher.php'; ?><main class="shell">
<nav class="nav"><a class="brand" href="/dashboard">c0m.me</a><div class="admin-nav"><a class="nav-link" href="/admin/users"><?= \App\Core\t('Members') ?></a><a class="nav-link" href="/admin/applications"><?= \App\Core\t('Applications') ?></a><a class="nav-link" href="/admin/groups"><?= \App\Core\t('Member groups') ?></a><a class="nav-link" href="/admin/oauth-clients"><?= \App\Core\t('OAuth clients') ?></a><a class="nav-link" href="/admin/settings"><?= \App\Core\t('Settings') ?></a></div></nav>
<section class="hero compact"><span class="eyebrow"><?= \App\Core\t('ADMINISTRATION') ?></span><h1><?= \App\Core\t('Audit log') ?></h1><p><?= \App\Core\t('Review recent security and administration events.') ?></p></section>
<section class="admin-list">
<?php foreach ($events as $event): ?><article>
<b><?= htmlspecialchars(\App\Core\t((string)$event['action']), ENT_QUOTES, 'UTF-8') ?></b>
<p><?= \App\Core\t('Actor') ?>: <?= htmlspecialchars($event['display_name'] ?: ($event['email'] ?: \App\Core\t('System')), ENT_QUOTES, 'UTF-8') ?><br>
<?= \App\Core\t('Subject') ?>: <?= htmlspecialchars(trim((string)($event['subject_type'] ?? '') . ' ' . (string)($event['subject_id'] ?? '')) ?: '—', ENT_QUOTES, 'UTF-8') ?><br>
<?= \App\Core\t('IP:') ?> <?= htmlspecialchars($event['ip_display'], ENT_QUOTES, 'UTF-8') ?><br>
<?= htmlspecialchars($event['created_at'], ENT_QUOTES, 'UTF-8') ?></p>
<?php if (!empty($event['metadata'])): ?><details><summary><?= \App\Core\t('Details') ?></summary><pre><?= htmlspecialchars((string)$event['metadata'], ENT_QUOTES, 'UTF-8') ?></pre></details><?php endif; ?>
</article><?php endforeach; ?>
<?php if (!$events): ?><article><p><?= \App\Core\t('No audit events found.') ?></p></article><?php endif; ?>
</section></main></body></html>
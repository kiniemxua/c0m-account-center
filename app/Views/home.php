<!doctype html>
<html lang="<?= \App\Core\Localization::current() ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#f6f8fc"><title>c0m.me — <?= \App\Core\t('Your digital home') ?></title><link rel="stylesheet" href="/assets/css/app.css"></head>
<body><?php require __DIR__ . '/partials/language-switcher.php'; ?>
<main class="shell">
<nav class="nav"><a class="brand" href="/">c0m.me</a><div><a class="nav-link" href="/login"><?= \App\Core\t("Sign in") ?></a><a class="button" style="margin:0;padding:9px 14px" href="/register"><?= \App\Core\t("Create account") ?></a></div></nav>
<section class="hero">
<span class="eyebrow"><?= \App\Core\t("ONE ACCOUNT · YOUR ECOSYSTEM") ?></span>
<h1><?= \App\Core\t("Everything connected.") ?><br><span><?= \App\Core\t("One place to begin.") ?></span></h1>
<p><?= \App\Core\t("Your central account for c0m.me applications, services and security.") ?></p>
<a class="button" href="/login"><?= \App\Core\t("Open Account Center") ?> <span aria-hidden="true">→</span></a>
</section>
<section class="feature-grid">
<article><b>01 · <?= \App\Core\t("ACCOUNT") ?></b><h2><?= \App\Core\t("Your account") ?></h2><p><?= \App\Core\t("Manage your profile, sessions and account security.") ?></p></article>
<article><b>02 · <?= \App\Core\t("APPLICATIONS") ?></b><h2><?= \App\Core\t("Your apps") ?></h2><p><?= \App\Core\t("Discover connected applications from one app hub.") ?></p></article>
<article><b>03 · <?= \App\Core\t("SECURITY") ?></b><h2><?= \App\Core\t("Your security") ?></h2><p><?= \App\Core\t("Review active devices and protect your account.") ?></p></article>
</section>
<footer>© <?= date('Y') ?> c0m.me · <?= \App\Core\t("Account Center") ?></footer>
</main></body></html>
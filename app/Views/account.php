<!doctype html>
<html lang="<?= \App\Core\Localization::current() ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= \App\Core\t("Account settings") ?> — c0m.me</title><link rel="stylesheet" href="/assets/css/app.css"></head>
<body><?php require __DIR__ . '/partials/language-switcher.php'; ?><main class="shell">
<nav class="nav"><a class="brand" href="/dashboard">c0m.me</a><a class="nav-link" href="/dashboard"><?= \App\Core\t("Dashboard") ?></a></nav>
<section class="hero compact"><span class="eyebrow"><?= \App\Core\t("ACCOUNT CENTER") ?></span><h1><?= \App\Core\t("Account settings") ?></h1><p><?= \App\Core\t("Update your profile and protect your account.") ?></p></section>
<?php if ($saved): ?><div class="alert" style="background:#eaf7ef;color:#23643c"><?= \App\Core\t("Profile saved successfully.") ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert"><?= htmlspecialchars(\App\Core\t($error), ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<section class="feature-grid" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr));align-items:start">
<article><b><?= \App\Core\t("PROFILE") ?></b><h2><?= \App\Core\t("Personal information") ?></h2><form method="post" action="/account/profile">
<input type="hidden" name="_csrf" value="<?= htmlspecialchars(\App\Core\Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
<label><?= \App\Core\t("Email address") ?></label><input type="email" value="<?= htmlspecialchars($account['email'], ENT_QUOTES, 'UTF-8') ?>" disabled>
<label><?= \App\Core\t("Display name") ?></label><input name="display_name" maxlength="100" value="<?= htmlspecialchars($account['display_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
<label><?= \App\Core\t("Username") ?></label><input name="username" maxlength="30" pattern="[a-zA-Z0-9_]{3,30}" value="<?= htmlspecialchars($account['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= htmlspecialchars(\App\\Core\\t('optional'), ENT_QUOTES, 'UTF-8') ?>">
<label><?= \App\Core\t("Language") ?></label><select name="language"><option value="vi" <?= $account['language'] === 'vi' ? 'selected' : '' ?>><?= \App\Core\t('Vietnamese') ?></option><option value="en" <?= $account['language'] === 'en' ? 'selected' : '' ?>><?= \App\Core\t('English') ?></option></select>
<label><?= \App\Core\t("Timezone") ?></label><select name="timezone"><?php foreach (['Asia/Ho_Chi_Minh','UTC','Asia/Singapore','America/Los_Angeles','Europe/London'] as $zone): ?><option value="<?= $zone ?>" <?= $account['timezone'] === $zone ? 'selected' : '' ?>><?= $zone ?></option><?php endforeach; ?></select>
<button class="button full" type="submit"><?= \App\Core\t("Save profile") ?></button></form></article>
<article><b><?= \App\Core\t("SECURITY") ?></b><h2><?= \App\Core\t("Change password") ?></h2><p><?= \App\Core\t("Changing your password signs out every device and sends a security notification to your email.") ?></p><form method="post" action="/account/password">
<input type="hidden" name="_csrf" value="<?= htmlspecialchars(\App\Core\Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
<label><?= \App\Core\t("Current password") ?></label><input type="password" name="current_password" autocomplete="current-password" required>
<label><?= \App\Core\t("New password") ?></label><input type="password" name="new_password" minlength="12" autocomplete="new-password" required>
<label><?= \App\Core\t("Confirm new password") ?></label><input type="password" name="confirm_password" minlength="12" autocomplete="new-password" required>
<button class="button full" type="submit"><?= \App\Core\t("Change password") ?></button></form></article>
</section></main></body></html>

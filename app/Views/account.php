<!doctype html>
<html lang="<?= ($account['language'] ?? 'vi') === 'vi' ? 'vi' : 'en' ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Account settings — c0m.me</title><link rel="stylesheet" href="/assets/css/app.css"></head>
<body><main class="shell">
<nav class="nav"><a class="brand" href="/dashboard">c0m.me</a><a class="nav-link" href="/dashboard">Dashboard</a></nav>
<section class="hero compact"><span class="eyebrow">ACCOUNT CENTER</span><h1>Account settings</h1><p>Update your profile and protect your account.</p></section>
<?php if ($saved): ?><div class="alert" style="background:#eaf7ef;color:#23643c">Profile saved successfully.</div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<section class="feature-grid" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr));align-items:start">
<article><b>PROFILE</b><h2>Personal information</h2><form method="post" action="/account/profile">
<input type="hidden" name="_csrf" value="<?= htmlspecialchars(\App\Core\Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
<label>Email address</label><input type="email" value="<?= htmlspecialchars($account['email'], ENT_QUOTES, 'UTF-8') ?>" disabled>
<label>Display name</label><input name="display_name" maxlength="100" value="<?= htmlspecialchars($account['display_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
<label>Username</label><input name="username" maxlength="30" pattern="[a-zA-Z0-9_]{3,30}" value="<?= htmlspecialchars($account['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="optional">
<label>Language</label><select name="language"><option value="vi" <?= $account['language'] === 'vi' ? 'selected' : '' ?>>Tiếng Việt</option><option value="en" <?= $account['language'] === 'en' ? 'selected' : '' ?>>English</option></select>
<label>Timezone</label><select name="timezone"><?php foreach (['Asia/Ho_Chi_Minh','UTC','Asia/Singapore','America/Los_Angeles','Europe/London'] as $zone): ?><option value="<?= $zone ?>" <?= $account['timezone'] === $zone ? 'selected' : '' ?>><?= $zone ?></option><?php endforeach; ?></select>
<button class="button full" type="submit">Save profile</button></form></article>
<article><b>SECURITY</b><h2>Change password</h2><p>Changing your password signs out every device and sends a security notification to your email.</p><form method="post" action="/account/password">
<input type="hidden" name="_csrf" value="<?= htmlspecialchars(\App\Core\Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
<label>Current password</label><input type="password" name="current_password" autocomplete="current-password" required>
<label>New password</label><input type="password" name="new_password" minlength="12" autocomplete="new-password" required>
<label>Confirm new password</label><input type="password" name="confirm_password" minlength="12" autocomplete="new-password" required>
<button class="button full" type="submit">Change password</button></form></article>
</section></main></body></html>

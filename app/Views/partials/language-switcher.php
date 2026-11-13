<?php
use App\Core\Csrf;
use App\Core\Localization;
$currentLocale = Localization::current();
$returnTo = (string)($_SERVER['REQUEST_URI'] ?? '/');
?>
<form class="language-switcher" method="post" action="/language" aria-label="<?= htmlspecialchars(\App\Core\t('Language'), ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="_csrf" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') ?>">
<label for="account-language"><?= htmlspecialchars(\App\Core\t('Language'), ENT_QUOTES, 'UTF-8') ?></label>
<select id="account-language" name="language" onchange="this.form.requestSubmit()">
<option value="vi" <?= $currentLocale === 'vi' ? 'selected' : '' ?>>Tiếng Việt</option>
<option value="en" <?= $currentLocale === 'en' ? 'selected' : '' ?>>English</option>
</select>
</form>
<script>
(() => {
 const key = 'c0m-form:' + location.pathname;
 const form = document.querySelector('.language-switcher');
 if (form) form.addEventListener('submit', () => {
   const values = [];
   document.querySelectorAll('input,select,textarea').forEach((el) => {
     if (!el.name || ['password','hidden','file'].includes(el.type) || el === form.querySelector('[name=language]') || el.name === '_csrf' || el.name === 'token') return;
     values.push({name:el.name,type:el.type,value:el.type === 'checkbox' || el.type === 'radio' ? el.checked : el.value});
   });
   try { sessionStorage.setItem(key, JSON.stringify(values)); } catch (_) {}
 });
 try {
   const saved = JSON.parse(sessionStorage.getItem(key) || '[]');
   if (Array.isArray(saved) && saved.length) {
     saved.forEach((v) => {
       const el = document.querySelector('[name="' + CSS.escape(v.name) + '"]');
       if (!el || el.type === 'password' || el.type === 'hidden') return;
       if (el.type === 'checkbox' || el.type === 'radio') el.checked = !!v.value; else el.value = v.value;
     });
     sessionStorage.removeItem(key);
   }
 } catch (_) {}
})();
</script>
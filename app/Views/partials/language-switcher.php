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
<script>window.C0M_I18N = <?= json_encode(Localization::javascriptMessages(['Confirm','Cancel','Saved','An error occurred.','Please try again.']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script>
(() => {
 const key = 'c0m-form:' + location.pathname;
 const switcher = document.querySelector('form.language-switcher');
 if (switcher) switcher.addEventListener('submit', () => {
   const values = [];
   Array.from(document.forms).forEach((form, formIndex) => {
     if (form.classList.contains('language-switcher')) return;
     Array.from(form.elements).forEach((el, elementIndex) => {
       if (!['INPUT','SELECT','TEXTAREA'].includes(el.tagName) || !el.name || ['password','hidden','file','submit','button','reset'].includes(el.type) || el.name === 'token' || el.name === '_csrf') return;
       values.push({formIndex, elementIndex, type:el.type, value:el.type === 'checkbox' || el.type === 'radio' ? el.checked : el.value});
     });
   });
   try { sessionStorage.setItem(key, JSON.stringify(values)); } catch (_) {}
 });
 try {
   const saved = JSON.parse(sessionStorage.getItem(key) || '[]');
   if (Array.isArray(saved) && saved.length) {
     saved.forEach((v) => {
       const form = document.forms[v.formIndex];
       const el = form && form.elements[v.elementIndex];
       if (!el || el.type === 'password' || el.type === 'hidden' || el.type === 'file') return;
       if (el.type === 'checkbox' || el.type === 'radio') el.checked = !!v.value; else el.value = v.value;
     });
     sessionStorage.removeItem(key);
   }
 } catch (_) {}
})();
</script>
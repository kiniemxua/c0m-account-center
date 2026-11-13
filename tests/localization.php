<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) require $file;
    }
});
function localeCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$vi = App\Core\Localization::all('vi');
$en = App\Core\Localization::all('en');
localeCheck($vi !== [] && $en !== [], 'Both translation catalogs must load.');
localeCheck(App\Core\Localization::current() === 'vi', 'Vietnamese must be the default locale.');
$missingVi = array_diff(array_keys($en), array_keys($vi));
$missingEn = array_diff(array_keys($vi), array_keys($en));
localeCheck($missingVi === [] && $missingEn === [], 'Translation catalogs must have identical key coverage.');
$partialSource = file_get_contents(BASE_PATH . '/app/Views/partials/language-switcher.php');
localeCheck($partialSource !== false && str_contains($partialSource, 'window.C0M_I18N') && str_contains($partialSource, 'sessionStorage') && str_contains($partialSource, 'formIndex') && str_contains($partialSource, "'password'"), 'JavaScript must receive localized messages and preserve non-password form state.');
$bootstrapSource = file_get_contents(BASE_PATH . '/app/Core/bootstrap.php');
localeCheck($bootstrapSource !== false && str_contains($bootstrapSource, "'/lang/'") && str_contains($bootstrapSource, 'c0m_lang'), 'HTTPS bootstrap errors must use the selected language catalog.');
$installerSource = file_get_contents(BASE_PATH . '/public/installer/index.php');
localeCheck($installerSource !== false && str_contains($installerSource, '$installerLocale') && str_contains($installerSource, '$installerT'), 'Installer must use the language catalog and locale switcher.');

$controllerFiles = glob(BASE_PATH . '/app/Controllers/*.php') ?: [];
foreach ($controllerFiles as $controllerFile) {
    $controllerSource = file_get_contents($controllerFile);
    localeCheck($controllerSource !== false, 'Controller source must be readable.');
    localeCheck(!preg_match('~\\$(?:error|message)\\s*=\\s*["\\x27]~', $controllerSource), 'Controllers must assign translated messages, not hardcoded interface copy: ' . basename($controllerFile));
}
$sourceFiles = array_merge(
    glob(BASE_PATH . '/app/Views/*.php') ?: [],
    glob(BASE_PATH . '/app/Views/partials/*.php') ?: [],
    glob(BASE_PATH . '/app/Controllers/*.php') ?: [],
    glob(BASE_PATH . '/app/Core/*.php') ?: [],
    [BASE_PATH . '/public/installer/index.php']
);
$requiredKeys = [];
foreach ($sourceFiles as $sourceFile) {
    $source = file_get_contents($sourceFile);
    localeCheck($source !== false, 'Application source must be readable: ' . basename($sourceFile));
    if (preg_match_all('~(?:\\bt|Localization::translate|\\$installerT)\\s*\\(\\s*(["\\x27])(.*?)\\1~s', $source, $matches)) {
        foreach ($matches[2] as $key) $requiredKeys[$key] = true;
    }
    if (preg_match_all('~\\$(?:error|message)\\s*=\\s*(["\\x27])(.*?)\\1~s', $source, $messages)) {
        foreach ($messages[2] as $key) $requiredKeys[$key] = true;
    }
}
foreach (array_keys($requiredKeys) as $key) {
    localeCheck(array_key_exists($key, $vi), 'Missing Vietnamese translation: ' . $key);
    localeCheck(array_key_exists($key, $en), 'Missing English translation: ' . $key);
}

$authorizationSource = file_get_contents(BASE_PATH . '/app/Core/Authorization.php');
localeCheck($authorizationSource !== false && str_contains($authorizationSource, "t('You do not have permission"), 'Authorization errors must be localized.');
$routerSource = file_get_contents(BASE_PATH . '/public/index.php');
localeCheck($routerSource !== false && str_contains($routerSource, "'/admin/audit'"), 'The audit log route must be registered.');
localeCheck(is_file(BASE_PATH . '/app/Views/admin-audit.php'), 'The localized audit log view must exist.');
$viewFiles = glob(BASE_PATH . '/app/Views/*.php') ?: [];
foreach ($viewFiles as $viewFile) {
    $viewSource = file_get_contents($viewFile);
    localeCheck($viewSource !== false && str_contains($viewSource, 'Localization::current()'), 'Every application page must declare the active locale: ' . basename($viewFile));
    $plainHtml = preg_replace('~<\\?(?:php|=).*?\\?>~s', '', $viewSource) ?? $viewSource;
    foreach (['New here?', 'Create account</button>', 'User administration — c0m.me', 'Forgot password — c0m.me', 'Reset password — c0m.me', 'Verify email — c0m.me', 'Your digital home</title>', 'App Hub</h2>', 'Last activity:', 'Expires:', 'placeholder="optional"', 'Center</footer>'] as $rawCopy) { localeCheck(!str_contains($plainHtml, $rawCopy), 'Hardcoded interface copy remains in ' . basename($viewFile) . ': ' . $rawCopy); }
    localeCheck(!str_contains($viewSource, 'Localization::translate($error)') && !str_contains($viewSource, 't($error)'), 'Views must not translate already-localized controller messages twice: ' . basename($viewFile));
    localeCheck(str_contains($viewSource, "partials/language-switcher.php"), 'Every application page must expose the language switcher: ' . basename($viewFile));
}
localeCheck(str_contains($vi['Welcome back'] ?? '', 'Chào mừng'), 'Vietnamese UTF-8 text must be preserved.');
localeCheck(($en['Welcome back'] ?? '') === 'Welcome back', 'English catalog must contain English copy.');
localeCheck(App\Core\Localization::translate('unknown.internal.key') === 'Thông báo hệ thống', 'Vietnamese fallback must be localized and must not expose raw keys.');
localeCheck(App\Core\Localization::translate('An untranslated interface phrase') === 'Thông báo hệ thống', 'Readable source phrases must not leak when a translation is missing.');
App\Core\Localization::set('vi');
localeCheck(App\Core\Localization::translate('Hello {name}', ['name' => 'Lan']) === 'Xin chào Lan', 'Vietnamese placeholder replacement must work.');
App\Core\Localization::set('en');
localeCheck(App\Core\Localization::translate('Hello {name}', ['name' => 'Lan']) === 'Hello Lan', 'English placeholder replacement must work.');
localeCheck(App\Core\Localization::translate('unknown.internal.key') === 'System message', 'English fallback must be localized and must not expose raw keys.');
localeCheck(App\Core\Localization::translate('An untranslated interface phrase') === 'System message', 'English fallback must not echo untranslated source phrases.');
foreach (['vi','en','xx'] as $locale) {
    $v = App\Core\EmailTemplates::verification($locale, 'Lan', 'https://example.test/verify?token=abc');
    $p = App\Core\EmailTemplates::passwordReset($locale, 'Lan', 'https://example.test/reset?token=def');
    $s = App\Core\EmailTemplates::passwordChanged($locale);
    localeCheck(str_contains($v['text'], 'https://example.test/verify?token=abc'), 'Verification URL must remain unchanged.');
if ($locale === 'vi') localeCheck(str_contains($v['text'], 'Xin chào'), 'Vietnamese verification email must be localized.');
if ($locale === 'en') localeCheck(str_contains($v['text'], 'Hello'), 'English verification email must be localized.');
    if ($locale === 'xx') localeCheck(str_contains($v['text'], 'Xin chào'), 'Unsupported email locales must safely fall back to Vietnamese.');
    localeCheck(str_contains($p['text'], 'https://example.test/reset?token=def'), 'Reset URL must remain unchanged.');
    localeCheck($v['subject'] !== '' && $p['subject'] !== '' && $s['subject'] !== '', 'Localized email subjects must be present.');
    if ($locale === 'vi') localeCheck(str_contains($p['text'], 'Xin chào') && str_contains($s['text'], 'Mật khẩu'), 'Reset and security emails must be Vietnamese.');
    if ($locale === 'en') localeCheck(str_contains($p['text'], 'Hello') && str_contains($s['text'], 'password'), 'Reset and security emails must be English.');
}
App\Core\Localization::set('vi');
$js = App\Core\Localization::javascriptMessages(['Confirm', 'Cancel']);
localeCheck(($js['Confirm'] ?? '') === 'Xác nhận', 'JavaScript message payload must use the active locale.');
App\Core\Localization::set('en');
$js = App\Core\Localization::javascriptMessages(['Confirm', 'Cancel']);
localeCheck(($js['Confirm'] ?? '') === 'Confirm', 'English JavaScript messages must use the active locale.');
App\Core\Localization::set('vi');
App\Core\Localization::persistGuest('en');
localeCheck(App\Core\Localization::current() === 'en', 'Guest locale selection must update immediately.');
App\Core\Localization::persistGuest('vi');
localeCheck(App\Core\Localization::current() === 'vi', 'Guest locale can return to Vietnamese.');
echo "Localization catalog and email template tests passed.\n";

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
localeCheck($partialSource !== false && str_contains($partialSource, 'window.C0M_I18N') && str_contains($partialSource, 'sessionStorage'), 'JavaScript must receive localized messages and preserve non-password form state.');
$installerSource = file_get_contents(BASE_PATH . '/public/installer/index.php');
localeCheck($installerSource !== false && str_contains($installerSource, '$installerLocale') && str_contains($installerSource, '$installerT'), 'Installer must use the language catalog and locale switcher.');
$viewFiles = glob(BASE_PATH . '/app/Views/*.php') ?: [];
foreach ($viewFiles as $viewFile) {
    $viewSource = file_get_contents($viewFile);
    localeCheck($viewSource !== false && str_contains($viewSource, 'Localization::current()'), 'Every application page must declare the active locale: ' . basename($viewFile));
    localeCheck(str_contains($viewSource, "partials/language-switcher.php"), 'Every application page must expose the language switcher: ' . basename($viewFile));
}
localeCheck(str_contains($vi['Welcome back'] ?? '', 'Chào mừng'), 'Vietnamese UTF-8 text must be preserved.');
localeCheck(($en['Welcome back'] ?? '') === 'Welcome back', 'English catalog must contain English copy.');
localeCheck(App\Core\Localization::translate('unknown.internal.key') !== 'unknown.internal.key', 'Missing keys must not expose raw keys.');
App\Core\Localization::set('vi');
localeCheck(App\Core\Localization::translate('Hello {name}', ['name' => 'Lan']) === 'Xin chào Lan', 'Vietnamese placeholder replacement must work.');
App\Core\Localization::set('en');
localeCheck(App\Core\Localization::translate('Hello {name}', ['name' => 'Lan']) === 'Hello Lan', 'English placeholder replacement must work.');
localeCheck(App\Core\Localization::translate('unknown.internal.key') !== 'unknown.internal.key', 'Fallback must not expose a raw key.');
foreach (['vi','en','xx'] as $locale) {
    $v = App\Core\EmailTemplates::verification($locale, 'Lan', 'https://example.test/verify?token=abc');
    $p = App\Core\EmailTemplates::passwordReset($locale, 'Lan', 'https://example.test/reset?token=def');
    $s = App\Core\EmailTemplates::passwordChanged($locale);
    localeCheck(str_contains($v['text'], 'https://example.test/verify?token=abc'), 'Verification URL must remain unchanged.');
if ($locale === 'vi') localeCheck(str_contains($v['text'], 'Xin chào'), 'Vietnamese verification email must be localized.');
if ($locale === 'en') localeCheck(str_contains($v['text'], 'Hello'), 'English verification email must be localized.');
    localeCheck(str_contains($p['text'], 'https://example.test/reset?token=def'), 'Reset URL must remain unchanged.');
    localeCheck($v['subject'] !== '' && $p['subject'] !== '' && $s['subject'] !== '', 'Localized email subjects must be present.');
    if ($locale === 'vi') localeCheck(str_contains($p['text'], 'Xin chào') && str_contains($s['text'], 'Mật khẩu'), 'Reset and security emails must be Vietnamese.');
    if ($locale === 'en') localeCheck(str_contains($p['text'], 'Hello') && str_contains($s['text'], 'password'), 'Reset and security emails must be English.');
}
App\Core\Localization::set('vi');
$js = App\Core\Localization::javascriptMessages(['Confirm', 'Cancel']);
localeCheck(($js['Confirm'] ?? '') === 'Xác nhận', 'JavaScript message payload must use the active locale.');
App\Core\Localization::persistGuest('en');
localeCheck(App\Core\Localization::current() === 'en', 'Guest locale selection must update immediately.');
App\Core\Localization::persistGuest('vi');
localeCheck(App\Core\Localization::current() === 'vi', 'Guest locale can return to Vietnamese.');
echo "Localization catalog and email template tests passed.\n";

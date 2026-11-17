<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Database;
use App\Core\Localization;
use Throwable;

final class LanguageController
{
    private function verifyLanguageCsrf(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit(Localization::translate('Invalid CSRF token'));
        }
    }
    private function verifySupportedLanguage(string $locale): void
    {
        if (!in_array($locale, ['vi', 'en'], true)) {
            http_response_code(422);
            exit(Localization::translate('Please choose a supported language.'));
        }
    }
    private function persistLanguagePreference(string $locale, int $userId): void
    {
        if ($userId > 0) {
            try {
                $stmt = Database::connection()->prepare('INSERT INTO user_profiles (user_id, language) VALUES (:id, :language) ON DUPLICATE KEY UPDATE language = VALUES(language)');
                $stmt->execute(['language' => $locale, 'id' => $userId]);
                Localization::set($locale);
                Localization::writeGuestCookie($locale);
            } catch (Throwable $e) {
                error_log('Account language preference update failed (' . get_class($e) . ', code ' . $e->getCode() . ').');
                http_response_code(500);
                exit(Localization::translate('Could not save your language preference.'));
            }
        } else {
            Localization::persistGuest($locale);
        }
    }
    public function switch(): void
    {
        $this->verifyLanguageCsrf();
        $locale = (string)($_POST['language'] ?? '');
        $this->verifySupportedLanguage($locale);
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $this->persistLanguagePreference($locale, $userId);
        $return = (string)($_POST['return_to'] ?? '/');
        // Reject raw control bytes before parsing: URL parsers may normalize them.
        $rawUnsafe = $return === ''
            || strlen($return) > 2048
            || $return[0] !== '/'
            || str_starts_with($return, '//')
            || str_contains($return, "\\")
            || preg_match('/[\\x00-\\x1F\\x7F]/', $return) === 1;
        $parsed = $rawUnsafe ? false : parse_url($return);
        $decodedPath = is_array($parsed) ? rawurldecode((string)($parsed['path'] ?? '')) : '';
        if ($rawUnsafe
            || str_starts_with($decodedPath, '//')
            || str_contains($decodedPath, "\\")
            || preg_match('/[\\x00-\\x1F\\x7F]/', $decodedPath) === 1
            || !is_array($parsed)
            || isset($parsed['scheme'])
            || isset($parsed['host'])
            || isset($parsed['user'])
            || isset($parsed['pass'])) {
            $return = '/';
        }
        header('Location: ' . $return, true, 303);
    }
}

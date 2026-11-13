<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Database;
use App\Core\Localization;
use Throwable;

final class LanguageController
{
    public function switch(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit(Localization::translate('Invalid CSRF token'));
        }
        $locale = (string)($_POST['language'] ?? '');
        if (!in_array($locale, ['vi', 'en'], true)) {
            http_response_code(422);
            exit(Localization::translate('Please choose a supported language.'));
        }
        $userId = (int)($_SESSION['user_id'] ?? 0);
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
        $return = (string)($_POST['return_to'] ?? '/');
        $parsed = parse_url($return);
        $decodedPath = rawurldecode((string)($parsed['path'] ?? ''));
        if ($return === ''
            || $return[0] !== '/'
            || str_starts_with($return, '//')
            || str_contains($return, "\\")
            || preg_match('/[\\x00-\\x1F\\x7F]/', $return)
            || str_starts_with($decodedPath, '//')
            || str_contains($decodedPath, "\\")
            || preg_match('/[\\x00-\\x1F\\x7F]/', $decodedPath)
            || !is_array($parsed)
            || isset($parsed['scheme'], $parsed['host'], $parsed['user'], $parsed['pass'])) {
            $return = '/';
        }
        header('Location: ' . $return, true, 303);
    }
}

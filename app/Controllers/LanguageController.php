<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Database;
use App\Core\Localization;

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
            $stmt = Database::connection()->prepare('UPDATE user_profiles SET language = :language WHERE user_id = :id');
            $stmt->execute(['language' => $locale, 'id' => $userId]);
            if ($stmt->rowCount() < 0) {
                http_response_code(500);
                exit(Localization::translate('Could not save your language preference.'));
            }
            Localization::set($locale);
            Localization::writeGuestCookie($locale);
        } else {
            Localization::persistGuest($locale);
        }
        $return = (string)($_POST['return_to'] ?? '/');
        if ($return === '' || $return[0] !== '/' || str_starts_with($return, '//') || str_contains($return, "\\") || str_contains($return, "\r") || str_contains($return, "\n")) {
            $return = '/';
        }
        header('Location: ' . $return, true, 303);
    }
}

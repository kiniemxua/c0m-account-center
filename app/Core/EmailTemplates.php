<?php
declare(strict_types=1);
namespace App\Core;

final class EmailTemplates
{
    private static function locale(string $locale): string { return in_array($locale, ['vi', 'en'], true) ? $locale : 'vi'; }

    public static function verification(string $locale, string $name, string $url): array
    {
        if (self::locale($locale) === 'en') {
            return ['subject' => 'Verify your c0m.me account', 'text' => "Hello {$name},\n\nVerify your email address using this link:\n{$url}\n\nThis link expires in 24 hours. If you did not request this account, you can ignore this message."];
        }
        return ['subject' => 'Xác minh tài khoản c0m.me của bạn', 'text' => "Xin chào {$name},\n\nVui lòng xác minh địa chỉ email bằng liên kết sau:\n{$url}\n\nLiên kết hết hạn sau 24 giờ. Nếu bạn không yêu cầu tạo tài khoản này, hãy bỏ qua email."];
    }

    public static function passwordReset(string $locale, string $name, string $url): array
    {
        if (self::locale($locale) === 'en') {
            return ['subject' => 'Reset your c0m.me password', 'text' => "Hello {$name},\n\nUse this one-time link to reset your password:\n{$url}\n\nThe link expires in one hour. If you did not request this, ignore this message."];
        }
        return ['subject' => 'Đặt lại mật khẩu c0m.me', 'text' => "Xin chào {$name},\n\nSử dụng liên kết một lần sau đây để đặt lại mật khẩu:\n{$url}\n\nLiên kết hết hạn sau một giờ. Nếu bạn không yêu cầu thao tác này, hãy bỏ qua email."];
    }

    public static function passwordChanged(string $locale): array
    {
        if (self::locale($locale) === 'en') {
            return ['subject' => 'Your c0m.me password was changed', 'text' => 'The password for your c0m.me account was changed. All active sessions were signed out. If you did not make this change, contact support immediately.'];
        }
        return ['subject' => 'Mật khẩu c0m.me của bạn đã được thay đổi', 'text' => 'Mật khẩu tài khoản c0m.me của bạn vừa được thay đổi. Tất cả phiên đăng nhập đang hoạt động đã bị đăng xuất. Nếu bạn không thực hiện thay đổi này, hãy liên hệ bộ phận hỗ trợ ngay lập tức.'];
    }
}

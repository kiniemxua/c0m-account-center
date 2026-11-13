<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Core/bootstrap.php';

use App\Core\Router;
use App\Controllers\HomeController;
use App\Controllers\AuthController;
use App\Controllers\SecurityController;
use App\Controllers\AdminController;
use App\Controllers\AccountController;

$router = new Router();
$router->get('/', [HomeController::class, 'index']);
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/register', [AuthController::class, 'showRegister']);
$router->post('/register', [AuthController::class, 'register']);
$router->get('/verify-email', [AuthController::class, 'verifyEmailForm']);
$router->post('/verify-email', [AuthController::class, 'verifyEmail']);
$router->post('/resend-verification', [AuthController::class, 'resendVerification']);
$router->get('/forgot-password', [AuthController::class, 'showForgotPassword']);
$router->post('/forgot-password', [AuthController::class, 'forgotPassword']);
$router->get('/reset-password', [AuthController::class, 'showResetPassword']);
$router->post('/reset-password', [AuthController::class, 'resetPassword']);
$router->get('/sessions', [SecurityController::class, 'sessions']);
$router->post('/sessions/revoke', [SecurityController::class, 'revoke']);
$router->post('/sessions/revoke-others', [SecurityController::class, 'revokeOthers']);
$router->get('/login-history', [SecurityController::class, 'loginHistory']);
$router->get('/admin/users', [AdminController::class, 'users']);
$router->post('/admin/users/status', [AdminController::class, 'updateStatus']);
$router->post('/admin/users/role', [AdminController::class, 'assignRole']);
$router->get('/admin/settings', [AdminController::class, 'settings']);
$router->post('/admin/settings', [AdminController::class, 'settings']);
$router->post('/logout', [AuthController::class, 'logout']);
$router->get('/dashboard', [HomeController::class, 'dashboard']);
$router->get('/account', [AccountController::class, 'show']);
$router->post('/account/profile', [AccountController::class, 'updateProfile']);
$router->post('/account/password', [AccountController::class, 'changePassword']);
$router->dispatch($_SERVER['REQUEST_METHOD'], parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');

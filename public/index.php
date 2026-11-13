<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Core/bootstrap.php';

use App\Core\Router;
use App\Controllers\HomeController;
use App\Controllers\AuthController;

$router = new Router();
$router->get('/', [HomeController::class, 'index']);
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/register', [AuthController::class, 'showRegister']);
$router->post('/register', [AuthController::class, 'register']);
$router->get('/verify-email', [AuthController::class, 'verifyEmailForm']);
$router->post('/verify-email', [AuthController::class, 'verifyEmail']);
$router->post('/resend-verification', [AuthController::class, 'resendVerification']);
$router->post('/logout', [AuthController::class, 'logout']);
$router->get('/dashboard', [HomeController::class, 'dashboard']);
$router->dispatch($_SERVER['REQUEST_METHOD'], parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');

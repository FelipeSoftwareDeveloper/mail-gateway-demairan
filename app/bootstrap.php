<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('Este sistema requer PHP 8.1 ou superior. Ajuste no cPanel em "MultiPHP Manager".');
}

define('APP_ROOT', dirname(__DIR__));
define('APP_DIR', __DIR__);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

(function (): void {
    $file = getenv('MAIL_GATEWAY_CONFIG') ?: APP_ROOT . '/config.php';
    if (!is_file($file)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        exit("config.php não encontrado. Copie config.sample.php para config.php e preencha os dados.\n");
    }
    $config = require $file;
    if (!is_array($config)) {
        http_response_code(500);
        exit('config.php inválido: o arquivo deve retornar um array.');
    }
    $GLOBALS['__APP_CONFIG'] = $config;
})();

require APP_DIR . '/helpers.php';
require APP_DIR . '/db.php';
require APP_DIR . '/crypto.php';
require APP_DIR . '/security.php';
require APP_DIR . '/logger.php';
require APP_DIR . '/mailer.php';
require APP_DIR . '/layout.php';

date_default_timezone_set((string) config('timezone', 'America/Sao_Paulo'));

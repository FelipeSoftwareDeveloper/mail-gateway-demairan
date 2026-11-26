<?php
/**
 * Limpeza periódica (rodar pelo "Cron Jobs" do cPanel, 1x por dia):
 *   /usr/local/bin/php /home/SEUUSUARIO/public_html/mail-gateway/cron/cleanup.php
 *
 * - Apaga logs de e-mail mais antigos que logs.retention_days
 * - Apaga tentativas de login com mais de 7 dias
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Somente via linha de comando (cron).');
}

require dirname(__DIR__) . '/app/bootstrap.php';

$days = max(1, (int) config('logs.retention_days', 90));
$deleted = 0;
do {
    $n = db_run("DELETE FROM email_logs WHERE created_at < NOW() - INTERVAL {$days} DAY LIMIT 5000")->rowCount();
    $deleted += $n;
} while ($n === 5000);

$attempts = db_run('DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 7 DAY')->rowCount();

echo date('c') . " — logs removidos: {$deleted}; tentativas de login removidas: {$attempts}\n";

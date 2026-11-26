<?php
/**
 * Copie este arquivo para config.php e preencha os valores.
 *
 * Dica de segurança (HostGator): guarde o arquivo real FORA do public_html,
 * por exemplo /home/SEUUSUARIO/mail-gateway-config.php, e deixe o config.php
 * do projeto com uma única linha:
 *     <?php return require '/home/SEUUSUARIO/mail-gateway-config.php';
 */
return [
    'app_name' => 'Demairan Mail Gateway',

    // Chave de 32 bytes em base64 para criptografar as senhas SMTP (AES-256-GCM).
    // Gere uma abrindo /install.php (ele mostra uma chave nova) ou, com SSH:
    //   php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
    // ATENÇÃO: se trocar esta chave, as senhas SMTP já salvas deixam de abrir
    // e precisam ser cadastradas de novo.
    'app_key' => '',

    'timezone' => 'America/Sao_Paulo',

    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => 'cpuser_mailgw',   // no cPanel o nome leva o prefixo do usuário
        'user'    => 'cpuser_mailgw',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    // Exige HTTPS no painel e na API (deixe true em produção).
    'force_https' => true,

    // Ambiente usado no prefixo dos tokens: tk_live_... ou tk_test_...
    'token_env' => 'live',

    // Só preencha se o site estiver atrás de proxy/CDN (ex.: Cloudflare):
    // 'HTTP_CF_CONNECTING_IP'. Caso contrário deixe null (usa REMOTE_ADDR).
    'trusted_proxy_header' => null,

    'session_idle_minutes' => 30,

    'login' => [
        'max_attempts'   => 5,   // falhas por IP dentro da janela
        'window_minutes' => 15,
    ],

    'api' => [
        'max_body_bytes'           => 1048576, // 1 MB de JSON por requisição
        'max_recipients'           => 50,      // to + cc + bcc
        'rate_limit_per_minute'    => 60,      // envios por token por minuto
        'auth_fail_limit'          => 20,      // tentativas inválidas por IP...
        'auth_fail_window_minutes' => 15,      // ...nesta janela -> HTTP 429
        'smtp_timeout'             => 20,      // segundos

        // Proteção anti-robô da HostGator: se a API responder 409 com
        // <script>document.cookie = "humans_XXXXX=1"...</script>, coloque aqui
        // 'humans_XXXXX=1' para o exemplo de curl da tela de tokens já sair com
        // o cookie. Deixe null quando a HostGator desativar a proteção.
        'hosting_cookie'           => null,
    ],

    'logs' => [
        'body'           => 'preview', // 'preview', 'full' ou 'none'
        'preview_chars'  => 500,
        'retention_days' => 90,        // usado pelo cron/cleanup.php
    ],
];

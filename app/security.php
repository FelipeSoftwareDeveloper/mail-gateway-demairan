<?php
declare(strict_types=1);

/* ---------------------------------------------------------------------
 *  Saída segura (XSS)
 * ------------------------------------------------------------------- */

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ---------------------------------------------------------------------
 *  Transporte / cabeçalhos
 * ------------------------------------------------------------------- */

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    // HostGator/cPanel com proxy reverso ou Cloudflare
    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function enforce_https_for_panel(): void
{
    if (config('force_https', true) && !is_https() && PHP_SAPI !== 'cli') {
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        redirect('https://' . $host . $uri);
    }
}

function send_panel_headers(): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; "
        . "style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; "
        . "img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

/** IP real do cliente. Só confia em cabeçalho de proxy se configurado. */
function client_ip(): string
{
    $header = config('trusted_proxy_header');
    if (is_string($header) && $header !== '' && !empty($_SERVER[$header])) {
        $candidate = trim(explode(',', (string) $_SERVER[$header])[0]);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/* ---------------------------------------------------------------------
 *  Sessão do painel
 * ------------------------------------------------------------------- */

function start_panel_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    enforce_https_for_panel();
    send_panel_headers();

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    session_name('MGW_SESSION');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

/* ---------------------------------------------------------------------
 *  CSRF
 * ------------------------------------------------------------------- */

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || empty($_SESSION['_csrf']) || !hash_equals($_SESSION['_csrf'], $sent)) {
        http_response_code(419);
        exit('Sessão expirada ou requisição inválida (CSRF). Volte, recarregue a página e tente de novo.');
    }
}

/* ---------------------------------------------------------------------
 *  Autenticação de administradores
 * ------------------------------------------------------------------- */

function current_admin(): ?array
{
    static $admin = false;
    if ($admin !== false) {
        return $admin;
    }
    $id = $_SESSION['admin_id'] ?? null;
    if (!$id) {
        return $admin = null;
    }
    $idle = (int) config('session_idle_minutes', 30) * 60;
    if (time() - (int) ($_SESSION['last_activity'] ?? 0) > $idle) {
        logout_admin();
        return $admin = null;
    }
    $_SESSION['last_activity'] = time();
    $admin = db_one('SELECT id, name, email FROM admins WHERE id = ?', [(int) $id]);
    if (!$admin) {
        logout_admin();
        return $admin = null;
    }
    return $admin;
}

/**
 * Abre a sessão, exige login e valida CSRF em todo POST.
 * Use no topo de cada página do painel.
 */
function require_admin(): array
{
    start_panel_session();
    $admin = current_admin();
    if (!$admin) {
        redirect('login.php');
    }
    if (is_post()) {
        verify_csrf();
    }
    return $admin;
}

function login_admin(array $admin): void
{
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int) $admin['id'];
    $_SESSION['last_activity'] = time();
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    db_run('UPDATE admins SET last_login_at = NOW() WHERE id = ?', [(int) $admin['id']]);
}

function logout_admin(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'],
            'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => $p['samesite'] ?? 'Strict',
        ]);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function login_is_throttled(string $ip): bool
{
    $window = (int) config('login.window_minutes', 15);
    $fails = (int) db_val(
        "SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND created_at >= NOW() - INTERVAL {$window} MINUTE",
        [$ip]
    );
    return $fails >= (int) config('login.max_attempts', 5);
}

function record_login_attempt(string $ip, string $email, bool $success): void
{
    db_run('INSERT INTO login_attempts (ip, email, success) VALUES (?, ?, ?)', [$ip, str_cut($email, 190), $success ? 1 : 0]);
}

/* ---------------------------------------------------------------------
 *  API
 * ------------------------------------------------------------------- */

/** Lê o token do header Authorization (com os fallbacks do Apache/CGI da HostGator). */
function bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    if (!$header && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                $header = $value;
                break;
            }
        }
    }
    if (is_string($header) && preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $m)) {
        return $m[1];
    }
    // Alternativa para hospedagens que removem o Authorization de vez
    $alt = $_SERVER['HTTP_X_API_KEY'] ?? null;
    return is_string($alt) && $alt !== '' ? trim($alt) : null;
}

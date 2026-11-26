<?php
declare(strict_types=1);

/** Lê valores do config.php com notação de ponto: config('api.max_recipients'). */
function config(string $key, mixed $default = null): mixed
{
    $value = $GLOBALS['__APP_CONFIG'] ?? [];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Valor de $_POST sempre como string aparada (arrays são ignorados). */
function post(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function query(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function query_int(string $key, int $default = 0): int
{
    $v = query($key);
    return ctype_digit($v) ? (int) $v : $default;
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function pull_flashes(): array
{
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

function str_limit(string $s, int $limit): string
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($s, 'UTF-8') > $limit ? mb_substr($s, 0, $limit, 'UTF-8') . '…' : $s;
    }
    return strlen($s) > $limit ? substr($s, 0, $limit) . '...' : $s;
}

function str_cut(?string $s, int $limit): ?string
{
    if ($s === null) {
        return null;
    }
    return function_exists('mb_substr') ? mb_substr($s, 0, $limit, 'UTF-8') : substr($s, 0, $limit);
}

function fmt_dt(?string $dt, string $format = 'd/m/Y H:i'): string
{
    if (!$dt) {
        return '—';
    }
    $t = strtotime($dt);
    return $t ? date($format, $t) : '—';
}

function fmt_num(int|string|null $n): string
{
    return number_format((int) $n, 0, ',', '.');
}

/** Gera um token de API: tk_live_<40 hex> (160 bits de entropia). */
function generate_api_token(): array
{
    $env = config('token_env', 'live') === 'test' ? 'test' : 'live';
    $random = bin2hex(random_bytes(20));
    $token = "tk_{$env}_{$random}";
    return [
        'token'  => $token,
        'hash'   => hash('sha256', $token),
        'prefix' => "tk_{$env}_" . substr($random, 0, 8),
    ];
}

function is_valid_token_format(string $token): bool
{
    return (bool) preg_match('/^tk_(live|test)_[a-f0-9]{40}$/', $token);
}

function is_valid_email(string $email): bool
{
    return strlen($email) <= 190
        && !preg_match('/[\r\n]/', $email)
        && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function status_label(string $status): string
{
    return [
        'sent' => 'Enviado', 'failed' => 'Falhou', 'unauthorized' => 'Bloqueado',
        'active' => 'Ativo', 'revoked' => 'Revogado',
    ][$status] ?? $status;
}

function status_badge(string $status): string
{
    $variant = [
        'sent' => 'success', 'active' => 'success',
        'failed' => 'danger', 'revoked' => 'neutral',
        'unauthorized' => 'warning',
    ][$status] ?? 'neutral';
    return '<span class="badge badge-' . $variant . '">' . e(status_label($status)) . '</span>';
}

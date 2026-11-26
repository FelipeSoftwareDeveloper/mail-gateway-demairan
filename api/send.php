<?php
/**
 * POST /api/send.php
 *
 * Authorization: Bearer tk_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
 * Content-Type: application/json
 *
 * {
 *   "from": "avisos@demaria.com.br",          // opcional; se vier, TEM que ser a conta do token
 *   "to": "cliente@exemplo.com",              // string ou lista
 *   "cc": [], "bcc": [], "reply_to": "...",   // opcionais
 *   "subject": "Seu código de verificação",
 *   "html": "<h1>Olá!</h1>",                  // html e/ou text
 *   "text": "Olá!"
 * }
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
// Sem cabeçalhos CORS de propósito: a API é servidor-a-servidor.
// Um token nunca deve ficar exposto em JavaScript de navegador.

function api_respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_error(int $status, string $code, string $message, ?int $logId = null): never
{
    $body = ['success' => false, 'error' => ['code' => $code, 'message' => $message]];
    if ($logId !== null) {
        $body['log_id'] = $logId;
    }
    api_respond($status, $body);
}

/** Normaliza string|lista em lista de e-mails válidos. */
function parse_recipients(mixed $value, string $field): array
{
    if ($value === null || $value === '' || $value === []) {
        return [];
    }
    $list = is_array($value) ? $value : [$value];
    $out = [];
    foreach ($list as $item) {
        if (!is_string($item) || !is_valid_email(trim($item))) {
            throw new InvalidArgumentException("Endereço inválido em \"{$field}\".");
        }
        $out[strtolower(trim($item))] = trim($item);
    }
    return array_values($out);
}

/** Aceita "email@x.com" ou "Nome <email@x.com>" e devolve só o e-mail. */
function extract_address(string $from): string
{
    if (preg_match('/<([^<>]+)>\s*$/', $from, $m)) {
        return trim($m[1]);
    }
    return trim($from);
}

// ---------------------------------------------------------------------
// Contexto da requisição (vai para todos os logs)
// ---------------------------------------------------------------------
$ctx = [
    'origin_ip'     => client_ip(),
    'user_agent'    => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
    'origin_header' => (string) ($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? ''),
];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    api_error(405, 'method_not_allowed', 'Use o método POST.');
}

if (config('force_https', true) && !is_https()) {
    api_error(403, 'https_required', 'A API só aceita requisições via HTTPS.');
}

try {
    db();
} catch (Throwable $e) {
    error_log('[mail-gateway] banco indisponível: ' . $e->getMessage());
    api_error(503, 'service_unavailable', 'Serviço temporariamente indisponível.');
}

// ---------------------------------------------------------------------
// 1) Bloqueio de força bruta por IP (muitas tentativas inválidas)
// ---------------------------------------------------------------------
$failWindow = max(1, (int) config('api.auth_fail_window_minutes', 15));
$recentFails = (int) db_val(
    "SELECT COUNT(*) FROM email_logs
      WHERE origin_ip = ? AND status = 'unauthorized' AND created_at >= NOW() - INTERVAL {$failWindow} MINUTE",
    [$ctx['origin_ip']]
);
if ($recentFails >= (int) config('api.auth_fail_limit', 20)) {
    header('Retry-After: ' . ($failWindow * 60));
    api_error(429, 'too_many_failed_attempts', 'Muitas tentativas inválidas deste IP. Tente mais tarde.');
}

// ---------------------------------------------------------------------
// 2) Autenticação do token
// ---------------------------------------------------------------------
$token = bearer_token();
if ($token === null || !is_valid_token_format($token)) {
    $logId = log_email($ctx + ['status' => 'unauthorized', 'error_message' => 'Token ausente ou malformado.']);
    header('WWW-Authenticate: Bearer');
    api_error(401, 'invalid_token', 'Token ausente ou inválido.', $logId);
}

$row = db_one(
    'SELECT t.id AS token_id, t.status AS token_status, t.project_id, t.smtp_account_id,
            s.email, s.from_name, s.smtp_password_encrypted, s.smtp_host, s.smtp_port,
            s.encryption, s.is_active
       FROM api_tokens t
       JOIN smtp_accounts s ON s.id = t.smtp_account_id
      WHERE t.token_hash = ?
      LIMIT 1',
    [hash('sha256', $token)]
);

if (!$row) {
    $logId = log_email($ctx + ['status' => 'unauthorized', 'error_message' => 'Token não encontrado.']);
    header('WWW-Authenticate: Bearer');
    api_error(401, 'invalid_token', 'Token ausente ou inválido.', $logId);
}

$ids = [
    'token_id'        => (int) $row['token_id'],
    'project_id'      => (int) $row['project_id'],
    'smtp_account_id' => (int) $row['smtp_account_id'],
];

if ($row['token_status'] !== 'active') {
    $logId = log_email($ctx + $ids + ['status' => 'unauthorized', 'error_message' => 'Token revogado.']);
    api_error(401, 'token_revoked', 'Este token foi revogado.', $logId);
}

db_run('UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?', [$ids['token_id']]);

if ((int) $row['is_active'] !== 1) {
    $logId = log_email($ctx + $ids + ['status' => 'unauthorized', 'sender_email' => $row['email'],
        'error_message' => 'Conta SMTP desativada.']);
    api_error(403, 'smtp_account_disabled', 'A conta de envio deste token está desativada.', $logId);
}

// ---------------------------------------------------------------------
// 3) Limite de envios por token
// ---------------------------------------------------------------------
$perMinute = (int) config('api.rate_limit_per_minute', 60);
if ($perMinute > 0) {
    $sentLastMinute = (int) db_val(
        "SELECT COUNT(*) FROM email_logs
          WHERE token_id = ? AND status IN ('sent','failed') AND created_at >= NOW() - INTERVAL 1 MINUTE",
        [$ids['token_id']]
    );
    if ($sentLastMinute >= $perMinute) {
        header('Retry-After: 60');
        api_error(429, 'rate_limited', "Limite de {$perMinute} envios por minuto atingido para este token.");
    }
}

// ---------------------------------------------------------------------
// 4) Leitura e validação do payload
// ---------------------------------------------------------------------
$maxBytes = (int) config('api.max_body_bytes', 1048576);
$raw = (string) file_get_contents('php://input', false, null, 0, $maxBytes + 1);
if (strlen($raw) > $maxBytes) {
    $logId = log_email($ctx + $ids + ['status' => 'failed', 'sender_email' => $row['email'],
        'error_message' => 'Payload acima do limite.']);
    api_error(413, 'payload_too_large', "O corpo da requisição passa de {$maxBytes} bytes.", $logId);
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    $logId = log_email($ctx + $ids + ['status' => 'failed', 'sender_email' => $row['email'],
        'error_message' => 'JSON inválido.']);
    api_error(400, 'invalid_json', 'O corpo precisa ser um JSON válido.', $logId);
}

// Regra crítica: o remetente TEM que ser a conta vinculada ao token.
$from = $data['from'] ?? null;
if ($from !== null) {
    if (!is_string($from) || strcasecmp(extract_address($from), $row['email']) !== 0) {
        $attempted = is_string($from) ? extract_address($from) : '(valor inválido)';
        $logId = log_email($ctx + $ids + [
            'status'        => 'unauthorized',
            'sender_email'  => str_cut($attempted, 190),
            'subject'       => is_string($data['subject'] ?? null) ? $data['subject'] : null,
            'error_message' => "Remetente \"{$attempted}\" não autorizado: este token só envia como {$row['email']}.",
        ]);
        api_error(403, 'sender_not_allowed', "Este token só pode enviar como {$row['email']}.", $logId);
    }
}

$subject = is_string($data['subject'] ?? null) ? trim(str_replace(["\r", "\n"], ' ', $data['subject'])) : '';
$html = is_string($data['html'] ?? null) ? $data['html'] : '';
$text = is_string($data['text'] ?? null) ? $data['text'] : '';
$replyTo = is_string($data['reply_to'] ?? null) ? trim($data['reply_to']) : '';

$recipientsForLog = is_string($data['to'] ?? null) ? $data['to'] : (is_array($data['to'] ?? null) ? implode(', ', array_filter($data['to'], 'is_string')) : null);
$logBase = $ctx + $ids + [
    'sender_email'    => $row['email'],
    'recipient_email' => $recipientsForLog,
    'subject'         => $subject,
];

try {
    $to = parse_recipients($data['to'] ?? null, 'to');
    $cc = parse_recipients($data['cc'] ?? null, 'cc');
    $bcc = parse_recipients($data['bcc'] ?? null, 'bcc');
    if ($to === []) {
        throw new InvalidArgumentException('Informe ao menos um destinatário em "to".');
    }
    $max = (int) config('api.max_recipients', 50);
    if (count($to) + count($cc) + count($bcc) > $max) {
        throw new InvalidArgumentException("Máximo de {$max} destinatários por envio (to + cc + bcc).");
    }
    if ($subject === '') {
        throw new InvalidArgumentException('O campo "subject" é obrigatório.');
    }
    if ((function_exists('mb_strlen') ? mb_strlen($subject, 'UTF-8') : strlen($subject)) > 255) {
        throw new InvalidArgumentException('O "subject" pode ter no máximo 255 caracteres.');
    }
    if (trim($html) === '' && trim($text) === '') {
        throw new InvalidArgumentException('Envie o conteúdo em "html" e/ou "text".');
    }
    if ($replyTo !== '' && !is_valid_email($replyTo)) {
        throw new InvalidArgumentException('Endereço inválido em "reply_to".');
    }
} catch (InvalidArgumentException $e) {
    $logId = log_email($logBase + ['status' => 'failed', 'error_message' => 'Validação: ' . $e->getMessage()]);
    api_error(422, 'validation_error', $e->getMessage(), $logId);
}

// ---------------------------------------------------------------------
// 5) Envio via SMTP (Titan)
// ---------------------------------------------------------------------
$allRecipients = implode(', ', array_merge($to, $cc ? ['cc: ' . implode(', ', $cc)] : [], $bcc ? ['bcc: ' . implode(', ', $bcc)] : []));
$result = smtp_send($row, [
    'to' => $to, 'cc' => $cc, 'bcc' => $bcc, 'reply_to' => $replyTo,
    'subject' => $subject, 'html' => $html, 'text' => $text,
]);

$logId = log_email([
    'recipient_email' => $allRecipients,
    'body_preview'    => body_for_log($html, $text),
    'message_id'      => $result['message_id'],
    'status'          => $result['ok'] ? 'sent' : 'failed',
    'error_message'   => $result['error'],
] + $logBase);

if (!$result['ok']) {
    error_log('[mail-gateway] falha SMTP (token ' . $ids['token_id'] . '): ' . $result['error']);
    api_error(502, 'smtp_error', 'O servidor SMTP recusou o envio: ' . $result['error'], $logId);
}

api_respond(200, [
    'success'    => true,
    'log_id'     => $logId,
    'message_id' => $result['message_id'],
    'from'       => $row['email'],
    'to'         => $to,
]);

<?php
declare(strict_types=1);

/**
 * Provedor Resend (API HTTP). https://resend.com/docs/api-reference
 * Usado pelas contas de envio com provider = 'resend'.
 */

function resend_base_url(): string
{
    return rtrim((string) config('resend.base_url', 'https://api.resend.com'), '/');
}

/**
 * Faz uma chamada à API do Resend.
 * @return array{status: int, body: ?array, error: ?string}
 */
function resend_request(string $method, string $path, string $apiKey, ?array $payload = null, array $extraHeaders = []): array
{
    if (!function_exists('curl_init')) {
        return ['status' => 0, 'body' => null, 'error' => 'A extensão cURL do PHP não está habilitada.'];
    }
    $headers = array_merge([
        'Authorization: Bearer ' . $apiKey,
        'Accept: application/json',
        'User-Agent: demairan-mail-gateway/1.1',
    ], $extraHeaders);

    $ch = curl_init(resend_base_url() . $path);
    $opts = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => (int) config('api.smtp_timeout', 20),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
    ];
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);

    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = $raw === false ? curl_error($ch) : null;
    unset($ch); // libera o handle (curl_close não tem efeito desde o PHP 8.0)

    if ($curlError !== null) {
        return ['status' => 0, 'body' => null, 'error' => 'Não foi possível conectar ao Resend: ' . $curlError];
    }
    $body = json_decode((string) $raw, true);
    return ['status' => $status, 'body' => is_array($body) ? $body : null, 'error' => null];
}

/** Mensagem legível a partir de uma resposta de erro do Resend. */
function resend_error_message(array $r): string
{
    if ($r['error'] !== null) {
        return $r['error'];
    }
    $b = $r['body'] ?? [];
    $name = is_string($b['name'] ?? null) ? $b['name'] : 'erro';
    $msg = is_string($b['message'] ?? null) ? $b['message'] : 'resposta inesperada';
    return "Resend {$r['status']} ({$name}): {$msg}";
}

/**
 * "Nome" <email> no formato aceito pelo Resend. $overrideName (o nome do "from"
 * da requisição) tem prioridade; vazio, vale o nome cadastrado na conta.
 */
function resend_from(array $account, string $overrideName = ''): string
{
    $raw = $overrideName !== '' ? $overrideName : (string) ($account['from_name'] ?? '');
    $name = trim(str_replace(['"', "\r", "\n", "\0"], '', $raw));
    return $name === '' ? $account['email'] : '"' . $name . '" <' . $account['email'] . '>';
}

/**
 * Envia pelo Resend. Mesmo formato de entrada e saída de smtp_send():
 * to[], cc[], bcc[], reply_to, subject, html, text, from_name, headers{}, idempotency_key.
 * @return array{ok: bool, message_id: ?string, error: ?string, resend_status?: int}
 */
function resend_send(array $account, array $msg): array
{
    try {
        $apiKey = decrypt_secret((string) ($account['api_key_encrypted'] ?? ''));
    } catch (Throwable $e) {
        return ['ok' => false, 'message_id' => null, 'error' => $e->getMessage()];
    }

    $payload = [
        'from'    => resend_from($account, (string) ($msg['from_name'] ?? '')),
        'to'      => array_values($msg['to']),
        'subject' => $msg['subject'],
    ];
    if (!empty($msg['cc'])) {
        $payload['cc'] = array_values($msg['cc']);
    }
    if (!empty($msg['bcc'])) {
        $payload['bcc'] = array_values($msg['bcc']);
    }
    if (!empty($msg['reply_to'])) {
        $payload['reply_to'] = $msg['reply_to'];
    }
    if (($msg['html'] ?? '') !== '') {
        $payload['html'] = $msg['html'];
    }
    if (($msg['text'] ?? '') !== '') {
        $payload['text'] = $msg['text'];
    }
    // Headers já validados pela API (lista permitida, sem quebras de linha).
    if (!empty($msg['headers'])) {
        $payload['headers'] = (object) $msg['headers'];
    }

    $extra = [];
    if (!empty($msg['idempotency_key'])) {
        $extra[] = 'Idempotency-Key: ' . $msg['idempotency_key'];
    }

    $r = resend_request('POST', '/emails', $apiKey, $payload, $extra);
    if ($r['status'] >= 200 && $r['status'] < 300 && is_string($r['body']['id'] ?? null)) {
        return ['ok' => true, 'message_id' => $r['body']['id'], 'error' => null];
    }
    return ['ok' => false, 'message_id' => null, 'error' => resend_error_message($r), 'resend_status' => $r['status']];
}

/**
 * Valida a API key e se o domínio do remetente está verificado no Resend,
 * sem enviar e-mail.
 * @return array{ok: bool, error: ?string, warning?: string}
 */
function resend_test(array $account): array
{
    try {
        $apiKey = decrypt_secret((string) ($account['api_key_encrypted'] ?? ''));
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    $domain = strtolower(substr((string) strrchr($account['email'], '@'), 1));

    $r = resend_request('GET', '/domains', $apiKey);
    $errName = (string) ($r['body']['name'] ?? '');
    $errText = strtolower((string) ($r['body']['message'] ?? ''));
    if (in_array($errName, ['missing_api_key', 'invalid_api_key'], true) || str_contains($errText, 'api key is invalid')) {
        return ['ok' => false, 'error' => 'API key do Resend inválida.'];
    }
    if ($errName === 'restricted_api_key' || str_contains($errText, 'restricted')) {
        // Chaves "Sending access" não podem listar domínios: a chave existe, mas
        // não dá para confirmar o domínio por aqui.
        return ['ok' => true, 'error' => null,
            'warning' => "Chave aceita (restrita a envio). Não foi possível confirmar se {$domain} está verificado: confira no painel do Resend."];
    }
    if ($r['status'] < 200 || $r['status'] >= 300) {
        return ['ok' => false, 'error' => resend_error_message($r)];
    }
    foreach ((array) ($r['body']['data'] ?? []) as $d) {
        if (strtolower((string) ($d['name'] ?? '')) === $domain) {
            $status = (string) ($d['status'] ?? '');
            return $status === 'verified'
                ? ['ok' => true, 'error' => null]
                : ['ok' => false, 'error' => "O domínio {$domain} está no Resend, mas com status \"{$status}\". Termine a verificação do DNS."];
        }
    }
    return ['ok' => false, 'error' => "O domínio {$domain} não está cadastrado nesta conta do Resend."];
}

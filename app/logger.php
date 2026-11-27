<?php
declare(strict_types=1);

/**
 * Grava um registro de auditoria. Nunca lança exceção: uma falha no log
 * não pode derrubar a resposta da API (o erro vai para o error_log do PHP).
 */
function log_email(array $d): ?int
{
    try {
        return db_insert(
            'INSERT INTO email_logs
                (token_id, project_id, smtp_account_id, provider, sender_email, recipient_email, subject,
                 body_preview, message_id, origin_ip, user_agent, origin_header, status, error_message)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $d['token_id'] ?? null,
                $d['project_id'] ?? null,
                $d['smtp_account_id'] ?? null,
                $d['provider'] ?? null,
                str_cut($d['sender_email'] ?? null, 190),
                str_cut($d['recipient_email'] ?? null, 10000),
                str_cut($d['subject'] ?? null, 500),
                $d['body_preview'] ?? null,
                str_cut($d['message_id'] ?? null, 255),
                $d['origin_ip'] ?? '0.0.0.0',
                str_cut($d['user_agent'] ?? null, 500),
                str_cut($d['origin_header'] ?? null, 500),
                $d['status'],
                str_cut($d['error_message'] ?? null, 5000),
            ]
        );
    } catch (Throwable $e) {
        error_log('[mail-gateway] falha ao gravar log: ' . $e->getMessage());
        return null;
    }
}

/** Monta o corpo que vai para o log conforme config logs.body. */
function body_for_log(string $html, string $text): ?string
{
    $mode = config('logs.body', 'preview');
    if ($mode === 'none') {
        return null;
    }
    if ($mode === 'full') {
        return $html !== '' ? $html : $text;
    }
    if ($text !== '') {
        $plain = $text;
    } else {
        $clean = (string) preg_replace('#<(style|script|head)\b[^>]*>.*?</\1>#is', ' ', $html);
        $plain = html_entity_decode(strip_tags(str_replace('<', ' <', $clean)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $plain = trim((string) preg_replace('/\s+/u', ' ', $plain));
    return str_limit($plain, (int) config('logs.preview_chars', 500));
}

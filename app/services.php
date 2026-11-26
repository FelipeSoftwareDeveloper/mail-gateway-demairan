<?php
declare(strict_types=1);

/**
 * Regras de negócio compartilhadas pelo painel e pelo servidor MCP.
 * Erros de validação lançam InvalidArgumentException com mensagem para o usuário.
 */

/* ---------------------------------------------------------------------
 *  Projetos
 * ------------------------------------------------------------------- */

function project_create(string $name, string $description = ''): int
{
    $name = trim($name);
    $description = trim($description);
    if ($name === '' || mb_strlen($name) > 120) {
        throw new InvalidArgumentException('Informe o nome do projeto (até 120 caracteres).');
    }
    if (mb_strlen($description) > 2000) {
        throw new InvalidArgumentException('A descrição pode ter no máximo 2000 caracteres.');
    }
    if (db_val('SELECT id FROM projects WHERE name = ?', [$name])) {
        throw new InvalidArgumentException('Já existe um projeto com esse nome.');
    }
    return db_insert('INSERT INTO projects (name, description) VALUES (?, ?)', [$name, $description !== '' ? $description : null]);
}

function projects_list(): array
{
    return db_all(
        "SELECT p.id, p.name, p.description, p.created_at,
                (SELECT COUNT(*) FROM api_tokens t WHERE t.project_id = p.id AND t.status = 'active') AS active_tokens,
                (SELECT COUNT(*) FROM email_logs l WHERE l.project_id = p.id AND l.status = 'sent') AS sent_total,
                (SELECT MAX(l.created_at) FROM email_logs l WHERE l.project_id = p.id) AS last_activity
           FROM projects p ORDER BY p.name"
    );
}

/* ---------------------------------------------------------------------
 *  Contas SMTP
 * ------------------------------------------------------------------- */

/** Normaliza e aplica padrões do Titan. */
function smtp_normalize(array $in): array
{
    $encryption = strtolower(trim((string) ($in['encryption'] ?? 'ssl')));
    $port = $in['smtp_port'] ?? null;
    $host = trim((string) ($in['smtp_host'] ?? ''));
    return [
        'email'      => strtolower(trim((string) ($in['email'] ?? ''))),
        'from_name'  => trim((string) ($in['from_name'] ?? '')),
        'smtp_host'  => strtolower($host !== '' ? $host : 'smtp.titan.email'),
        'smtp_port'  => ($port === null || $port === '') ? ($encryption === 'tls' ? 587 : 465) : (int) $port,
        'encryption' => $encryption,
        'is_active'  => !empty($in['is_active']) ? 1 : 0,
    ];
}

/** @return string[] lista de erros */
function smtp_validate(array $v, bool $passwordRequired, string $password, int $ignoreId = 0): array
{
    $errors = [];
    if (!is_valid_email($v['email'])) $errors[] = 'E-mail inválido.';
    if (mb_strlen($v['from_name']) > 120 || preg_match('/[\r\n]/', $v['from_name'])) $errors[] = 'Nome de exibição inválido (até 120 caracteres).';
    if (!preg_match('/^(?=.{1,190}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $v['smtp_host'])) $errors[] = 'Servidor SMTP inválido.';
    if ($v['smtp_port'] < 1 || $v['smtp_port'] > 65535) $errors[] = 'Porta inválida.';
    if (!in_array($v['encryption'], ['ssl', 'tls'], true)) $errors[] = 'Criptografia deve ser ssl ou tls.';
    if ($passwordRequired && $password === '') $errors[] = 'Informe a senha da caixa de e-mail.';
    if (strlen($password) > 500) $errors[] = 'Senha longa demais.';
    if ($v['email'] !== '' && db_val('SELECT id FROM smtp_accounts WHERE email = ? AND id <> ?', [$v['email'], $ignoreId])) {
        $errors[] = 'Esse e-mail já está cadastrado.';
    }
    if (!app_key_is_valid()) $errors[] = 'A app_key do config.php está ausente ou inválida.';
    return $errors;
}

function smtp_create(array $v, string $password): int
{
    $errors = smtp_validate($v, true, $password);
    if ($errors) {
        throw new InvalidArgumentException(implode(' ', $errors));
    }
    return db_insert(
        'INSERT INTO smtp_accounts (email, from_name, smtp_password_encrypted, smtp_host, smtp_port, encryption, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$v['email'], $v['from_name'], encrypt_secret($password), $v['smtp_host'], $v['smtp_port'], $v['encryption'], $v['is_active']]
    );
}

/** Lista contas sem nunca expor a senha. */
function smtp_list(): array
{
    return db_all(
        "SELECT s.id, s.email, s.from_name, s.smtp_host, s.smtp_port, s.encryption, s.is_active, s.created_at,
                (SELECT COUNT(*) FROM api_tokens t WHERE t.smtp_account_id = s.id AND t.status = 'active') AS active_tokens,
                (SELECT MAX(l.created_at) FROM email_logs l WHERE l.smtp_account_id = s.id AND l.status = 'sent') AS last_sent
           FROM smtp_accounts s ORDER BY s.email"
    );
}

/* ---------------------------------------------------------------------
 *  Tokens de API
 * ------------------------------------------------------------------- */

/** Cria o token e devolve o valor completo (única vez em que ele existe em texto). */
function api_token_create(string $name, int $projectId, int $smtpId): array
{
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 120) {
        throw new InvalidArgumentException('Informe o nome do token (até 120 caracteres).');
    }
    if (!db_val('SELECT id FROM projects WHERE id = ?', [$projectId])) {
        throw new InvalidArgumentException('Projeto não encontrado.');
    }
    $account = db_one('SELECT id, email, is_active FROM smtp_accounts WHERE id = ?', [$smtpId]);
    if (!$account) {
        throw new InvalidArgumentException('Conta SMTP não encontrada.');
    }
    if (!$account['is_active']) {
        throw new InvalidArgumentException('A conta SMTP escolhida está desativada.');
    }
    $t = generate_api_token();
    $id = db_insert(
        'INSERT INTO api_tokens (name, token_hash, token_prefix, project_id, smtp_account_id) VALUES (?, ?, ?, ?, ?)',
        [$name, $t['hash'], $t['prefix'], $projectId, $smtpId]
    );
    return ['id' => $id, 'token' => $t['token'], 'prefix' => $t['prefix'], 'name' => $name, 'email' => $account['email']];
}

function api_token_revoke(int $id): bool
{
    return db_run("UPDATE api_tokens SET status = 'revoked', revoked_at = NOW() WHERE id = ? AND status = 'active'", [$id])->rowCount() > 0;
}

function api_tokens_list(string $status = '', int $projectId = 0): array
{
    $where = [];
    $params = [];
    if (in_array($status, ['active', 'revoked'], true)) {
        $where[] = 't.status = ?';
        $params[] = $status;
    }
    if ($projectId > 0) {
        $where[] = 't.project_id = ?';
        $params[] = $projectId;
    }
    $sql = 'SELECT t.id, t.name, t.token_prefix, t.status, t.last_used_at, t.revoked_at, t.created_at,
                   t.project_id, p.name AS project, t.smtp_account_id, s.email AS smtp_email, s.is_active AS smtp_active
              FROM api_tokens t
              JOIN projects p ON p.id = t.project_id
              JOIN smtp_accounts s ON s.id = t.smtp_account_id'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . " ORDER BY t.status = 'active' DESC, t.created_at DESC";
    return db_all($sql, $params);
}

/* ---------------------------------------------------------------------
 *  Logs
 * ------------------------------------------------------------------- */

/**
 * Busca nos logs. Filtros: status, project_id, token_id, q, from (Y-m-d), to (Y-m-d).
 * @return array{total: int, rows: array}
 */
function logs_query(array $f, int $limit, int $offset): array
{
    $where = [];
    $params = [];
    if (in_array($f['status'] ?? '', ['sent', 'failed', 'unauthorized'], true)) {
        $where[] = 'l.status = ?';
        $params[] = $f['status'];
    }
    if (!empty($f['project_id'])) {
        $where[] = 'l.project_id = ?';
        $params[] = (int) $f['project_id'];
    }
    if (!empty($f['token_id'])) {
        $where[] = 'l.token_id = ?';
        $params[] = (int) $f['token_id'];
    }
    $q = trim((string) ($f['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . addcslashes(mb_substr($q, 0, 190), '%_\\') . '%';
        $where[] = '(l.recipient_email LIKE ? OR l.subject LIKE ? OR l.sender_email LIKE ? OR l.origin_ip LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f['from'] ?? ''))) {
        $where[] = 'l.created_at >= ?';
        $params[] = $f['from'] . ' 00:00:00';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f['to'] ?? ''))) {
        $where[] = 'l.created_at <= ?';
        $params[] = $f['to'] . ' 23:59:59';
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $limit = max(1, min(200, $limit));
    $offset = max(0, $offset);

    $total = (int) db_val("SELECT COUNT(*) FROM email_logs l {$whereSql}", $params);
    $rows = db_all(
        "SELECT l.id, l.status, l.sender_email, l.recipient_email, l.subject, l.origin_ip, l.error_message, l.created_at,
                l.project_id, p.name AS project, l.token_id, t.name AS token_name, t.token_prefix
           FROM email_logs l
           LEFT JOIN projects p ON p.id = l.project_id
           LEFT JOIN api_tokens t ON t.id = l.token_id
           {$whereSql}
          ORDER BY l.id DESC
          LIMIT {$limit} OFFSET {$offset}",
        $params
    );
    return ['total' => $total, 'rows' => $rows];
}

function log_find(int $id): ?array
{
    return db_one(
        'SELECT l.*, p.name AS project, t.name AS token_name, t.token_prefix, t.status AS token_status
           FROM email_logs l
           LEFT JOIN projects p ON p.id = l.project_id
           LEFT JOIN api_tokens t ON t.id = l.token_id
          WHERE l.id = ?',
        [$id]
    );
}

/* ---------------------------------------------------------------------
 *  Visão geral
 * ------------------------------------------------------------------- */

function gateway_overview(): array
{
    $stats = db_one(
        "SELECT
            COALESCE(SUM(status = 'sent'         AND created_at >= CURDATE()), 0)               AS sent_today,
            COALESCE(SUM(status = 'failed'       AND created_at >= CURDATE()), 0)               AS failed_today,
            COALESCE(SUM(status = 'unauthorized' AND created_at >= NOW() - INTERVAL 1 DAY), 0)  AS unauthorized_24h,
            COALESCE(SUM(status = 'sent'), 0)                                                   AS sent_30d
         FROM email_logs
         WHERE created_at >= CURDATE() - INTERVAL 29 DAY"
    ) ?? [];
    $counts = db_one(
        "SELECT (SELECT COUNT(*) FROM projects) AS projects,
                (SELECT COUNT(*) FROM smtp_accounts) AS smtp_accounts,
                (SELECT COUNT(*) FROM smtp_accounts WHERE is_active = 1) AS smtp_accounts_active,
                (SELECT COUNT(*) FROM api_tokens WHERE status = 'active') AS active_tokens"
    ) ?? [];

    $days = [];
    for ($i = 6; $i >= 0; $i--) {
        $days[date('Y-m-d', strtotime("-{$i} days"))] = ['sent' => 0, 'failed' => 0, 'unauthorized' => 0];
    }
    foreach (db_all(
        "SELECT DATE(created_at) AS d, status, COUNT(*) AS c
           FROM email_logs WHERE created_at >= CURDATE() - INTERVAL 6 DAY
          GROUP BY DATE(created_at), status"
    ) as $r) {
        if (isset($days[$r['d']])) {
            $days[$r['d']][$r['status']] = (int) $r['c'];
        }
    }

    return [
        'stats'  => array_map('intval', $stats),
        'counts' => array_map('intval', $counts),
        'days'   => $days,
    ];
}

/* ---------------------------------------------------------------------
 *  Chaves MCP
 * ------------------------------------------------------------------- */

function generate_mcp_key(): array
{
    $random = bin2hex(random_bytes(24));
    $key = 'mcp_' . $random;
    return ['key' => $key, 'hash' => hash('sha256', $key), 'prefix' => 'mcp_' . substr($random, 0, 8)];
}

function is_valid_mcp_key_format(string $key): bool
{
    return (bool) preg_match('/^mcp_[a-f0-9]{48}$/', $key);
}

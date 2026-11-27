<?php
declare(strict_types=1);

/**
 * Ferramentas MCP do gateway.
 * Cada ferramenta: description, inputSchema, write (exige chave com escopo
 * "write"), annotations e handler(array $args): array (objeto JSON).
 */

function mcp_arg_str(array $a, string $key, bool $required = false, int $max = 1000): string
{
    $v = $a[$key] ?? null;
    if ($v === null || $v === '') {
        if ($required) {
            throw new InvalidArgumentException("O campo \"{$key}\" é obrigatório.");
        }
        return '';
    }
    if (!is_string($v) && !is_int($v)) {
        throw new InvalidArgumentException("O campo \"{$key}\" precisa ser texto.");
    }
    $v = trim((string) $v);
    if (mb_strlen($v) > $max) {
        throw new InvalidArgumentException("O campo \"{$key}\" passa de {$max} caracteres.");
    }
    return $v;
}

function mcp_arg_int(array $a, string $key, bool $required = false, int $default = 0): int
{
    $v = $a[$key] ?? null;
    if ($v === null || $v === '') {
        if ($required) {
            throw new InvalidArgumentException("O campo \"{$key}\" é obrigatório.");
        }
        return $default;
    }
    if (is_int($v) || (is_string($v) && ctype_digit($v))) {
        return (int) $v;
    }
    throw new InvalidArgumentException("O campo \"{$key}\" precisa ser um número inteiro.");
}

/** Converte linhas do banco em tipos JSON corretos (ints e bools). */
function mcp_clean_row(array $row, array $ints = [], array $bools = []): array
{
    foreach ($ints as $k) {
        if (array_key_exists($k, $row) && $row[$k] !== null) {
            $row[$k] = (int) $row[$k];
        }
    }
    foreach ($bools as $k) {
        if (array_key_exists($k, $row)) {
            $row[$k] = (bool) $row[$k];
        }
    }
    return $row;
}

function mcp_tools(): array
{
    $ro = ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false];
    $noArgs = ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false];

    return [
        // ------------------------------------------------------------- leitura
        'get_status' => [
            'title' => 'Status do gateway',
            'description' => 'Resumo do gateway: envios e falhas de hoje, bloqueios nas últimas 24h, totais de projetos, contas SMTP e tokens ativos, e a série dos últimos 7 dias.',
            'inputSchema' => $noArgs,
            'write' => false,
            'annotations' => $ro,
            'handler' => function (array $a): array {
                $o = gateway_overview();
                return [
                    'today'            => ['sent' => $o['stats']['sent_today'], 'failed' => $o['stats']['failed_today']],
                    'unauthorized_24h' => $o['stats']['unauthorized_24h'],
                    'sent_last_30d'    => $o['stats']['sent_30d'],
                    'totals'           => $o['counts'],
                    'last_7_days'      => $o['days'],
                    'phpmailer_ok'     => phpmailer_path() !== null,
                    'server_time'      => date('c'),
                ];
            },
        ],

        'list_projects' => [
            'title' => 'Listar projetos',
            'description' => 'Lista os projetos com quantidade de tokens ativos, total de e-mails enviados e última atividade.',
            'inputSchema' => $noArgs,
            'write' => false,
            'annotations' => $ro,
            'handler' => function (array $a): array {
                $rows = array_map(fn($r) => mcp_clean_row($r, ['id', 'active_tokens', 'sent_total']), projects_list());
                return ['count' => count($rows), 'projects' => $rows];
            },
        ],

        'list_smtp_accounts' => [
            'title' => 'Listar contas SMTP',
            'description' => 'Lista as contas de envio (remetentes), com o provedor de cada uma (titan ou resend). Senhas e API keys nunca são retornadas.',
            'inputSchema' => $noArgs,
            'write' => false,
            'annotations' => $ro,
            'handler' => function (array $a): array {
                $rows = array_map(fn($r) => mcp_clean_row($r, ['id', 'smtp_port', 'active_tokens'], ['is_active']), smtp_list());
                return ['count' => count($rows), 'smtp_accounts' => $rows];
            },
        ],

        'test_smtp_account' => [
            'title' => 'Testar conta SMTP',
            'description' => 'Testa a conta sem enviar e-mail. Titan: conecta e autentica no SMTP. Resend: valida a API key e se o domínio do remetente está verificado.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['smtp_account_id' => ['type' => 'integer', 'description' => 'ID da conta (veja list_smtp_accounts).']],
                'required' => ['smtp_account_id'],
                'additionalProperties' => false,
            ],
            'write' => false,
            'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => true],
            'handler' => function (array $a): array {
                $acc = db_one('SELECT * FROM smtp_accounts WHERE id = ?', [mcp_arg_int($a, 'smtp_account_id', true)]);
                if (!$acc) {
                    throw new InvalidArgumentException('Conta SMTP não encontrada.');
                }
                $r = provider_test($acc);
                return ['smtp_account_id' => (int) $acc['id'], 'email' => $acc['email'], 'provider' => account_provider($acc),
                    'ok' => $r['ok'], 'error' => $r['error'], 'warning' => $r['warning'] ?? null];
            },
        ],

        'list_tokens' => [
            'title' => 'Listar tokens de API',
            'description' => 'Lista os tokens de envio (só o prefixo; o token completo nunca é recuperável). Filtros opcionais por status e projeto.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'status'     => ['type' => 'string', 'enum' => ['active', 'revoked'], 'description' => 'Filtra por status.'],
                    'project_id' => ['type' => 'integer', 'description' => 'Filtra por projeto.'],
                ],
                'additionalProperties' => false,
            ],
            'write' => false,
            'annotations' => $ro,
            'handler' => function (array $a): array {
                $rows = api_tokens_list(mcp_arg_str($a, 'status'), mcp_arg_int($a, 'project_id'));
                $rows = array_map(fn($r) => mcp_clean_row($r, ['id', 'project_id', 'smtp_account_id'], ['smtp_active']), $rows);
                return ['count' => count($rows), 'tokens' => $rows];
            },
        ],

        'search_logs' => [
            'title' => 'Buscar logs',
            'description' => 'Busca nos logs de auditoria (envios, falhas e tentativas bloqueadas), do mais recente para o mais antigo.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'status'     => ['type' => 'string', 'enum' => ['sent', 'failed', 'unauthorized']],
                    'provider'   => ['type' => 'string', 'enum' => ['titan', 'resend']],
                    'project_id' => ['type' => 'integer'],
                    'token_id'   => ['type' => 'integer'],
                    'query'      => ['type' => 'string', 'description' => 'Trecho de e-mail, assunto ou IP.'],
                    'date_from'  => ['type' => 'string', 'description' => 'Data inicial, AAAA-MM-DD.'],
                    'date_to'    => ['type' => 'string', 'description' => 'Data final, AAAA-MM-DD.'],
                    'limit'      => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                    'page'       => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                ],
                'additionalProperties' => false,
            ],
            'write' => false,
            'annotations' => $ro,
            'handler' => function (array $a): array {
                $limit = max(1, min(100, mcp_arg_int($a, 'limit', false, 20)));
                $page = max(1, mcp_arg_int($a, 'page', false, 1));
                $r = logs_query([
                    'status'     => mcp_arg_str($a, 'status'),
                    'provider'   => mcp_arg_str($a, 'provider'),
                    'project_id' => mcp_arg_int($a, 'project_id'),
                    'token_id'   => mcp_arg_int($a, 'token_id'),
                    'q'          => mcp_arg_str($a, 'query', false, 190),
                    'from'       => mcp_arg_str($a, 'date_from', false, 10),
                    'to'         => mcp_arg_str($a, 'date_to', false, 10),
                ], $limit, ($page - 1) * $limit);
                $rows = array_map(fn($row) => mcp_clean_row($row, ['id', 'project_id', 'token_id']), $r['rows']);
                return ['total' => $r['total'], 'page' => $page, 'pages' => max(1, (int) ceil($r['total'] / $limit)), 'logs' => $rows];
            },
        ],

        'get_log' => [
            'title' => 'Detalhe de um log',
            'description' => 'Mostra todos os campos de um registro de log, incluindo o erro do SMTP e a prévia do corpo.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['log_id' => ['type' => 'integer']],
                'required' => ['log_id'],
                'additionalProperties' => false,
            ],
            'write' => false,
            'annotations' => $ro,
            'handler' => function (array $a): array {
                $log = log_find(mcp_arg_int($a, 'log_id', true));
                if (!$log) {
                    throw new InvalidArgumentException('Registro não encontrado.');
                }
                return mcp_clean_row($log, ['id', 'token_id', 'project_id', 'smtp_account_id']);
            },
        ],

        // ------------------------------------------------------------- escrita
        'create_project' => [
            'title' => 'Criar projeto',
            'description' => 'Cadastra um projeto (ex.: "Sistema ERP"). O nome precisa ser único.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'name'        => ['type' => 'string', 'maxLength' => 120],
                    'description' => ['type' => 'string', 'maxLength' => 2000],
                ],
                'required' => ['name'],
                'additionalProperties' => false,
            ],
            'write' => true,
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false],
            'handler' => function (array $a): array {
                $id = project_create(mcp_arg_str($a, 'name', true, 120), mcp_arg_str($a, 'description', false, 2000));
                return ['created' => true, 'project_id' => $id];
            },
        ],

        'create_smtp_account' => [
            'title' => 'Cadastrar conta de envio',
            'description' => 'Cadastra um remetente. provider "titan" (padrão): caixa do Titan via SMTP, exige "password" '
                . '(padrões smtp.titan.email, ssl na 465 ou tls na 587). provider "resend": API do Resend, exige "api_key" (re_...) '
                . 'e o domínio do e-mail precisa estar verificado no Resend. Senha e API key são criptografadas e nunca voltam em '
                . 'nenhuma ferramenta. Depois de cadastrar, rode test_smtp_account.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'provider'   => ['type' => 'string', 'enum' => ['titan', 'resend'], 'default' => 'titan'],
                    'email'      => ['type' => 'string', 'format' => 'email'],
                    'from_name'  => ['type' => 'string', 'maxLength' => 120, 'description' => 'Nome de exibição do remetente.'],
                    'password'   => ['type' => 'string', 'description' => 'Titan: senha da caixa de e-mail.'],
                    'api_key'    => ['type' => 'string', 'description' => 'Resend: API key (re_...).'],
                    'smtp_host'  => ['type' => 'string', 'default' => 'smtp.titan.email', 'description' => 'Só Titan.'],
                    'smtp_port'  => ['type' => 'integer', 'description' => 'Só Titan: 465 (ssl) ou 587 (tls).'],
                    'encryption' => ['type' => 'string', 'enum' => ['ssl', 'tls'], 'default' => 'ssl', 'description' => 'Só Titan.'],
                ],
                'required' => ['email'],
                'additionalProperties' => false,
            ],
            'write' => true,
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false],
            'handler' => function (array $a): array {
                $provider = mcp_arg_str($a, 'provider') ?: 'titan';
                if (!in_array($provider, ['titan', 'resend'], true)) {
                    throw new InvalidArgumentException('provider deve ser "titan" ou "resend".');
                }
                $field = $provider === 'resend' ? 'api_key' : 'password';
                $secret = $a[$field] ?? '';
                if (!is_string($secret) || trim($secret) === '') {
                    throw new InvalidArgumentException("O campo \"{$field}\" é obrigatório para o provedor {$provider}.");
                }
                $v = smtp_normalize([
                    'provider'   => $provider,
                    'email'      => mcp_arg_str($a, 'email', true, 190),
                    'from_name'  => mcp_arg_str($a, 'from_name', false, 120),
                    'smtp_host'  => mcp_arg_str($a, 'smtp_host', false, 190),
                    'smtp_port'  => $a['smtp_port'] ?? null,
                    'encryption' => mcp_arg_str($a, 'encryption') ?: 'ssl',
                    'is_active'  => true,
                ]);
                $id = smtp_create($v, $provider === 'resend' ? trim($secret) : $secret);
                $out = ['created' => true, 'smtp_account_id' => $id, 'email' => $v['email'], 'provider' => $provider];
                if ($provider === 'titan') {
                    $out += ['smtp_host' => $v['smtp_host'], 'smtp_port' => $v['smtp_port'], 'encryption' => $v['encryption']];
                }
                return $out;
            },
        ],

        'create_token' => [
            'title' => 'Gerar token de API',
            'description' => 'Gera um token de envio vinculado a UM projeto e a UMA conta SMTP. O token completo aparece só nesta resposta '
                . '(no banco fica apenas o hash): entregue-o ao usuário e recomende guardá-lo como variável de ambiente.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'name'            => ['type' => 'string', 'maxLength' => 120, 'description' => 'Identificação do serviço, ex.: "ERP · produção".'],
                    'project_id'      => ['type' => 'integer'],
                    'smtp_account_id' => ['type' => 'integer'],
                ],
                'required' => ['name', 'project_id', 'smtp_account_id'],
                'additionalProperties' => false,
            ],
            'write' => true,
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false],
            'handler' => function (array $a): array {
                $t = api_token_create(mcp_arg_str($a, 'name', true, 120), mcp_arg_int($a, 'project_id', true), mcp_arg_int($a, 'smtp_account_id', true));
                return ['created' => true, 'token_id' => $t['id'], 'token' => $t['token'], 'prefix' => $t['prefix'],
                    'sender' => $t['email'], 'warning' => 'Este token não será mostrado de novo.'];
            },
        ],

        'revoke_token' => [
            'title' => 'Revogar token de API',
            'description' => 'Revoga um token de envio na hora: a aplicação que o usa passa a receber 401. Não dá para desfazer (gere outro token).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['token_id' => ['type' => 'integer']],
                'required' => ['token_id'],
                'additionalProperties' => false,
            ],
            'write' => true,
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
            'handler' => function (array $a): array {
                $id = mcp_arg_int($a, 'token_id', true);
                if (!db_val('SELECT id FROM api_tokens WHERE id = ?', [$id])) {
                    throw new InvalidArgumentException('Token não encontrado.');
                }
                $changed = api_token_revoke($id);
                return ['token_id' => $id, 'revoked' => true, 'already_revoked' => !$changed];
            },
        ],
    ];
}

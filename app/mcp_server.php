<?php
declare(strict_types=1);

/**
 * Servidor MCP (Model Context Protocol) — transporte Streamable HTTP, sem estado.
 * Cada POST traz uma mensagem JSON-RPC 2.0 e recebe a resposta em JSON.
 * Não há stream SSE: GET responde 405, como a especificação permite.
 */

const MCP_PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];
const MCP_SERVER_VERSION = '1.0.0';

function mcp_send(int $status, ?array $payload): never
{
    http_response_code($status);
    if ($payload !== null) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    exit;
}

function rpc_result(mixed $id, mixed $result): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
}

function rpc_error(mixed $id, int $code, string $message): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
}

/** Resultado de ferramenta com erro (o modelo vê a mensagem e pode corrigir). */
function mcp_tool_error(string $message): array
{
    return ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true];
}

function mcp_tool_ok(array $data): array
{
    return [
        'content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)]],
        'structuredContent' => (object) $data,
        'isError' => false,
    ];
}

/** Grava ações de escrita; nunca guarda senhas. */
function mcp_audit(int $keyId, string $tool, array $args, bool $success, string $message, string $ip): void
{
    unset($args['password'], $args['api_key']);
    try {
        db_run(
            'INSERT INTO mcp_audit (key_id, tool, arguments, success, message, origin_ip) VALUES (?, ?, ?, ?, ?, ?)',
            [$keyId, $tool, str_cut(json_encode($args, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 5000), $success ? 1 : 0, str_cut($message, 1000), $ip]
        );
    } catch (Throwable $e) {
        error_log('[mail-gateway] falha na auditoria MCP: ' . $e->getMessage());
    }
}

/** Ferramentas visíveis para a chave (somente leitura não vê as de escrita). */
function mcp_tools_for(array $key): array
{
    $tools = mcp_tools();
    if ($key['scope'] !== 'write') {
        $tools = array_filter($tools, fn($t) => !$t['write']);
    }
    return $tools;
}

function mcp_dispatch(mixed $msg, array $key, string $ip): ?array
{
    if (!is_array($msg) || ($msg['jsonrpc'] ?? null) !== '2.0') {
        return rpc_error(null, -32600, 'Invalid Request');
    }
    // Respostas do cliente a requisições do servidor: não usamos, apenas aceitamos.
    if (!isset($msg['method']) && (array_key_exists('result', $msg) || array_key_exists('error', $msg))) {
        return null;
    }
    if (!is_string($msg['method'] ?? null)) {
        return rpc_error($msg['id'] ?? null, -32600, 'Invalid Request');
    }
    // Notificações (sem id) não recebem resposta: notifications/initialized, cancelled etc.
    if (!array_key_exists('id', $msg)) {
        return null;
    }
    $id = $msg['id'];
    $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];

    switch ($msg['method']) {
        case 'initialize':
            $requested = (string) ($params['protocolVersion'] ?? '');
            return rpc_result($id, [
                'protocolVersion' => in_array($requested, MCP_PROTOCOL_VERSIONS, true) ? $requested : MCP_PROTOCOL_VERSIONS[0],
                'capabilities'    => ['tools' => ['listChanged' => false]],
                'serverInfo'      => ['name' => 'demairan-mail-gateway', 'title' => 'Demairan Mail Gateway', 'version' => MCP_SERVER_VERSION],
                'instructions'    => 'Administra o gateway de e-mails transacionais da Demairan (SMTP Titan). '
                    . 'Cada token de envio pertence a um projeto e só envia como uma conta SMTP. '
                    . 'Tokens completos aparecem uma única vez, em create_token. Senhas SMTP nunca são retornadas. '
                    . ($key['scope'] === 'write' ? 'Esta chave pode criar e revogar cadastros.' : 'Esta chave é somente leitura.'),
            ]);

        case 'ping':
            return rpc_result($id, new stdClass());

        case 'tools/list':
            $list = [];
            foreach (mcp_tools_for($key) as $name => $t) {
                $list[] = ['name' => $name, 'title' => $t['title'], 'description' => $t['description'],
                    'inputSchema' => $t['inputSchema'], 'annotations' => $t['annotations'] + ['title' => $t['title']]];
            }
            return rpc_result($id, ['tools' => $list]);

        case 'tools/call':
            $name = is_string($params['name'] ?? null) ? $params['name'] : '';
            $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
            $tools = mcp_tools();
            if (!isset($tools[$name])) {
                return rpc_error($id, -32602, "Ferramenta desconhecida: {$name}");
            }
            $tool = $tools[$name];
            if ($tool['write'] && $key['scope'] !== 'write') {
                return rpc_result($id, mcp_tool_error('Esta chave MCP é somente leitura. Gere uma chave com permissão de escrita no painel (Acesso MCP).'));
            }
            try {
                $data = ($tool['handler'])($args);
                if ($tool['write']) {
                    mcp_audit((int) $key['id'], $name, $args, true, 'ok', $ip);
                }
                return rpc_result($id, mcp_tool_ok($data));
            } catch (InvalidArgumentException $e) {
                if ($tool['write']) {
                    mcp_audit((int) $key['id'], $name, $args, false, $e->getMessage(), $ip);
                }
                return rpc_result($id, mcp_tool_error($e->getMessage()));
            } catch (Throwable $e) {
                error_log("[mail-gateway] erro na ferramenta MCP {$name}: " . $e->getMessage());
                if ($tool['write']) {
                    mcp_audit((int) $key['id'], $name, $args, false, 'erro interno', $ip);
                }
                return rpc_result($id, mcp_tool_error('Erro interno ao executar a ferramenta. Veja o error_log do servidor.'));
            }

        case 'resources/list':
            return rpc_result($id, ['resources' => []]);

        case 'prompts/list':
            return rpc_result($id, ['prompts' => []]);

        default:
            return rpc_error($id, -32601, 'Method not found');
    }
}

function mcp_handle_request(): never
{
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if ($method !== 'POST') {
        header('Allow: POST');
        mcp_send(405, null);
    }
    if (config('force_https', true) && !is_https()) {
        mcp_send(403, rpc_error(null, -32000, 'O endpoint MCP só aceita HTTPS.'));
    }

    // Proteção contra DNS rebinding: navegadores enviam Origin; clientes MCP não.
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && !in_array($origin, (array) config('mcp.allowed_origins', []), true)) {
        mcp_send(403, rpc_error(null, -32000, 'Origin não permitido.'));
    }

    try {
        db();
    } catch (Throwable $e) {
        error_log('[mail-gateway] banco indisponível (MCP): ' . $e->getMessage());
        mcp_send(503, rpc_error(null, -32000, 'Serviço temporariamente indisponível.'));
    }

    // Autenticação por chave MCP (Bearer), com bloqueio de força bruta por IP.
    $ip = client_ip();
    if (login_is_throttled($ip)) {
        header('Retry-After: ' . ((int) config('login.window_minutes', 15) * 60));
        mcp_send(429, rpc_error(null, -32000, 'Muitas tentativas inválidas deste IP. Tente mais tarde.'));
    }
    $raw = bearer_token();
    $key = ($raw !== null && is_valid_mcp_key_format($raw))
        ? db_one("SELECT id, name, scope FROM mcp_keys WHERE key_hash = ? AND status = 'active'", [hash('sha256', $raw)])
        : null;
    if (!$key) {
        record_login_attempt($ip, 'mcp-key', false);
        header('WWW-Authenticate: Bearer');
        mcp_send(401, rpc_error(null, -32001, 'Chave MCP ausente, inválida ou revogada.'));
    }
    db_run('UPDATE mcp_keys SET last_used_at = NOW(), last_used_ip = ? WHERE id = ?', [$ip, $key['id']]);

    $body = (string) file_get_contents('php://input', false, null, 0, 1048577);
    if (strlen($body) > 1048576) {
        mcp_send(413, rpc_error(null, -32600, 'Requisição grande demais.'));
    }
    $msg = json_decode($body, true);
    if (!is_array($msg)) {
        mcp_send(400, rpc_error(null, -32700, 'Parse error'));
    }

    // Lote (permitido na versão 2025-03-26 do protocolo).
    if ($msg !== [] && array_is_list($msg)) {
        $out = [];
        foreach ($msg as $m) {
            $r = mcp_dispatch($m, $key, $ip);
            if ($r !== null) {
                $out[] = $r;
            }
        }
        $out ? mcp_send(200, $out) : mcp_send(202, null);
    }

    $response = mcp_dispatch($msg, $key, $ip);
    $response === null ? mcp_send(202, null) : mcp_send(200, $response);
}

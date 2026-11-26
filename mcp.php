<?php
/**
 * Endpoint MCP para o Claude: POST /mcp.php
 * Authorization: Bearer mcp_xxxxxxxx...
 * Chaves são geradas no painel, em "Acesso MCP".
 */
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require APP_DIR . '/mcp_tools.php';
require APP_DIR . '/mcp_server.php';

mcp_handle_request();

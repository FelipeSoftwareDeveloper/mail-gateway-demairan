<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$me = require_admin();

$tablesReady = true;
try {
    db_val('SELECT 1 FROM mcp_keys LIMIT 1');
} catch (PDOException $e) {
    $tablesReady = false;
}

if (is_post() && $tablesReady) {
    $action = post('action');
    $id = (int) post('id');

    if ($action === 'create') {
        $name = post('name');
        $scope = post('scope') === 'write' ? 'write' : 'read';
        if ($name === '' || mb_strlen($name) > 120) {
            flash('error', 'Informe um nome para a chave (até 120 caracteres).');
        } else {
            $k = generate_mcp_key();
            $keyId = db_insert(
                'INSERT INTO mcp_keys (name, key_hash, key_prefix, scope, created_by) VALUES (?, ?, ?, ?, ?)',
                [$name, $k['hash'], $k['prefix'], $scope, (int) $me['id']]
            );
            $_SESSION['_new_mcp_key'] = ['id' => $keyId, 'key' => $k['key'], 'name' => $name, 'scope' => $scope];
        }
    } elseif ($action === 'revoke') {
        db_run("UPDATE mcp_keys SET status = 'revoked', revoked_at = NOW() WHERE id = ? AND status = 'active'", [$id]);
        flash('success', 'Chave revogada. O Claude perde o acesso na próxima chamada.');
    } elseif ($action === 'delete') {
        $n = db_run("DELETE FROM mcp_keys WHERE id = ? AND status = 'revoked'", [$id])->rowCount();
        flash($n ? 'success' : 'error', $n ? 'Chave excluída.' : 'Só chaves revogadas podem ser excluídas.');
    }
    redirect('mcp_keys.php');
}

$newKey = $_SESSION['_new_mcp_key'] ?? null;
unset($_SESSION['_new_mcp_key']);

$keys = $tablesReady ? db_all(
    'SELECT k.*, a.name AS admin_name FROM mcp_keys k LEFT JOIN admins a ON a.id = k.created_by
      ORDER BY k.status = \'active\' DESC, k.created_at DESC'
) : [];
$audit = $tablesReady ? db_all(
    'SELECT m.*, k.name AS key_name FROM mcp_audit m LEFT JOIN mcp_keys k ON k.id = m.key_id ORDER BY m.id DESC LIMIT 30'
) : [];

$base = (is_https() ? 'https://' : 'http://')
    . preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'seu-dominio.com.br'))
    . rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
$endpoint = $base . '/mcp.php';
$hostingCookie = (string) config('api.hosting_cookie', '');
if (!preg_match('/^[A-Za-z0-9_\-]+=[A-Za-z0-9_\-]+$/', $hostingCookie)) {
    $hostingCookie = '';
}
$keyForExample = $newKey['key'] ?? 'mcp_SUA_CHAVE';

render_header('Acesso MCP', 'mcp');
page_head('Acesso MCP', 'Chaves para o Claude consultar e administrar o gateway pelo Model Context Protocol.');

if (!$tablesReady): ?>
  <div class="alert alert-warning">As tabelas do MCP ainda não existem. No phpMyAdmin, abra a aba <strong>SQL</strong> e rode o conteúdo de <code>migrations/002_mcp.sql</code>.</div>
<?php endif;

if ($newKey): ?>
<section class="reveal" aria-live="polite">
  <h2>Chave criada: <?= e($newKey['name']) ?> (<?= $newKey['scope'] === 'write' ? 'leitura e escrita' : 'somente leitura' ?>)</h2>
  <p>Copie agora. Guardamos só o hash e <strong>ela não será mostrada de novo</strong>.</p>
  <div class="reveal-token">
    <code id="new-key"><?= e($newKey['key']) ?></code>
    <button class="btn btn-primary" type="button" data-copy="#new-key"><?= icon('copy') ?><span data-copy-label>Copiar</span></button>
  </div>
</section>
<?php endif; ?>

<section class="grid-2">
  <div class="card">
    <div class="card-head"><h2>Chaves</h2></div>
    <?php if (!$keys): ?>
      <div class="empty"><h3>Nenhuma chave</h3><p>Crie uma chave para conectar o Claude.</p></div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Nome</th><th>Permissão</th><th>Último uso</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($keys as $k): ?>
          <tr>
            <td><strong><?= e($k['name']) ?></strong><span class="cell-sub mono"><?= e($k['key_prefix']) ?>…</span></td>
            <td><?= $k['scope'] === 'write' ? '<span class="badge badge-warning">Leitura e escrita</span>' : '<span class="badge badge-neutral">Somente leitura</span>' ?></td>
            <td class="nowrap muted"><?= e(fmt_dt($k['last_used_at'])) ?><span class="cell-sub mono"><?= e($k['last_used_ip'] ?? '') ?></span></td>
            <td><?= status_badge($k['status']) ?></td>
            <td>
              <div class="actions">
              <?php if ($k['status'] === 'active'): ?>
                <form method="post" data-confirm="Revogar a chave &quot;<?= e($k['name']) ?>&quot;? O Claude perde o acesso na hora.">
                  <?= csrf_field() ?><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit">Revogar</button>
                </form>
              <?php else: ?>
                <form method="post" data-confirm="Excluir esta chave revogada?">
                  <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit">Excluir</button>
                </form>
              <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-head"><h2>Nova chave</h2></div>
    <div class="card-body">
      <form method="post" class="form">
        <?= csrf_field() ?><input type="hidden" name="action" value="create">
        <div class="field"><label for="name">Nome</label><input class="input" id="name" name="name" required maxlength="120" placeholder="Claude Code · notebook"></div>
        <div class="field">
          <label for="scope">Permissão</label>
          <select class="select" id="scope" name="scope">
            <option value="read">Somente leitura (status, listas e logs)</option>
            <option value="write">Leitura e escrita (cadastrar projetos, contas SMTP e tokens; revogar tokens)</option>
          </select>
          <span class="hint">Prefira somente leitura. Use escrita só quando for cadastrar pelo Claude.</span>
        </div>
        <button class="btn btn-primary" type="submit" <?= $tablesReady ? '' : 'disabled' ?>>Gerar chave</button>
      </form>
    </div>
  </div>
</section>

<section class="card">
  <div class="card-head"><h2>Como conectar</h2></div>
  <div class="card-body">
    <p class="label">Claude Code (terminal)</p>
    <pre class="codeblock mt-4" id="cfg-code">claude mcp add --transport http demairan-mail <?= e($endpoint) ?> \
  --header "Authorization: Bearer <?= e($keyForExample) ?>"<?php if ($hostingCookie !== ''): ?> \
  --header "Cookie: <?= e($hostingCookie) ?>"<?php endif; ?></pre>
    <p class="label mt-6">Claude Desktop (claude_desktop_config.json, requer Node.js)</p>
    <pre class="codeblock mt-4" id="cfg-desktop">{
  "mcpServers": {
    "demairan-mail": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "<?= e($endpoint) ?>",
               "--header", "Authorization:${MCP_AUTH}"<?php if ($hostingCookie !== ''): ?>,
               "--header", "Cookie:${MCP_COOKIE}"<?php endif; ?>],
      "env": {
        "MCP_AUTH": "Bearer <?= e($keyForExample) ?>"<?php if ($hostingCookie !== ''): ?>,
        "MCP_COOKIE": "<?= e($hostingCookie) ?>"<?php endif; ?>

      }
    }
  }
}</pre>
    <?php if ($hostingCookie === ''): ?>
      <p class="muted small mt-4">Se a HostGator responder com o desafio anti-robô (<code>humans_…</code>), preencha <code>api.hosting_cookie</code> no config.php: os exemplos acima passam a incluir o cookie.</p>
    <?php endif; ?>
  </div>
</section>

<section class="card">
  <div class="card-head"><h2>Ações feitas pelo Claude</h2><span class="muted small">Últimas 30 ações de escrita</span></div>
  <?php if (!$audit): ?>
    <div class="empty"><p>Nenhuma ação de escrita registrada.</p></div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Quando</th><th>Ferramenta</th><th>Argumentos</th><th>Resultado</th><th>Chave · IP</th></tr></thead>
      <tbody>
      <?php foreach ($audit as $a): ?>
        <tr>
          <td class="nowrap muted"><?= e(fmt_dt($a['created_at'], 'd/m/Y H:i:s')) ?></td>
          <td class="mono"><?= e($a['tool']) ?></td>
          <td><span class="truncate mono"><?= e($a['arguments'] ?? '') ?></span></td>
          <td><?= $a['success'] ? '<span class="badge badge-success">OK</span>' :'<span class="badge badge-danger">Erro</span><span class="cell-sub truncate">' . e($a['message']) . '</span>' ?></td>
          <td><?= e($a['key_name'] ?? '—') ?><span class="cell-sub mono"><?= e($a['origin_ip']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php
render_footer();

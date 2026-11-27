<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

require_admin();

if (is_post()) {
    $action = post('action');
    $id = (int) post('id');

    if ($action === 'create') {
        try {
            $created = api_token_create(post('name'), (int) post('project_id'), (int) post('smtp_account_id'));
            // O token completo fica na sessão só até a próxima página ser exibida.
            $_SESSION['_new_token'] = $created;
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        }
        redirect('tokens.php');
    }

    if ($action === 'revoke') {
        api_token_revoke($id);
        flash('success', 'Token revogado. Novas requisições com ele recebem erro 401 imediatamente.');
    } elseif ($action === 'delete') {
        $n = db_run("DELETE FROM api_tokens WHERE id = ? AND status = 'revoked'", [$id])->rowCount();
        flash($n ? 'success' : 'error', $n ? 'Token excluído. Os logs dele foram mantidos.' : 'Só tokens revogados podem ser excluídos.');
    }
    redirect('tokens.php');
}

$newToken = $_SESSION['_new_token'] ?? null;
unset($_SESSION['_new_token']);

$filter = query('status');
$tokens = api_tokens_list($filter);
$projects = db_all('SELECT id, name FROM projects ORDER BY name');
$accounts = db_all('SELECT id, email, from_name, provider FROM smtp_accounts WHERE is_active = 1 ORDER BY email');

$endpoint = (is_https() ? 'https://' : 'http://')
    . preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'seu-dominio.com.br'))
    . rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/api/send.php';

// Cookie da proteção anti-robô da HostGator (api.hosting_cookie no config.php).
// Só nome=valor com caracteres seguros, para não quebrar o comando de exemplo.
$hostingCookie = (string) config('api.hosting_cookie', '');
if (!preg_match('/^[A-Za-z0-9_\-]+=[A-Za-z0-9_\-]+$/', $hostingCookie)) {
    $hostingCookie = '';
}

render_header('Tokens de API', 'tokens');
page_head('Tokens de API', 'Cada token pertence a um projeto e só envia como uma conta SMTP.');

if ($newToken): ?>
<section class="reveal" aria-live="polite">
  <h2>Token criado: <?= e($newToken['name']) ?></h2>
  <p>Copie agora. Por segurança, guardamos só o hash e <strong>ele não será mostrado de novo</strong>.</p>
  <div class="reveal-token">
    <code id="new-token"><?= e($newToken['token']) ?></code>
    <button class="btn btn-primary" type="button" data-copy="#new-token"><?= icon('copy') ?><span data-copy-label>Copiar</span></button>
  </div>
  <p class="small muted">Exemplo de uso (este token só envia como <strong><?= e($newToken['email']) ?></strong>):</p>
  <pre class="codeblock mt-4" id="curl-example">curl -X POST <?= e($endpoint) ?> \
  -H "Authorization: Bearer <?= e($newToken['token']) ?>" \
  -H "Content-Type: application/json" \
<?php if ($hostingCookie !== ''): ?>  -H "Cookie: <?= e($hostingCookie) ?>" \
<?php endif; ?>  -d '{"from":"<?= e($newToken['email']) ?>","to":"cliente@exemplo.com","subject":"Teste","html":"&lt;p&gt;Olá!&lt;/p&gt;","text":"Olá!"}'</pre>
</section>
<?php endif; ?>

<section class="grid-2">
  <div class="card">
    <div class="card-head">
      <h2>Tokens</h2>
      <div class="page-actions">
        <a class="btn btn-sm <?= $filter === '' ? 'btn-dark' : 'btn-secondary' ?>" href="tokens.php">Todos</a>
        <a class="btn btn-sm <?= $filter === 'active' ? 'btn-dark' : 'btn-secondary' ?>" href="tokens.php?status=active">Ativos</a>
        <a class="btn btn-sm <?= $filter === 'revoked' ? 'btn-dark' : 'btn-secondary' ?>" href="tokens.php?status=revoked">Revogados</a>
      </div>
    </div>
    <?php if (!$tokens): ?>
      <div class="empty"><h3>Nenhum token</h3><p>Gere um token para cada aplicação que vai enviar e-mails.</p></div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Nome</th><th>Projeto · Remetente</th><th>Último uso</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($tokens as $t): ?>
          <tr>
            <td><strong><?= e($t['name']) ?></strong><span class="cell-sub mono"><?= e($t['token_prefix']) ?>…</span></td>
            <td><?= e($t['project']) ?><span class="cell-sub"><?= e($t['smtp_email']) ?><?= $t['smtp_active'] ? '' : ' (conta inativa)' ?></span></td>
            <td class="nowrap muted"><?= e(fmt_dt($t['last_used_at'])) ?><span class="cell-sub">criado <?= e(fmt_dt($t['created_at'], 'd/m/Y')) ?></span></td>
            <td><?= status_badge($t['status']) ?></td>
            <td>
              <div class="actions">
                <a class="btn btn-secondary btn-sm" href="logs.php?token_id=<?= (int) $t['id'] ?>">Logs</a>
                <?php if ($t['status'] === 'active'): ?>
                  <form method="post" data-confirm="Revogar o token &quot;<?= e($t['name']) ?>&quot;? A aplicação que usa este token para de enviar na hora.">
                    <?= csrf_field() ?><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                    <button class="btn btn-danger btn-sm" type="submit">Revogar</button>
                  </form>
                <?php else: ?>
                  <form method="post" data-confirm="Excluir este token revogado? Os logs serão mantidos.">
                    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
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
    <div class="card-head"><h2>Novo token</h2></div>
    <div class="card-body">
      <?php if (!$projects || !$accounts): ?>
        <div class="alert alert-info">Antes de gerar um token, cadastre
          <?= !$accounts ? '<a href="smtp.php">uma conta SMTP ativa</a>' : '' ?><?= !$accounts && !$projects ? ' e ' : '' ?><?= !$projects ? '<a href="projects.php">um projeto</a>' : '' ?>.</div>
      <?php else: ?>
      <form method="post" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="field">
          <label for="name">Nome do serviço</label>
          <input class="input" id="name" name="name" required maxlength="120" placeholder="ERP · produção">
        </div>
        <div class="field">
          <label for="project_id">Projeto</label>
          <select class="select" id="project_id" name="project_id" required>
            <?php foreach ($projects as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="smtp_account_id">Conta de envio</label>
          <select class="select" id="smtp_account_id" name="smtp_account_id" required>
            <?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e($a['email']) ?><?= $a['from_name'] ? ' · ' . e($a['from_name']) : '' ?> (<?= account_provider($a) === 'resend' ? 'Resend' : 'Titan' ?>)</option><?php endforeach; ?>
          </select>
          <span class="hint">O token só poderá enviar como este endereço. Qualquer outro "from" recebe erro 403.</span>
        </div>
        <button class="btn btn-primary" type="submit">Gerar token</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php
render_footer();

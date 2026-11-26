<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

require_admin();

if (is_post()) {
    $action = post('action');
    $id = (int) post('id');
    $password = (string) ($_POST['smtp_password'] ?? '');

    if ($action === 'create' || $action === 'update') {
        $v = smtp_normalize([
            'email'      => post('email'),
            'from_name'  => post('from_name'),
            'smtp_host'  => post('smtp_host'),
            'smtp_port'  => post('smtp_port'),
            'encryption' => post('encryption', 'ssl'),
            'is_active'  => isset($_POST['is_active']),
        ]);
        $errors = smtp_validate($v, $action === 'create', $password, $action === 'update' ? $id : 0);
        if ($errors) {
            foreach ($errors as $err) flash('error', $err);
            $_SESSION['_old_smtp'] = $v;
            redirect($action === 'update' ? 'smtp.php?edit=' . $id : 'smtp.php');
        }
        if ($action === 'create') {
            smtp_create($v, $password);
            flash('success', 'Conta SMTP cadastrada. Use "Testar" para validar a senha.');
        } else {
            db_run(
                'UPDATE smtp_accounts SET email = ?, from_name = ?, smtp_host = ?, smtp_port = ?, encryption = ?, is_active = ? WHERE id = ?',
                [$v['email'], $v['from_name'], $v['smtp_host'], $v['smtp_port'], $v['encryption'], $v['is_active'], $id]
            );
            if ($password !== '') {
                db_run('UPDATE smtp_accounts SET smtp_password_encrypted = ? WHERE id = ?', [encrypt_secret($password), $id]);
            }
            flash('success', 'Conta SMTP atualizada.');
        }
    } elseif ($action === 'toggle') {
        db_run('UPDATE smtp_accounts SET is_active = 1 - is_active WHERE id = ?', [$id]);
        flash('success', 'Status da conta alterado.');
    } elseif ($action === 'test') {
        $acc = db_one('SELECT * FROM smtp_accounts WHERE id = ?', [$id]);
        if ($acc) {
            $r = smtp_test($acc);
            $r['ok']
                ? flash('success', "Conexão e autenticação OK em {$acc['smtp_host']}:{$acc['smtp_port']} para {$acc['email']}.")
                : flash('error', "Falha no teste de {$acc['email']}: {$r['error']}");
        }
    } elseif ($action === 'delete') {
        $tokens = (int) db_val('SELECT COUNT(*) FROM api_tokens WHERE smtp_account_id = ?', [$id]);
        if ($tokens > 0) {
            flash('error', "Esta conta tem {$tokens} token(s) vinculados. Exclua os tokens antes (ou apenas desative a conta).");
        } else {
            db_run('DELETE FROM smtp_accounts WHERE id = ?', [$id]);
            flash('success', 'Conta SMTP excluída.');
        }
    }
    redirect('smtp.php');
}

$editing = null;
if ($editId = query_int('edit')) {
    $editing = db_one('SELECT id, email, from_name, smtp_host, smtp_port, encryption, is_active FROM smtp_accounts WHERE id = ?', [$editId]);
}
$old = $_SESSION['_old_smtp'] ?? null;
unset($_SESSION['_old_smtp']);
$f = $old ?? $editing ?? ['email' => '', 'from_name' => '', 'smtp_host' => 'smtp.titan.email', 'smtp_port' => 465, 'encryption' => 'ssl', 'is_active' => 1];

$accounts = smtp_list();

render_header('Contas SMTP', 'smtp');
page_head('Contas SMTP', 'Caixas do Titan Email que fazem os disparos. As senhas ficam criptografadas (AES-256-GCM).');
?>
<section class="grid-2">
  <div class="card">
    <?php if (!$accounts): ?>
      <div class="empty"><h3>Nenhuma conta</h3><p>Cadastre a primeira caixa do Titan no formulário ao lado.</p></div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Conta</th><th>Servidor</th><th>Tokens</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($accounts as $a): ?>
          <tr>
            <td><strong><?= e($a['email']) ?></strong><span class="cell-sub"><?= e($a['from_name'] ?: 'Sem nome de exibição') ?></span></td>
            <td class="nowrap"><span class="mono"><?= e($a['smtp_host']) ?>:<?= (int) $a['smtp_port'] ?></span><span class="cell-sub"><?= e(strtoupper($a['encryption'])) ?> · último envio <?= e(fmt_dt($a['last_sent'])) ?></span></td>
            <td><?= fmt_num($a['active_tokens']) ?></td>
            <td><?= $a['is_active'] ? status_badge('active') : '<span class="badge badge-neutral">Inativa</span>' ?></td>
            <td>
              <div class="actions">
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><button class="btn btn-secondary btn-sm" type="submit">Testar</button></form>
                <a class="btn btn-secondary btn-sm" href="smtp.php?edit=<?= (int) $a['id'] ?>">Editar</a>
                <form method="post" data-confirm="<?= $a['is_active'] ? 'Desativar esta conta? Os tokens dela passam a receber erro 403.' : 'Reativar esta conta?' ?>">
                  <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                  <button class="btn btn-secondary btn-sm" type="submit"><?= $a['is_active'] ? 'Desativar' : 'Ativar' ?></button>
                </form>
                <form method="post" data-confirm="Excluir a conta <?= e($a['email']) ?>?">
                  <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit">Excluir</button>
                </form>
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
    <div class="card-head"><h2><?= $editing ? 'Editar conta' : 'Nova conta SMTP' ?></h2></div>
    <div class="card-body">
      <form method="post" class="form" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
        <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
        <div class="field">
          <label for="email">E-mail (usuário SMTP)</label>
          <input class="input" type="email" id="email" name="email" required maxlength="190" value="<?= e($f['email']) ?>" placeholder="avisos@demaria.com.br">
        </div>
        <div class="field">
          <label for="from_name">Nome de exibição</label>
          <input class="input" id="from_name" name="from_name" maxlength="120" value="<?= e($f['from_name']) ?>" placeholder="Demaria Avisos">
        </div>
        <div class="field">
          <label for="smtp_password">Senha da caixa</label>
          <div class="reveal-token">
            <input class="input" type="password" id="smtp_password" name="smtp_password" <?= $editing ? '' : 'required' ?> autocomplete="new-password" placeholder="<?= $editing ? 'Deixe em branco para manter a atual' : '' ?>">
            <button class="btn btn-secondary" type="button" data-toggle-password="#smtp_password">Mostrar</button>
          </div>
        </div>
        <div class="field">
          <label for="smtp_host">Servidor</label>
          <input class="input" id="smtp_host" name="smtp_host" required value="<?= e($f['smtp_host']) ?>">
        </div>
        <div class="form-row">
          <div class="field">
            <label for="encryption">Criptografia</label>
            <select class="select" id="encryption" name="encryption">
              <option value="ssl" <?= $f['encryption'] === 'ssl' ? 'selected' : '' ?>>SSL (porta 465)</option>
              <option value="tls" <?= $f['encryption'] === 'tls' ? 'selected' : '' ?>>STARTTLS (porta 587)</option>
            </select>
          </div>
          <div class="field">
            <label for="smtp_port">Porta</label>
            <input class="input" type="number" id="smtp_port" name="smtp_port" min="1" max="65535" required value="<?= (int) $f['smtp_port'] ?>">
          </div>
        </div>
        <label class="check"><input type="checkbox" name="is_active" value="1" <?= $f['is_active'] ? 'checked' : '' ?>> Conta ativa</label>
        <div class="form-actions">
          <button class="btn btn-primary" type="submit"><?= $editing ? 'Salvar' : 'Cadastrar conta' ?></button>
          <?php if ($editing): ?><a class="btn btn-secondary" href="smtp.php">Cancelar</a><?php endif; ?>
        </div>
        <p class="muted small">Titan: <span class="mono">smtp.titan.email</span>, porta 465 com SSL ou 587 com STARTTLS.</p>
      </form>
    </div>
  </div>
</section>
<?php
render_footer();

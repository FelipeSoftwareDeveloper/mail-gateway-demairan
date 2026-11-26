<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

require_admin();

function smtp_form_values(): array
{
    return [
        'email'      => strtolower(post('email')),
        'from_name'  => post('from_name'),
        'smtp_host'  => strtolower(post('smtp_host', 'smtp.titan.email')),
        'smtp_port'  => (int) post('smtp_port', '465'),
        'encryption' => post('encryption', 'ssl'),
        'is_active'  => isset($_POST['is_active']) ? 1 : 0,
    ];
}

function smtp_validate(array $v, bool $passwordRequired, string $password): array
{
    $errors = [];
    if (!is_valid_email($v['email'])) $errors[] = 'E-mail inválido.';
    if (mb_strlen($v['from_name']) > 120 || preg_match('/[\r\n]/', $v['from_name'])) $errors[] = 'Nome de exibição inválido (até 120 caracteres).';
    if (!preg_match('/^(?=.{1,190}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $v['smtp_host'])) $errors[] = 'Servidor SMTP inválido.';
    if ($v['smtp_port'] < 1 || $v['smtp_port'] > 65535) $errors[] = 'Porta inválida.';
    if (!in_array($v['encryption'], ['ssl', 'tls'], true)) $errors[] = 'Criptografia deve ser SSL ou TLS.';
    if ($passwordRequired && $password === '') $errors[] = 'Informe a senha da caixa de e-mail.';
    if (strlen($password) > 500) $errors[] = 'Senha longa demais.';
    return $errors;
}

if (is_post()) {
    $action = post('action');
    $id = (int) post('id');
    $password = (string) ($_POST['smtp_password'] ?? '');

    if ($action === 'create' || $action === 'update') {
        $v = smtp_form_values();
        $errors = smtp_validate($v, $action === 'create', $password);
        if (db_val('SELECT id FROM smtp_accounts WHERE email = ? AND id <> ?', [$v['email'], $id])) {
            $errors[] = 'Esse e-mail já está cadastrado.';
        }
        if (!app_key_is_valid()) {
            $errors[] = 'A app_key do config.php está ausente ou inválida.';
        }
        if ($errors) {
            foreach ($errors as $err) flash('error', $err);
            $_SESSION['_old_smtp'] = $v;
            redirect($action === 'update' ? 'smtp.php?edit=' . $id : 'smtp.php');
        }
        if ($action === 'create') {
            db_run(
                'INSERT INTO smtp_accounts (email, from_name, smtp_password_encrypted, smtp_host, smtp_port, encryption, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$v['email'], $v['from_name'], encrypt_secret($password), $v['smtp_host'], $v['smtp_port'], $v['encryption'], $v['is_active']]
            );
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

$accounts = db_all(
    "SELECT s.id, s.email, s.from_name, s.smtp_host, s.smtp_port, s.encryption, s.is_active, s.created_at,
            (SELECT COUNT(*) FROM api_tokens t WHERE t.smtp_account_id = s.id AND t.status = 'active') AS active_tokens,
            (SELECT MAX(l.created_at) FROM email_logs l WHERE l.smtp_account_id = s.id AND l.status = 'sent') AS last_sent
       FROM smtp_accounts s ORDER BY s.email"
);

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

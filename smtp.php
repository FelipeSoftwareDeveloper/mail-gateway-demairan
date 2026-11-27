<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

require_admin();

if (is_post()) {
    $action = post('action');
    $id = (int) post('id');

    if ($action === 'create' || $action === 'update') {
        // O provedor é escolhido no cadastro e não muda depois.
        $provider = $action === 'update'
            ? (string) db_val('SELECT provider FROM smtp_accounts WHERE id = ?', [$id])
            : post('provider', 'titan');
        $v = smtp_normalize([
            'email'      => post('email'),
            'from_name'  => post('from_name'),
            'provider'   => $provider,
            'smtp_host'  => post('smtp_host'),
            'smtp_port'  => post('smtp_port'),
            'encryption' => post('encryption', 'ssl'),
            'is_active'  => isset($_POST['is_active']),
        ]);
        $secret = $v['provider'] === 'resend'
            ? trim((string) ($_POST['api_key'] ?? ''))
            : (string) ($_POST['smtp_password'] ?? '');

        $errors = smtp_validate($v, $action === 'create', $secret, $action === 'update' ? $id : 0);
        if ($errors) {
            foreach ($errors as $err) flash('error', $err);
            $_SESSION['_old_smtp'] = $v;
            redirect($action === 'update' ? 'smtp.php?edit=' . $id : 'smtp.php');
        }
        if ($action === 'create') {
            smtp_create($v, $secret);
            flash('success', 'Conta cadastrada. Use "Testar" para validar ' . ($v['provider'] === 'resend' ? 'a API key e o domínio.' : 'a senha.'));
        } else {
            db_run(
                'UPDATE smtp_accounts SET email = ?, from_name = ?, smtp_host = ?, smtp_port = ?, encryption = ?, is_active = ? WHERE id = ?',
                [$v['email'], $v['from_name'], $v['smtp_host'], $v['smtp_port'], $v['encryption'], $v['is_active'], $id]
            );
            if ($secret !== '') {
                smtp_update_secret($id, $v['provider'], $secret);
            }
            flash('success', 'Conta atualizada.');
        }
    } elseif ($action === 'toggle') {
        db_run('UPDATE smtp_accounts SET is_active = 1 - is_active WHERE id = ?', [$id]);
        flash('success', 'Status da conta alterado.');
    } elseif ($action === 'test') {
        $acc = db_one('SELECT * FROM smtp_accounts WHERE id = ?', [$id]);
        if ($acc) {
            $r = provider_test($acc);
            $where = account_provider($acc) === 'resend' ? 'API do Resend' : "{$acc['smtp_host']}:{$acc['smtp_port']}";
            if (!$r['ok']) {
                flash('error', "Falha no teste de {$acc['email']}: {$r['error']}");
            } elseif (!empty($r['warning'])) {
                flash('warning', "{$acc['email']}: {$r['warning']}");
            } else {
                flash('success', "Conexão e autenticação OK em {$where} para {$acc['email']}.");
            }
        }
    } elseif ($action === 'delete') {
        $tokens = (int) db_val('SELECT COUNT(*) FROM api_tokens WHERE smtp_account_id = ?', [$id]);
        if ($tokens > 0) {
            flash('error', "Esta conta tem {$tokens} token(s) vinculados. Exclua os tokens antes (ou apenas desative a conta).");
        } else {
            db_run('DELETE FROM smtp_accounts WHERE id = ?', [$id]);
            flash('success', 'Conta excluída.');
        }
    }
    redirect('smtp.php');
}

$editing = null;
if ($editId = query_int('edit')) {
    $editing = db_one('SELECT id, email, from_name, provider, smtp_host, smtp_port, encryption, is_active FROM smtp_accounts WHERE id = ?', [$editId]);
}
$old = $_SESSION['_old_smtp'] ?? null;
unset($_SESSION['_old_smtp']);
$f = $old ?? $editing ?? ['email' => '', 'from_name' => '', 'provider' => 'titan', 'smtp_host' => 'smtp.titan.email', 'smtp_port' => 465, 'encryption' => 'ssl', 'is_active' => 1];
$fp = ($f['provider'] ?? 'titan') === 'resend' ? 'resend' : 'titan';

$accounts = smtp_list();

render_header('Contas de envio', 'smtp');
page_head('Contas de envio', 'Remetentes dos disparos: caixas do Titan (SMTP) ou domínios no Resend (API). Senhas e API keys ficam criptografadas (AES-256-GCM).');
?>
<section class="grid-2">
  <div class="card">
    <?php if (!$accounts): ?>
      <div class="empty"><h3>Nenhuma conta</h3><p>Cadastre o primeiro remetente no formulário ao lado.</p></div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Conta</th><th>Provedor</th><th>Tokens</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($accounts as $a): $isResend = account_provider($a) === 'resend'; ?>
          <tr>
            <td><strong><?= e($a['email']) ?></strong><span class="cell-sub"><?= e($a['from_name'] ?: 'Sem nome de exibição') ?></span></td>
            <td class="nowrap">
              <span class="badge <?= $isResend ? 'badge-warning' : 'badge-neutral' ?>"><?= $isResend ? 'Resend' : 'Titan' ?></span>
              <span class="cell-sub"><?= $isResend ? 'API HTTP' : e($a['smtp_host'] . ':' . $a['smtp_port'] . ' · ' . strtoupper($a['encryption'])) ?> · último envio <?= e(fmt_dt($a['last_sent'])) ?></span>
            </td>
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
    <div class="card-head"><h2><?= $editing ? 'Editar conta' : 'Nova conta de envio' ?></h2></div>
    <div class="card-body">
      <form method="post" class="form" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
        <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>

        <div class="field">
          <label for="provider">Provedor</label>
          <?php if ($editing): ?>
            <input type="hidden" name="provider" value="<?= e($fp) ?>">
            <input class="input" id="provider" value="<?= e(MAIL_PROVIDERS[$fp]) ?>" disabled data-provider-select data-provider-value="<?= e($fp) ?>">
            <span class="hint">O provedor não muda depois do cadastro. Para trocar, crie outra conta.</span>
          <?php else: ?>
            <select class="select" id="provider" name="provider" data-provider-select>
              <?php foreach (MAIL_PROVIDERS as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $fp === $key ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
        </div>
        <div class="field">
          <label for="email">E-mail do remetente</label>
          <input class="input" type="email" id="email" name="email" required maxlength="190" value="<?= e($f['email']) ?>" placeholder="avisos@demairan.com.br">
        </div>
        <div class="field">
          <label for="from_name">Nome de exibição</label>
          <input class="input" id="from_name" name="from_name" maxlength="120" value="<?= e($f['from_name']) ?>" placeholder="Demairan">
        </div>

        <div class="field" data-provider-fields="resend" <?= $fp === 'resend' ? '' : 'hidden' ?>>
          <label for="api_key">API key do Resend</label>
          <div class="reveal-token">
            <input class="input" type="password" id="api_key" name="api_key" autocomplete="off" placeholder="<?= $editing ? 'Deixe em branco para manter a atual' : 're_…' ?>">
            <button class="btn btn-secondary" type="button" data-toggle-password="#api_key">Mostrar</button>
          </div>
          <span class="hint">Crie em resend.com → API Keys, com permissão "Sending access" restrita ao domínio. O domínio do remetente precisa estar verificado no Resend.</span>
        </div>

        <div class="form" data-provider-fields="titan" <?= $fp === 'titan' ? '' : 'hidden' ?>>
          <div class="field">
            <label for="smtp_password">Senha da caixa</label>
            <div class="reveal-token">
              <input class="input" type="password" id="smtp_password" name="smtp_password" autocomplete="new-password" placeholder="<?= $editing ? 'Deixe em branco para manter a atual' : '' ?>">
              <button class="btn btn-secondary" type="button" data-toggle-password="#smtp_password">Mostrar</button>
            </div>
          </div>
          <div class="field">
            <label for="smtp_host">Servidor</label>
            <input class="input" id="smtp_host" name="smtp_host" value="<?= e($fp === 'titan' ? $f['smtp_host'] : 'smtp.titan.email') ?>">
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
              <input class="input" type="number" id="smtp_port" name="smtp_port" min="1" max="65535" value="<?= (int) ($fp === 'titan' ? $f['smtp_port'] : 465) ?>">
            </div>
          </div>
          <p class="muted small">Titan: <span class="mono">smtp.titan.email</span>, porta 465 com SSL ou 587 com STARTTLS.</p>
        </div>

        <label class="check"><input type="checkbox" name="is_active" value="1" <?= $f['is_active'] ? 'checked' : '' ?>> Conta ativa</label>
        <div class="form-actions">
          <button class="btn btn-primary" type="submit"><?= $editing ? 'Salvar' : 'Cadastrar conta' ?></button>
          <?php if ($editing): ?><a class="btn btn-secondary" href="smtp.php">Cancelar</a><?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</section>
<?php
render_footer();

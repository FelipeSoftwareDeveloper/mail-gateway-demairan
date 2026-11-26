<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$me = require_admin();

if (is_post()) {
    $action = post('action');

    if ($action === 'create') {
        $name = post('name');
        $email = strtolower(post('email'));
        $password = (string) ($_POST['password'] ?? '');
        $errors = [];
        if ($name === '' || mb_strlen($name) > 100) $errors[] = 'Informe o nome (até 100 caracteres).';
        if (!is_valid_email($email)) $errors[] = 'E-mail inválido.';
        if (strlen($password) < 12) $errors[] = 'A senha precisa ter pelo menos 12 caracteres.';
        if (db_val('SELECT id FROM admins WHERE email = ?', [$email])) $errors[] = 'Já existe um administrador com esse e-mail.';
        if ($errors) {
            foreach ($errors as $err) flash('error', $err);
        } else {
            db_run('INSERT INTO admins (name, email, password_hash) VALUES (?, ?, ?)', [$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
            flash('success', "Administrador {$email} criado.");
        }
    } elseif ($action === 'delete') {
        $id = (int) post('id');
        if ($id === (int) $me['id']) {
            flash('error', 'Você não pode excluir a própria conta.');
        } elseif ((int) db_val('SELECT COUNT(*) FROM admins') <= 1) {
            flash('error', 'É preciso manter pelo menos um administrador.');
        } else {
            db_run('DELETE FROM admins WHERE id = ?', [$id]);
            flash('success', 'Administrador removido.');
        }
    } elseif ($action === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');
        $hash = (string) db_val('SELECT password_hash FROM admins WHERE id = ?', [$me['id']]);
        if (!password_verify($current, $hash)) {
            flash('error', 'Senha atual incorreta.');
        } elseif (strlen($new) < 12) {
            flash('error', 'A nova senha precisa ter pelo menos 12 caracteres.');
        } elseif (!hash_equals($new, $confirm)) {
            flash('error', 'A confirmação não confere.');
        } else {
            db_run('UPDATE admins SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $me['id']]);
            session_regenerate_id(true);
            flash('success', 'Senha alterada.');
        }
    }
    redirect('admins.php');
}

$admins = db_all('SELECT id, name, email, last_login_at, created_at FROM admins ORDER BY name');

render_header('Administradores', 'admins');
page_head('Administradores', 'Quem pode acessar este painel.');
?>
<section class="grid-2">
  <div class="card">
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Nome</th><th>Último acesso</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($admins as $a): ?>
          <tr>
            <td><strong><?= e($a['name']) ?></strong><?= (int) $a['id'] === (int) $me['id'] ? ' <span class="badge badge-neutral">você</span>' : '' ?><span class="cell-sub"><?= e($a['email']) ?></span></td>
            <td class="nowrap muted"><?= e(fmt_dt($a['last_login_at'])) ?></td>
            <td>
              <div class="actions">
              <?php if ((int) $a['id'] !== (int) $me['id']): ?>
                <form method="post" data-confirm="Remover o acesso de <?= e($a['email']) ?>?">
                  <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit">Remover</button>
                </form>
              <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div>
    <div class="card">
      <div class="card-head"><h2>Novo administrador</h2></div>
      <div class="card-body">
        <form method="post" class="form" autocomplete="off">
          <?= csrf_field() ?><input type="hidden" name="action" value="create">
          <div class="field"><label for="name">Nome</label><input class="input" id="name" name="name" required maxlength="100"></div>
          <div class="field"><label for="email">E-mail</label><input class="input" type="email" id="email" name="email" required maxlength="190"></div>
          <div class="field"><label for="password">Senha inicial</label><input class="input" type="password" id="password" name="password" required minlength="12" autocomplete="new-password"><span class="hint">Mínimo de 12 caracteres.</span></div>
          <button class="btn btn-primary" type="submit">Adicionar</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2>Alterar minha senha</h2></div>
      <div class="card-body">
        <form method="post" class="form">
          <?= csrf_field() ?><input type="hidden" name="action" value="password">
          <input type="hidden" name="username" value="<?= e($me['email']) ?>" autocomplete="username">
          <div class="field"><label for="current_password">Senha atual</label><input class="input" type="password" id="current_password" name="current_password" required autocomplete="current-password"></div>
          <div class="field"><label for="new_password">Nova senha</label><input class="input" type="password" id="new_password" name="new_password" required minlength="12" autocomplete="new-password"></div>
          <div class="field"><label for="new_password_confirm">Confirme a nova senha</label><input class="input" type="password" id="new_password_confirm" name="new_password_confirm" required minlength="12" autocomplete="new-password"></div>
          <button class="btn btn-secondary" type="submit">Alterar senha</button>
        </form>
      </div>
    </div>
  </div>
</section>
<?php
render_footer();

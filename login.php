<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

start_panel_session();
if (current_admin()) {
    redirect('index.php');
}

$email = '';
if (is_post()) {
    verify_csrf();
    $email = strtolower(post('email'));
    $password = (string) ($_POST['password'] ?? '');
    $ip = client_ip();

    if (login_is_throttled($ip)) {
        flash('error', 'Muitas tentativas. Aguarde ' . (int) config('login.window_minutes', 15) . ' minutos e tente de novo.');
        redirect('login.php');
    }

    $admin = db_one('SELECT id, name, email, password_hash FROM admins WHERE email = ?', [$email]);
    // Verifica mesmo quando o e-mail não existe (tempo de resposta uniforme).
    $hash = $admin['password_hash'] ?? '$2y$12$T2X4H6UFc8WS3k7o7bG//Oerq7yRREoCZ3YNP3AkJKdbz1LoSfYw6';
    $ok = password_verify($password, $hash) && $admin !== null;

    record_login_attempt($ip, $email, $ok);

    if ($ok) {
        if (password_needs_rehash($admin['password_hash'], PASSWORD_DEFAULT)) {
            db_run('UPDATE admins SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $admin['id']]);
        }
        login_admin($admin);
        redirect('index.php');
    }
    flash('error', 'E-mail ou senha incorretos.');
    redirect('login.php');
}

render_guest_header('Entrar');
?>
    <h1>Entrar no painel</h1>
    <p class="lead">Gateway de e-mails transacionais.</p>
    <form method="post" class="form" autocomplete="on">
      <?= csrf_field() ?>
      <div class="field">
        <label for="email">E-mail</label>
        <input class="input" type="email" id="email" name="email" value="<?= e($email) ?>" required autofocus autocomplete="username">
      </div>
      <div class="field">
        <label for="password">Senha</label>
        <input class="input" type="password" id="password" name="password" required autocomplete="current-password">
      </div>
      <button class="btn btn-primary btn-block" type="submit">Entrar</button>
    </form>
<?php
render_guest_footer();

<?php
/**
 * Instalação: verifica o ambiente e cria o PRIMEIRO administrador.
 * Só funciona enquanto a tabela admins estiver vazia.
 * APAGUE ESTE ARQUIVO DO SERVIDOR DEPOIS DE USAR.
 */
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

start_panel_session();

$checks = [
    ['PHP 8.1 ou superior (atual: ' . PHP_VERSION . ')', PHP_VERSION_ID >= 80100],
    ['Extensão PDO MySQL', extension_loaded('pdo_mysql')],
    ['Extensão OpenSSL', extension_loaded('openssl')],
    ['Extensão mbstring', extension_loaded('mbstring')],
    ['PHPMailer em lib/PHPMailer/src/ ou vendor/', phpmailer_path() !== null],
    ['app_key válida no config.php', app_key_is_valid()],
    ['HTTPS ativo', is_https()],
];

$dbError = null;
$hasAdmins = false;
try {
    $hasAdmins = (int) db_val('SELECT COUNT(*) FROM admins') > 0;
    $checks[] = ['Banco de dados conectado e tabelas importadas', true];
} catch (PDOException $e) {
    $dbError = $e->getCode() === '42S02'
        ? 'Conectou ao banco, mas as tabelas não existem. Importe o database.sql pelo phpMyAdmin.'
        : 'Não foi possível conectar ao MySQL. Confira host, nome do banco, usuário e senha no config.php (e se o usuário foi adicionado ao banco com todos os privilégios).';
    $checks[] = ['Banco de dados conectado e tabelas importadas', false];
}

if ($hasAdmins) {
    http_response_code(403);
    render_guest_header('Instalação concluída');
    echo '<h1>Instalação já concluída</h1><p class="lead">Já existe um administrador. Por segurança, <strong>apague o install.php</strong> do servidor.</p>';
    echo '<a class="btn btn-primary btn-block" href="login.php">Ir para o login</a>';
    render_guest_footer();
    exit;
}

$errors = [];
$name = $email = '';
if (is_post() && $dbError === null) {
    verify_csrf();
    $name = post('name');
    $email = strtolower(post('email'));
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    if ($name === '' || strlen($name) > 100) $errors[] = 'Informe o nome (até 100 caracteres).';
    if (!is_valid_email($email)) $errors[] = 'Informe um e-mail válido.';
    if (strlen($password) < 12) $errors[] = 'A senha precisa ter pelo menos 12 caracteres.';
    if (!hash_equals($password, $confirm)) $errors[] = 'As senhas não conferem.';
    if (!app_key_is_valid()) $errors[] = 'Preencha a app_key no config.php antes de continuar.';

    if (!$errors) {
        db_run('INSERT INTO admins (name, email, password_hash) VALUES (?, ?, ?)', [$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        flash('success', 'Administrador criado. Entre com seus dados e apague o install.php do servidor.');
        redirect('login.php');
    }
}

$suggestedKey = base64_encode(random_bytes(32));

render_guest_header('Instalação');
?>
    <h1>Instalação</h1>
    <p class="lead">Confira o ambiente e crie o primeiro administrador.</p>

    <ul class="checklist">
      <?php foreach ($checks as [$label, $ok]): ?>
        <li><?= $ok ? status_badge('sent') : status_badge('failed') ?> <span><?= e($label) ?></span></li>
      <?php endforeach; ?>
    </ul>

    <?php if (!app_key_is_valid()): ?>
      <div class="alert alert-warning">
        Cole esta chave em <code>'app_key'</code> no config.php (gerada agora, só para você):
        <div class="reveal-token"><code id="newkey"><?= e($suggestedKey) ?></code></div>
        Guarde uma cópia em local seguro: sem ela, as senhas SMTP salvas não podem ser lidas.
      </div>
    <?php endif; ?>

    <?php if ($dbError): ?>
      <div class="alert alert-error"><?= e($dbError) ?></div>
    <?php else: ?>
      <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
      <form method="post" class="form">
        <?= csrf_field() ?>
        <div class="field"><label for="name">Nome</label><input class="input" id="name" name="name" value="<?= e($name) ?>" required maxlength="100"></div>
        <div class="field"><label for="email">E-mail</label><input class="input" type="email" id="email" name="email" value="<?= e($email) ?>" required autocomplete="username"></div>
        <div class="field"><label for="password">Senha</label><input class="input" type="password" id="password" name="password" required minlength="12" autocomplete="new-password"><span class="hint">Mínimo de 12 caracteres.</span></div>
        <div class="field"><label for="password_confirm">Confirme a senha</label><input class="input" type="password" id="password_confirm" name="password_confirm" required minlength="12" autocomplete="new-password"></div>
        <button class="btn btn-primary btn-block" type="submit">Criar administrador</button>
      </form>
    <?php endif; ?>
<?php
render_guest_footer();

<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Carrega o PHPMailer de vendor/ (Composer) ou de lib/PHPMailer/src/
 * (upload manual pelo Gerenciador de Arquivos do cPanel).
 */
function phpmailer_path(): ?string
{
    if (is_file(APP_ROOT . '/vendor/autoload.php')) {
        return APP_ROOT . '/vendor/autoload.php';
    }
    if (is_file(APP_ROOT . '/lib/PHPMailer/src/PHPMailer.php')) {
        return APP_ROOT . '/lib/PHPMailer/src/';
    }
    return null;
}

function load_phpmailer(): void
{
    if (class_exists(PHPMailer::class, false)) {
        return;
    }
    $path = phpmailer_path();
    if ($path === null) {
        throw new RuntimeException('PHPMailer não encontrado. Envie a pasta src/ para lib/PHPMailer/src/ (veja o README).');
    }
    if (str_ends_with($path, 'autoload.php')) {
        require_once $path;
    } else {
        require_once $path . 'Exception.php';
        require_once $path . 'PHPMailer.php';
        require_once $path . 'SMTP.php';
    }
}

function build_mailer(array $account): PHPMailer
{
    load_phpmailer();
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $account['smtp_host'];
    $mail->Port = (int) $account['smtp_port'];
    $mail->SMTPAuth = true;
    $mail->Username = $account['email'];
    $mail->Password = decrypt_secret($account['smtp_password_encrypted']);
    $mail->SMTPSecure = $account['encryption'] === 'ssl'
        ? PHPMailer::ENCRYPTION_SMTPS      // porta 465
        : PHPMailer::ENCRYPTION_STARTTLS;  // porta 587
    $mail->Timeout = (int) config('api.smtp_timeout', 20);
    $mail->CharSet = PHPMailer::CHARSET_UTF8;
    $mail->SMTPDebug = 0;
    $mail->XMailer = ' '; // não expõe a versão do PHPMailer
    return $mail;
}

/**
 * Envia a mensagem. $msg: to[], cc[], bcc[], reply_to, subject, html, text, from_name, headers{}.
 * from_name vazio: vale o nome cadastrado na conta SMTP.
 * @return array{ok: bool, message_id: ?string, error: ?string}
 */
function smtp_send(array $account, array $msg): array
{
    $mail = null;
    try {
        $mail = build_mailer($account);
        $fromName = (string) ($msg['from_name'] ?? '');
        $mail->setFrom($account['email'], $fromName !== '' ? $fromName : (string) $account['from_name']);
        foreach ($msg['headers'] ?? [] as $name => $value) {
            $mail->addCustomHeader($name, $value);
        }
        foreach ($msg['to'] as $addr) {
            $mail->addAddress($addr);
        }
        foreach ($msg['cc'] ?? [] as $addr) {
            $mail->addCC($addr);
        }
        foreach ($msg['bcc'] ?? [] as $addr) {
            $mail->addBCC($addr);
        }
        if (!empty($msg['reply_to'])) {
            $mail->addReplyTo($msg['reply_to']);
        }
        $mail->Subject = $msg['subject'];
        if ($msg['html'] !== '') {
            $mail->isHTML(true);
            $mail->Body = $msg['html'];
            $mail->AltBody = $msg['text'] !== '' ? $msg['text'] : $mail->html2text($msg['html']);
        } else {
            $mail->isHTML(false);
            $mail->Body = $msg['text'];
        }
        $mail->send();
        return ['ok' => true, 'message_id' => $mail->getLastMessageID(), 'error' => null];
    } catch (MailerException $e) {
        $info = $mail instanceof PHPMailer && $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage();
        return ['ok' => false, 'message_id' => null, 'error' => $info];
    } catch (Throwable $e) {
        return ['ok' => false, 'message_id' => null, 'error' => $e->getMessage()];
    }
}

/** Testa conexão + autenticação no SMTP sem enviar nada. */
function smtp_test(array $account): array
{
    try {
        $mail = build_mailer($account);
        $mail->smtpConnect();
        $mail->smtpClose();
        return ['ok' => true, 'error' => null];
    } catch (MailerException $e) {
        return ['ok' => false, 'error' => isset($mail) && $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage()];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

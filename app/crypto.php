<?php
declare(strict_types=1);

/**
 * Criptografia reversível das senhas SMTP (o sistema precisa da senha em
 * texto puro para autenticar no Titan). AES-256-GCM: confidencialidade +
 * integridade (adulterações no banco são detectadas na descriptografia).
 *
 * Formato salvo: "v1:" . base64(iv[12] . tag[16] . ciphertext)
 */

const CRYPTO_CIPHER = 'aes-256-gcm';

function app_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    $raw = (string) config('app_key', '');
    if (str_starts_with($raw, 'base64:')) {
        $raw = substr($raw, 7);
    }
    $decoded = base64_decode($raw, true);
    if ($decoded === false || strlen($decoded) !== 32) {
        throw new RuntimeException('app_key ausente ou inválida no config.php (precisa ser 32 bytes em base64).');
    }
    return $key = $decoded;
}

function app_key_is_valid(): bool
{
    try {
        app_key();
        return true;
    } catch (RuntimeException) {
        return false;
    }
}

function encrypt_secret(string $plain): string
{
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('Extensão OpenSSL do PHP não está habilitada.');
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, CRYPTO_CIPHER, app_key(), OPENSSL_RAW_DATA, $iv, $tag, 'smtp-password', 16);
    if ($cipher === false) {
        throw new RuntimeException('Falha ao criptografar.');
    }
    return 'v1:' . base64_encode($iv . $tag . $cipher);
}

function decrypt_secret(string $stored): string
{
    if (!str_starts_with($stored, 'v1:')) {
        throw new RuntimeException('Formato de segredo desconhecido.');
    }
    $bin = base64_decode(substr($stored, 3), true);
    if ($bin === false || strlen($bin) < 29) {
        throw new RuntimeException('Segredo corrompido.');
    }
    $iv = substr($bin, 0, 12);
    $tag = substr($bin, 12, 16);
    $cipher = substr($bin, 28);
    $plain = openssl_decrypt($cipher, CRYPTO_CIPHER, app_key(), OPENSSL_RAW_DATA, $iv, $tag, 'smtp-password');
    if ($plain === false) {
        throw new RuntimeException('Não foi possível descriptografar a senha SMTP (a app_key mudou?). Cadastre a senha novamente.');
    }
    return $plain;
}

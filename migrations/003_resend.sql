-- =====================================================================
--  Migração 003 — Resend como provedor alternativo ao Titan
--  Rode no phpMyAdmin (aba SQL) ANTES de implantar o código desta versão.
--  É compatível com o código anterior: só acrescenta colunas.
-- =====================================================================

SET NAMES utf8mb4;

-- Cada conta de envio passa a ter um provedor:
--   titan  = SMTP (PHPMailer), como antes
--   resend = API HTTP do Resend (api_key_encrypted, AES-256-GCM)
ALTER TABLE smtp_accounts
    ADD COLUMN provider ENUM('titan','resend') NOT NULL DEFAULT 'titan' AFTER from_name,
    ADD COLUMN api_key_encrypted TEXT NULL AFTER smtp_password_encrypted,
    MODIFY smtp_password_encrypted TEXT NULL;

-- Provedor que efetivamente fez cada envio.
ALTER TABLE email_logs
    ADD COLUMN provider VARCHAR(20) NULL AFTER smtp_account_id;

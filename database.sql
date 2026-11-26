-- =====================================================================
--  Demairan Mail Gateway — esquema MySQL 5.7+ / MariaDB 10.3+
--  Importe pelo phpMyAdmin do cPanel (aba "Importar") no banco criado.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Administradores do painel
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admins (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(100) NOT NULL,
    email           VARCHAR(190) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    last_login_at   DATETIME     NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admins_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tentativas de login (proteção contra força bruta no painel)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip          VARCHAR(45)  NOT NULL,
    email       VARCHAR(190) NOT NULL,
    success     TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_ip (ip, created_at),
    KEY idx_login_email (email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Projetos (ex.: Sistema ERP, Site Institucional, App Mobile)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS projects (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name         VARCHAR(120) NOT NULL,
    description  TEXT         NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_projects_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Contas SMTP (Titan Email)
-- smtp_password_encrypted = "v1:" + base64(iv[12] | tag[16] | ciphertext)
-- (AES-256-GCM com a APP_KEY do config.php)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS smtp_accounts (
    id                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email                     VARCHAR(190) NOT NULL,
    from_name                 VARCHAR(120) NOT NULL DEFAULT '',
    smtp_password_encrypted   TEXT         NOT NULL,
    smtp_host                 VARCHAR(190) NOT NULL DEFAULT 'smtp.titan.email',
    smtp_port                 SMALLINT UNSIGNED NOT NULL DEFAULT 465,
    encryption                ENUM('ssl','tls') NOT NULL DEFAULT 'ssl',
    is_active                 TINYINT(1)   NOT NULL DEFAULT 1,
    created_at                DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_smtp_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tokens de API — cada token pertence a UM projeto e a UMA conta SMTP.
-- Só o hash SHA-256 é guardado; o token completo aparece uma única vez.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS api_tokens (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name             VARCHAR(120) NOT NULL,
    token_hash       CHAR(64)     NOT NULL,
    token_prefix     VARCHAR(32)  NOT NULL,
    project_id       INT UNSIGNED NOT NULL,
    smtp_account_id  INT UNSIGNED NOT NULL,
    status           ENUM('active','revoked') NOT NULL DEFAULT 'active',
    last_used_at     DATETIME     NULL,
    revoked_at       DATETIME     NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tokens_hash (token_hash),
    KEY idx_tokens_project (project_id),
    KEY idx_tokens_smtp (smtp_account_id),
    CONSTRAINT fk_tokens_project FOREIGN KEY (project_id)      REFERENCES projects (id)      ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_tokens_smtp    FOREIGN KEY (smtp_account_id) REFERENCES smtp_accounts (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Logs de auditoria — todo disparo e toda tentativa inválida
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_logs (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token_id         INT UNSIGNED NULL,
    project_id       INT UNSIGNED NULL,
    smtp_account_id  INT UNSIGNED NULL,
    sender_email     VARCHAR(190) NULL,
    recipient_email  TEXT         NULL,
    subject          VARCHAR(500) NULL,
    body_preview     MEDIUMTEXT   NULL,
    message_id       VARCHAR(255) NULL,
    origin_ip        VARCHAR(45)  NOT NULL,
    user_agent       VARCHAR(500) NULL,
    origin_header    VARCHAR(500) NULL,
    status           ENUM('sent','failed','unauthorized') NOT NULL,
    error_message    TEXT         NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_logs_created (created_at),
    KEY idx_logs_status (status, created_at),
    KEY idx_logs_token (token_id, created_at),
    KEY idx_logs_project (project_id, created_at),
    KEY idx_logs_ip (origin_ip, status, created_at),
    CONSTRAINT fk_logs_token   FOREIGN KEY (token_id)        REFERENCES api_tokens (id)    ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_logs_project FOREIGN KEY (project_id)      REFERENCES projects (id)      ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_logs_smtp    FOREIGN KEY (smtp_account_id) REFERENCES smtp_accounts (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Acesso MCP (Claude): chaves de administração e auditoria
-- (mesmo conteúdo de migrations/002_mcp.sql)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mcp_keys (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(120) NOT NULL,
    key_hash      CHAR(64)     NOT NULL,
    key_prefix    VARCHAR(32)  NOT NULL,
    scope         ENUM('read','write') NOT NULL DEFAULT 'read',
    status        ENUM('active','revoked') NOT NULL DEFAULT 'active',
    created_by    INT UNSIGNED NULL,
    last_used_at  DATETIME     NULL,
    last_used_ip  VARCHAR(45)  NULL,
    revoked_at    DATETIME     NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mcp_keys_hash (key_hash),
    CONSTRAINT fk_mcp_keys_admin FOREIGN KEY (created_by) REFERENCES admins (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mcp_audit (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    key_id      INT UNSIGNED NULL,
    tool        VARCHAR(64)  NOT NULL,
    arguments   TEXT         NULL,
    success     TINYINT(1)   NOT NULL,
    message     VARCHAR(1000) NULL,
    origin_ip   VARCHAR(45)  NOT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mcp_audit_created (created_at),
    CONSTRAINT fk_mcp_audit_key FOREIGN KEY (key_id) REFERENCES mcp_keys (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- O primeiro administrador é criado pelo navegador em /install.php
-- (apague o install.php do servidor logo depois).

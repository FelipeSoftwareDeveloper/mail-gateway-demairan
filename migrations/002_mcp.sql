-- =====================================================================
--  Migração 002 — acesso MCP (Claude)
--  Rode no phpMyAdmin (aba SQL) em instalações feitas antes desta versão.
--  Instalações novas já recebem estas tabelas pelo database.sql.
-- =====================================================================

SET NAMES utf8mb4;

-- Chaves de administração via MCP. Só o hash SHA-256 é guardado.
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

-- Auditoria das ações de escrita feitas pelo Claude (senhas nunca são gravadas).
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

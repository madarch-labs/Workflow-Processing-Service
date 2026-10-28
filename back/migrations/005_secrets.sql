-- Secrets for external actions (md/tasks_list/done/7.4-secrets.md): values are encrypted by the application
-- (XChaCha20-Poly1305, key WPS_SECRETS_KEY), ciphertext and nonce are stored as base64 text.
CREATE TABLE IF NOT EXISTS secrets (
    id            BIGSERIAL   PRIMARY KEY,
    name          TEXT        NOT NULL UNIQUE,
    kind          TEXT        NOT NULL DEFAULT 'generic',
    description   TEXT        NOT NULL DEFAULT '',
    ciphertext    TEXT        NOT NULL,
    nonce         TEXT        NOT NULL,
    kek_id        TEXT        NOT NULL,             -- which key encrypted the row (rotation)
    version       INT         NOT NULL DEFAULT 1,   -- part of the associated data, +1 on every value change
    mask          TEXT        NOT NULL,             -- ready mask for the list (no decryption needed)
    allowed_hosts JSONB       NOT NULL DEFAULT '[]',
    created_by    BIGINT      REFERENCES users(id) ON DELETE SET NULL,
    updated_by    BIGINT      REFERENCES users(id) ON DELETE SET NULL,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_used_at  TIMESTAMPTZ
);

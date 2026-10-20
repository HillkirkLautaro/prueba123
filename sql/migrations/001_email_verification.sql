-- Ejecutar una sola vez en instalaciones creadas antes de la verificación de correo.
ALTER TABLE usuarios
    ADD COLUMN email_verified_at DATETIME NULL AFTER avatar,
    ADD COLUMN email_verification_token CHAR(64) NULL AFTER email_verified_at,
    ADD COLUMN email_verification_expires_at DATETIME NULL AFTER email_verification_token,
    ADD INDEX idx_usuarios_email_verification_token (email_verification_token);

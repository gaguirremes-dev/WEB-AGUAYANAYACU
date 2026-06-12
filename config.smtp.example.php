<?php
/**
 * PLANTILLA de credenciales SMTP.
 *
 * En el servidor de producción:
 *   1. Copia este archivo como  config.smtp.php
 *   2. Reemplaza los valores de ejemplo con las credenciales reales.
 *
 * config.smtp.php está excluido del repositorio (.gitignore) para no exponer
 * la contraseña en Git.
 */
define('SMTP_HOST', 'mail.tudominio.com');
define('SMTP_PORT', 465);                              // 465 = SSL implícito, 587 = STARTTLS
define('SMTP_USER', 'correo@tudominio.com');
define('SMTP_PASS', 'TU_CONTRASEÑA_AQUI');
define('SMTP_FROM_NAME', 'Agua Yana Yacu');

// A dónde llegan las notificaciones internas de nuevos reclamos
define('EMPRESA_NOTIF_EMAIL', 'correo@tudominio.com');

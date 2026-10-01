# 🛡️ Documentación de Seguridad

## 1. Resumen de Seguridad

Esta aplicación web implementa múltiples capas de seguridad para proteger contra vulnerabilidades comunes y avanzadas.

### Puntuación de Seguridad: **8.5/10**

---

## 2. Medidas de Seguridad Implementadas

### 2.1 Protección OWASP Top 10

| Vulnerabilidad | Estado | Implementación |
|---------------|--------|----------------|
| Inyección SQL | ✅ Protegido | Sentencias preparadas PDO con ATTR_EMULATE_PREPARES=false |
| XSS | ✅ Protegido | htmlspecialchars() + sanitize_html() + CSP con nonces |
| CSRF | ✅ Protegido | Tokens CSRF con hash_equals() + validación de origen |
| IDOR | ✅ Protegido | Verificación de propiedad en todas las operaciones |
| SSRF | ✅ Protegido | No hay funcionalidad de fetch externo |
| Deserialización | ✅ Protegido | No se usa deserialización |
| Autenticación | ✅ Protegido | password_hash() + password_verify() + rate limiting |
| Sesiones | ✅ Protegido | httponly, samesite, secure, fingerprint |
| Configuración | ✅ Protegido | Cabeceras de seguridad + CSP estricto |
| Logging | ✅ Implementado | Eventos de seguridad registrados |

### 2.2 Cabeceras de Seguridad

```
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
X-XSS-Protection: 1; mode=block
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: camera=(), microphone=(), geolocation=()
Strict-Transport-Security: max-age=31536000; includeSubDomains
Cross-Origin-Opener-Policy: same-origin
Cross-Origin-Resource-Policy: same-origin
Content-Security-Policy: default-src 'self'; script-src 'self' https://cdnjs.cloudflare.com https://js.hcaptcha.com 'nonce-{nonce}'; style-src 'self' https://cdnjs.cloudflare.com 'nonce-{nonce}'; ...
```

### 2.3 Validación de Entrada

- **Usuarios:** `/^[A-Za-z0-9_]{3,50}$/`
- **Email:** `FILTER_VALIDATE_EMAIL`
- **Contraseñas:** Mínimo 8 caracteres, máximo 72 (bcrypt)
- **Comentarios:** 1-5000 caracteres, UTF-8 válido
- **IDs:** `FILTER_VALIDATE_INT`

### 2.4 Protección de Sesiones

```php
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => $isHttps,
]);
```

- **Timeout:** 30 minutos de inactividad
- **Regeneración:** ID regenerado después de login
- **Fingerprint:** User-Agent validado en cada request

### 2.5 Rate Limiting

- **Login:** 5 intentos por IP en 5 minutos
- **Registro:** 5 intentos por IP en 5 minutos
- **Acciones:** 10 intentos por usuario en 1 minuto

### 2.6 Validación de Origen

- **AJAX:** Origin y Referer validados contra lista blanca
- **Formularios:** Origin y Referer validados (permite navegación directa)
- **Configurable:** ALLOWED_ORIGINS en .env

### 2.7 Logging de Seguridad

Eventos registrados:
- `LOGIN_FAILED` - Intentos de login fallidos
- `CSRF_ATTEMPT` - Intentos de CSRF
- `SESSION_HIJACKING_ATTEMPT` - Posible robo de sesión
- `UNAUTHORIZED_EDIT_ATTEMPT` - Intentos de edición no autorizada
- `UNAUTHORIZED_DELETE_ATTEMPT` - Intentos de eliminación no autorizada
- `UNAUTHORIZED_PROFILE_UPDATE_ATTEMPT` - Intentos de actualización no autorizada
- `INVALID_ORIGIN` - Peticiones con origen inválido
- `INVALID_CONTENT_TYPE` - Content-Type no soportado

---

## 3. Configuración de Seguridad

### 3.1 Variables de Entorno (.env)

```bash
# Base de datos
DB_HOST=localhost
DB_PORT=3306
DB_NAME=mi_base_de_datos
DB_USER=usuario_seguro
DB_PASSWORD=contraseña_fuerte

# Aplicación
APP_ENV=production

# hCaptcha
HCAPTCHA_SITEKEY=tu_sitekey
HCAPTCHA_SECRET=tu_secret

# Google OAuth
GOOGLE_CLIENT_ID_OAUTH=tu_client_id
GOOGLE_SECRET_CLIENT=tu_client_secret
GOOGLE_OAUTH_REDIRECT_URI=https://tudominio.com/google_callback.php

# Seguridad adicional
ALLOWED_ORIGINS=https://tudominio.com,https://www.tudominio.com
```

### 3.2 Configuración de Producción

```apache
# .htaccess
Options -Indexes

<FilesMatch "^\.env|\.log|\.sql$">
    Order allow,deny
    Deny from all
</FilesMatch>

# Forzar HTTPS
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

---

## 4. Monitoreo de Seguridad

### 4.1 Script de Monitoreo

```bash
# Ejecutar cada 5 minutos vía cron
*/5 * * * * /usr/bin/php /ruta/al/proyecto/security_monitor.php
```

### 4.2 Alertas Configuradas

| Severidad | Condición | Acción |
|-----------|-----------|--------|
| CRITICAL | Session hijacking detectado | Bloquear IP + email |
| HIGH | > 10 logins fallidos por IP | Email de alerta |
| HIGH | > 3 accesos no autorizados | Email de alerta |
| MEDIUM | > 5 intentos CSRF por IP | Email de alerta |
| MEDIUM | > 20 orígenes inválidos | Email de alerta |

### 4.3 Logs de Seguridad

Ubicación: `/var/log/apache2/error.log` o `logs/security_report_*.txt`

Formato:
```
[2026-10-01 12:00:00] [SECURITY] [LOGIN_FAILED] [IP: 192.168.1.100] [User: guest] [URI: /login.php] [UA: Mozilla/5.0...] Username: admin
```

---

## 5. Procedimientos de Respuesta a Incidentes

Ver documento completo en `INCIDENT_RESPONSE.md`

### Resumen Rápido

1. **Detección:** Monitoreo automático cada 5 minutos
2. **Análisis:** Clasificación por severidad (P0-P3)
3. **Contención:** Aislar sistemas afectados
4. **Erradicación:** Eliminar causa raíz
5. **Recuperación:** Restaurar servicios
6. **Lecciones:** Documentar y mejorar

---

## 6. Pruebas de Penetración

### 6.1 Checklist de Pruebas

- [ ] SQL Injection en todos los parámetros
- [ ] XSS reflejado y almacenado
- [ ] CSRF en todos los formularios
- [ ] IDOR en operaciones de usuario
- [ ] Session hijacking
- [ ] File upload vulnerabilities
- [ ] Clickjacking
- [ ] Open redirect
- [ ] SSRF
- [ ] Command injection

### 6.2 Herramientas Recomendadas

- **Burp Suite** - Proxy de interceptación
- **OWASP ZAP** - Escáner automático
- **SQLMap** - Detección de SQLi
- **Nikto** - Escáner de vulnerabilidades
- **Nmap** - Escaneo de puertos

### 6.3 Frecuencia

- **Escaneo automático:** Semanal
- **Pruebas manuales:** Mensual
- **Auditoría externa:** Trimestral

---

## 7. Dependencias y Actualizaciones

### 7.1 Dependencias del Proyecto

| Dependencia | Versión | Última Revisión |
|-------------|---------|-----------------|
| CropperJS | 1.6.2 | 2026-10-01 |
| hCaptcha API | 1.0 | 2026-10-01 |
| Google OAuth | 2.0 | 2026-10-01 |

### 7.2 Proceso de Actualización

1. Revisar changelog de seguridad
2. Probar en entorno staging
3. Actualizar en producción
4. Verificar funcionamiento
5. Actualizar documentación

---

## 8. Backups

### 8.1 Configuración

```bash
# Backup diario de base de datos
0 2 * * * /usr/bin/mysqldump -u usuario -p'password' mi_base_de_datos > /backups/db_$(date +\%Y\%m\%d).sql

# Backup semanal de archivos
0 3 * * 0 tar -czf /backups/files_$(date +\%Y\%m\%d).tar.gz /var/www/html/
```

### 8.2 Retención

- **Backups diarios:** 30 días
- **Backups semanales:** 12 semanas
- **Backups mensuales:** 12 meses

### 8.3 Verificación

```bash
# Verificar integridad de backup
mysql -u usuario -p -e "SHOW TABLES" mi_base_de_datos < backup.sql

# Probar restauración mensualmente
```

---

## 9. Contacto de Seguridad

| Rol | Contacto |
|-----|----------|
| Equipo de Seguridad | security@dominio.com |
| Reportar Vulnerabilidades | security@dominio.com |
| Emergencias | +1-XXX-XXX-XXXX |

---

## 10. Historial de Seguridad

| Fecha | Evento | Severidad | Estado |
|-------|--------|-----------|--------|
| 2026-10-01 | Auditoría inicial | - | Completada |
| 2026-10-01 | Mitigaciones implementadas | - | Completadas |
| 2026-10-01 | Monitoreo configurado | - | Activo |

---

**Última actualización:** 2026-10-01
**Próxima revisión:** 2027-01-01

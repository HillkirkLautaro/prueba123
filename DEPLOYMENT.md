# 🚀 Guía de Despliegue Seguro

## Requisitos Previos

- Ubuntu 20.04+ / Debian 10+
- Apache 2.4+ / Nginx 1.18+
- PHP 8.0+
- MySQL 8.0+ / MariaDB 10.5+
- Certbot (para SSL)

---

## 1. Instalación de Dependencias

```bash
# Actualizar sistema
sudo apt update && sudo apt upgrade -y

# Instalar Apache
sudo apt install -y apache2

# Instalar PHP y extensiones
sudo apt install -y php8.0 libapache2-mod-php8.0 php8.0-mysql php8.0-curl php8.0-mbstring php8.0-xml php8.0-gd php8.0-zip

# Instalar MySQL
sudo apt install -y mysql-server

# Instalar Certbot para SSL
sudo apt install -y certbot python3-certbot-apache
```

---

## 2. Configuración de Base de Datos

```bash
# Acceder a MySQL
sudo mysql

# Crear base de datos
CREATE DATABASE mi_base_de_datos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

# Crear usuario seguro
CREATE USER 'usuario_seguro'@'localhost' IDENTIFIED BY 'contraseña_fuerte';

# Otorgar privilegios
GRANT ALL PRIVILEGES ON mi_base_de_datos.* TO 'usuario_seguro'@'localhost';

# Aplicar cambios
FLUSH PRIVILEGES;
EXIT;
```

---

## 3. Configuración de Apache

### 3.1 Crear VirtualHost

```bash
sudo nano /etc/apache2/sites-available/mi_aplicacion.conf
```

```apache
<VirtualHost *:80>
    ServerName tudominio.com
    ServerAlias www.tudominio.com
    DocumentRoot /var/www/html/mi_aplicacion
    
    <Directory /var/www/html/mi_aplicacion>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/mi_aplicacion_error.log
    CustomLog ${APACHE_LOG_DIR}/mi_aplicacion_access.log combined
    
    # Forzar HTTPS
    RewriteEngine On
    RewriteCond %{HTTPS} off
    RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
</VirtualHost>

<VirtualHost *:443>
    ServerName tudominio.com
    ServerAlias www.tudominio.com
    DocumentRoot /var/www/html/mi_aplicacion
    
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/tudominio.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/tudominio.com/privkey.pem
    
    <Directory /var/www/html/mi_aplicacion>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    # Cabeceras de seguridad
    Header always set X-Frame-Options "DENY"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
    
    ErrorLog ${APACHE_LOG_DIR}/mi_aplicacion_error.log
    CustomLog ${APACHE_LOG_DIR}/mi_aplicacion_access.log combined
</VirtualHost>
```

### 3.2 Habilitar módulos y sitio

```bash
# Habilitar módulos necesarios
sudo a2enmod rewrite ssl headers

# Habilitar sitio
sudo a2ensite mi_aplicacion.conf

# Deshabilitar sitio por defecto
sudo a2dissite 000-default.conf

# Reiniciar Apache
sudo systemctl restart apache2
```

---

## 4. Configuración SSL con Let's Encrypt

```bash
# Obtener certificado
sudo certbot --apache -d tudominio.com -d www.tudominio.com

# Verificar renovación automática
sudo certbot renew --dry-run
```

---

## 5. Despliegue de la Aplicación

```bash
# Clonar repositorio
sudo git clone https://github.com/usuario/mi_aplicacion.git /var/www/html/mi_aplicacion

# Configurar permisos
sudo chown -R www-data:www-data /var/www/html/mi_aplicacion
sudo chmod -R 755 /var/www/html/mi_aplicacion

# Crear directorio de uploads
sudo mkdir -p /var/www/html/mi_aplicacion/uploads/avatars
sudo chown -R www-data:www-data /var/www/html/mi_aplicacion/uploads
sudo chmod 755 /var/www/html/mi_aplicacion/uploads

# Crear directorio de logs
sudo mkdir -p /var/www/html/mi_aplicacion/logs
sudo chown www-data:www-data /var/www/html/mi_aplicacion/logs
sudo chmod 755 /var/www/html/mi_aplicacion/logs
```

---

## 6. Configuración de .env

```bash
# Copiar ejemplo
sudo cp /var/www/html/mi_aplicacion/.env.example /var/www/html/mi_aplicacion/.env

# Editar configuración
sudo nano /var/www/html/mi_aplicacion/.env
```

```env
DB_HOST=localhost
DB_PORT=3306
DB_NAME=mi_base_de_datos
DB_USER=usuario_seguro
DB_PASSWORD=contraseña_fuerte

APP_ENV=production
APP_URL=https://tudominio.com

HCAPTCHA_SITEKEY=tu_sitekey
HCAPTCHA_SECRET=tu_secret

GOOGLE_CLIENT_ID_OAUTH=tu_client_id
GOOGLE_SECRET_CLIENT=tu_client_secret
GOOGLE_OAUTH_REDIRECT_URI=https://tudominio.com/google_callback.php

ALLOWED_ORIGINS=https://tudominio.com,https://www.tudominio.com
SECURITY_ALERT_EMAIL=security@tudominio.com
```

```bash
# Configurar permisos del .env
sudo chmod 600 /var/www/html/mi_aplicacion/.env
sudo chown www-data:www-data /var/www/html/mi_aplicacion/.env
```

---

## 7. Configuración de Seguridad

```bash
# Ejecutar script de configuración
sudo bash /var/www/html/mi_aplicacion/setup_security.sh
```

---

## 8. Verificación Final

### 8.1 Verificar configuración de Apache
```bash
sudo apache2ctl configtest
```

### 8.2 Verificar permisos
```bash
sudo find /var/www/html/mi_aplicacion -type f -exec ls -la {} \; | head -20
```

### 8.3 Verificar cron jobs
```bash
sudo crontab -l
```

### 8.4 Verificar SSL
```bash
sudo certbot certificates
```

### 8.5 Probar monitoreo
```bash
sudo php /var/www/html/mi_aplicacion/security_monitor.php
```

### 8.6 Verificar logs
```bash
sudo tail -f /var/log/apache2/mi_aplicacion_error.log
```

---

## 9. Mantenimiento

### 9.1 Actualizaciones de seguridad
```bash
# Actualizar sistema
sudo apt update && sudo apt upgrade -y

# Actualizar dependencias de PHP
sudo composer update

# Revisar logs de seguridad
sudo grep "SECURITY" /var/log/apache2/mi_aplicacion_error.log | tail -50
```

### 9.2 Revisión de backups
```bash
# Verificar backups de base de datos
ls -lh /var/backups/mi_aplicacion/db_*.sql.gz

# Verificar backups de archivos
ls -lh /var/backups/mi_aplicacion/files_*.tar.gz

# Probar restauración (mensualmente)
sudo bash /var/backups/mi_aplicacion/backup_db.sh
```

### 9.3 Monitoreo continuo
```bash
# Verificar estado del monitoreo
sudo tail -f /var/www/html/mi_aplicacion/logs/monitor.log

# Verificar alertas
sudo grep "ALERT" /var/log/apache2/mi_aplicacion_error.log
```

---

## 10. Resolución de Problemas

### Error 500 Internal Server Error
```bash
# Ver logs de Apache
sudo tail -50 /var/log/apache2/mi_aplicacion_error.log

# Verificar permisos
sudo chown -R www-data:www-data /var/www/html/mi_aplicacion
sudo chmod -R 755 /var/www/html/mi_aplicacion
```

### Error de conexión a base de datos
```bash
# Verificar credenciales en .env
sudo nano /var/www/html/mi_aplicacion/.env

# Probar conexión
mysql -u usuario_seguro -p mi_base_de_datos
```

### Certificado SSL expirado
```bash
# Renovar certificado
sudo certbot renew

# Reiniciar Apache
sudo systemctl restart apache2
```

---

## 11. Contacto

- **Soporte técnico:** soporte@tudominio.com
- **Seguridad:** security@tudominio.com
- **Documentación:** https://docs.tudominio.com

---

**Última actualización:** 2026-10-01

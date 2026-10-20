#!/bin/bash
# ============================================
# SCRIPT DE CONFIGURACIÓN DE SEGURIDAD
# ============================================
# Este script configura:
# 1. Monitoreo de seguridad (cron)
# 2. Backups automáticos
# 3. Permisos de archivos
# 4. Configuración de .env
#
# Uso: sudo bash setup_security.sh
# ============================================

set -e

# Colores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Variables (se leen del entorno; valores por defecto son placeholders, nunca credenciales reales)
PROJECT_DIR="${PROJECT_DIR:-.}"
DB_NAME="${DB_NAME:-tu_base_de_datos}"
DB_USER="${DB_USER:-tu_usuario}"
DB_PASSWORD="${DB_PASSWORD:-tu_contraseña}"
BACKUP_DIR="${BACKUP_DIR:-./backups}"
DOMAIN="${DOMAIN:-tudominio.com}"

echo -e "${BLUE}============================================${NC}"
echo -e "${BLUE}  CONFIGURACIÓN DE SEGURIDAD - INICIO${NC}"
echo -e "${BLUE}============================================${NC}"
echo ""

# --------------------------------------------
# 1. VERIFICAR PERMISOS DE ROOT
# --------------------------------------------
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}Este script debe ejecutarse como root (sudo)${NC}"
    exit 1
fi

# --------------------------------------------
# 2. CONFIGURAR PERMISOS DE ARCHIVOS
# --------------------------------------------
echo -e "${YELLOW}[1/5] Configurando permisos de archivos...${NC}"

# Permisos para archivos PHP (644)
find "$PROJECT_DIR" -type f -name "*.php" -exec chmod 644 {} \;

# Permisos para directorios (755)
find "$PROJECT_DIR" -type d -exec chmod 755 {} \;

# Permisos para directorios de subida (755)
if [ -d "$PROJECT_DIR/uploads" ]; then
    chmod -R 755 "$PROJECT_DIR/uploads"
fi

# Permisos para .env (600 - solo lectura del propietario)
if [ -f "$PROJECT_DIR/.env" ]; then
    chmod 600 "$PROJECT_DIR/.env"
    chown www-data:www-data "$PROJECT_DIR/.env"
fi

echo -e "${GREEN}✓ Permisos configurados correctamente${NC}"
echo ""

# --------------------------------------------
# 3. CONFIGURAR MONITOREO DE SEGURIDAD (CRON)
# --------------------------------------------
echo -e "${YELLOW}[2/5] Configurando monitoreo de seguridad...${NC}"

# Crear directorio de logs
mkdir -p "$PROJECT_DIR/logs"
chmod 755 "$PROJECT_DIR/logs"

# Agregar cron job para monitoreo cada 5 minutos
CRON_JOB="*/5 * * * * /usr/bin/php $PROJECT_DIR/security_monitor.php >> $PROJECT_DIR/logs/monitor.log 2>&1"

# Verificar si el cron job ya existe
if crontab -l 2>/dev/null | grep -q "security_monitor.php"; then
    echo -e "${YELLOW}  El cron job ya existe, omitiendo...${NC}"
else
    # Agregar cron job
    (crontab -l 2>/dev/null; echo "$CRON_JOB") | crontab -
    echo -e "${GREEN}✓ Cron job agregado: Monitoreo cada 5 minutos${NC}"
fi

echo ""

# --------------------------------------------
# 4. CONFIGURAR BACKUPS AUTOMÁTICOS
# --------------------------------------------
echo -e "${YELLOW}[3/5] Configurando backups automáticos...${NC}"

# Crear directorio de backups
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

# Script de backup de base de datos
cat > "$BACKUP_DIR/backup_db.sh" << 'EOF'
#!/bin/bash
# Backup de base de datos - credenciales desde el entorno
BACKUP_DIR="${BACKUP_DIR:-./backups}"
DB_HOST="${DB_HOST:-sqlXXX.infinityfree.com}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-tu_base_de_datos}"
DB_USER="${DB_USER:-tu_usuario}"
DB_PASSWORD="${DB_PASSWORD:-tu_contraseña}"
DATE=$(date +%Y%m%d_%H%M%S)

mkdir -p "$BACKUP_DIR"

mysqldump -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" > "$BACKUP_DIR/db_${DATE}.sql"
gzip "$BACKUP_DIR/db_${DATE}.sql"
find "$BACKUP_DIR" -name "db_*.sql.gz" -mtime +30 -delete 2>/dev/null

echo "[$(date)] Backup de base de datos completado: db_${DATE}.sql.gz"
EOF

chmod +x "$BACKUP_DIR/backup_db.sh"

# Script de backup de archivos
cat > "$BACKUP_DIR/backup_files.sh" << EOF
#!/bin/bash
# Backup de archivos - rutas desde el entorno
BACKUP_DIR="${BACKUP_DIR}"
PROJECT_DIR="${PROJECT_DIR}"
DATE=\$(date +%Y%m%d_%H%M%S)

mkdir -p "\$BACKUP_DIR"

# Crear backup
tar -czf "\$BACKUP_DIR/files_\${DATE}.tar.gz" -C "\$PROJECT_DIR" .

# Eliminar backups antiguos (más de 30 días)
find "\$BACKUP_DIR" -name "files_*.tar.gz" -mtime +30 -delete 2>/dev/null

echo "[\$(date)] Backup de archivos completado: files_\${DATE}.tar.gz"
EOF

chmod +x "$BACKUP_DIR/backup_files.sh"

# Agregar cron jobs para backups
CRON_BACKUP_DB="0 2 * * * $BACKUP_DIR/backup_db.sh >> $BACKUP_DIR/backup.log 2>&1"
CRON_BACKUP_FILES="0 3 * * 0 $BACKUP_DIR/backup_files.sh >> $BACKUP_DIR/backup.log 2>&1"

# Verificar si los cron jobs ya existen
if crontab -l 2>/dev/null | grep -q "backup_db.sh"; then
    echo -e "${YELLOW}  El cron job de backup de DB ya existe, omitiendo...${NC}"
else
    (crontab -l 2>/dev/null; echo "$CRON_BACKUP_DB") | crontab -
    echo -e "${GREEN}✓ Cron job agregado: Backup de DB diario a las 2:00 AM${NC}"
fi

if crontab -l 2>/dev/null | grep -q "backup_files.sh"; then
    echo -e "${YELLOW}  El cron job de backup de archivos ya existe, omitiendo...${NC}"
else
    (crontab -l 2>/dev/null; echo "$CRON_BACKUP_FILES") | crontab -
    echo -e "${GREEN}✓ Cron job agregado: Backup de archivos semanal (Domingos 3:00 AM)${NC}"
fi

echo ""

# --------------------------------------------
# 5. CONFIGURAR .ENV
# --------------------------------------------
echo -e "${YELLOW}[4/5] Configurando archivo .env...${NC}"

if [ -f "$PROJECT_DIR/.env" ]; then
    echo -e "${YELLOW}  El archivo .env ya existe, omitiendo...${NC}"
else
    # Crear .env desde el ejemplo
    if [ -f "$PROJECT_DIR/.env.example" ]; then
        cp "$PROJECT_DIR/.env.example" "$PROJECT_DIR/.env"
        chmod 600 "$PROJECT_DIR/.env"
        chown www-data:www-data "$PROJECT_DIR/.env"
        echo -e "${GREEN}✓ Archivo .env creado desde .env.example${NC}"
        echo -e "${RED}  IMPORTANTE: Editar .env con las credenciales correctas${NC}"
    else
        echo -e "${RED}  ERROR: No se encontró .env.example${NC}"
    fi
fi

echo ""

# --------------------------------------------
# 6. CONFIGURAR LOGROTATE
# --------------------------------------------
echo -e "${YELLOW}[5/5] Configurando rotación de logs...${NC}"

cat > /etc/logrotate.d/mi_aplicacion << EOF
$PROJECT_DIR/logs/*.log {
    daily
    missingok
    rotate 30
    compress
    delaycompress
    notifempty
    create 644 www-data www-data
}
EOF

echo -e "${GREEN}✓ Logrotate configurado${NC}"
echo ""

# --------------------------------------------
# RESUMEN
# --------------------------------------------
echo -e "${BLUE}============================================${NC}"
echo -e "${BLUE}  CONFIGURACIÓN COMPLETADA${NC}"
echo -e "${BLUE}============================================${NC}"
echo ""
echo -e "${GREEN}Configuraciones aplicadas:${NC}"
echo "  • Permisos de archivos configurados"
echo "  • Monitoreo de seguridad: cada 5 minutos"
echo "  • Backup de base de datos: diario a las 2:00 AM"
echo "  • Backup de archivos: semanal (Domingos 3:00 AM)"
echo "  • Rotación de logs: 30 días"
echo ""
echo -e "${YELLOW}Próximos pasos:${NC}"
echo "  1. Editar $PROJECT_DIR/.env con las credenciales correctas"
echo "  2. Configurar las variables en .env:"
echo "     - DB_HOST, DB_NAME, DB_USER, DB_PASSWORD"
echo "     - HCAPTCHA_SITEKEY, HCAPTCHA_SECRET"
echo "     - GOOGLE_CLIENT_ID_OAUTH, GOOGLE_SECRET_CLIENT"
echo "     - ALLOWED_ORIGINS"
echo "  3. Probar el monitoreo: php $PROJECT_DIR/security_monitor.php"
echo "  4. Verificar los cron jobs: crontab -l"
echo ""
echo -e "${RED}IMPORTANTE:${NC}"
echo "  • Cambiar todas las contraseñas por defecto"
echo "  • Configurar HTTPS antes de producción"
echo "  • Revisar los logs regularmente"
echo ""

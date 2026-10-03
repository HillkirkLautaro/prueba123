#!/bin/bash
# Backup de base de datos
BACKUP_DIR="/home/lau/Escritorio/repos/prueba123-main/backups"
DB_NAME="if0_42867030_base_prueba"
DB_USER="if0_42867030"
DB_PASSWORD="9DcaQPZVTx"
DB_HOST="sql109.infinityfree.com"
DATE=$(date +%Y%m%d_%H%M%S)

# Crear backup
mysqldump -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" > "$BACKUP_DIR/db_${DATE}.sql"

# Comprimir backup
gzip "$BACKUP_DIR/db_${DATE}.sql"

# Eliminar backups antiguos (más de 30 días)
find "$BACKUP_DIR" -name "db_*.sql.gz" -mtime +30 -delete

echo "[$(date)] Backup de base de datos completado: db_${DATE}.sql.gz"

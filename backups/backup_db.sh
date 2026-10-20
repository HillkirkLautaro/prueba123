#!/bin/bash
# ============================================================
# Backup de base de datos - TP Integrador N.º 1
# Las credenciales se leen del entorno (R7). Nunca se escriben
# valores reales aquí. Completa las variables en .env o en el
# panel del proveedor (InfinityFree).
# ============================================================

BACKUP_DIR="${BACKUP_DIR:-./backups}"
DB_HOST="${DB_HOST:-sqlXXX.infinityfree.com}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-tu_base_de_datos}"
DB_USER="${DB_USER:-tu_usuario}"
DB_PASSWORD="${DB_PASSWORD:-tu_contraseña}"
DATE=$(date +%Y%m%d_%H%M%S)

mkdir -p "$BACKUP_DIR"

# Crear backup
mysqldump -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" > "$BACKUP_DIR/db_${DATE}.sql"

# Comprimir backup
gzip "$BACKUP_DIR/db_${DATE}.sql"

# Eliminar backups antiguos (más de 30 días)
find "$BACKUP_DIR" -name "db_*.sql.gz" -mtime +30 -delete 2>/dev/null

echo "[$(date)] Backup de base de datos completado: db_${DATE}.sql.gz"

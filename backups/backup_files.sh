#!/bin/bash
# ============================================================
# Backup de archivos - TP Integrador N.º 1
# Las rutas se leen del entorno (R7).
# ============================================================

BACKUP_DIR="${BACKUP_DIR:-./backups}"
PROJECT_DIR="${PROJECT_DIR:-.}"
DATE=$(date +%Y%m%d_%H%M%S)

mkdir -p "$BACKUP_DIR"

# Crear backup
tar -czf "$BACKUP_DIR/files_${DATE}.tar.gz" -C "$PROJECT_DIR" .

# Eliminar backups antiguos (más de 30 días)
find "$BACKUP_DIR" -name "files_*.tar.gz" -mtime +30 -delete 2>/dev/null

echo "[$(date)] Backup de archivos completado: files_${DATE}.tar.gz"

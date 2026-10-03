#!/bin/bash
# Backup de archivos
BACKUP_DIR="/home/lau/Escritorio/repos/prueba123-main/backups"
PROJECT_DIR="/home/lau/Escritorio/repos/prueba123-main"
DATE=$(date +%Y%m%d_%H%M%S)

# Crear backup
tar -czf "$BACKUP_DIR/files_${DATE}.tar.gz" -C "$PROJECT_DIR" .

# Eliminar backups antiguos (más de 30 días)
find "$BACKUP_DIR" -name "files_*.tar.gz" -mtime +30 -delete

echo "[$(date)] Backup de archivos completado: files_${DATE}.tar.gz"

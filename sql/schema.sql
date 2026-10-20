-- ============================================================
-- Script SQL para crear la base de datos del TP Integrador N.º 1
-- Proyecto: App de Comentarios (fullstack PHP + MySQL)
-- Materia: Aplicaciones Móviles y Cloud Computing
-- Integrante: Lautaro Hillkirk (trabajo individual, creado solo por él)
-- Proveedor cloud: InfinityFree (hosting compartido + MySQL remoto)
-- ============================================================

CREATE DATABASE IF NOT EXISTS mi_crud_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mi_crud_local;

-- Tabla de usuarios
CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(254) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    avatar VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Tabla de comentarios (recurso principal con CRUD)
CREATE TABLE IF NOT EXISTS comentarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    parent_id INT NULL,
    contenido TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES comentarios(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Tabla de votos (like / dislike)
CREATE TABLE IF NOT EXISTS votos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    comentario_id INT NOT NULL,
    usuario_id INT NOT NULL,
    tipo_voto ENUM('like','dislike') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (comentario_id) REFERENCES comentarios(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    UNIQUE KEY unique_voto (comentario_id, usuario_id)
) ENGINE=InnoDB;

-- Tabla de notificaciones
CREATE TABLE IF NOT EXISTS notificaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    comentario_id INT NULL,
    mensaje VARCHAR(500) NOT NULL,
    leido TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (comentario_id) REFERENCES comentarios(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Índices para rendimiento de consultas
CREATE INDEX idx_comentarios_usuario ON comentarios(usuario_id);
CREATE INDEX idx_comentarios_parent ON comentarios(parent_id);
CREATE INDEX idx_votos_comentario ON votos(comentario_id);
CREATE INDEX idx_notificaciones_usuario ON notificaciones(usuario_id, leido);

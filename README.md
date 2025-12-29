# Pomodoro
Copiar y pegar en php myadmin u otra base de datos:
//////////////////////////////////////////////////////////////
-- Crear la base de datos (ajusta el nombre según config.php)
CREATE DATABASE IF NOT EXISTS olimpiadas_estudio CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE olimpiadas_estudio;

-- Tabla: usuarios
CREATE TABLE usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    record_horas_dia DECIMAL(10,2) DEFAULT 0.00,
    dias_activos INT DEFAULT 0,
    racha_madrugador INT DEFAULT 0,
    mejor_racha_madrugador INT DEFAULT 0,
    ultima_fecha_madrugador DATE DEFAULT NULL,
    INDEX idx_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: sesiones
CREATE TABLE sesiones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    fecha DATE NOT NULL,
    hora_inicio DATETIME NOT NULL,
    hora_fin DATETIME DEFAULT NULL,
    minutos INT DEFAULT 0,
    activa TINYINT(1) DEFAULT 0,
    INDEX idx_usuario (usuario_id),
    INDEX idx_fecha (fecha),
    INDEX idx_activa (activa),
    INDEX idx_usuario_activa (usuario_id, activa),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: resumen_diario
CREATE TABLE resumen_diario (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    fecha DATE NOT NULL,
    horas_dia DECIMAL(10,2) DEFAULT 0.00,
    horas_acumuladas DECIMAL(10,2) DEFAULT 0.00,
    ultima_activacion DATETIME DEFAULT NULL,
    UNIQUE KEY unique_usuario_fecha (usuario_id, fecha),
    INDEX idx_fecha (fecha),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: eventos
CREATE TABLE eventos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    titulo VARCHAR(200) NOT NULL,
    descripcion TEXT,
    fecha DATE NOT NULL,
    hora TIME DEFAULT NULL,
    INDEX idx_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Datos de ejemplo: usuarios (ajusta los nombres según necesites)
INSERT INTO usuarios (nombre) VALUES 
('Usuario 1'),
('Usuario 2'),
('Usuario 3');

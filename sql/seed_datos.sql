-- =============================================================
-- MediTurnos — Esquema completo + datos de prueba
-- Ejecutar en phpMyAdmin o MySQL CLI
--
-- Es el punto de partida (crea la base y las tablas originales), pero
-- NO refleja el estado actual del esquema: estado_turno.sql,
-- control_concurrencia.sql, concurrencia_consultorio.sql,
-- pagos_y_descuentos.sql, normalizacion.sql, ausencias_medico.sql y
-- baja_medico.sql se corrieron DESPUÉS de este archivo y modifican
-- columnas/tablas que acá todavía aparecen con su forma vieja (p. ej.
-- turno.estado como texto en vez de turno.id_estado). Para saber cómo
-- es la base REAL con la que corre la aplicación, cada migración
-- posterior es la fuente de verdad, no este archivo — no se lo
-- reescribió para no perder el registro histórico de cómo evolucionó
-- el diseño.
-- =============================================================

CREATE DATABASE IF NOT EXISTS mediturnos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mediturnos;

-- ---------------------------------------------------------------
-- TABLAS
-- ---------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rol (
    id_rol  INT AUTO_INCREMENT PRIMARY KEY,
    nombre  VARCHAR(30) NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS permiso (
    id_permiso INT AUTO_INCREMENT PRIMARY KEY,
    nombre     VARCHAR(60) NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS rol_permiso (
    id_rol     INT NOT NULL,
    id_permiso INT NOT NULL,
    PRIMARY KEY (id_rol, id_permiso),
    FOREIGN KEY (id_rol)     REFERENCES rol(id_rol),
    FOREIGN KEY (id_permiso) REFERENCES permiso(id_permiso)
);

CREATE TABLE IF NOT EXISTS especialidad (
    id_especialidad   INT AUTO_INCREMENT PRIMARY KEY,
    nombre            VARCHAR(80)  NOT NULL,
    duracion_turno_min INT         NOT NULL DEFAULT 20
);

CREATE TABLE IF NOT EXISTS consultorio (
    id_consultorio INT AUTO_INCREMENT PRIMARY KEY,
    numero         INT NOT NULL,
    piso           INT NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS obra_social (
    id_obra_social INT AUTO_INCREMENT PRIMARY KEY,
    nombre         VARCHAR(100) NOT NULL
);

CREATE TABLE IF NOT EXISTS plan_os (
    id_plan              INT AUTO_INCREMENT PRIMARY KEY,
    id_obra_social       INT NOT NULL,
    nombre_plan          VARCHAR(80)    NOT NULL,
    porcentaje_cobertura DECIMAL(5,2)   NOT NULL DEFAULT 70.00,
    FOREIGN KEY (id_obra_social) REFERENCES obra_social(id_obra_social)
);

CREATE TABLE IF NOT EXISTS paciente (
    id_paciente INT AUTO_INCREMENT PRIMARY KEY,
    dni         VARCHAR(15)  NOT NULL UNIQUE,
    nombre      VARCHAR(60)  NOT NULL,
    apellido    VARCHAR(60)  NOT NULL,
    fecha_nac   DATE,
    telefono    VARCHAR(20),
    email       VARCHAR(100)
);

CREATE TABLE IF NOT EXISTS medico (
    matricula INT PRIMARY KEY,
    nombre    VARCHAR(60) NOT NULL,
    apellido  VARCHAR(60) NOT NULL,
    telefono  VARCHAR(20),
    email     VARCHAR(100)
);

CREATE TABLE IF NOT EXISTS medico_especialidad (
    matricula       INT NOT NULL,
    id_especialidad INT NOT NULL,
    PRIMARY KEY (matricula, id_especialidad),
    FOREIGN KEY (matricula)       REFERENCES medico(matricula),
    FOREIGN KEY (id_especialidad) REFERENCES especialidad(id_especialidad)
);

CREATE TABLE IF NOT EXISTS horario_atencion (
    id_horario      INT AUTO_INCREMENT PRIMARY KEY,
    matricula       INT         NOT NULL,
    dia_semana      VARCHAR(15) NOT NULL,
    hora_inicio     TIME        NOT NULL,
    hora_fin        TIME        NOT NULL,
    id_consultorio  INT         NOT NULL,
    id_especialidad INT         NOT NULL,
    FOREIGN KEY (matricula)       REFERENCES medico(matricula),
    FOREIGN KEY (id_consultorio)  REFERENCES consultorio(id_consultorio),
    FOREIGN KEY (id_especialidad) REFERENCES especialidad(id_especialidad)
);

-- Catálogo de estados del turno (normalización 4FN: el estado es un
-- atributo de dominio cerrado → tabla de referencia, no texto repetido).
-- ids fijos para que sean estables (los usa slot_unico en control_concurrencia.sql).
CREATE TABLE IF NOT EXISTS estado_turno (
    id_estado   INT AUTO_INCREMENT PRIMARY KEY,
    descripcion VARCHAR(30) NOT NULL UNIQUE
);

INSERT IGNORE INTO estado_turno (id_estado, descripcion) VALUES
    (1, 'Reservado'),
    (2, 'Confirmado'),
    (3, 'Realizado'),
    (4, 'Ausente'),
    (5, 'Cancelado');

CREATE TABLE IF NOT EXISTS turno (
    id_turno        INT AUTO_INCREMENT PRIMARY KEY,
    fecha           DATE        NOT NULL,
    hora_inicio     TIME        NOT NULL,
    id_estado       INT         NOT NULL DEFAULT 1,   -- 1 = Reservado
    observacion     TEXT,
    id_paciente     INT         NOT NULL,
    matricula       INT         NOT NULL,
    id_especialidad INT         NOT NULL,
    id_consultorio  INT         NOT NULL,
    id_plan         INT         NOT NULL,
    FOREIGN KEY (id_estado)       REFERENCES estado_turno(id_estado),
    FOREIGN KEY (id_paciente)     REFERENCES paciente(id_paciente),
    FOREIGN KEY (matricula)       REFERENCES medico(matricula),
    FOREIGN KEY (id_especialidad) REFERENCES especialidad(id_especialidad),
    FOREIGN KEY (id_consultorio)  REFERENCES consultorio(id_consultorio),
    FOREIGN KEY (id_plan)         REFERENCES plan_os(id_plan)
);

CREATE TABLE IF NOT EXISTS historial_turno (
    id_historial   INT AUTO_INCREMENT PRIMARY KEY,
    id_turno       INT         NOT NULL,
    estado_anterior VARCHAR(30),
    estado_nuevo    VARCHAR(30) NOT NULL,
    observacion     TEXT,
    fecha_cambio    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_turno) REFERENCES turno(id_turno)
);

CREATE TABLE IF NOT EXISTS usuario (
    id_usuario   INT AUTO_INCREMENT PRIMARY KEY,
    nombre       VARCHAR(60)  NOT NULL,
    apellido     VARCHAR(60)  NOT NULL,
    usuario      VARCHAR(40)  NOT NULL UNIQUE,
    email        VARCHAR(100),
    contrasenia  VARCHAR(255) NOT NULL,
    id_rol       INT          NOT NULL,
    id_paciente  INT          DEFAULT NULL,
    matricula    INT          DEFAULT NULL,
    estado       VARCHAR(15)  NOT NULL DEFAULT 'activo',
    online       TINYINT(1)   NOT NULL DEFAULT 0,
    ultimo_login DATETIME,
    fecha_alta   DATE         NOT NULL DEFAULT (CURDATE()),
    fecha_baja   DATE,
    FOREIGN KEY (id_rol)     REFERENCES rol(id_rol),
    FOREIGN KEY (id_paciente) REFERENCES paciente(id_paciente),
    FOREIGN KEY (matricula)  REFERENCES medico(matricula)
);

-- ---------------------------------------------------------------
-- ROLES Y PERMISOS
-- ---------------------------------------------------------------

INSERT IGNORE INTO rol (nombre) VALUES ('admin'), ('recepcionista'), ('medico'), ('paciente');

INSERT IGNORE INTO permiso (nombre) VALUES
    ('ver_turnos'), ('crear_turno'), ('cancelar_turno'), ('cambiar_estado_turno'),
    ('ver_pacientes'), ('crear_paciente'), ('editar_paciente'),
    ('ver_medicos'), ('crear_medico'), ('editar_medico'),
    ('ver_usuarios'), ('crear_usuario'), ('editar_usuario');

-- Admin tiene todos los permisos
INSERT IGNORE INTO rol_permiso (id_rol, id_permiso)
SELECT r.id_rol, p.id_permiso
FROM rol r, permiso p
WHERE r.nombre = 'admin';

-- Recepcionista
INSERT IGNORE INTO rol_permiso (id_rol, id_permiso)
SELECT r.id_rol, p.id_permiso FROM rol r, permiso p
WHERE r.nombre = 'recepcionista'
  AND p.nombre IN ('ver_turnos','crear_turno','cancelar_turno','ver_pacientes','crear_paciente','editar_paciente','ver_medicos');

-- Médico
INSERT IGNORE INTO rol_permiso (id_rol, id_permiso)
SELECT r.id_rol, p.id_permiso FROM rol r, permiso p
WHERE r.nombre = 'medico'
  AND p.nombre IN ('ver_turnos','cambiar_estado_turno','ver_pacientes');

-- Paciente
INSERT IGNORE INTO rol_permiso (id_rol, id_permiso)
SELECT r.id_rol, p.id_permiso FROM rol r, permiso p
WHERE r.nombre = 'paciente'
  AND p.nombre IN ('ver_turnos','crear_turno','cancelar_turno');

-- ---------------------------------------------------------------
-- ESPECIALIDADES
-- ---------------------------------------------------------------

INSERT IGNORE INTO especialidad (nombre, duracion_turno_min) VALUES
    ('Clínica Médica',      20),
    ('Cardiología',         30),
    ('Pediatría',           20),
    ('Dermatología',        20),
    ('Traumatología',       30),
    ('Ginecología',         30),
    ('Neurología',          30),
    ('Oftalmología',        20);

-- ---------------------------------------------------------------
-- CONSULTORIOS
-- ---------------------------------------------------------------

INSERT IGNORE INTO consultorio (numero, piso) VALUES
    (1, 1), (2, 1), (3, 1),
    (4, 2), (5, 2), (6, 2);

-- ---------------------------------------------------------------
-- OBRAS SOCIALES Y PLANES
-- ---------------------------------------------------------------

INSERT IGNORE INTO obra_social (nombre) VALUES
    ('OSDE'), ('Swiss Medical'), ('IOMA'), ('Galeno'), ('Particular');

INSERT IGNORE INTO plan_os (id_obra_social, nombre_plan, porcentaje_cobertura) VALUES
    (1, 'Plan 210',   70.00),
    (1, 'Plan 310',   80.00),
    (1, 'Plan 410',   90.00),
    (2, 'Bronze',     70.00),
    (2, 'Silver',     80.00),
    (2, 'Gold',       90.00),
    (3, 'IOMA Básico',70.00),
    (3, 'IOMA Plus',  80.00),
    (4, 'Galeno Blue',75.00),
    (5, 'Sin cobertura', 0.00);

-- ---------------------------------------------------------------
-- MÉDICOS
-- ---------------------------------------------------------------

INSERT IGNORE INTO medico (matricula, nombre, apellido, telefono, email) VALUES
    (10001, 'Carlos',   'Fernández',  '1155550001', 'cfernandez@mediturnos.com'),
    (10002, 'María',    'González',   '1155550002', 'mgonzalez@mediturnos.com'),
    (10003, 'Roberto',  'López',      '1155550003', 'rlopez@mediturnos.com'),
    (10004, 'Valeria',  'Martínez',   '1155550004', 'vmartinez@mediturnos.com'),
    (10005, 'Diego',    'Rodríguez',  '1155550005', 'drodriguez@mediturnos.com');

-- Especialidades por médico
INSERT IGNORE INTO medico_especialidad (matricula, id_especialidad) VALUES
    (10001, 1), -- Fernández → Clínica Médica
    (10001, 2), -- Fernández → Cardiología
    (10002, 3), -- González  → Pediatría
    (10002, 6), -- González  → Ginecología
    (10003, 4), -- López     → Dermatología
    (10003, 7), -- López     → Neurología
    (10004, 5), -- Martínez  → Traumatología
    (10005, 8), -- Rodríguez → Oftalmología
    (10005, 1); -- Rodríguez → Clínica Médica

-- ---------------------------------------------------------------
-- HORARIOS DE ATENCIÓN
-- ---------------------------------------------------------------

INSERT IGNORE INTO horario_atencion (matricula, dia_semana, hora_inicio, hora_fin, id_consultorio, id_especialidad) VALUES
    -- Fernández (Clínica Médica) — Lunes, Miércoles, Viernes mañana
    (10001, 'Lunes',      '08:00:00', '12:00:00', 1, 1),
    (10001, 'Miercoles',  '08:00:00', '12:00:00', 1, 1),
    (10001, 'Viernes',    '08:00:00', '12:00:00', 1, 1),
    -- Fernández (Cardiología) — Martes, Jueves tarde
    (10001, 'Martes',     '14:00:00', '18:00:00', 2, 2),
    (10001, 'Jueves',     '14:00:00', '18:00:00', 2, 2),

    -- González (Pediatría) — Lunes a Viernes mañana
    (10002, 'Lunes',      '08:00:00', '13:00:00', 3, 3),
    (10002, 'Martes',     '08:00:00', '13:00:00', 3, 3),
    (10002, 'Miercoles',  '08:00:00', '13:00:00', 3, 3),
    (10002, 'Jueves',     '08:00:00', '13:00:00', 3, 3),
    (10002, 'Viernes',    '08:00:00', '13:00:00', 3, 3),
    -- González (Ginecología) — Lunes, Miércoles tarde
    (10002, 'Lunes',      '14:00:00', '18:00:00', 4, 6),
    (10002, 'Miercoles',  '14:00:00', '18:00:00', 4, 6),

    -- López (Dermatología) — Martes, Jueves, Sábados
    (10003, 'Martes',     '09:00:00', '13:00:00', 5, 4),
    (10003, 'Jueves',     '09:00:00', '13:00:00', 5, 4),
    (10003, 'Sabado',     '09:00:00', '12:00:00', 5, 4),

    -- Martínez (Traumatología) — Lunes, Miércoles, Viernes tarde
    (10004, 'Lunes',      '14:00:00', '19:00:00', 6, 5),
    (10004, 'Miercoles',  '14:00:00', '19:00:00', 6, 5),
    (10004, 'Viernes',    '14:00:00', '19:00:00', 6, 5),

    -- Rodríguez (Oftalmología) — Martes, Jueves tarde
    (10005, 'Martes',     '14:00:00', '18:00:00', 1, 8),
    (10005, 'Jueves',     '14:00:00', '18:00:00', 1, 8);

-- ---------------------------------------------------------------
-- PACIENTES
-- ---------------------------------------------------------------

INSERT IGNORE INTO paciente (dni, nombre, apellido, fecha_nac, telefono, email) VALUES
    ('30111222', 'Juan',     'Pérez',    '1985-03-15', '1144441111', 'jperez@email.com'),
    ('31222333', 'Ana',      'García',   '1990-07-22', '1144442222', 'agarcia@email.com'),
    ('32333444', 'Luis',     'Herrera',  '1978-11-08', '1144443333', 'lherrera@email.com'),
    ('33444555', 'Sofía',    'Torres',   '2000-01-30', '1144444444', 'storres@email.com'),
    ('34555666', 'Marcos',   'Díaz',     '1995-05-12', '1144445555', 'mdiaz@email.com');

-- ---------------------------------------------------------------
-- USUARIOS
-- Contraseñas: todas son "password" (hash bcrypt)
-- ---------------------------------------------------------------

INSERT IGNORE INTO usuario (nombre, apellido, usuario, email, contrasenia, id_rol, id_paciente, matricula) VALUES
    ('Admin',   'Sistema',   'admin',        'admin@mediturnos.com',      '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', (SELECT id_rol FROM rol WHERE nombre='admin'),         NULL,  NULL),
    ('Laura',   'Recepción', 'recepcion',    'recepcion@mediturnos.com',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', (SELECT id_rol FROM rol WHERE nombre='recepcionista'),  NULL,  NULL),
    ('Carlos',  'Fernández', 'cfernandez',   'cfernandez@mediturnos.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', (SELECT id_rol FROM rol WHERE nombre='medico'),         NULL,  10001),
    ('María',   'González',  'mgonzalez',    'mgonzalez@mediturnos.com',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', (SELECT id_rol FROM rol WHERE nombre='medico'),         NULL,  10002),
    ('Juan',    'Pérez',     'jperez',       'jperez@email.com',          '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', (SELECT id_rol FROM rol WHERE nombre='paciente'),       1,     NULL),
    ('Ana',     'García',    'agarcia',      'agarcia@email.com',         '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', (SELECT id_rol FROM rol WHERE nombre='paciente'),       2,     NULL);

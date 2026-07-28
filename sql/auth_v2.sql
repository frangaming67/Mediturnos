-- =============================================================
-- MediTurnos — Migración: sistema de autenticación v2
-- =============================================================
-- Ejecutar UNA vez sobre la base "mediturnos":
--   mysql -u root --default-character-set=utf8mb4 mediturnos < auth_v2.sql
--
-- Es IDEMPOTENTE: usa IF NOT EXISTS / IF EXISTS en todo, así que
-- volver a correrlo no rompe nada ni duplica columnas.
--
-- QUÉ RESUELVE
--   1) Campos que el registro nuevo necesita y no existían
--      (sexo, dirección, foto de perfil).
--   2) Tres defectos de tipo en columnas YA EXISTENTES que estaban
--      corrompiendo datos en silencio (ver cada punto).
--   3) Tablas nuevas para recuperación de contraseña y para frenar
--      ataques de fuerza bruta.
--
-- COMPATIBILIDAD: ninguna columna se elimina y ninguna pasa a ser
-- NOT NULL sin default, por lo que TODO el código actual sigue
-- funcionando sin cambios.
-- =============================================================

USE mediturnos;

-- -------------------------------------------------------------
-- 1) paciente.telefono : int(10) unsigned  →  VARCHAR(30)
-- -------------------------------------------------------------
-- PROBLEMA REAL: int(10) unsigned llega hasta 4.294.967.295. Un
-- celular argentino con característica, como 91123456789 (11
-- dígitos), vale 91.123.456.789 y NO ENTRA: MySQL lo rechazaba o
-- lo truncaba. Además, al ser numérico se perdían los ceros a la
-- izquierda y era imposible guardar formatos como "+54 11 4444-1111".
--
-- Un teléfono NUNCA es un número con el que se opere aritméticamente:
-- es una cadena de dígitos. Su tipo correcto es VARCHAR.
--
-- La conversión es segura: MySQL pasa cada número a su representación
-- en texto ("1144441111"), sin pérdida de los datos ya cargados.
ALTER TABLE paciente
    MODIFY COLUMN telefono VARCHAR(30) NOT NULL;

-- -------------------------------------------------------------
-- 2) paciente.email : varchar(30)  →  VARCHAR(120)
-- -------------------------------------------------------------
-- PROBLEMA REAL: el estándar (RFC 5321) admite hasta 254 caracteres.
-- Con 30, un email perfectamente normal como
-- "maria.fernandez.lopez@hospitalitaliano.org.ar" (45) se guardaba
-- CORTADO, dejando una dirección inválida sin ningún aviso.
-- 120 cubre con holgura cualquier email real.
ALTER TABLE paciente
    MODIFY COLUMN email VARCHAR(120) NULL;

-- -------------------------------------------------------------
-- 3) Campos nuevos del perfil del paciente
-- -------------------------------------------------------------
-- sexo: se guarda como ENUM y no como texto libre porque es un
-- dominio cerrado. Incluye 'X' (DNI no binario, Ley 26.743 en
-- Argentina) y 'prefiero_no_decir' para no forzar la respuesta.
-- Todos NULL: los 1010 pacientes que ya existen no tienen estos
-- datos y no se los puede inventar.
ALTER TABLE paciente
    ADD COLUMN IF NOT EXISTS sexo ENUM('F','M','X','prefiero_no_decir') NULL AFTER fecha_nac;

ALTER TABLE paciente
    ADD COLUMN IF NOT EXISTS direccion VARCHAR(150) NULL AFTER telefono;

-- -------------------------------------------------------------
-- 4) usuario.foto — foto de perfil
-- -------------------------------------------------------------
-- Se guarda el NOMBRE DEL ARCHIVO, no la imagen. Meter binarios en
-- la base infla el tamaño, complica los backups y obliga a que PHP
-- lea y reenvíe cada byte; sirviéndola como archivo estático, la
-- entrega Apache directamente (mucho más rápido y cacheable).
-- Los archivos viven en publico/img/perfiles/.
-- Va en `usuario` (no en `paciente`) porque TODOS los roles tienen
-- foto: también admin, recepcionista y médico.
ALTER TABLE usuario
    ADD COLUMN IF NOT EXISTS foto VARCHAR(255) NULL AFTER email;

-- -------------------------------------------------------------
-- 5) Recuperación de contraseña
-- -------------------------------------------------------------
-- Se guarda el HASH del token, nunca el token en claro: si alguien
-- leyera esta tabla no podría usar los enlaces pendientes. Es el
-- mismo criterio que con las contraseñas.
--   usado_en: marca de un solo uso. Al usarse el enlace se completa
--             y el token queda inservible aunque no haya expirado.
--   expira_en: vencimiento corto (la app usa 60 minutos).
CREATE TABLE IF NOT EXISTS password_reset (
    id_reset    INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario  INT          NOT NULL,
    token_hash  CHAR(64)     NOT NULL,
    creado_en   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expira_en   DATETIME     NOT NULL,
    usado_en    DATETIME     NULL,
    ip_solicito VARCHAR(45)  NULL,
    UNIQUE KEY uq_reset_token (token_hash),
    KEY idx_reset_usuario (id_usuario),
    KEY idx_reset_expira  (expira_en),
    CONSTRAINT fk_reset_usuario FOREIGN KEY (id_usuario)
        REFERENCES usuario(id_usuario) ON DELETE CASCADE
) ENGINE = InnoDB;

-- -------------------------------------------------------------
-- 6) Intentos de login (defensa contra fuerza bruta)
-- -------------------------------------------------------------
-- Se registra CADA intento, exitoso o no. El bloqueo se calcula por
-- (identificador + IP) en una ventana de tiempo, para que un atacante
-- no pueda dejar bloqueada la cuenta de otra persona simplemente
-- fallando desde su propia IP.
CREATE TABLE IF NOT EXISTS intento_login (
    id_intento    INT AUTO_INCREMENT PRIMARY KEY,
    identificador VARCHAR(100) NOT NULL,   -- usuario o email tipeado
    ip            VARCHAR(45)  NOT NULL,
    exito         TINYINT(1)   NOT NULL DEFAULT 0,
    fecha         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_intento_busqueda (identificador, ip, fecha),
    KEY idx_intento_fecha (fecha)
) ENGINE = InnoDB;

-- -------------------------------------------------------------
-- 7) Índice para login por email
-- -------------------------------------------------------------
-- El login nuevo acepta email O nombre de usuario en el mismo campo.
-- `usuario` ya tiene índice por su UNIQUE; `email` también, pero se
-- deja explícito el de búsqueda por si cambia la definición.
CREATE INDEX IF NOT EXISTS idx_usuario_email ON usuario (email);

-- =============================================================
-- Verificación rápida (opcional):
--   DESCRIBE paciente;
--   DESCRIBE usuario;
--   SHOW TABLES LIKE '%reset%';
--   SELECT COUNT(*) FROM paciente;   -- debe seguir dando 1010
-- =============================================================

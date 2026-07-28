-- =============================================================
-- MediTurnos — Pagos de turnos + Descuentos por obra social y médico
-- Ejecutar sobre la base "mediturnos" DESPUÉS de seed_datos.sql
-- (phpMyAdmin o consola MySQL). Requiere MariaDB 10.2+ / InnoDB.
--
-- OBJETIVO:
--   1) Que cada turno tenga un precio y un pago asociado. El paciente
--      paga en el momento (tarjeta) o más tarde (tarjeta hasta el
--      vencimiento, o en recepción).
--   2) Que cada obra social aplique un descuento DISTINTO según el
--      médico. El monto que paga el paciente sale de:
--          precio_consulta(especialidad) * (1 - descuento/100)
--      donde el descuento depende del par (obra_social, médico).
--
-- Migración incremental aparte de seed_datos.sql (mismo motivo que
-- ausencias_medico.sql: se agregó con la base ya en uso).
-- =============================================================

USE mediturnos;

-- -------------------------------------------------------------
-- 1) Precio base de la consulta, por especialidad.
--    Es el precio "de lista". Sobre éste se aplica el descuento
--    de la obra social para el médico elegido.
-- -------------------------------------------------------------
ALTER TABLE especialidad
  ADD COLUMN IF NOT EXISTS precio_consulta DECIMAL(10,2) NOT NULL DEFAULT 5000.00;

UPDATE especialidad SET precio_consulta = 5000.00 WHERE nombre LIKE 'Clinica%';
UPDATE especialidad SET precio_consulta = 9000.00 WHERE nombre LIKE 'Cardiolog%';
UPDATE especialidad SET precio_consulta = 6000.00 WHERE nombre LIKE 'Pediatr%';
UPDATE especialidad SET precio_consulta = 7000.00 WHERE nombre LIKE 'Dermatolog%';
UPDATE especialidad SET precio_consulta = 8500.00 WHERE nombre LIKE 'Traumatolog%';
UPDATE especialidad SET precio_consulta = 8000.00 WHERE nombre LIKE 'Ginecolog%';
UPDATE especialidad SET precio_consulta = 9500.00 WHERE nombre LIKE 'Neurolog%';
UPDATE especialidad SET precio_consulta = 6500.00 WHERE nombre LIKE 'Oftalmolog%';

-- -------------------------------------------------------------
-- 2) Descuentos por obra social y médico.
--    Cada fila dice: "la obra social X le hace al paciente un
--    descuento de Y% si el turno es con el médico Z".
--    La clave única (id_obra_social, matricula) garantiza un solo
--    descuento por combinación. Si no existe fila para un par, el
--    descuento es 0 (el paciente paga el precio de lista).
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS descuento_os_medico (
    id_descuento         INT AUTO_INCREMENT PRIMARY KEY,
    id_obra_social       INT          NOT NULL,
    matricula            INT          NOT NULL,
    porcentaje_descuento DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    UNIQUE KEY uq_desc_os_med (id_obra_social, matricula),
    FOREIGN KEY (id_obra_social) REFERENCES obra_social(id_obra_social),
    FOREIGN KEY (matricula)      REFERENCES medico(matricula)
) ENGINE = InnoDB;

-- Datos de ejemplo: cada obra social descuenta distinto según el médico.
-- (id_obra_social: 1=OSDE, 6=Swiss Medical, 7=IOMA, 8=Galeno, 9=Particular)
INSERT INTO descuento_os_medico (id_obra_social, matricula, porcentaje_descuento) VALUES
    (1, 10001, 80.00),  -- OSDE          → Fernández  80%
    (1, 10002, 50.00),  -- OSDE          → González   50%
    (1, 10004, 60.00),  -- OSDE          → Martínez   60%
    (6, 10001, 70.00),  -- Swiss Medical → Fernández  70%
    (6, 10003, 90.00),  -- Swiss Medical → López      90%
    (6, 10005, 40.00),  -- Swiss Medical → Rodríguez  40%
    (7, 10002, 100.00), -- IOMA          → González  100% (gratis)
    (7, 10004, 50.00),  -- IOMA          → Martínez   50%
    (8, 10003, 60.00),  -- Galeno        → López      60%
    (8, 10005, 75.00)   -- Galeno        → Rodríguez  75%
ON DUPLICATE KEY UPDATE porcentaje_descuento = VALUES(porcentaje_descuento);

-- -------------------------------------------------------------
-- 3) Pago de un turno (uno por turno).
--    estado: Pendiente | Pagado | Vencido | Anulado
--    metodo: Tarjeta | Recepcion (NULL mientras está pendiente)
--    fecha_vencimiento: hasta cuándo se puede pagar sin que el turno
--                       se cancele solo (lo calcula el PHP al reservar).
--    Sólo se guardan los últimos 4 dígitos y el titular de la tarjeta:
--    NUNCA el número completo ni el CVV (buena práctica PCI).
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pago (
    id_pago              INT AUTO_INCREMENT PRIMARY KEY,
    id_turno             INT          NOT NULL,
    monto_base           DECIMAL(10,2) NOT NULL,
    porcentaje_descuento DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    monto_total          DECIMAL(10,2) NOT NULL,
    estado               VARCHAR(20)  NOT NULL DEFAULT 'Pendiente',
    metodo               VARCHAR(20),
    fecha_creacion       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_vencimiento    DATETIME     NOT NULL,
    fecha_pago           DATETIME,
    tarjeta_ult4         VARCHAR(4),
    tarjeta_titular      VARCHAR(100),
    referencia           VARCHAR(40),
    UNIQUE KEY uq_pago_turno (id_turno),
    FOREIGN KEY (id_turno) REFERENCES turno(id_turno)
) ENGINE = InnoDB;

CREATE INDEX IF NOT EXISTS idx_pago_estado ON pago (estado);
CREATE INDEX IF NOT EXISTS idx_pago_venc   ON pago (fecha_vencimiento);

-- =============================================================
-- MediTurnos — Baja lógica de médicos (el admin da de baja un médico)
-- Ejecutar sobre la base "mediturnos" DESPUÉS de seed_datos.sql
-- (phpMyAdmin o consola MySQL). Requiere motor InnoDB.
--
-- OBJETIVO: que el administrador pueda dar de baja a un médico desde
-- el apartado de "Acciones" del listado. La baja es LÓGICA (no se borra
-- la fila) porque la matrícula la referencian turnos históricos,
-- horarios, ausencias, descuentos y la cuenta de usuario del médico.
-- Un médico dado de baja:
--   1) queda marcado como 'inactivo' (con fecha_baja para auditoría), y
--   2) deja de ofrecerse al cargar nuevos turnos / horarios / ausencias.
-- Sus turnos y datos históricos se conservan intactos.
--
-- Migración incremental aparte de seed_datos.sql (mismo motivo que
-- ausencias_medico.sql: se agregó con la base ya en uso). Es BAJA
-- LÓGICA (columna estado) y no DELETE porque borrar la fila del médico
-- rompería las FK de turno/horario_atencion/ausencia_medico/descuento_os_medico
-- que ya lo referencian — perdería todo el historial clínico y de turnos
-- asociado a esa matrícula.
-- =============================================================

USE mediturnos;

-- -------------------------------------------------------------
-- 1) Estado del médico. Por defecto 'activo' para no afectar a los
--    médicos ya cargados. fecha_baja queda NULL hasta que se da de baja.
-- -------------------------------------------------------------
ALTER TABLE medico
    ADD COLUMN estado     ENUM('activo','inactivo') NOT NULL DEFAULT 'activo' AFTER email,
    ADD COLUMN fecha_baja DATE NULL DEFAULT NULL AFTER estado;

-- -------------------------------------------------------------
-- 2) Permiso fino para la baja (solo el admin lo tendrá).
-- -------------------------------------------------------------
INSERT INTO permiso (nombre, descripcion)
SELECT 'medicos.baja', 'Dar de baja / reactivar médicos'
WHERE NOT EXISTS (SELECT 1 FROM permiso WHERE nombre = 'medicos.baja');

-- -------------------------------------------------------------
-- 3) Asignar el permiso al rol admin (id_rol = 1).
-- -------------------------------------------------------------
INSERT INTO rol_permiso (id_rol, id_permiso)
SELECT r.id_rol, p.id_permiso
FROM   rol     r
JOIN   permiso p ON p.nombre = 'medicos.baja'
WHERE  r.nombre = 'admin'
  AND  NOT EXISTS (
      SELECT 1 FROM rol_permiso rp
      WHERE rp.id_rol = r.id_rol AND rp.id_permiso = p.id_permiso
  );

-- =============================================================
-- MediTurnos — Migración: Normalización a 4FN
-- =============================================================
-- Corrige las 2 dependencias que rompían 3FN/BCNF (el resto del
-- esquema ya estaba en 4FN: todas las relaciones N:M están
-- resueltas en tablas puente, sin dependencias multivaluadas):
--
--   1) turno.nro_afiliado  → dependencia transitiva.
--      nro_afiliado depende del par (paciente, plan), no del turno.
--      Su lugar correcto es paciente_plan (donde ya existe la col).
--
--   2) pago.monto_total     → columna derivada dentro de la fila.
--      monto_total = monto_base * (1 - porcentaje_descuento/100).
--      Se vuelve columna GENERADA (STORED): consultable pero sin
--      riesgo de inconsistencia.
--
-- Ejecutar (después de los scripts previos):
--   mysql -u root mediturnos < normalizacion_4fn.sql
--
-- ADVERTENCIA: el punto 1 de este archivo redefine ReservarTurno como
-- parte de sacarle el parámetro p_nro_afiliado — esa versión tampoco
-- valida consultorio (mismo problema que triggers_y_sp.sql). La versión
-- vigente es la de concurrencia_consultorio.sql. Si en algún momento se
-- reordenan las migraciones, este archivo debe correrse ANTES que
-- concurrencia_consultorio.sql, nunca después.
-- =============================================================

USE mediturnos;

-- -------------------------------------------------------------
-- 1) turno.nro_afiliado  →  paciente_plan.nro_afiliado
-- -------------------------------------------------------------

-- Mover a paciente_plan los nro de afiliado que pudieran existir
-- en turnos, sin pisar los ya cargados.
INSERT INTO paciente_plan (id_paciente, id_plan, nro_afiliado, fecha_alta)
SELECT t.id_paciente, t.id_plan, t.nro_afiliado, CURDATE()
FROM   turno t
WHERE  t.nro_afiliado IS NOT NULL AND t.nro_afiliado <> ''
ON DUPLICATE KEY UPDATE nro_afiliado = VALUES(nro_afiliado);

-- Eliminar la columna redundante del turno.
ALTER TABLE turno DROP COLUMN nro_afiliado;

-- SP ReservarTurno sin el parámetro p_nro_afiliado (la captura del
-- afiliado pasa a hacerse contra paciente_plan desde la aplicación).
DROP PROCEDURE IF EXISTS ReservarTurno;
DELIMITER //
CREATE PROCEDURE ReservarTurno(
    IN  p_fecha           DATE,
    IN  p_hora_inicio     TIME,
    IN  p_id_paciente     INT,
    IN  p_matricula       INT,
    IN  p_id_especialidad INT,
    IN  p_id_consultorio  INT,
    IN  p_id_plan         INT,
    OUT p_id_turno        INT
)
BEGIN
    IF EXISTS (
        SELECT 1
        FROM   turno t
        JOIN   estado_turno e ON e.id_estado = t.id_estado
        WHERE  t.matricula   = p_matricula
          AND  t.fecha       = p_fecha
          AND  t.hora_inicio = p_hora_inicio
          AND  e.descripcion <> 'Cancelado'
    ) THEN
        SET p_id_turno = -1;
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El médico ya tiene un turno asignado en ese horario.';
    ELSE
        INSERT INTO turno
            (fecha, hora_inicio, id_estado, id_paciente, matricula,
             id_especialidad, id_consultorio, id_plan)
        VALUES
            (p_fecha, p_hora_inicio,
             (SELECT id_estado FROM estado_turno WHERE descripcion = 'Reservado'),
             p_id_paciente, p_matricula,
             p_id_especialidad, p_id_consultorio, p_id_plan);
        SET p_id_turno = LAST_INSERT_ID();
    END IF;
END //
DELIMITER ;

-- -------------------------------------------------------------
-- 2) pago.monto_total  →  columna GENERADA (STORED)
-- -------------------------------------------------------------

ALTER TABLE pago DROP COLUMN monto_total;
ALTER TABLE pago
    ADD COLUMN monto_total DECIMAL(10,2)
        AS (ROUND(monto_base * (1 - porcentaje_descuento / 100), 2)) STORED
        AFTER porcentaje_descuento;

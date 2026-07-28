-- =============================================================
-- MediTurnos — Migración: normalización del estado del turno (4FN)
-- =============================================================
-- PROBLEMA: turno.estado guardaba el texto del estado
-- ('Reservado', 'Confirmado', 'Realizado', 'Ausente', 'Cancelado')
-- repetido en cada fila. Eso es un atributo con dominio cerrado y
-- conocido → corresponde a una tabla de catálogo referenciada por FK.
--
-- SOLUCIÓN: tabla `estado_turno` (catálogo) + `turno.id_estado` (FK).
-- Así el texto vive UNA sola vez por estado y el turno guarda el id.
--
-- Compatibilidad: se conservan los MISMOS nombres de estado que ya
-- usa la aplicación, por lo que el PHP y los SPs siguen comportándose
-- igual. La vista v_turnos_detalle reexpone `descripcion AS estado`,
-- así que todo lo que lee del listado no necesita cambios.
--
-- Requisitos: MariaDB 10.2+ (XAMPP) / MySQL 5.7+, motor InnoDB.
-- Ejecutar UNA vez sobre la base "mediturnos" que todavía tiene la
-- columna turno.estado:
--   /c/xampp/mysql/bin/mysql.exe -u root mediturnos < estado_turno_4fn.sql
--
-- ADVERTENCIA: el paso 4 redefine ReservarTurno (solo valida conflicto
-- de médico, todavía sin el chequeo de consultorio que se agrega más
-- adelante en concurrencia_consultorio.sql, que es la versión vigente).
-- Este archivo va PRIMERO en el orden de migraciones porque el resto
-- (control_concurrencia.sql, concurrencia_consultorio.sql) depende de
-- que ya exista la columna turno.id_estado y el catálogo estado_turno.
-- =============================================================

USE mediturnos;

-- -------------------------------------------------------------
-- 1) Catálogo de estados.
--    Se cargan con id EXPLÍCITO para que el valor sea estable
--    (lo necesita la columna generada slot_unico del paso 6, que
--    no admite subconsultas y debe comparar contra un literal).
--      1 Reservado · 2 Confirmado · 3 Realizado · 4 Ausente · 5 Cancelado
-- -------------------------------------------------------------
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

-- Red de seguridad: si en la data real existiera algún estado que no
-- está en el catálogo, se agrega para no perder filas en el backfill
-- (recibe un id auto-incremental >= 6 y no rompe el NOT NULL del paso 2).
INSERT IGNORE INTO estado_turno (descripcion)
SELECT DISTINCT t.estado
FROM   turno t
LEFT   JOIN estado_turno e ON e.descripcion = t.estado
WHERE  t.estado IS NOT NULL AND e.id_estado IS NULL;

-- -------------------------------------------------------------
-- 2) Nueva columna turno.id_estado + migración de datos + FK.
--    Se agrega NULL para poder hacer el backfill, y recién después
--    se la pasa a NOT NULL DEFAULT 1 (Reservado, igual que el viejo
--    DEFAULT 'Reservado').
-- -------------------------------------------------------------
ALTER TABLE turno
    ADD COLUMN IF NOT EXISTS id_estado INT NULL AFTER hora_inicio;

UPDATE turno t
JOIN   estado_turno e ON e.descripcion = t.estado
SET    t.id_estado = e.id_estado;

ALTER TABLE turno
    MODIFY COLUMN id_estado INT NOT NULL DEFAULT 1;

ALTER TABLE turno
    ADD CONSTRAINT fk_turno_estado
        FOREIGN KEY IF NOT EXISTS (id_estado) REFERENCES estado_turno(id_estado);

-- Índice de estado: el viejo estaba sobre la columna de texto `estado`;
-- se reemplaza por uno equivalente sobre id_estado (mismo nombre).
ALTER TABLE turno DROP INDEX IF EXISTS idx_turno_estado;
CREATE INDEX IF NOT EXISTS idx_turno_estado ON turno(id_estado);

-- -------------------------------------------------------------
-- 3) Soltar la columna generada slot_unico ANTES de tirar `estado`
--    (su expresión la referencia, y el índice único cuelga de ella).
--    Se reconstruye en el paso 6 ya contra id_estado.
-- -------------------------------------------------------------
ALTER TABLE turno DROP INDEX  IF EXISTS uq_turno_slot;
ALTER TABLE turno DROP COLUMN IF EXISTS slot_unico;

-- -------------------------------------------------------------
-- 4) Reemplazar triggers y SPs para que usen id_estado.
--    (Se redefinen acá; al recrearlos dejan de leer `turno.estado`.)
-- -------------------------------------------------------------

-- Trigger INSERT: registra en historial el estado inicial (texto legible
-- resuelto desde el catálogo; historial_turno es una bitácora de auditoría
-- y se mantiene como snapshot de texto a propósito).
DROP TRIGGER IF EXISTS trg_turno_after_insert;
DELIMITER //
CREATE TRIGGER trg_turno_after_insert
AFTER INSERT ON turno
FOR EACH ROW
BEGIN
    INSERT INTO historial_turno (id_turno, estado_anterior, estado_nuevo, observacion)
    VALUES (
        NEW.id_turno,
        NULL,
        (SELECT descripcion FROM estado_turno WHERE id_estado = NEW.id_estado),
        'Turno creado'
    );
END //
DELIMITER ;

-- Trigger UPDATE: registra el cambio de estado.
DROP TRIGGER IF EXISTS trg_turno_after_update;
DELIMITER //
CREATE TRIGGER trg_turno_after_update
AFTER UPDATE ON turno
FOR EACH ROW
BEGIN
    IF OLD.id_estado <> NEW.id_estado THEN
        INSERT INTO historial_turno (id_turno, estado_anterior, estado_nuevo, observacion)
        VALUES (
            NEW.id_turno,
            (SELECT descripcion FROM estado_turno WHERE id_estado = OLD.id_estado),
            (SELECT descripcion FROM estado_turno WHERE id_estado = NEW.id_estado),
            NEW.observacion
        );
    END IF;
END //
DELIMITER ;

-- SP CancelarTurno: misma lógica, comparando contra el catálogo.
DROP PROCEDURE IF EXISTS CancelarTurno;
DELIMITER //
CREATE PROCEDURE CancelarTurno(
    IN  p_id_turno    INT,
    IN  p_observacion TEXT
)
BEGIN
    DECLARE v_estado VARCHAR(30);

    SELECT e.descripcion INTO v_estado
    FROM   turno t
    JOIN   estado_turno e ON e.id_estado = t.id_estado
    WHERE  t.id_turno = p_id_turno;

    IF v_estado IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Turno no encontrado.';
    ELSEIF v_estado IN ('Cancelado', 'Realizado') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'No se puede cancelar un turno ya finalizado.';
    ELSE
        UPDATE turno
        SET    id_estado   = (SELECT id_estado FROM estado_turno WHERE descripcion = 'Cancelado'),
               observacion = p_observacion
        WHERE  id_turno = p_id_turno;
    END IF;
END //
DELIMITER ;

-- SP ReservarTurno: inserta el turno con id_estado de 'Reservado' y
-- valida el conflicto de horario contra los NO cancelados.
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
-- 5) Ya nada referencia turno.estado: se elimina la columna.
-- -------------------------------------------------------------
ALTER TABLE turno DROP COLUMN IF EXISTS estado;

-- -------------------------------------------------------------
-- 6) Reconstruir slot_unico (control de concurrencia) contra id_estado.
--    id_estado = 5  →  'Cancelado'  (cupo liberado = NULL).
--    Un turno Cancelado libera el horario; los NULL no chocan entre sí
--    en el índice UNIQUE, así el cupo vuelve a estar disponible sin
--    perder el registro histórico.
-- -------------------------------------------------------------
ALTER TABLE turno
    ADD COLUMN slot_unico VARCHAR(60)
    GENERATED ALWAYS AS (
        IF(id_estado = 5,
           NULL,
           CONCAT(matricula, '|', fecha, '|', hora_inicio))
    ) STORED;

ALTER TABLE turno
    ADD CONSTRAINT uq_turno_slot UNIQUE (slot_unico);

-- -------------------------------------------------------------
-- 7) Vista de listado: reexpone descripcion AS estado para que el
--    PHP (Turno::listar, turnos/index.php) y las vistas de reporte
--    (v_agenda_hoy) sigan funcionando sin cambios.
-- -------------------------------------------------------------
CREATE OR REPLACE VIEW v_turnos_detalle AS
SELECT
    t.id_turno,
    t.fecha,
    t.hora_inicio,
    et.descripcion                                 AS estado,
    t.observacion,
    -- columnas crudas para los filtros del modelo
    t.id_paciente,
    t.matricula,
    t.id_especialidad,
    t.id_estado,
    -- nro de afiliado: vive en paciente_plan (normalización 4FN)
    pp.nro_afiliado,
    CONCAT(p.apellido, ', ', p.nombre)             AS paciente,
    p.dni                                          AS paciente_dni,
    CONCAT(m.apellido, ', ', m.nombre)             AS medico,
    e.nombre                                       AS especialidad,
    CONCAT('Cons. ', c.numero, ' - Piso ', c.piso) AS consultorio,
    os.nombre                                      AS obra_social,
    pl.nombre_plan                                 AS plan,
    pg.id_pago,
    pg.estado                                      AS estado_pago,
    pg.monto_total,
    pg.metodo                                      AS metodo_pago,
    pg.fecha_vencimiento                           AS pago_vence
FROM        turno         t
JOIN        estado_turno  et ON et.id_estado      = t.id_estado
JOIN        paciente      p  ON p.id_paciente     = t.id_paciente
JOIN        medico        m  ON m.matricula       = t.matricula
JOIN        especialidad  e  ON e.id_especialidad = t.id_especialidad
JOIN        consultorio   c  ON c.id_consultorio  = t.id_consultorio
JOIN        plan_os       pl ON pl.id_plan        = t.id_plan
JOIN        obra_social   os ON os.id_obra_social = pl.id_obra_social
LEFT JOIN   paciente_plan pp ON pp.id_paciente    = t.id_paciente
                            AND pp.id_plan         = t.id_plan
LEFT JOIN   pago          pg ON pg.id_turno        = t.id_turno;

-- =============================================================
-- FIN. Verificación rápida (opcional):
--   SELECT id_turno, id_estado FROM turno LIMIT 5;
--   SELECT * FROM estado_turno;
--   SELECT estado, COUNT(*) FROM v_turnos_detalle GROUP BY estado;
-- =============================================================

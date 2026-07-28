-- =============================================================
-- MediTurnos — Control de concurrencia POR CONSULTORIO
-- Ejecutar sobre la base "mediturnos" DESPUES de control_concurrencia.sql
-- y de estado_turno_4fn.sql (requiere la columna id_estado y el catalogo
-- estado_turno ya normalizados). InnoDB / MariaDB 10.2+ / MySQL 5.7+.
--
-- ESTE ARCHIVO CONTIENE LA VERSIÓN CANÓNICA (la más completa) del SP
-- ReservarTurno: valida médico Y consultorio. El SP también se redefine
-- en estado_turno.sql, triggers_y_sp.sql y normalizacion.sql (cada uno
-- fue el "último" en su momento del proyecto, antes de que se sumara
-- este archivo), y esas versiones más viejas SOLO validan médico, no
-- consultorio. Como CREATE PROCEDURE sobrescribe sin importar el orden
-- de creación de los archivos, si alguno de esos 3 scripts se vuelve a
-- correr DESPUÉS de este, el control de consultorio desaparece
-- silenciosamente (sigue funcionando el control de médico, así que el
-- bug no es obvio a simple vista). Por eso, ante cualquier duda de qué
-- SP está activo en la base: SHOW CREATE PROCEDURE ReservarTurno;
--
-- IMPORTANTE: importar con charset utf8mb4 para no corromper acentos:
--   mysql -u root --default-character-set=utf8mb4 mediturnos < concurrencia_consultorio.sql
--
-- CAUSA DEL PROBLEMA:
--   El unico control de cupo era slot_unico = matricula|fecha|hora, que
--   garantiza "un turno activo por MEDICO/fecha/hora", pero NO mira el
--   consultorio. Por eso dos medicos distintos podian quedar reservados
--   en el mismo consultorio fisico, misma fecha y misma hora.
--
-- SOLUCION (misma estrategia ACID que slot_unico):
--   1) Columna generada slot_consultorio = id_consultorio|fecha|hora para
--      los turnos ACTIVOS (NULL si esta Cancelado, asi el cupo se libera).
--   2) Indice UNIQUE uq_turno_consultorio sobre esa columna: el motor
--      rechaza de forma atomica el segundo turno activo en el mismo
--      consultorio/fecha/hora (error 1062), sin importar el medico.
--   3) Pre-chequeo en ReservarTurno para devolver un mensaje claro antes
--      de tocar el indice (la garantia real sigue siendo el UNIQUE).
-- =============================================================

USE mediturnos;

-- -------------------------------------------------------------
-- 0) Diagnostico: si esto devuelve filas, hay conflictos activos
--    que deben resolverse (cancelar uno) ANTES de crear el indice,
--    o el ALTER del paso 2 fallara con error 1062.
--    (5 = Cancelado en el catalogo estado_turno)
--
--    SELECT id_consultorio, fecha, hora_inicio, COUNT(*) AS cant
--    FROM   turno
--    WHERE  id_estado <> 5
--    GROUP  BY id_consultorio, fecha, hora_inicio
--    HAVING COUNT(*) > 1;
-- -------------------------------------------------------------

-- -------------------------------------------------------------
-- 1) Columna generada: "cupo" del consultorio para turnos activos.
--    Turno Cancelado (id_estado = 5) -> NULL (libera el cupo; los
--    NULL no chocan entre si en un indice UNIQUE).
-- -------------------------------------------------------------
ALTER TABLE turno
  DROP INDEX IF EXISTS uq_turno_consultorio;

ALTER TABLE turno
  DROP COLUMN IF EXISTS slot_consultorio;

ALTER TABLE turno
  ADD COLUMN slot_consultorio VARCHAR(60)
  GENERATED ALWAYS AS (
      IF(id_estado = 5,
         NULL,
         CONCAT(id_consultorio, '|', fecha, '|', hora_inicio))
  ) STORED;

-- -------------------------------------------------------------
-- 2) Regla de integridad: a lo sumo UN turno activo por
--    consultorio/fecha/hora (independiente del medico).
-- -------------------------------------------------------------
ALTER TABLE turno
  ADD CONSTRAINT uq_turno_consultorio UNIQUE (slot_consultorio);

-- -------------------------------------------------------------
-- 3) ReservarTurno: se agrega el pre-chequeo del consultorio.
--    Mantiene intacto el chequeo previo del medico; solo suma el
--    control del consultorio antes del INSERT.
-- -------------------------------------------------------------
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
    -- 3.1) El medico ya tiene un turno activo en ese horario.
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
            SET MESSAGE_TEXT = 'El medico ya tiene un turno asignado en ese horario.';

    -- 3.2) El consultorio ya esta ocupado por OTRO turno activo (cualquier medico).
    ELSEIF EXISTS (
        SELECT 1
        FROM   turno t
        JOIN   estado_turno e ON e.id_estado = t.id_estado
        WHERE  t.id_consultorio = p_id_consultorio
          AND  t.fecha          = p_fecha
          AND  t.hora_inicio    = p_hora_inicio
          AND  e.descripcion <> 'Cancelado'
    ) THEN
        SET p_id_turno = -1;
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El consultorio ya esta ocupado en ese horario.';

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

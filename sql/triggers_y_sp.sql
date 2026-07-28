-- =============================================================
-- MediTurnos — Triggers, Stored Procedures e Índices
-- Ejecutar DESPUÉS del script principal mediturnos_prueba.sql
--
-- ADVERTENCIA: este archivo redefine ReservarTurno con una versión que
-- SOLO valida conflicto de médico (sin chequear consultorio). La versión
-- vigente y más completa está en concurrencia_consultorio.sql. Si este
-- script se corre DESPUÉS de aquel (por ejemplo al reconstruir la base
-- desde cero en otro orden), pisa el SP y el control de consultorio se
-- pierde sin ningún error visible. No volver a correr este archivo solo
-- sobre una base que ya tiene concurrencia_consultorio.sql aplicado.
-- =============================================================

USE mediturnos;

-- ---------------------------------------------------------------
-- TRIGGERS
-- ---------------------------------------------------------------

-- Estos triggers no cambian la lógica de negocio directamente.
-- Solo registran eventos importantes para mantener un historial de auditoría.
-- De esta forma, se puede saber qué pasó con un turno y cuándo.

-- Trigger 1: trg_turno_after_insert
-- Se ejecuta automáticamente después de insertar un turno nuevo.
-- Su función es guardar en historial_turno que el turno fue creado,
-- con qué estado quedó inicial y cuál fue la observación asociada.
-- Esto permite rastrear el nacimiento del turno desde la base de datos.
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

-- Trigger 2: trg_turno_after_update
-- Se ejecuta automáticamente después de actualizar un turno.
-- Solo registra el cambio cuando cambia el estado del turno.
-- Sirve para llevar un registro de transiciones como:
-- Reservado -> Confirmado -> Realizado o Cancelado.
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

-- ---------------------------------------------------------------
-- STORED PROCEDURES
-- ---------------------------------------------------------------

-- Procedimiento almacenado: CancelarTurno
-- Se encarga de cancelar un turno de forma controlada.
-- Primero verifica si el turno existe y si ya está finalizado.
-- Si está activo, cambia su estado a Cancelado y guarda la observación.
DROP PROCEDURE IF EXISTS CancelarTurno;
DELIMITER //
CREATE PROCEDURE CancelarTurno(
    -- ID del turno que se desea cancelar
    IN  p_id_turno    INT,
    -- Motivo o comentario de la cancelación
    IN  p_observacion TEXT
)
BEGIN
    DECLARE v_estado VARCHAR(30);

    -- Obtener el estado actual del turno para tomar una decisión
    SELECT e.descripcion INTO v_estado
    FROM   turno t
    JOIN   estado_turno e ON e.id_estado = t.id_estado
    WHERE  t.id_turno = p_id_turno;

    -- Validar si el turno existe o si ya no se puede cancelar
    IF v_estado IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Turno no encontrado.';
    ELSEIF v_estado IN ('Cancelado', 'Realizado') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'No se puede cancelar un turno ya finalizado.';
    ELSE
        -- Cambiar el estado a Cancelado y guardar la observación
        UPDATE turno
        SET id_estado   = (SELECT id_estado FROM estado_turno WHERE descripcion = 'Cancelado'),
            observacion = p_observacion
        WHERE id_turno = p_id_turno;
    END IF;
END //
DELIMITER ;

-- Procedimiento almacenado: ReservarTurno
-- Se usa para crear un turno con validación previa de conflictos.
-- Evita que un mismo médico tenga dos turnos en la misma fecha y hora.
-- Si encuentra un conflicto, lanza un error; si no, inserta el turno y devuelve su ID.
-- Nota: la protección real contra reservas simultáneas viene del índice único
-- uq_turno_slot, mientras que este procedimiento solo da una validación temprana.
DROP PROCEDURE IF EXISTS ReservarTurno;
DELIMITER //
-- Nota: el número de afiliado no se guarda en turno porque pertenece a la
-- relación paciente-plan, que se maneja en la tabla paciente_plan.
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
    -- Verificar si ya existe un turno activo para el mismo médico,
    -- misma fecha y misma hora, distinto de Cancelado.
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
        -- Insertar el turno con estado Reservado y devolver su ID
        INSERT INTO turno
            (fecha, hora_inicio, id_estado, id_paciente, matricula,id_especialidad, id_consultorio, id_plan)
        VALUES
            (p_fecha, p_hora_inicio,(SELECT id_estado FROM estado_turno WHERE descripcion = 'Reservado'),p_id_paciente, p_matricula,p_id_especialidad, p_id_consultorio, p_id_plan);
        SET p_id_turno = LAST_INSERT_ID();
    END IF;
END //
DELIMITER ;

-- ---------------------------------------------------------------
-- ÍNDICES DE RENDIMIENTO
-- ---------------------------------------------------------------

-- Evita Full Table Scan en la agenda diaria
CREATE INDEX IF NOT EXISTS idx_turno_fecha       ON turno(fecha);
CREATE INDEX IF NOT EXISTS idx_turno_estado      ON turno(id_estado);
CREATE INDEX IF NOT EXISTS idx_turno_medico      ON turno(matricula);
CREATE INDEX IF NOT EXISTS idx_turno_paciente    ON turno(id_paciente);
CREATE INDEX IF NOT EXISTS idx_paciente_dni      ON paciente(dni);
CREATE INDEX IF NOT EXISTS idx_usuario_usuario   ON usuario(usuario);

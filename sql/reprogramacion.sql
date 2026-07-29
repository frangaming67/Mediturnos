-- =============================================================
-- sql/reprogramacion.sql — Migración 14: registrar el cambio de horario
-- =============================================================
-- El paciente ahora puede reprogramar su turno. Mover un turno de día
-- u hora es un cambio importante y tiene que quedar registrado: si
-- mañana alguien pregunta "¿por qué mi turno figura el martes si yo lo
-- saqué para el lunes?", la respuesta tiene que estar en la base.
--
-- POR QUÉ SE TOCA EL TRIGGER Y NO SE INSERTA DESDE PHP
-- La regla del proyecto es que los modelos NO escriben en
-- `historial_turno`: lo llenan los triggers, y hacerlo también a mano
-- dejaría filas duplicadas. Extender el trigger mantiene esa regla y,
-- además, garantiza que el cambio quede registrado venga de donde
-- venga —del paciente, de la recepción o de un UPDATE hecho a mano
-- desde phpMyAdmin—.
--
-- El trigger anterior sólo miraba el estado. Un turno que cambia de
-- fecha sin cambiar de estado pasaba sin dejar rastro.
--
-- Requiere: triggers_y_sp.sql, estado_turno.sql
-- =============================================================

DROP TRIGGER IF EXISTS trg_turno_after_update;

DELIMITER $$

CREATE TRIGGER trg_turno_after_update
AFTER UPDATE ON turno
FOR EACH ROW
BEGIN
    -- Caso 1: cambió el estado. Es el comportamiento de siempre.
    IF OLD.id_estado <> NEW.id_estado THEN
        INSERT INTO historial_turno (id_turno, estado_anterior, estado_nuevo, observacion)
        VALUES (
            NEW.id_turno,
            (SELECT descripcion FROM estado_turno WHERE id_estado = OLD.id_estado),
            (SELECT descripcion FROM estado_turno WHERE id_estado = NEW.id_estado),
            NEW.observacion
        );

    -- Caso 2 (nuevo): mismo estado, pero se movió de día o de hora.
    --
    -- Se registra con el mismo estado en las dos columnas porque el
    -- turno no cambió de situación: sigue reservado o confirmado, sólo
    -- que en otro momento. La columna `observacion` es la que cuenta
    -- qué pasó, con el horario viejo y el nuevo.
    --
    -- Se reutiliza `historial_turno` en vez de crear una tabla aparte
    -- para que la bitácora de un turno siga siendo UNA sola lista
    -- ordenada por fecha: partirla en dos obligaría a mezclar dos
    -- consultas cada vez que alguien quiere ver qué le pasó a un turno.
    ELSEIF OLD.fecha <> NEW.fecha OR OLD.hora_inicio <> NEW.hora_inicio THEN
        INSERT INTO historial_turno (id_turno, estado_anterior, estado_nuevo, observacion)
        VALUES (
            NEW.id_turno,
            (SELECT descripcion FROM estado_turno WHERE id_estado = NEW.id_estado),
            (SELECT descripcion FROM estado_turno WHERE id_estado = NEW.id_estado),
            CONCAT('Reprogramado: ',
                   DATE_FORMAT(OLD.fecha, '%d/%m/%Y'), ' ', TIME_FORMAT(OLD.hora_inicio, '%H:%i'),
                   ' → ',
                   DATE_FORMAT(NEW.fecha, '%d/%m/%Y'), ' ', TIME_FORMAT(NEW.hora_inicio, '%H:%i'),
                   IF(NEW.observacion IS NULL OR NEW.observacion = '',
                      '', CONCAT('. ', NEW.observacion)))
        );
    END IF;
END$$

DELIMITER ;

-- =============================================================
-- Verificación
-- =============================================================
-- Mover un turno futuro y comprobar que quedó la línea:
--
--   UPDATE turno SET fecha = DATE_ADD(fecha, INTERVAL 1 DAY)
--   WHERE id_turno = <id>;
--
--   SELECT estado_anterior, estado_nuevo, observacion
--   FROM historial_turno WHERE id_turno = <id> ORDER BY id_hist DESC LIMIT 1;
--
-- Debe decir "Reprogramado: dd/mm/aaaa hh:mm → dd/mm/aaaa hh:mm".
--
-- El control de doble reserva sigue siendo del motor: si el horario
-- nuevo ya está tomado, el UPDATE es rechazado por uq_turno_slot antes
-- de que el trigger llegue a correr.
-- =============================================================

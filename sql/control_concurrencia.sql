-- =============================================================
-- MediTurnos — Control de concurrencia en la reserva de turnos (ACID)
-- Ejecutar sobre la base "mediturnos" (phpMyAdmin o consola MySQL).
-- Requiere MariaDB 10.2+ / MySQL 5.7+ con motor InnoDB.
--
-- OBJETIVO: que sea IMPOSIBLE que dos pacientes reserven el mismo
-- turno (mismo médico, misma fecha, misma hora). La garantía la da
-- el motor de la base (propiedad de CONSISTENCIA de ACID), no el
-- código PHP, así no hay forma de saltarla aunque dos reservas
-- lleguen exactamente al mismo tiempo (race condition / TOCTOU).
--
-- Por qué esto vive en SQL y no se resuelve con PHP (p. ej. un SELECT
-- antes del INSERT): esa validación en dos pasos desde la aplicación
-- deja una ventana entre el SELECT y el INSERT donde otra petición
-- puede colarse; dos procesos PHP corriendo en paralelo (dos pestañas,
-- dos pacientes) pueden pasar el SELECT los dos antes de que cualquiera
-- llegue al INSERT. Un índice UNIQUE es indivisible a nivel de motor:
-- no existe ese hueco.
-- =============================================================

USE mediturnos;

-- -------------------------------------------------------------
-- 0) Requisito ACID: el motor debe ser InnoDB.
--    InnoDB soporta transacciones, claves foráneas y bloqueos a
--    nivel de fila. MyISAM NO es ACID, por eso no sirve acá.
-- -------------------------------------------------------------
ALTER TABLE turno ENGINE = InnoDB;

-- -------------------------------------------------------------
-- 1) Diagnóstico (opcional): detectar duplicados ya existentes.
--    Si esta consulta devuelve filas, hay turnos activos repetidos
--    que hay que cancelar/eliminar ANTES de crear el índice único,
--    o el ALTER del paso 3 fallará.
--
--    SELECT matricula, fecha, hora_inicio, COUNT(*) AS cantidad
--    FROM   turno
--    WHERE  id_estado <> 5            -- 5 = Cancelado (catálogo estado_turno)
--    GROUP  BY matricula, fecha, hora_inicio
--    HAVING COUNT(*) > 1;
-- -------------------------------------------------------------

-- -------------------------------------------------------------
-- 2) Columna generada que representa el "cupo" de un turno ACTIVO.
--    - Turno NO cancelado -> 'matricula|fecha|hora'  (texto único)
--    - Turno Cancelado     -> NULL
--    Truco clave: en un índice UNIQUE, varios NULL NO chocan entre
--    sí. Así, al cancelar un turno su cupo queda libre y el horario
--    puede volver a reservarse, sin perder el registro histórico.
--    NOTA: el estado se normalizó a estado_turno (id_estado). Una
--    columna generada NO admite subconsultas, por eso se compara
--    contra el id literal del catálogo:  5 = 'Cancelado'.
-- -------------------------------------------------------------
ALTER TABLE turno
  DROP INDEX IF EXISTS uq_turno_slot;

ALTER TABLE turno
  DROP COLUMN IF EXISTS slot_unico;

ALTER TABLE turno
  ADD COLUMN slot_unico VARCHAR(60)
  GENERATED ALWAYS AS (
      IF(id_estado = 5,
         NULL,
         CONCAT(matricula, '|', fecha, '|', hora_inicio))
  ) STORED;

-- -------------------------------------------------------------
-- 3) LA REGLA DE ORO: a lo sumo UN turno activo por médico/fecha/hora.
--    Esta restricción la verifica InnoDB en cada INSERT de forma
--    atómica y serializada. Si dos reservas compiten por el mismo
--    cupo, una entra y la otra recibe el error 1062 (Duplicate entry),
--    que el PHP traduce a un mensaje amable para el paciente.
-- -------------------------------------------------------------
ALTER TABLE turno
  DROP INDEX IF EXISTS uq_turno_slot;

ALTER TABLE turno
  ADD CONSTRAINT uq_turno_slot UNIQUE (slot_unico);

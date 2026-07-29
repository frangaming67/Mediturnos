-- =============================================================
-- sql/calificaciones.sql — Migración 15: calificación de profesionales
-- =============================================================
-- Al elegir médico, el paciente ve la calificación promedio. Para que
-- ese número exista tiene que haber quién lo genere: cada paciente
-- puede calificar una consulta que YA se atendió.
--
-- LA REGLA ESTÁ EN EL ESQUEMA, NO EN EL CÓDIGO
-- `id_turno` es UNIQUE. Eso solo garantiza las dos condiciones que
-- hacen creíble a una calificación:
--
--   · Sólo se puede calificar habiendo tenido un turno (no hay
--     calificación sin id_turno).
--   · Una sola vez por turno (el índice único lo impide).
--
-- Se podría haber controlado desde PHP, pero entonces dependería de
-- que TODO camino futuro se acuerde de comprobarlo. Acá una segunda
-- calificación del mismo turno es imposible aunque el código falle.
--
-- Requiere: seed_datos.sql, estado_turno.sql
-- =============================================================

CREATE TABLE IF NOT EXISTS calificacion (
    id_calificacion INT AUTO_INCREMENT PRIMARY KEY,

    -- La consulta que se está calificando. UNIQUE: una por turno.
    id_turno     INT NOT NULL UNIQUE,

    -- Se guardan también médico y paciente aunque se podrían deducir del
    -- turno. No es redundancia por descuido: el promedio de un médico se
    -- consulta CADA VEZ que alguien abre la lista de profesionales, y
    -- tener la matrícula acá evita el JOIN con `turno` en esa consulta,
    -- que es la más frecuente de toda la pantalla de reserva.
    matricula    INT NOT NULL,
    id_paciente  INT NOT NULL,

    -- 1 a 5. El CHECK lo aplica el motor: MariaDB 10.4 los hace cumplir
    -- de verdad, así que un 9 o un 0 no entran ni escribiendo el INSERT
    -- a mano desde phpMyAdmin.
    puntaje      TINYINT NOT NULL,

    comentario   VARCHAR(400) NULL,
    creada_en    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT chk_calificacion_puntaje CHECK (puntaje BETWEEN 1 AND 5),

    CONSTRAINT fk_calificacion_turno
        FOREIGN KEY (id_turno) REFERENCES turno(id_turno) ON DELETE CASCADE,
    CONSTRAINT fk_calificacion_medico
        FOREIGN KEY (matricula) REFERENCES medico(matricula),
    CONSTRAINT fk_calificacion_paciente
        FOREIGN KEY (id_paciente) REFERENCES paciente(id_paciente) ON DELETE CASCADE,

    -- El promedio por médico es la consulta más caliente de la pantalla
    -- de reserva: se resuelve entera con este índice, sin tocar la tabla.
    INDEX idx_calificacion_medico (matricula, puntaje)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Sobre la foto del profesional ────────────────────────────
-- La pantalla de reserva muestra la foto del médico, pero NO se agrega
-- una columna `medico.foto`: el profesional ya sube su foto desde su
-- perfil y queda en `usuario.foto`. Es la misma persona y la misma
-- imagen. Duplicarla obligaría a mantener dos copias sincronizadas y a
-- decidir cuál gana cuando difieran.
--
-- La consulta la resuelve con:
--     LEFT JOIN usuario u ON u.matricula = m.matricula
--
-- Un médico sin cuenta de usuario simplemente no tiene foto y se
-- muestran sus iniciales, igual que en la barra lateral.

-- =============================================================
-- Verificación
-- =============================================================
--   SHOW CREATE TABLE calificacion;
--
-- El CHECK tiene que rechazar esto:
--   INSERT INTO calificacion (id_turno, matricula, id_paciente, puntaje)
--   VALUES (1, 10001, 1, 9);      -- ERROR 4025: CONSTRAINT failed
--
-- Y el UNIQUE, la segunda calificación del mismo turno.
-- =============================================================

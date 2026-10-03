-- =============================================================
-- sql/historial_clinico.sql — Migración 16: consultas y estudios
-- =============================================================
-- Etapa 4 del Área del Paciente: el historial médico necesita datos
-- reales detrás. Hasta acá nada en el sistema podía registrar QUÉ pasó
-- en una consulta ni pedir un estudio — sólo se sabía que el turno
-- había ocurrido (`turno.estado = 'Realizado'`).
--
-- Dos tablas nuevas:
--   · consulta → lo que el médico escribe sobre una consulta ya dada.
--   · estudio  → algo que el médico pide (análisis, imagen) y que más
--                tarde tiene un resultado adjunto.
--
-- Requiere: estado_turno.sql, auth_v2.sql
--
-- ── SOBRE EL COLLATE ─────────────────────────────────────────
-- Las dos tablas usan utf8mb4_general_ci, que es el de la base y el de
-- las 21 tablas que ya existían. NO es un detalle cosmético: el
-- historial se arma con un UNION entre `consulta`/`estudio` y
-- `especialidad`/`medico`, y MySQL rechaza un UNION que mezcla dos
-- collations con "Illegal mix of collations". Se aprendió al primer
-- intento, creándolas con utf8mb4_unicode_ci: la pantalla moría entera.
--
-- utf8mb4_unicode_ci ordena mejor los acentos, así que a futuro
-- convendría unificar TODO el esquema hacia él; lo que no se puede es
-- tener las dos cosas conviviendo. Queda anotado como deuda técnica.
-- =============================================================

CREATE TABLE IF NOT EXISTS consulta (
    id_consulta      INT AUTO_INCREMENT PRIMARY KEY,

    -- UNIQUE a propósito: una consulta es el registro de UN turno ya
    -- atendido. Si el médico necesitara agregar algo más tarde, edita
    -- esta fila; no se acumulan varias "consultas" sueltas para el
    -- mismo turno, porque entonces el historial del paciente mostraría
    -- la misma cita duplicada.
    id_turno         INT NOT NULL UNIQUE,

    motivo_consulta  VARCHAR(200)  NOT NULL,
    diagnostico      TEXT          NULL,
    indicaciones     TEXT          NULL,

    -- Quién la redactó. No se deduce del turno porque un turno puede
    -- reprogramarse a otro consultorio pero NO cambia de médico, así que
    -- en la práctica coincide siempre — pero guardarlo explícito evita
    -- tener que confiar en esa suposición si el día de mañana cambia.
    matricula        INT NOT NULL,

    creada_en        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_consulta_turno
        FOREIGN KEY (id_turno) REFERENCES turno(id_turno) ON DELETE CASCADE,
    CONSTRAINT fk_consulta_medico
        FOREIGN KEY (matricula) REFERENCES medico(matricula)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS estudio (
    id_estudio       INT AUTO_INCREMENT PRIMARY KEY,

    -- El paciente es el dueño real del estudio, no el turno: el
    -- historial se arma "todo lo de este paciente", y un estudio con
    -- resultado disponible sigue siendo del paciente aunque el turno
    -- que lo originó se hubiera cancelado después (no debería poder
    -- pasar por el FK, pero el dueño lógico es la persona, no la cita).
    id_paciente      INT NOT NULL,

    -- Turno que originó el pedido. Puede ser NULL: un estudio de rutina
    -- pedido "para la próxima consulta" todavía no tiene turno asociado
    -- en un sistema tan simplificado como éste, pero se deja la puerta
    -- abierta en el esquema en vez de forzar un turno ficticio.
    id_turno         INT NULL,

    matricula        INT NOT NULL,   -- quién lo solicitó

    -- Catálogo corto y ABIERTO (VARCHAR, no ENUM): quién carga el pedido
    -- describe el tipo con sus propias palabras ("Laboratorio",
    -- "Radiografía"…) y agregar uno nuevo no debe pedir una migración.
    tipo             VARCHAR(60)  NOT NULL,
    nombre           VARCHAR(150) NOT NULL,

    -- NULL = todavía no se cargó ningún archivo. El nombre real vive en
    -- el disco (almacenamiento/estudios/), nunca se sirve por URL
    -- directa: es información de salud, no un avatar. Se transmite por
    -- ControladorHistorial.php?accion=descargar, que primero valida que
    -- quien lo pide sea el dueño, su médico tratante o el staff.
    archivo          VARCHAR(255) NULL,

    estado           ENUM('Pendiente','Disponible') NOT NULL DEFAULT 'Pendiente',

    solicitado_en    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resultado_en     DATETIME NULL,

    CONSTRAINT fk_estudio_paciente
        FOREIGN KEY (id_paciente) REFERENCES paciente(id_paciente) ON DELETE CASCADE,
    CONSTRAINT fk_estudio_turno
        FOREIGN KEY (id_turno) REFERENCES turno(id_turno) ON DELETE SET NULL,
    CONSTRAINT fk_estudio_medico
        FOREIGN KEY (matricula) REFERENCES medico(matricula),

    -- El historial del paciente es siempre "los míos, más nuevos primero".
    INDEX idx_estudio_paciente (id_paciente, solicitado_en DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =============================================================
-- Verificación
-- =============================================================
--   SHOW CREATE TABLE consulta;
--   SHOW CREATE TABLE estudio;
--
-- Dos consultas para el mismo turno tienen que rechazarse:
--   INSERT INTO consulta (id_turno, motivo_consulta, matricula)
--   VALUES (<id>, 'x', <mat>);
--   INSERT INTO consulta (id_turno, motivo_consulta, matricula)
--   VALUES (<id>, 'y', <mat>);   -- ERROR 1062: Duplicate entry
-- =============================================================

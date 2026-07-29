-- =============================================================
-- sql/notificaciones.sql — Migración 13: centro de notificaciones
-- =============================================================
-- Primera pieza del Área del Paciente. Va antes que todo lo demás
-- porque cada módulo que sigue (reserva, pago, receta, resultado)
-- termina avisándole algo a alguien, y todos escriben acá.
--
-- Requiere: auth_v2.sql
-- =============================================================

CREATE TABLE IF NOT EXISTS notificacion (
    id_notificacion  INT AUTO_INCREMENT PRIMARY KEY,

    -- Se apunta a `usuario` y no a `paciente` a propósito: un médico
    -- también recibe avisos (una solicitud de renovación de receta, por
    -- ejemplo). La notificación es de la CUENTA, no de la ficha clínica.
    id_usuario       INT NOT NULL,

    -- VARCHAR y no ENUM: agregar un tipo de aviso nuevo es algo que va a
    -- pasar seguido, y con ENUM cada uno obligaría a una migración con
    -- ALTER TABLE. El catálogo de tipos válidos vive en PHP
    -- (includes/notificaciones.php), que es donde se decide qué icono le
    -- toca y si además se manda por correo.
    tipo             VARCHAR(40)  NOT NULL,

    titulo           VARCHAR(120) NOT NULL,
    mensaje          VARCHAR(400) NOT NULL,

    -- A dónde lleva al hacer clic. Se guarda RELATIVA a BASE_URL para que
    -- el enlace siga funcionando si el proyecto cambia de carpeta o de
    -- dominio: guardar la URL absoluta dejaría avisos viejos apuntando a
    -- una dirección que ya no existe.
    url_accion       VARCHAR(255) NULL,

    -- Id del turno / pago / receta que originó el aviso. Sirve para no
    -- duplicar recordatorios del mismo turno y para poder rastrear, desde
    -- una notificación, qué la produjo.
    id_referencia    INT NULL,

    creada_en        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Fecha en vez de un booleano `leida`. Guardar CUÁNDO se leyó es
    -- estrictamente más información que guardar sólo que se leyó, no
    -- ocupa más y permite responder "cuánto tarda la gente en enterarse".
    -- NULL = no leída.
    leida_en         DATETIME NULL,

    -- Cuándo salió el correo de este aviso. NULL = no se mandó (porque el
    -- tipo no lo lleva, o porque el envío falló). Sirve para diagnosticar
    -- "no me llegó el mail" sin tener que abrir el log del servidor.
    email_enviado_en DATETIME NULL,

    CONSTRAINT fk_notificacion_usuario
        FOREIGN KEY (id_usuario) REFERENCES usuario(id_usuario)
        ON DELETE CASCADE,

    -- El listado siempre es "las de este usuario, más nuevas primero".
    INDEX idx_notif_usuario (id_usuario, creada_en DESC),
    -- El contador del campanita es "las de este usuario sin leer".
    INDEX idx_notif_sin_leer (id_usuario, leida_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Sobre el borrado ─────────────────────────────────────────
-- El paciente puede eliminar una notificación y se borra de verdad
-- (DELETE), no se marca como oculta. Es correspondencia propia: si la
-- descarta, no hay razón para conservarla. Lo que sí queda es el hecho
-- que la originó —el turno, el pago, la receta—, que vive en su tabla.
--
-- ON DELETE CASCADE sobre `usuario`: si se elimina una cuenta, sus
-- avisos no tienen a quién pertenecer.

-- =============================================================
-- Verificación
-- =============================================================
--   SHOW CREATE TABLE notificacion;
--   SELECT COUNT(*) FROM notificacion;
-- =============================================================

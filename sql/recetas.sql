-- =============================================================
-- sql/recetas.sql — Migración 18: recetas y renovaciones
-- =============================================================
-- Etapa 5 del Área del Paciente. Hasta acá el médico podía registrar lo
-- que pasó en la consulta (`consulta`) y pedir estudios (`estudio`),
-- pero no había forma de prescribir: las indicaciones eran un texto
-- libre dentro de la ficha, que el paciente leía y nada más.
--
-- Una receta no es un texto. Es una lista de medicamentos, cada uno con
-- su dosis y su frecuencia, que vence, que se puede anular, y que el
-- paciente necesita poder PEDIR DE NUEVO cuando se le termina sin tener
-- que sacar un turno sólo para eso.
--
-- Tres tablas:
--   · receta              → la cabecera: quién, a quién, cuándo, vigencia
--   · receta_medicamento  → un renglón por medicamento
--   · renovacion_receta   → el circuito de pedido/respuesta
--
-- Requiere: historial_clinico.sql, estado_turno.sql, auth_v2.sql
--
-- COLLATE: utf8mb4_general_ci explícito, el de la base y el de las 25
-- tablas que ya existen. Se aprendió en la migración 17 — ver
-- collation_unificada.sql.
-- =============================================================


-- =============================================================
-- 1. LA RECETA
-- =============================================================
CREATE TABLE IF NOT EXISTS receta (
    id_receta        INT AUTO_INCREMENT PRIMARY KEY,

    -- El dueño es el PACIENTE, no el turno. Una receta sigue siendo del
    -- paciente aunque la consulta que la originó se borre, y el
    -- historial se arma siempre como "todo lo de esta persona".
    id_paciente      INT NOT NULL,

    matricula        INT NOT NULL,   -- quién la firmó

    -- Turno que la originó. NULL a propósito en dos casos reales: una
    -- receta emitida al aprobar una renovación (no nace de una consulta
    -- nueva), y el día que recepción cargue una receta de papel.
    id_turno         INT NULL,

    diagnostico      VARCHAR(200) NULL,   -- por qué se prescribe
    indicaciones     TEXT         NULL,   -- cómo tomarlo, en general

    emitida_el       DATE NOT NULL,

    -- ── POR QUÉ EL VENCIMIENTO ES UNA FECHA Y NO UN ESTADO ───
    -- La tentación es poner estado='Vencida' y una tarea que lo
    -- actualice. Eso trae dos problemas que este proyecto ya conoce:
    -- `pago` lo hace así y necesita `expirarVencidos()` corriendo en
    -- CADA visita (está anotado en la deuda técnica), y entre dos
    -- corridas la fila MIENTE: dice "Vigente" algo que venció ayer.
    --
    -- Acá la vigencia se DEDUCE de la fecha, que es un dato que no
    -- cambia solo. `vence_el < CURDATE()` es verdad desde el instante
    -- exacto en que lo es, sin que nadie tenga que pasar a actualizarla.
    -- No hay tarea programada, no hay estado desactualizado.
    vence_el         DATE NOT NULL,

    -- Entonces el estado guarda SÓLO lo que una fecha no puede decir:
    -- que alguien la dio de baja a mano. "Vencida" no está acá porque no
    -- es una decisión, es el paso del tiempo.
    estado           ENUM('Vigente','Anulada') NOT NULL DEFAULT 'Vigente',
    anulada_motivo   VARCHAR(200) NULL,
    anulada_el       DATETIME NULL,

    creada_en        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_receta_paciente
        FOREIGN KEY (id_paciente) REFERENCES paciente(id_paciente) ON DELETE CASCADE,
    CONSTRAINT fk_receta_medico
        FOREIGN KEY (matricula)   REFERENCES medico(matricula),
    CONSTRAINT fk_receta_turno
        FOREIGN KEY (id_turno)    REFERENCES turno(id_turno) ON DELETE SET NULL,

    -- Una receta no puede vencer antes de emitirse. MariaDB 10.4 sí
    -- hace cumplir los CHECK (10.2 los aceptaba y los ignoraba), así
    -- que esto es una garantía real y no documentación.
    CONSTRAINT chk_receta_vigencia CHECK (vence_el >= emitida_el),

    -- "Mis recetas, las más nuevas primero" es LA consulta de la
    -- pantalla del paciente.
    INDEX idx_receta_paciente (id_paciente, emitida_el DESC),
    INDEX idx_receta_medico   (matricula, emitida_el DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- =============================================================
-- 2. LOS MEDICAMENTOS DE LA RECETA
-- =============================================================
-- Una tabla aparte y no un TEXT con todo junto, por tres motivos
-- concretos:
--   · Renovar una receta es copiar sus renglones. Con un TEXT habría que
--     interpretar lo que escribió una persona a mano.
--   · El paciente ve cada medicamento como un renglón con su dosis y su
--     frecuencia. Partir un TEXT para dibujarlo es adivinar.
--   · "¿Cuáles son los medicamentos más recetados?" —el widget que el
--     tablero del administrador hoy no puede mostrar— es un GROUP BY
--     sobre esta tabla. Con un TEXT no es ninguna consulta.
CREATE TABLE IF NOT EXISTS receta_medicamento (
    id_item          INT AUTO_INCREMENT PRIMARY KEY,
    id_receta        INT NOT NULL,

    -- Catálogo ABIERTO (VARCHAR, no una tabla `medicamento` con clave
    -- foránea). Es la misma decisión que en `estudio.tipo` y por el
    -- mismo motivo: un vademécum real son decenas de miles de productos
    -- que se actualizan por fuera, y encerrarlo en una tabla propia
    -- obligaría a mantenerlo a mano o a inventar un alta cada vez que
    -- aparece un medicamento nuevo. Queda anotado: si el día de mañana
    -- hay que controlar stock, ahí sí hace falta el catálogo cerrado.
    nombre           VARCHAR(150) NOT NULL,
    presentacion     VARCHAR(100) NULL,       -- "comprimidos 500 mg"
    dosis            VARCHAR(100) NOT NULL,   -- "1 comprimido"
    frecuencia       VARCHAR(100) NOT NULL,   -- "cada 8 horas"
    duracion         VARCHAR(100) NULL,       -- "por 7 días"
    cantidad         INT NOT NULL DEFAULT 1,  -- envases a dispensar

    CONSTRAINT fk_item_receta
        FOREIGN KEY (id_receta) REFERENCES receta(id_receta) ON DELETE CASCADE,

    -- Cero envases no es una receta, es un error de carga.
    CONSTRAINT chk_item_cantidad CHECK (cantidad > 0),

    INDEX idx_item_receta (id_receta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- =============================================================
-- 3. LA RENOVACIÓN
-- =============================================================
-- El circuito: el paciente pide, el médico aprueba o rechaza, los dos se
-- enteran.
CREATE TABLE IF NOT EXISTS renovacion_receta (
    id_renovacion    INT AUTO_INCREMENT PRIMARY KEY,

    id_receta        INT NOT NULL,   -- la que se quiere renovar

    -- Se repite el paciente aunque se deduzca de la receta. No es
    -- desnormalización por descuido: "mis pedidos pendientes" es una
    -- consulta de la pantalla del paciente, y sin esta columna cada
    -- lectura necesita el JOIN con `receta` sólo para saber de quién es.
    -- La fila la escribe un único método del modelo, que lo copia de la
    -- receta ya verificada.
    id_paciente      INT NOT NULL,

    motivo           VARCHAR(300) NULL,   -- lo que escribe el paciente

    estado           ENUM('Pendiente','Aprobada','Rechazada')
                     NOT NULL DEFAULT 'Pendiente',

    respuesta        VARCHAR(300) NULL,   -- lo que contesta el médico

    -- Quién resolvió. NULL mientras está pendiente.
    matricula        INT NULL,

    -- Aprobar NO modifica la receta vieja: emite una NUEVA y la enlaza
    -- acá. Cambiarle la fecha de vencimiento a la original sería
    -- reescribir la historia clínica — dejaría de existir el registro de
    -- qué se prescribió en su momento y hasta cuándo valía.
    id_receta_nueva  INT NULL,

    solicitada_en    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resuelta_en      DATETIME NULL,

    -- ── EL CONTROL DE CONCURRENCIA ───────────────────────────
    -- Dos clics rápidos en "Solicitar renovación" son dos peticiones a
    -- la vez: las dos consultan "¿hay alguna pendiente?", las dos leen
    -- que no, y las dos insertan. El médico ve el mismo pedido duplicado
    -- en su bandeja y el paciente recibe dos correos.
    --
    -- Verificar en PHP no alcanza, porque entre el SELECT y el INSERT
    -- hay una ventana. La garantía tiene que estar en el motor.
    --
    -- Es el mismo recurso que `turno.slot_unico` (ver
    -- control_concurrencia.sql): una columna generada que vale el id de
    -- la receta mientras el pedido está pendiente y NULL en cuanto se
    -- resuelve, más un UNIQUE encima. Y funciona porque en SQL dos NULL
    -- NO colisionan: se puede pedir la renovación de la misma receta
    -- cuantas veces se quiera a lo largo del tiempo, pero nunca hay dos
    -- pendientes al mismo tiempo.
    pendiente_unica  INT GENERATED ALWAYS AS
                     (IF(estado = 'Pendiente', id_receta, NULL)) STORED,

    CONSTRAINT fk_renov_receta
        FOREIGN KEY (id_receta)       REFERENCES receta(id_receta) ON DELETE CASCADE,
    CONSTRAINT fk_renov_paciente
        FOREIGN KEY (id_paciente)     REFERENCES paciente(id_paciente) ON DELETE CASCADE,
    CONSTRAINT fk_renov_medico
        FOREIGN KEY (matricula)       REFERENCES medico(matricula),
    CONSTRAINT fk_renov_nueva
        FOREIGN KEY (id_receta_nueva) REFERENCES receta(id_receta) ON DELETE SET NULL,

    UNIQUE KEY uq_renov_pendiente (pendiente_unica),

    -- La bandeja del médico: "pedidos pendientes, el más viejo primero".
    INDEX idx_renov_estado    (estado, solicitada_en),
    INDEX idx_renov_paciente  (id_paciente, solicitada_en DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- =============================================================
-- Verificación
-- =============================================================
-- 1) Las tres tablas, con el collation de la base:
--
--   SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES
--   WHERE TABLE_SCHEMA = 'mediturnos'
--     AND TABLE_NAME IN ('receta','receta_medicamento','renovacion_receta');
--
-- 2) El CHECK de vigencia rechaza una receta que vence antes de emitirse:
--
--   INSERT INTO receta (id_paciente, matricula, emitida_el, vence_el)
--   VALUES (1, 1, '2026-10-02', '2026-10-01');   -- ERROR 4025
--
-- 3) El UNIQUE rechaza dos renovaciones pendientes de la misma receta:
--
--   INSERT INTO renovacion_receta (id_receta, id_paciente) VALUES (1, 1);
--   INSERT INTO renovacion_receta (id_receta, id_paciente) VALUES (1, 1);
--   -- ERROR 1062: Duplicate entry '1' for key 'uq_renov_pendiente'
--
-- 4) Y las deja pasar en cuanto la primera se resuelve:
--
--   UPDATE renovacion_receta SET estado = 'Rechazada' WHERE id_renovacion = 1;
--   INSERT INTO renovacion_receta (id_receta, id_paciente) VALUES (1, 1);  -- OK
-- =============================================================

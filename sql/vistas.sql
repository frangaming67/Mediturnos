-- =============================================================
-- MediTurnos — Vistas SQL (VIEWS)
-- Ejecutar sobre la base "mediturnos" DESPUÉS de seed_datos.sql
-- y de las migraciones (pagos_y_descuentos.sql, normalizacion_4fn.sql).
--   /c/xampp/mysql/bin/mysql.exe -u root mediturnos < vistas.sql
--
-- Una VISTA es un SELECT guardado con nombre: una "tabla virtual".
-- No guarda datos; se recalcula cada vez que la consultás. Sirve para
-- no repetir JOINs grandes y para mostrar datos ya legibles.
-- Una vez creada se usa igual que una tabla: SELECT * FROM v_xxx;
--
-- ESTRUCTURA DE ESTE ARCHIVO:
--   · 2 vistas "base" que el PHP usa de verdad (reemplazan JOINs de los
--     modelos):  v_turnos_detalle  y  v_pagos_detalle.
--   · 3 vistas "de reporte" construidas SOBRE las anteriores.
--
-- Por qué el JOIN vive en una VISTA y no repetido en cada método del
-- modelo que lo necesita: Turno::listar(), Turno::agendaHoy() y (vía
-- v_pagos_detalle) Pago::listar()/pendientes() necesitan las mismas
-- columnas resueltas (nombre de paciente, médico, obra social...). Antes
-- de esto cada método PHP traía su propio JOIN de 5-6 tablas; ahora ese
-- JOIN se escribe una sola vez acá y los modelos solo agregan WHERE/ORDER.
-- El costo es que estas vistas son la razón por la que los modelos usan
-- try/catch al leerlas (ver Turno::agendaHoy, Pago::pendientes): si a
-- alguien se le olvida correr este script, la vista no existe y esas
-- consultas fallarían con "table doesn't exist" en vez de degradar
-- silenciosamente a una lista vacía.
-- =============================================================

USE mediturnos;

-- =============================================================
-- 1) v_turnos_detalle   ← la usa Turno::listar()  (modelos/Turno.php)
--    El turno con TODOS sus datos legibles ya resueltos: en vez de
--    id_paciente / matricula (números), trae nombres, obra social,
--    plan y el estado del pago. Expone además las columnas "crudas"
--    (id_paciente, matricula, id_especialidad, paciente_dni) que el
--    modelo necesita para filtrar con WHERE.
-- =============================================================
CREATE OR REPLACE VIEW v_turnos_detalle AS
SELECT
    t.id_turno,
    t.fecha,
    t.hora_inicio,
    -- estado: ahora vive en el catálogo estado_turno (normalización 4FN).
    -- Se reexpone como 'estado' (texto) para no tocar el PHP que lo lee.
    et.descripcion                                 AS estado,
    t.observacion,
    -- columnas crudas para los filtros del modelo
    t.id_paciente,
    t.matricula,
    t.id_especialidad,
    t.id_estado,
    -- nro de afiliado: vive en paciente_plan (normalización 4FN)
    pp.nro_afiliado,
    -- paciente: JOIN normal (un turno SIEMPRE tiene paciente)
    CONCAT(p.apellido, ', ', p.nombre)             AS paciente,
    p.dni                                          AS paciente_dni,
    -- médico
    CONCAT(m.apellido, ', ', m.nombre)             AS medico,
    -- especialidad y consultorio
    e.nombre                                       AS especialidad,
    CONCAT('Cons. ', c.numero, ' - Piso ', c.piso) AS consultorio,
    -- obra social y plan (turno → plan_os → obra_social)
    os.nombre                                      AS obra_social,
    pl.nombre_plan                                 AS plan,
    -- pago: LEFT JOIN (el pago puede NO existir todavía → NULL,
    -- pero el turno igual aparece en el listado)
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
-- 2) v_pagos_detalle    ← la usa Pago::listar()  (modelos/Pago.php)
--    Cada pago con los datos del turno al que pertenece. Acá la tabla
--    central es 'pago' y todos los JOIN son normales (un pago siempre
--    cuelga de un turno, y el turno de un paciente/médico/etc.).
-- =============================================================
CREATE OR REPLACE VIEW v_pagos_detalle AS
SELECT
    pg.id_pago,
    pg.id_turno,
    pg.monto_base,
    pg.porcentaje_descuento,
    pg.monto_total,
    pg.estado,
    pg.metodo,
    pg.fecha_vencimiento,
    pg.fecha_pago,
    pg.fecha_creacion,
    pg.referencia,
    t.fecha,
    t.hora_inicio,
    -- columnas crudas para filtrar (por paciente y por médico)
    t.id_paciente,
    t.matricula,
    CONCAT(p.apellido, ', ', p.nombre) AS paciente,
    p.dni                              AS paciente_dni,
    CONCAT(m.apellido, ', ', m.nombre) AS medico,
    e.nombre                           AS especialidad,
    os.nombre                          AS obra_social
FROM   pago         pg
JOIN   turno        t  ON t.id_turno        = pg.id_turno
JOIN   paciente     p  ON p.id_paciente     = t.id_paciente
JOIN   medico       m  ON m.matricula       = t.matricula
JOIN   especialidad e  ON e.id_especialidad = t.id_especialidad
JOIN   plan_os      pl ON pl.id_plan        = t.id_plan
JOIN   obra_social  os ON os.id_obra_social = pl.id_obra_social;

-- =============================================================
-- 3) v_agenda_hoy   (reporte, se apoya en v_turnos_detalle)
--    Los turnos de HOY, ordenados por médico y hora. Una vista PUEDE
--    construirse SOBRE otra vista.
-- =============================================================
CREATE OR REPLACE VIEW v_agenda_hoy AS
SELECT *
FROM   v_turnos_detalle
WHERE  fecha = CURDATE()
  AND  estado <> 'Cancelado'
ORDER BY medico, hora_inicio;

-- =============================================================
-- 4) v_pagos_pendientes   (reporte, se apoya en v_pagos_detalle)
--    Pagos que quedaron en 'Pendiente', ordenados por vencimiento:
--    los que están por vencer primero.
-- =============================================================
CREATE OR REPLACE VIEW v_pagos_pendientes AS
SELECT id_pago, id_turno, fecha, hora_inicio, paciente, medico,
       monto_total, fecha_vencimiento
FROM   v_pagos_detalle
WHERE  estado = 'Pendiente'
ORDER BY fecha_vencimiento;

-- =============================================================
-- 5) v_recaudacion_medico   (reporte de AGREGACIÓN)
--    Cuántos turnos pagados generó cada médico y cuánta plata.
--    COUNT() y SUM() resumen muchas filas en una sola por médico.
-- =============================================================
CREATE OR REPLACE VIEW v_recaudacion_medico AS
SELECT
    m.matricula,
    CONCAT(m.apellido, ', ', m.nombre) AS medico,
    COUNT(pg.id_pago)                  AS turnos_pagados,
    SUM(pg.monto_total)                AS total_recaudado
FROM   medico m
JOIN   turno  t  ON t.matricula = m.matricula
JOIN   pago   pg ON pg.id_turno = t.id_turno
WHERE  pg.estado = 'Pagado'
GROUP BY m.matricula, medico
ORDER BY total_recaudado DESC;

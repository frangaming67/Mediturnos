-- =============================================================
-- sql/collation_unificada.sql — Migración 17: un solo collation
-- =============================================================
-- POR QUÉ EXISTE ESTA MIGRACIÓN
-- `notificacion` (migración 13) y `calificacion` (migración 15) se
-- crearon con utf8mb4_unicode_ci, mientras que las 21 tablas anteriores
-- y la base misma usan utf8mb4_general_ci.
--
-- Mientras esas tablas se consultaron solas, la diferencia no se notó.
-- Apareció al armar el historial clínico, que hace un UNION entre
-- tablas nuevas y viejas: MySQL lo rechaza con
--
--     ERROR 1271: Illegal mix of collations for operation 'UNION'
--
-- y la pantalla muere entera. El mismo choque puede aparecer en
-- cualquier JOIN, ORDER BY o comparación futura entre una tabla nueva y
-- una vieja, así que no alcanza con arreglar la consulta: hay que
-- eliminar la inconsistencia.
--
-- ── POR QUÉ SE UNIFICA HACIA general_ci Y NO AL REVÉS ────────
-- utf8mb4_unicode_ci es el MEJOR de los dos: ordena los acentos como
-- corresponde en castellano. Lo correcto a futuro sería llevar todo el
-- esquema hacia él.
--
-- Pero eso son 23 tablas, varias con claves foráneas entre sí, y una
-- conversión así se hace con la base fuera de servicio y con respaldo.
-- Acá se alinean las TRES tablas nuevas —que están casi vacías— al
-- collation que ya tiene todo lo demás. Es la operación de menor riesgo
-- que deja el esquema consistente.
--
-- Queda anotado en la deuda técnica: unificar todo hacia unicode_ci.
--
-- Requiere: notificaciones.sql, calificaciones.sql, historial_clinico.sql
-- =============================================================

ALTER TABLE notificacion CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE calificacion CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE consulta     CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE estudio      CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

-- =============================================================
-- Verificación
-- =============================================================
-- No debe quedar NINGUNA tabla fuera del collation de la base:
--
--   SELECT TABLE_NAME, TABLE_COLLATION
--   FROM   information_schema.TABLES
--   WHERE  TABLE_SCHEMA = 'mediturnos'
--     AND  TABLE_COLLATION <> 'utf8mb4_general_ci';
--
-- Y el UNION del historial tiene que ejecutarse sin error.
-- =============================================================

-- =============================================================
-- sql/perfil.sql — Migración 12: columnas de contacto editables
-- =============================================================
-- La pantalla de perfil deja que cada usuario edite su propio
-- teléfono y correo. Antes de habilitarlo hay que corregir tres
-- columnas que NO pueden guardar bien lo que el formulario acepta.
--
-- Es el mismo defecto que auth_v2.sql ya corrigió en `paciente`:
-- acá se termina el trabajo en las dos tablas que habían quedado.
--
-- POR QUÉ ES URGENTE Y NO COSMÉTICO
-- El sql_mode de esta instalación NO incluye STRICT_TRANS_TABLES.
-- Sin modo estricto MySQL **no da error** cuando un valor no entra:
-- lo satura o lo trunca en silencio. El usuario ve "Guardado" y el
-- dato quedó mal. Es la falla más peligrosa de todas, porque no
-- deja ningún rastro.
--
-- Requiere: auth_v2.sql
-- =============================================================

-- ── 1. medico.telefono era int(10) unsigned ──────────────────
-- Un teléfono NO es un número con el que se opere: nunca se suma ni
-- se promedia. Es una cadena de dígitos, y como tal puede empezar
-- con 0 (se perdería) y superar el rango de INT.
--
-- Máximo de int unsigned: 4.294.967.295 (10 dígitos).
-- Un celular con característica y prefijo — 91155550001, 11 dígitos —
-- NO entra: se guardaba aplastado en 4294967295.
--
-- Los 6 médicos cargados hoy tienen 10 dígitos y entran justo, así que
-- la conversión es exacta: ninguno pierde información. Se hace ahora,
-- con datos sanos, y no cuando el problema ya haya arruinado filas —
-- que es exactamente lo que pasó con los 1000 pacientes.
ALTER TABLE medico MODIFY telefono VARCHAR(30) NOT NULL;

-- ── 2. medico.email era varchar(30) ──────────────────────────
-- El estándar admite 254 caracteres. Una dirección institucional
-- normal como `roberto.lopez@hospitalitaliano.org.ar` (37) ya se
-- guardaba truncada, sin aviso: el correo quedaba inválido y
-- cualquier notificación a ese médico rebotaba para siempre.
ALTER TABLE medico MODIFY email VARCHAR(120) NOT NULL;

-- ── 3. usuario.email era varchar(100) ────────────────────────
-- Incoherencia entre capas: el registro valida hasta 120 caracteres
-- y `paciente.email` es VARCHAR(120), pero la misma dirección se
-- guardaba además en `usuario.email`, que sólo aceptaba 100.
--
-- Una dirección de entre 101 y 120 caracteres pasaba la validación,
-- entraba entera en `paciente` y truncada en `usuario`. Consecuencia
-- concreta: esa persona NO podía volver a entrar con su correo (el
-- guardado ya no coincide con el que escribe) ni recuperar la
-- contraseña, porque la búsqueda es por igualdad exacta.
--
-- Se alinea la columna con la validación en vez de recortar la
-- validación: 120 es lo que el resto del sistema ya da por válido.
ALTER TABLE usuario MODIFY email VARCHAR(120) NOT NULL;

-- =============================================================
-- Verificación
-- =============================================================
--   SHOW COLUMNS FROM medico  LIKE 'telefono';   -- varchar(30)
--   SHOW COLUMNS FROM medico  LIKE 'email';      -- varchar(120)
--   SHOW COLUMNS FROM usuario LIKE 'email';      -- varchar(120)
--
-- Y que no se haya perdido ningún teléfono en la conversión:
--   SELECT matricula, telefono FROM medico ORDER BY matricula;
-- =============================================================

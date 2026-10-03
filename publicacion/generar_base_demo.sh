#!/usr/bin/env bash
# =============================================================
# publicacion/generar_base_demo.sh
# =============================================================
# Arma `publicacion/mediturnos_demo.sql`: la base lista para importar en
# el hosting, con los catálogos reales y tres cuentas de demostración,
# SIN los 1010 pacientes de prueba del entorno de desarrollo.
#
#     bash publicacion/generar_base_demo.sh
#
# ── POR QUÉ SE GENERA Y NO SE ESCRIBE A MANO ─────────────────
# El esquema se saca de la base de desarrollo, que es la que tiene las
# dieciocho migraciones aplicadas, los triggers, los procedimientos y las
# columnas generadas. Escribir el archivo a mano, o concatenar los
# dieciocho `.sql`, significa que el día que una migración cambie el
# archivo de publicación quede atrás sin que nadie se entere.
#
# ── QUÉ SE LLEVA Y QUÉ NO ────────────────────────────────────
# Estructura: TODO (28 tablas, 5 vistas, triggers y procedimientos).
#
# Datos: sólo los catálogos, que son configuración real de la clínica y
# no datos de nadie:
#
#     rol · permiso · rol_permiso · estado_turno
#     especialidad · obra_social · plan_os · consultorio
#     medico · medico_especialidad · horario_atencion · descuento_os_medico
#
# NO se lleva: paciente, usuario, turno, pago, consulta, estudio, receta,
# receta_medicamento, renovacion_receta, notificacion, calificacion,
# historial_turno, paciente_plan, ausencia_medico, intento_login,
# password_reset. Ahí están los datos de las personas de prueba, y los
# nombres, DNI y teléfonos de mil pacientes generados no tienen por qué
# viajar a un servidor público.
#
# Y después se agregan a mano las tres cuentas de demostración y un poco
# de actividad para ellas, para que las pantallas no sean todas un estado
# vacío. Está en un bloque aparte, marcado, con el DELETE para quitarlo.
# =============================================================
set -euo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root -N -B mediturnos"
DUMP="/c/xampp/mysql/bin/mysqldump.exe -u root"
PHP="/c/xampp/php/php.exe"
SALIDA="$RAIZ/publicacion/mediturnos_demo.sql"

# La contraseña de las tres cuentas. Es pública a propósito: es un sitio
# de demostración y la gente tiene que poder entrar. Ver el aviso del
# final sobre lo que eso implica.
CLAVE_DEMO='Demo.2026'

CATALOGOS="rol permiso rol_permiso estado_turno especialidad obra_social plan_os consultorio medico medico_especialidad horario_atencion descuento_os_medico"

echo "Generando la base de demostración…"

# ── 1. Encabezado ────────────────────────────────────────────
cat > "$SALIDA" <<'SQL'
-- =============================================================
-- MediTurnos — base de demostración
-- =============================================================
-- GENERADO AUTOMÁTICAMENTE por publicacion/generar_base_demo.sh.
-- No editar a mano: se regenera y se pierden los cambios.
--
-- Contiene el esquema completo (28 tablas, 5 vistas, triggers y
-- procedimientos), los catálogos reales de la clínica y tres cuentas de
-- demostración con algo de actividad.
--
-- NO contiene ningún dato de los pacientes del entorno de desarrollo.
--
-- ── CÓMO SE IMPORTA ──────────────────────────────────────────
-- En el panel del hosting: crear la base, entrar a phpMyAdmin, elegir la
-- base recién creada, pestaña "Importar", subir este archivo.
--
-- Por línea de comandos, si el hosting da acceso:
--     mysql -u USUARIO -p NOMBRE_BASE < mediturnos_demo.sql
--
-- IMPORTANTE: el archivo NO lleva CREATE DATABASE ni USE. La base la
-- crea el panel del hosting, que además es quien decide cómo se llama
-- —en muchos casos le pone un prefijo, tipo `miusuario_mediturnos`—. Un
-- USE con el nombre equivocado haría fallar la importación entera.
-- =============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '-03:00';

SQL

# ── 2. Estructura completa ───────────────────────────────────
# --no-data: sólo la estructura.
# --routines --triggers: los procedimientos ReservarTurno/CancelarTurno y
#   los triggers del historial. Sin ellos la reserva de turnos no
#   funciona: la lógica de concurrencia vive ahí.
# --skip-add-drop-table: la base del hosting está recién creada; un DROP
#   de más es una invitación a correr esto por error sobre datos reales.
# --skip-comments: el encabezado de mysqldump incluye la versión del
#   servidor y el nombre del host de desarrollo.
echo "  · estructura (tablas, vistas, triggers, procedimientos)"
{
    echo "-- ══════════════════════════════════════════════════════════"
    echo "-- ESTRUCTURA"
    echo "-- ══════════════════════════════════════════════════════════"
    echo
    $DUMP --no-data --routines --triggers --skip-add-drop-table \
          --skip-comments --default-character-set=utf8mb4 \
          --skip-set-charset mediturnos
} >> "$SALIDA"

# ── 3. Datos de los catálogos ────────────────────────────────
echo "  · catálogos: $(echo $CATALOGOS | wc -w) tablas"
{
    echo
    echo "-- ══════════════════════════════════════════════════════════"
    echo "-- CATÁLOGOS"
    echo "-- ══════════════════════════════════════════════════════════"
    echo "-- Configuración real de la clínica: especialidades, coberturas,"
    echo "-- consultorios, profesionales y sus horarios. No son datos de"
    echo "-- ninguna persona que se haya atendido."
    echo
    $DUMP --no-create-info --skip-comments --default-character-set=utf8mb4 \
          --skip-set-charset --complete-insert \
          mediturnos $CATALOGOS
} >> "$SALIDA"

# ── 4. Cuentas de demostración ───────────────────────────────
# Los hashes se generan con password_hash() de PHP, que es lo que usa el
# login: escribir un hash a mano en el .sql significaría que nadie puede
# entrar y que el error aparece recién al probar en el servidor.
echo "  · cuentas de demostración (hashes con password_hash)"
HASH="$($PHP -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$CLAVE_DEMO")"

# La matrícula y el id de especialidad salen de la base, no se escriben
# fijos: si el catálogo cambia, el archivo generado sigue siendo válido.
MAT="$($MYSQL -e "SELECT matricula FROM medico WHERE estado='activo' ORDER BY matricula LIMIT 1;" | tr -d '\r')"
ESP="$($MYSQL -e "SELECT e.id_especialidad FROM especialidad e JOIN medico_especialidad me ON me.id_especialidad=e.id_especialidad WHERE me.matricula=$MAT LIMIT 1;" | tr -d '\r')"
CONS="$($MYSQL -e "SELECT id_consultorio FROM consultorio ORDER BY id_consultorio LIMIT 1;" | tr -d '\r')"
# El plan se elige buscando uno cuyo descuento con ESTE profesional no sea
# del 100%. No es un detalle: con cobertura total el monto del turno queda
# en cero, y un turno de monto cero se marca como pagado solo. La demo
# mostraría un turno "Pagado" que nadie pagó, y no se podría probar la
# pantalla de pago —que es una de las que hay que mostrar—.
PLAN_OS="$($MYSQL -e "
  SELECT pl.id_plan
  FROM   plan_os pl
  JOIN   obra_social os ON os.id_obra_social = pl.id_obra_social
  LEFT   JOIN descuento_os_medico d
         ON d.id_obra_social = os.id_obra_social AND d.matricula = $MAT
  WHERE  LOWER(os.nombre) <> 'particular'
    AND  IFNULL(d.porcentaje_descuento, 0) < 100
  ORDER  BY pl.id_plan LIMIT 1;" | tr -d '\r')"
AFILIADO="$(printf '%s' "$RANDOM$RANDOM" | cut -c1-9)"

# El precio y el descuento se leen del catálogo para que el monto del
# pago coincida con lo que el sistema calcularía al reservar ese mismo
# turno. Escribirlos fijos haría que la demo muestre un importe que no
# se corresponde con su especialidad ni con su cobertura.
PRECIO="$($MYSQL -e "SELECT precio_consulta FROM especialidad WHERE id_especialidad=$ESP;" | tr -d '\r')"
DESCUENTO="$($MYSQL -e "
  SELECT IFNULL(d.porcentaje_descuento, 0)
  FROM   plan_os pl
  LEFT   JOIN descuento_os_medico d
         ON d.id_obra_social = pl.id_obra_social AND d.matricula = $MAT
  WHERE  pl.id_plan = $PLAN_OS;" | tr -d '\r')"
ID_ROL_ADMIN="$($MYSQL -e "SELECT id_rol FROM rol WHERE nombre='admin';" | tr -d '\r')"
ID_ROL_MED="$($MYSQL -e "SELECT id_rol FROM rol WHERE nombre='medico';" | tr -d '\r')"
ID_ROL_PAC="$($MYSQL -e "SELECT id_rol FROM rol WHERE nombre='paciente';" | tr -d '\r')"
EST_REAL="$($MYSQL -e "SELECT id_estado FROM estado_turno WHERE descripcion='Realizado';" | tr -d '\r')"
EST_CONF="$($MYSQL -e "SELECT id_estado FROM estado_turno WHERE descripcion='Confirmado';" | tr -d '\r')"

cat >> "$SALIDA" <<SQL

-- ══════════════════════════════════════════════════════════
-- CUENTAS DE DEMOSTRACIÓN
-- ══════════════════════════════════════════════════════════
-- Tres cuentas, una por rol, con la contraseña: $CLAVE_DEMO
--
--   demo.admin      → administración: ABM, descuentos, usuarios
--   demo.medico     → la agenda y la ficha clínica del Dr/a. de la
--                     matrícula $MAT (que ya viene en el catálogo)
--   demo.paciente   → el Área del Paciente completa
--
-- ⚠️ La contraseña es pública A PROPÓSITO: es un sitio de demostración y
-- la gente tiene que poder entrar. Eso significa que cualquiera que
-- entre con demo.admin puede borrar y modificar todo. Para una demo
-- abierta es aceptable; si el sitio tiene que resistir, hay que cambiar
-- la contraseña del administrador y publicar sólo las otras dos.
--
-- Los hashes son de password_hash() con PASSWORD_DEFAULT, el mismo que
-- usa el registro del sistema.

-- El médico de la demo es uno que YA está en el catálogo: así tiene
-- horarios de atención, especialidades y descuentos cargados, y la
-- agenda no aparece vacía.
INSERT INTO usuario (nombre, apellido, usuario, email, contrasenia, id_rol, matricula, estado)
SELECT m.nombre, m.apellido, 'demo.medico', 'demo.medico@ejemplo-mediturnos.ar',
       '$HASH', $ID_ROL_MED, m.matricula, 'activo'
FROM   medico m WHERE m.matricula = $MAT;

INSERT INTO usuario (nombre, apellido, usuario, email, contrasenia, id_rol, estado)
VALUES ('Demo', 'Administración', 'demo.admin',
        'demo.admin@ejemplo-mediturnos.ar', '$HASH', $ID_ROL_ADMIN, 'activo');

-- El paciente necesita ficha en \`paciente\` ADEMÁS de cuenta en
-- \`usuario\`: son dos cosas distintas en este modelo (recepción carga
-- pacientes que nunca se registraron). Sin la ficha, el Área del
-- Paciente avisa que la cuenta no tiene una asociada.
INSERT INTO paciente (nombre, apellido, dni, fecha_nac, sexo, telefono, email, direccion)
VALUES ('Demo', 'Paciente', '30000000', '1990-05-14', 'prefiero_no_decir', '1122334455',
        'demo.paciente@ejemplo-mediturnos.ar', 'Av. Siempreviva 742');
SET @pac = LAST_INSERT_ID();

INSERT INTO usuario (nombre, apellido, usuario, email, contrasenia, id_rol, id_paciente, estado)
VALUES ('Demo', 'Paciente', 'demo.paciente',
        'demo.paciente@ejemplo-mediturnos.ar', '$HASH', $ID_ROL_PAC, @pac, 'activo');

-- Cobertura: un plan de obra social de verdad, con su número de
-- afiliado. Los planes "Particular" los puede usar cualquiera sin estar
-- asignados (así está escrito \`Turno::planesDePaciente()\`), así que
-- asignar uno de esos no mostraría nada: con una cobertura real se ve el
-- descuento aplicado en el resumen de la reserva, que es la mitad de la
-- lógica de precios del sistema.
INSERT INTO paciente_plan (id_paciente, id_plan, nro_afiliado, fecha_alta)
VALUES (@pac, $PLAN_OS, '$AFILIADO', CURDATE());


-- ══════════════════════════════════════════════════════════
-- ACTIVIDAD DE LA DEMO
-- ══════════════════════════════════════════════════════════
-- Un turno atendido con su ficha y su receta, y uno próximo confirmado.
-- Sin esto, el paciente entra y ve cinco pantallas vacías: no se puede
-- mostrar el historial, ni las recetas, ni la renovación.
--
-- PARA QUITARLO, una línea (el resto cae por las claves foráneas):
--     DELETE FROM turno WHERE id_paciente =
--         (SELECT id_paciente FROM usuario WHERE usuario = 'demo.paciente');
--     DELETE FROM receta WHERE id_paciente =
--         (SELECT id_paciente FROM usuario WHERE usuario = 'demo.paciente');
--     DELETE FROM notificacion WHERE id_usuario =
--         (SELECT id_usuario FROM usuario WHERE usuario = 'demo.paciente');

-- ── Turno ya atendido, hace tres semanas ─────────────────────
-- La hora es 10:00 y la fecha se calcula desde hoy, así que el archivo
-- no envejece: importado en cualquier momento, el turno queda en el
-- pasado reciente y el próximo en el futuro.
INSERT INTO turno (fecha, hora_inicio, id_estado, id_paciente, matricula,
                   id_especialidad, id_consultorio, id_plan)
VALUES (DATE_SUB(CURDATE(), INTERVAL 21 DAY), '10:00:00', $EST_REAL,
        @pac, $MAT, $ESP, $CONS, $PLAN_OS);
SET @t1 = LAST_INSERT_ID();

-- El pago no lo crea ningún trigger: lo crea el flujo de reserva. Un
-- turno insertado a mano se queda sin pago, y entonces el sistema no
-- sabe si está abonado — `v_turnos_detalle.estado_pago` viene en NULL y
-- "Mis pagos" aparece vacío.
INSERT INTO pago (id_turno, monto_base, porcentaje_descuento, estado, metodo,
                  fecha_creacion, fecha_vencimiento, fecha_pago,
                  tarjeta_ult4, tarjeta_titular, referencia)
VALUES (@t1, $PRECIO, $DESCUENTO, 'Pagado', 'Tarjeta',
        DATE_SUB(CURDATE(), INTERVAL 21 DAY),
        DATE_SUB(CURDATE(), INTERVAL 19 DAY),
        DATE_SUB(CURDATE(), INTERVAL 21 DAY),
        '4242', 'DEMO PACIENTE', 'DEMO-0001');

INSERT INTO consulta (id_turno, matricula, motivo_consulta, diagnostico, indicaciones)
VALUES (@t1, $MAT, 'Control anual',
        'Paciente en buen estado general. Presión arterial 120/80.',
        'Continuar con actividad física moderada. Control en seis meses.');

INSERT INTO estudio (id_paciente, id_turno, matricula, tipo, nombre, estado)
VALUES (@pac, @t1, $MAT, 'Laboratorio', 'Hemograma completo', 'Pendiente');

-- Una receta vigente, para que se pueda probar la renovación.
INSERT INTO receta (id_paciente, matricula, id_turno, diagnostico, indicaciones,
                    emitida_el, vence_el)
VALUES (@pac, $MAT, @t1, 'Control anual',
        'Tomar con las comidas. Si aparece malestar, suspender y consultar.',
        DATE_SUB(CURDATE(), INTERVAL 21 DAY),
        DATE_ADD(CURDATE(), INTERVAL 9 DAY));
SET @r1 = LAST_INSERT_ID();

INSERT INTO receta_medicamento (id_receta, nombre, presentacion, dosis, frecuencia, duracion, cantidad)
VALUES (@r1, 'Enalapril', 'comprimidos 10 mg', '1 comprimido', 'cada 12 horas', 'por 30 días', 2),
       (@r1, 'Aspirina',  'comprimidos 100 mg', '1 comprimido', 'por la mañana',  'por 30 días', 1);

-- ── Notificaciones ───────────────────────────────────────────
-- Para que el centro de avisos no aparezca vacío. Son los mismos
-- avisos que el sistema habría emitido al pasar lo de arriba.
--
-- Uno queda SIN LEER a propósito: así se ve el campanita con su globo,
-- que es la mitad de lo que hay que mostrar.
INSERT INTO notificacion (id_usuario, tipo, titulo, mensaje, url_accion,
                          id_referencia, creada_en, leida_en, email_enviado_en)
SELECT u.id_usuario, 'receta_nueva', 'Tenés una receta nueva',
       CONCAT('Dr/a. ', m.apellido, ', ', m.nombre,
              ' te emitió una receta con 2 medicamentos.'),
       'recetas.php', @r1,
       DATE_SUB(NOW(), INTERVAL 21 DAY), NULL, DATE_SUB(NOW(), INTERVAL 21 DAY)
FROM   usuario u, medico m
WHERE  u.usuario = 'demo.paciente' AND m.matricula = $MAT;

INSERT INTO notificacion (id_usuario, tipo, titulo, mensaje, url_accion,
                          creada_en, leida_en, email_enviado_en)
SELECT u.id_usuario, 'estudio_pedido', 'Te pidieron un estudio',
       'Hemograma completo. Cuando el resultado esté cargado te avisamos.',
       'historial.php',
       DATE_SUB(NOW(), INTERVAL 21 DAY),
       DATE_SUB(NOW(), INTERVAL 20 DAY), DATE_SUB(NOW(), INTERVAL 21 DAY)
FROM   usuario u WHERE u.usuario = 'demo.paciente';

INSERT INTO notificacion (id_usuario, tipo, titulo, mensaje, url_accion,
                          creada_en, leida_en)
SELECT u.id_usuario, 'resultados_listos', 'Ficha de tu consulta disponible',
       'Tu profesional registró el detalle de la atención.',
       'historial.php',
       DATE_SUB(NOW(), INTERVAL 20 DAY), DATE_SUB(NOW(), INTERVAL 20 DAY)
FROM   usuario u WHERE u.usuario = 'demo.paciente';


-- ── Turno próximo, confirmado ────────────────────────────────
-- A diez días: deja ver la cuenta regresiva, el detalle y la
-- reprogramación sin chocar con el mínimo de dos horas de antelación.
INSERT INTO turno (fecha, hora_inicio, id_estado, id_paciente, matricula,
                   id_especialidad, id_consultorio, id_plan)
VALUES (DATE_ADD(CURDATE(), INTERVAL 10 DAY), '11:00:00', $EST_CONF,
        @pac, $MAT, $ESP, $CONS, $PLAN_OS);
SET @t2 = LAST_INSERT_ID();

-- Pendiente y con el vencimiento por delante: así la pantalla de pago se
-- puede abrir y probar. Con el plazo ya vencido, la primera visita al
-- panel lo cancelaría solo (`Pago::expirarVencidos()` corre en cada
-- carga), y la demo se quedaría sin turno próximo.
INSERT INTO pago (id_turno, monto_base, porcentaje_descuento, estado,
                  fecha_creacion, fecha_vencimiento)
VALUES (@t2, $PRECIO, $DESCUENTO, 'Pendiente',
        NOW(), DATE_ADD(NOW(), INTERVAL 48 HOUR));
SQL

# ── 5. Cierre ────────────────────────────────────────────────
cat >> "$SALIDA" <<'SQL'

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================
-- VERIFICACIÓN después de importar
-- =============================================================
--   SELECT COUNT(*) FROM especialidad;   -- 8
--   SELECT COUNT(*) FROM medico;         -- 6
--   SELECT COUNT(*) FROM horario_atencion; -- 24
--   SELECT usuario, estado FROM usuario; -- las 3 cuentas demo
--   SELECT COUNT(*) FROM paciente;       -- 1
--
-- Y probar entrar con demo.paciente: si el login falla, el hash no
-- coincide (archivo generado con otra contraseña) o falta la tabla de
-- permisos.
-- =============================================================
SQL

# ── Un solo tipo de fin de línea ──────────────────────────────
# mysqldump en Windows escribe CRLF, pero los cuerpos de los
# procedimientos salen con los saltos que tenían en la migración (LF).
# El archivo queda mezclado, y entonces: Git lo marca como modificado
# cada vez que se regenera aunque el contenido sea idéntico, y el .sql
# que se sube termina con dos convenciones adentro.
#
# Se saca el CR del final de cada línea. No se usa `tr -d` a secas
# porque eso borraría también un CR que estuviera DENTRO de un dato.
sed -i 's/\r$//' "$SALIDA"

LINEAS="$(wc -l < "$SALIDA")"
PESO="$(du -h "$SALIDA" | cut -f1)"
echo
echo "Listo: publicacion/mediturnos_demo.sql ($LINEAS líneas, $PESO)"
echo "Contraseña de las tres cuentas: $CLAVE_DEMO"

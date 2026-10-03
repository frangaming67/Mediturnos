#!/usr/bin/env bash
# =============================================================
# publicacion/verificar_demo.sh
# =============================================================
# Prueba el paquete de publicación de verdad: importa
# `mediturnos_demo.sql` en una base aparte, apunta el sitio a ESA base y
# entra con las tres cuentas de demostración a recorrer las pantallas.
#
#     bash publicacion/verificar_demo.sh
#
# ── POR QUÉ HACE FALTA ───────────────────────────────────────
# Que el archivo .sql se importe sin errores no quiere decir que el sitio
# funcione con él. La primera versión importaba perfecto y le faltaba la
# fila de `pago` de cada turno: "Mis pagos" quedaba vacío y la pantalla
# de pago no se podía abrir desde ningún lado. Eso no lo dice el motor,
# lo dice entrar y mirar.
#
# Dos cosas que este guión NO toca:
#   · la base de desarrollo `mediturnos`, que queda intacta;
#   · `config/entorno.php`, si ya existía: se respalda y se restaura.
#
# Necesita Apache y MySQL levantados, y se corre desde Git Bash.
# =============================================================
set -uo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SITIO="http://localhost/mediturnos"
MYSQL_ROOT="/c/xampp/mysql/bin/mysql.exe -u root"
BASE="mediturnos_demo_prueba"
MY="$MYSQL_ROOT -N -B $BASE"

# El cliente de MySQL en Windows devuelve los valores con CR al final, y
# $( ) no lo saca: "3" y "3" se comparan como distintos.
my_demo() { printf %s "$($MY -e "$1")" | tr -d ''; }
ENTORNO="$RAIZ/config/entorno.php"
RESPALDO="$RAIZ/config/entorno.php.respaldo-verificacion"
TMP="$(mktemp -d)"
CLAVE='Demo.2026'
OK=0; MAL=0

# Marca que identifica un entorno.php hecho por este guión. Sirve para
# no confundir la basura de una corrida anterior con la configuración
# del usuario — ver el comentario de la función limpiar().
MARCA='VERIFICACION-DEMO-MEDITURNOS'

pct() { printf %s "$1" | od -An -tx1 -v | tr -d ' \n' | sed 's/../%&/g'; }
chk() { if [ "$2" = "$3" ]; then OK=$((OK+1)); echo "[OK]    $1"
        else MAL=$((MAL+1)); printf '[MAL]   %s\n          esperado: %s\n          real:     %s\n' "$1" "$2" "$3"; fi; }
tiene() { if grep -qF -- "$3" "$2"; then OK=$((OK+1)); echo "[OK]    $1"
          else MAL=$((MAL+1)); echo "[MAL]   $1 (no aparece: $3)"; fi; }

entrar() { rm -f "$TMP/$1"
  curl -s -c "$TMP/$1" -o /dev/null "$SITIO/login.php"
  curl -s -b "$TMP/$1" -c "$TMP/$1" -o /dev/null \
       --data "usuario=$(pct "$2")" --data "contrasenia=$(pct "$3")" "$SITIO/login.php"; }

# Abre una pantalla y verifica el código Y que no haya avisos de PHP.
abrir() {
  local c
  c="$(curl -s -L -m 20 -b "$TMP/$1" -o "$TMP/body" -w '%{http_code}' "$SITIO/$2")"
  if [ "$c" = "000" ]; then echo; echo "!! Apache no responde."; exit 1; fi
  if [ "$c" = "$3" ] && ! grep -qiE "Fatal error|Warning:|Notice:|Deprecated:" "$TMP/body"; then
      OK=$((OK+1)); echo "[OK]    $4"
  else
      MAL=$((MAL+1)); echo "[MAL]   $4 (código $c)"
      grep -oiE "(Fatal error|Warning|Notice|Deprecated)[^<]{0,140}" "$TMP/body" | head -2
  fi
}

limpiar() {
    echo
    echo "-- RESTAURACIÓN -------------------------------------"
    if [ -f "$RESPALDO" ]; then mv -f "$RESPALDO" "$ENTORNO"; echo "       entorno.php original restaurado"
    else rm -f "$ENTORNO"; echo "       entorno.php de prueba borrado"; fi
    # NO se pregunta por login.php: esa pantalla devuelve 200 aunque la
    # base esté caída, así que daba OK justo en el caso que tenía que
    # detectar. Se consulta la base de desarrollo a través del sitio,
    # con una pantalla que sin base no puede responder.
    local sano
    sano="$(curl -s -m 10 -o "$TMP/final" -w '%{http_code}' "$SITIO/index.php")"
    if [ "$sano" = "200" ] && grep -qiE 'especialidad|Cardio|profesional' "$TMP/final"; then
        OK=$((OK+1)); echo "[OK]    el sitio volvió a la base de desarrollo y lee datos"
    else
        MAL=$((MAL+1)); echo "[MAL]   el sitio NO está leyendo la base de desarrollo (código $sano)"
        echo "        revisá config/entorno.php: no debería existir"
    fi
    $MYSQL_ROOT -e "DROP DATABASE IF EXISTS $BASE;" 2>/dev/null
    local quedo
    quedo="$($MYSQL_ROOT -N -B -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$BASE';" | tr -d '\r')"
    chk "la base de prueba quedó eliminada" "0" "$quedo"
    local devel
    devel="$($MYSQL_ROOT -N -B mediturnos -e "SELECT COUNT(*) FROM turno;" | tr -d '\r')"
    chk "la base de desarrollo quedó intacta ($devel turnos)" "48" "$devel"
    rm -rf "$TMP"
    echo "================================================================"
    printf 'TOTAL: %d OK, %d MAL\n' "$OK" "$MAL"
    [ "$MAL" -eq 0 ] || exit 1
}
trap limpiar EXIT

# =============================================================
echo "-- IMPORTACIÓN --------------------------------------"
$MYSQL_ROOT -e "DROP DATABASE IF EXISTS $BASE;
                CREATE DATABASE $BASE CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
SALIDA="$($MYSQL_ROOT "$BASE" < "$RAIZ/publicacion/mediturnos_demo.sql" 2>&1)"
chk "el .sql se importa sin un solo error" "" "$SALIDA"

chk "28 tablas"          "28" "$($MY -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$BASE' AND TABLE_TYPE='BASE TABLE';" | tr -d '\r')"
chk "5 vistas"            "5" "$($MY -e "SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA='$BASE';" | tr -d '\r')"
chk "2 triggers"          "2" "$($MY -e "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$BASE';" | tr -d '\r')"
chk "2 procedimientos"    "2" "$($MY -e "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='$BASE';" | tr -d '\r')"
chk "los catálogos: 8 especialidades"  "8" "$($MY -e "SELECT COUNT(*) FROM especialidad;" | tr -d '\r')"
chk "6 profesionales"      "6" "$($MY -e "SELECT COUNT(*) FROM medico;" | tr -d '\r')"
chk "24 franjas de atención" "24" "$($MY -e "SELECT COUNT(*) FROM horario_atencion;" | tr -d '\r')"
chk "22 permisos"         "22" "$($MY -e "SELECT COUNT(*) FROM permiso;" | tr -d '\r')"

echo
echo "-- NADA DE DATOS DE DESARROLLO ----------------------"
chk "un solo paciente (el de la demo), no 1012" "1" "$($MY -e "SELECT COUNT(*) FROM paciente;" | tr -d '\r')"
chk "tres cuentas, no 1026"                     "3" "$($MY -e "SELECT COUNT(*) FROM usuario;" | tr -d '\r')"
chk "sin intentos de login de nadie"            "0" "$($MY -e "SELECT COUNT(*) FROM intento_login;" | tr -d '\r')"
chk "sin tokens de recuperación"                "0" "$($MY -e "SELECT COUNT(*) FROM password_reset;" | tr -d '\r')"
# No "cero notificaciones": la demo trae tres a propósito, para que el
# centro de avisos no aparezca vacío. Lo que se comprueba es que no haya
# NINGUNA de otra cuenta, o sea arrastrada del entorno de desarrollo.
chk "tres avisos, todos de la cuenta de demostración" "3" \
    "$(my_demo "SELECT COUNT(*) FROM notificacion n JOIN usuario u ON u.id_usuario=n.id_usuario WHERE u.usuario='demo.paciente';")"
chk "ninguna de otra cuenta" "0" \
    "$(my_demo "SELECT COUNT(*) FROM notificacion n JOIN usuario u ON u.id_usuario=n.id_usuario WHERE u.usuario<>'demo.paciente';")"
chk "sin calificaciones"                        "0" "$($MY -e "SELECT COUNT(*) FROM calificacion;" | tr -d '\r')"
chk "ninguna cuenta con la clave del seed"      "0" \
    "$($MY -e "SELECT COUNT(*) FROM usuario WHERE usuario IN ('admin','cfernandez','mgonzalez','laila');" | tr -d '\r')"

echo
echo "-- EL SITIO APUNTANDO A LA BASE LIMPIA --------------"
# Sólo se respalda si NO lleva la marca: con la marca es el sobrante de
# una corrida que quedó cortada, y "restaurarlo" dejaría el sitio
# apuntando a la base de prueba que este guión elimina al final.
if [ -f "$ENTORNO" ] && ! grep -q "$MARCA" "$ENTORNO"; then
    cp -f "$ENTORNO" "$RESPALDO"
    echo "       había un entorno.php propio: se respaldó"
elif [ -f "$ENTORNO" ]; then
    echo "       había un entorno.php de una corrida anterior: se descarta"
fi
cat > "$ENTORNO" <<PHP
<?php
// Generado por publicacion/verificar_demo.sh ($MARCA).
// Se borra al terminar. Si lo encontrás suelto, borralo: apunta a una
// base de prueba que ya no existe.
define('DB_HOST', 'localhost');
define('DB_NAME', '$BASE');
define('DB_USER', 'root');
define('DB_PASS', '');
define('BASE_URL', '/mediturnos/');
define('EN_PRODUCCION', false);
PHP
chk "entorno.php de prueba creado" "1" "$([ -f "$ENTORNO" ] && echo 1 || echo 0)"

entrar pac demo.paciente "$CLAVE"
abrir pac dashboard.php 200 "demo.paciente entra a su panel"
tiene "y lo saluda por su nombre"            "$TMP/body" "Demo"
tiene "con su próximo turno"                 "$TMP/body" "Confirmado"
tiene "y el aviso de pago pendiente"         "$TMP/body" "Falta abonar"
tiene "el campanita con avisos pendientes"  "$TMP/body" "topbar-campana-globo"
tiene "con el botón para pagarlo"            "$TMP/body" "Pagar ahora"
abrir pac recetas.php 200 "sus recetas"
tiene "con el medicamento de la demo"        "$TMP/body" "Enalapril"
tiene "el segundo medicamento"               "$TMP/body" "Aspirina"
tiene "y la renovación disponible"           "$TMP/body" "Pedir renovación"
abrir pac historial.php 200 "su historial"
tiene "con la consulta registrada"           "$TMP/body" "Control anual"
tiene "el diagnóstico"                       "$TMP/body" "120/80"
tiene "y el estudio pendiente"               "$TMP/body" "Hemograma completo"
abrir pac "sistema/controladores/ControladorTurno.php?accion=index" 200 "sus turnos"
abrir pac "sistema/controladores/ControladorPago.php?accion=index" 200 "sus pagos"
tiene "con el importe con descuento aplicado" "$TMP/body" "1.000"
abrir pac agendar.php 200 "el asistente de reserva"
tiene "ofrece especialidades reales"         "$TMP/body" "Cardiologia"
abrir pac "sistema/controladores/ControladorNotificacion.php?accion=index" 200 "su centro de notificaciones"
tiene "con el aviso de la receta"          "$TMP/body" "Tenés una receta nueva"
tiene "y uno sin leer"                     "$TMP/body" "Sin leer"
abrir pac perfil.php 200 "su perfil"
tiene "con su cobertura cargada"             "$TMP/body" "OSDE"

entrar med demo.medico "$CLAVE"
abrir med dashboard.php 200 "demo.medico entra a su agenda"
tiene "con el contador de renovaciones"      "$TMP/body" "Renovaciones"
abrir med "sistema/controladores/ControladorReceta.php?accion=renovaciones" 200 "su bandeja de renovaciones"
T1="$($MY -e "SELECT id_turno FROM turno WHERE fecha < CURDATE() LIMIT 1;" | tr -d '\r')"
abrir med "sistema/controladores/ControladorHistorial.php?accion=consulta&id=$T1" 200 "la ficha del turno atendido"
tiene "con la ficha ya cargada"              "$TMP/body" "Control anual"
tiene "y la receta que emitió"               "$TMP/body" "Enalapril"

entrar adm demo.admin "$CLAVE"
abrir adm dashboard.php 200 "demo.admin entra al tablero"
abrir adm "sistema/controladores/ControladorPaciente.php?accion=index" 200 "el listado de pacientes"
abrir adm "sistema/controladores/ControladorMedico.php?accion=index" 200 "el listado de profesionales"
abrir adm "sistema/controladores/ControladorHorario.php?accion=index" 200 "los horarios"
abrir adm "sistema/controladores/ControladorObraSocial.php?accion=index" 200 "las obras sociales"
abrir adm "sistema/controladores/ControladorObraSocial.php?accion=descuentos" 200 "los descuentos"
abrir adm "sistema/controladores/ControladorConsultorio.php?accion=index" 200 "los consultorios"
abrir adm "sistema/controladores/ControladorUsuario.php?accion=index" 200 "los usuarios"

echo
echo "-- MODO PRODUCCIÓN ---------------------------------"
# Con EN_PRODUCCION en true, un error no puede llegar al navegador.
sed -i "s/define('EN_PRODUCCION', false);/define('EN_PRODUCCION', true);/" "$ENTORNO"
abrir pac dashboard.php 200 "el panel sigue funcionando en modo producción"
C="$(curl -s -L -b "$TMP/pac" -o "$TMP/body" -w '%{http_code}' \
     "$SITIO/sistema/controladores/ControladorHistorial.php?accion=descargar&id=99999999")"
chk "un recurso inexistente responde 404, no una traza" "404" "$C"
if grep -qiE "on line [0-9]+|C:\\\\xampp|Stack trace" "$TMP/body"; then
    MAL=$((MAL+1)); echo "[MAL]   la respuesta filtra rutas o números de línea"
else
    OK=$((OK+1)); echo "[OK]    sin rutas del servidor ni números de línea en la respuesta"
fi

echo
echo "-- LAS CARPETAS PRIVADAS NO SE SIRVEN ---------------"
for d in config includes sistema sql pruebas docs dashboard almacenamiento publicacion; do
    chk "/$d/ responde 403" "403" \
        "$(curl -s -o /dev/null -w '%{http_code}' "$SITIO/$d/")"
done
chk "el .sql de publicación no se puede descargar" "403" \
    "$(curl -s -o /dev/null -w '%{http_code}' "$SITIO/publicacion/mediturnos_demo.sql")"

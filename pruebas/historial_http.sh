#!/usr/bin/env bash
# =============================================================
# pruebas/historial_http.sh — La ficha clínica por HTTP
# =============================================================
# Cubre los dos arreglos del controlador que salieron de revisar los
# hallazgos sin verificar:
#
#   1. un parámetro de la URL que llega como ARREGLO ya no tumba la
#      página (antes: TypeError de urldecode(), impreso con un 200);
#   2. pedir un estudio y cargar su resultado exigen que el turno esté
#      REALIZADO, igual que la ficha — la vista ya no ofrecía el
#      formulario, pero un POST armado a mano entraba.
#
#     bash pruebas/historial_http.sh
#
# Necesita Apache y MySQL. Usa cfernandez, que conserva la clave del
# seed, así que no toca ninguna contraseña.
# =============================================================
set -uo pipefail

SITIO="http://localhost/mediturnos"
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root -N -B --default-character-set=utf8mb4 mediturnos"
RUTA="sistema/controladores/ControladorHistorial.php"
TMP="$(mktemp -d)"
OK=0; MAL=0

chk() { if [ "$2" = "$3" ]; then OK=$((OK+1)); echo "[OK]    $1"
        else MAL=$((MAL+1)); printf '[MAL]   %s\n          esperado: %s\n          real:     %s\n' "$1" "$2" "$3"; fi; }
no_tiene() { if grep -qiE -- "$3" "$2"; then MAL=$((MAL+1)); echo "[MAL]   $1"
             grep -oiE -- "$3[^<]{0,110}" "$2" | head -1
             else OK=$((OK+1)); echo "[OK]    $1"; fi; }

my() { local s; if ! s="$($MYSQL -e "$1" 2>&1)"; then echo "!! base: $s"; exit 1; fi
       printf %s "$s" | tr -d '\r'; }
pct() { printf %s "$1" | od -An -tx1 -v | tr -d ' \n' | sed 's/../%&/g'; }

C="$TMP/c"
entrar() {
    curl -s -c "$C" -o /dev/null "$SITIO/login.php"
    curl -s -b "$C" -c "$C" -o /dev/null \
         --data "usuario=$(pct cfernandez)" --data "contrasenia=$(pct password)" "$SITIO/login.php"
}
get() {
    local c
    c="$(curl -s -L -m 20 -b "$C" -c "$C" -o "$TMP/body" -w '%{http_code}' "$SITIO/$1")"
    [ "$c" = "000" ] && { echo "!! Apache no responde."; exit 1; }
    echo "$c"
}
post() {
    local ruta="$1"; shift
    local args=() n v
    for d in "$@"; do n="${d%%=*}"; v="${d#*=}"; args+=(--data "$(pct "$n")=$(pct "$v")"); done
    curl -s -m 20 -b "$C" -c "$C" -o "$TMP/body" -D "$TMP/head" "${args[@]}" \
         "$SITIO/$ruta" -w '%{http_code}' >"$TMP/code"
    local loc; loc="$(grep -i '^location:' "$TMP/head" | tr -d '\r' | sed 's/^[Ll]ocation: *//')"
    echo "$(cat "$TMP/code")|$loc"
}
token() { get "$1" >/dev/null
          grep -o 'name="csrf_token" value="[a-f0-9]*"' "$TMP/body" | head -1 | sed 's/.*value="//; s/"//'; }

# Un turno REALIZADO y uno que NO, los dos de cfernandez.
T_OK="$(my "SELECT t.id_turno FROM turno t JOIN estado_turno e ON e.id_estado=t.id_estado
            WHERE e.descripcion='Realizado' AND t.matricula=10001 ORDER BY t.id_turno LIMIT 1;")"
T_NO="$(my "SELECT t.id_turno FROM turno t JOIN estado_turno e ON e.id_estado=t.id_estado
            WHERE e.descripcion <> 'Realizado' AND t.matricula=10001 ORDER BY t.id_turno LIMIT 1;")"
EST_NO="$(my "SELECT e.descripcion FROM turno t JOIN estado_turno e ON e.id_estado=t.id_estado
              WHERE t.id_turno=$T_NO;")"

echo "turno realizado=$T_OK   turno $EST_NO=$T_NO"
echo "================================================================"

limpiar() {
    echo
    echo "-- LIMPIEZA ------------------------------------------"
    $MYSQL -e "DELETE FROM estudio WHERE nombre LIKE 'PRUEBA-H%';"
    # Y los avisos que esos estudios emitieron.
    $MYSQL -e "DELETE FROM notificacion WHERE tipo='estudio_pedido'
               AND creada_en >= DATE_SUB(NOW(), INTERVAL 1 HOUR);"
    chk "estudios de prueba borrados" "0" \
        "$(my "SELECT COUNT(*) FROM estudio WHERE nombre LIKE 'PRUEBA-H%';")"
    chk "los 48 turnos siguen ahí" "48" "$(my "SELECT COUNT(*) FROM turno;")"
    rm -rf "$TMP"
    echo "================================================================"
    printf 'TOTAL: %d OK, %d MAL\n' "$OK" "$MAL"
    [ "$MAL" -eq 0 ] || exit 1
}
trap limpiar EXIT

entrar
$MYSQL -e "DELETE FROM estudio WHERE nombre LIKE 'PRUEBA-H%';"

# =============================================================
echo
echo "-- PARÁMETROS QUE LLEGAN COMO ARREGLO ---------------"
# 🚨 Esto devolvía 200 con un «Fatal error: Uncaught TypeError» impreso
# arriba del contenido. El código de respuesta no cambia, así que una
# prueba que sólo mire el código no lo ve: hay que mirar el cuerpo.
chk "la ficha abre normalmente" "200" "$(get "$RUTA?accion=consulta&id=$T_OK")"
chk "con un err normal también" "200" "$(get "$RUTA?accion=consulta&id=$T_OK&err=hola")"
no_tiene "y el mensaje no rompe nada" "$TMP/body" "Fatal error|TypeError"

chk "con err como ARREGLO responde 200" "200" "$(get "$RUTA?accion=consulta&id=$T_OK&err[]=x")"
no_tiene "y NO imprime un error de PHP"  "$TMP/body" "Fatal error|Uncaught|TypeError"
chk "con msg como arreglo tampoco"  "200" "$(get "$RUTA?accion=consulta&id=$T_OK&msg[]=x")"
no_tiene "sin errores"               "$TMP/body" "Fatal error|Uncaught|TypeError"
chk "con id como arreglo"           "302" "$(curl -s -o /dev/null -w '%{http_code}' -b "$C" "$SITIO/$RUTA?accion=consulta&id[]=1")"

# =============================================================
echo
echo "-- PEDIR UN ESTUDIO EXIGE TURNO REALIZADO -----------"
T="$(token "$RUTA?accion=consulta&id=$T_OK")"

# Sobre el turno realizado: se puede.
R="$(post "$RUTA?accion=pedirEstudio" "csrf_token=$T" "id_turno=$T_OK" \
     "tipo=Laboratorio" "nombre=PRUEBA-H permitido")"
chk "sobre un turno realizado funciona" "si" \
    "$(case "${R#*|}" in *"msg=estudio_pedido"*) echo si;; *) echo "${R#*|}";; esac)"
chk "y quedó registrado" "1" "$(my "SELECT COUNT(*) FROM estudio WHERE nombre='PRUEBA-H permitido';")"

# Sobre el turno que NO está realizado: rechazado. La vista no ofrece el
# formulario, pero el POST se arma a mano igual.
R="$(post "$RUTA?accion=pedirEstudio" "csrf_token=$T" "id_turno=$T_NO" \
     "tipo=Laboratorio" "nombre=PRUEBA-H rechazado")"
chk "sobre un turno $EST_NO se rechaza" "si" \
    "$(case "${R#*|}" in *"err="*) echo si;; *) echo "${R#*|}";; esac)"
chk "y NO se registró nada" "0" "$(my "SELECT COUNT(*) FROM estudio WHERE nombre='PRUEBA-H rechazado';")"

# =============================================================
echo
echo "-- CARGAR UN RESULTADO, IDEM ------------------------"
ID_EST="$(my "SELECT id_estudio FROM estudio WHERE nombre='PRUEBA-H permitido';")"
R="$(post "$RUTA?accion=subirResultado" "csrf_token=$T" "id_turno=$T_NO" "id_estudio=$ID_EST")"
chk "desde un turno $EST_NO se rechaza" "si" \
    "$(case "${R#*|}" in *"err="*) echo si;; *) echo "${R#*|}";; esac)"
chk "y el estudio sigue Pendiente" "Pendiente" \
    "$(my "SELECT estado FROM estudio WHERE id_estudio=$ID_EST;")"

# =============================================================
echo
echo "-- LAS DEFENSAS QUE YA ESTABAN ----------------------"
R="$(curl -s -o /dev/null -D "$TMP/head" -w '%{http_code}' -b "$C" \
     "$SITIO/$RUTA?accion=pedirEstudio&id_turno=$T_OK&tipo=x&nombre=PRUEBA-H-porGET")"
chk "pedir un estudio por GET: 405" "405" "$R"
chk "y no creó nada" "0" "$(my "SELECT COUNT(*) FROM estudio WHERE nombre='PRUEBA-H-porGET';")"

R="$(post "$RUTA?accion=pedirEstudio" "id_turno=$T_OK" "tipo=x" "nombre=PRUEBA-H sinToken")"
chk "POST sin token CSRF: 403" "403" "${R%%|*}"
chk "y no creó nada"            "0" "$(my "SELECT COUNT(*) FROM estudio WHERE nombre='PRUEBA-H sinToken';")"

T_AJENO="$(my "SELECT id_turno FROM turno WHERE matricula <> 10001 ORDER BY id_turno LIMIT 1;")"
chk "la ficha de un turno de otro médico: 403" "403" "$(get "$RUTA?accion=consulta&id=$T_AJENO")"
R="$(post "$RUTA?accion=pedirEstudio" "csrf_token=$T" "id_turno=$T_AJENO" \
     "tipo=x" "nombre=PRUEBA-H ajeno")"
chk "y pedir un estudio ahí: 403" "403" "${R%%|*}"
chk "sin crear nada"               "0" "$(my "SELECT COUNT(*) FROM estudio WHERE nombre='PRUEBA-H ajeno';")"

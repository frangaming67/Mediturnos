#!/usr/bin/env bash
# =============================================================
# pruebas/notificaciones_http.sh — El centro de notificaciones
# =============================================================
# Entra al sitio y prueba el centro de avisos como lo usa una persona,
# y sobre todo lo que NO debería poder hacerse: ver, marcar o borrar el
# aviso de otra cuenta, y hacer que un aviso redirija fuera del sitio.
#
#     bash pruebas/notificaciones_http.sh
#
# Necesita Apache y MySQL levantados. Trabaja con dos cuentas reales de
# la base de desarrollo: a una le pone una contraseña temporal y se la
# restaura al terminar, pase lo que pase.
#
# Las tres trampas de este entorno (acentos en los argumentos, el
# cliente de MySQL y las caídas del servidor) están explicadas en
# LEEME.md y resueltas acá igual que en los otros guiones.
# =============================================================
set -uo pipefail

SITIO="http://localhost/mediturnos"
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root -N -B --default-character-set=utf8mb4 mediturnos"
PHP="/c/xampp/php/php.exe"
RUTA="sistema/controladores/ControladorNotificacion.php"
TMP="$(mktemp -d)"
CLAVE='Prueba.Etapa6'
OK=0; MAL=0

chk() { if [ "$2" = "$3" ]; then OK=$((OK+1)); echo "[OK]    $1"
        else MAL=$((MAL+1)); printf '[MAL]   %s\n          esperado: %s\n          real:     %s\n' "$1" "$2" "$3"; fi; }
tiene()    { if grep -qF -- "$3" "$2"; then OK=$((OK+1)); echo "[OK]    $1"
             else MAL=$((MAL+1)); echo "[MAL]   $1 (no aparece: $3)"; fi; }
no_tiene() { if grep -qF -- "$3" "$2"; then MAL=$((MAL+1)); echo "[MAL]   $1 (aparece y no debía: $3)"
             else OK=$((OK+1)); echo "[OK]    $1"; fi; }

my() {
    local salida
    if ! salida="$($MYSQL -e "$1" 2>&1)"; then
        echo; echo "!! La base no responde:"; echo "$salida"; exit 1
    fi
    printf %s "$salida" | tr -d '\r'
}

pct() { printf %s "$1" | od -An -tx1 -v | tr -d ' \n' | sed 's/../%&/g'; }

sesion() { echo "$TMP/cookies_$1"; }
get()  {
    local c
    c="$(curl -s -L -m 20 -b "$(sesion "$1")" -c "$(sesion "$1")" \
         -o "$TMP/body" -w '%{http_code}' "$SITIO/$2")"
    [ "$c" = "000" ] && { echo; echo "!! Apache dejó de responder."; exit 1; }
    echo "$c"
}
# Sin seguir la redirección: en estas pruebas lo que importa es A DÓNDE manda.
crudo() {
    local s="$1" ruta="$2"
    curl -s -m 20 -b "$(sesion "$s")" -c "$(sesion "$s")" \
         -o "$TMP/body" -D "$TMP/head" -w '%{http_code}' "$SITIO/$ruta" >"$TMP/code"
    local loc
    loc="$(grep -i '^location:' "$TMP/head" | tr -d '\r' | sed 's/^[Ll]ocation: *//')"
    echo "$(cat "$TMP/code")|$loc"
}
post() {
    local s="$1" ruta="$2"; shift 2
    local args=() nombre valor
    for d in "$@"; do
        nombre="${d%%=*}"; valor="${d#*=}"
        args+=(--data "$(pct "$nombre")=$(pct "$valor")")
    done
    curl -s -m 20 -b "$(sesion "$s")" -c "$(sesion "$s")" \
         -o "$TMP/body" -D "$TMP/head" "${args[@]}" "$SITIO/$ruta" -w '%{http_code}' >"$TMP/code"
    local loc
    loc="$(grep -i '^location:' "$TMP/head" | tr -d '\r' | sed 's/^[Ll]ocation: *//')"
    echo "$(cat "$TMP/code")|$loc"
}
token() {
    get "$1" "$2" >/dev/null
    grep -o 'name="csrf_token" value="[a-f0-9]*"' "$TMP/body" | head -1 | sed 's/.*value="//; s/"//'
}
entrar() {
    rm -f "$(sesion "$1")"
    curl -s -c "$(sesion "$1")" -o /dev/null "$SITIO/login.php"
    curl -s -b "$(sesion "$1")" -c "$(sesion "$1")" -o /dev/null \
         --data "usuario=$(pct "$2")" --data "contrasenia=$(pct "$3")" "$SITIO/login.php"
}

# =============================================================
# Datos de trabajo
# =============================================================
HASH="$($PHP -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$CLAVE")"

# cfernandez conserva la clave del seed. La segunda cuenta es un paciente
# cualquiera, que NO sea la cuenta personal del dueño del proyecto.
MED_ID="$(my "SELECT id_usuario FROM usuario WHERE usuario='cfernandez';")"
read -r PAC_USER PAC_ID <<<"$(my "
SELECT u.usuario, u.id_usuario FROM usuario u
JOIN rol r ON r.id_rol = u.id_rol
WHERE r.nombre='paciente' AND u.estado='activo'
  AND u.id_paciente IS NOT NULL AND u.usuario <> 'laila'
ORDER BY u.id_usuario LIMIT 1;")"

echo "medico=cfernandez ($MED_ID)  paciente=$PAC_USER ($PAC_ID)"
echo "================================================================"

HASH_PAC="$(my "SELECT contrasenia FROM usuario WHERE id_usuario=$PAC_ID;")"

restaurar() {
    echo
    echo "-- RESTAURACIÓN -------------------------------------"
    $MYSQL -e "UPDATE usuario SET contrasenia='$HASH_PAC' WHERE id_usuario=$PAC_ID;"
    chk "contraseña de $PAC_USER restaurada" "1" \
        "$(my "SELECT contrasenia='$HASH_PAC' FROM usuario WHERE id_usuario=$PAC_ID;")"
    # Sólo los avisos que creó la prueba: los demás son del sistema.
    $MYSQL -e "DELETE FROM notificacion WHERE titulo LIKE 'PRUEBA-E6%';"
    chk "avisos de prueba borrados" "0" \
        "$(my "SELECT COUNT(*) FROM notificacion WHERE titulo LIKE 'PRUEBA-E6%';")"
    rm -rf "$TMP"
    echo "================================================================"
    printf 'TOTAL: %d OK, %d MAL\n' "$OK" "$MAL"
    [ "$MAL" -eq 0 ] || exit 1
}
trap restaurar EXIT

$MYSQL -e "UPDATE usuario SET contrasenia='$HASH' WHERE id_usuario=$PAC_ID;"
$MYSQL -e "DELETE FROM notificacion WHERE titulo LIKE 'PRUEBA-E6%';"

# Avisos de prueba: dos del paciente (uno sin leer, uno leído) y uno del
# médico, para los intentos cruzados.
$MYSQL -e "
INSERT INTO notificacion (id_usuario, tipo, titulo, mensaje, url_accion, id_referencia, creada_en)
VALUES ($PAC_ID, 'turno_confirmado', 'PRUEBA-E6 sin leer', 'Mensaje de la prueba sin leer',
        'recetas.php', NULL, NOW());
INSERT INTO notificacion (id_usuario, tipo, titulo, mensaje, url_accion, leida_en, creada_en)
VALUES ($PAC_ID, 'pago_aprobado', 'PRUEBA-E6 ya leido', 'Mensaje de la prueba ya leido',
        'historial.php', NOW(), DATE_SUB(NOW(), INTERVAL 2 DAY));
INSERT INTO notificacion (id_usuario, tipo, titulo, mensaje, creada_en)
VALUES ($MED_ID, 'refill_solicitado', 'PRUEBA-E6 del medico', 'Este aviso es del medico', NOW());"

N_SINLEER="$(my "SELECT id_notificacion FROM notificacion WHERE titulo='PRUEBA-E6 sin leer';")"
N_LEIDO="$(my "SELECT id_notificacion FROM notificacion WHERE titulo='PRUEBA-E6 ya leido';")"
N_AJENO="$(my "SELECT id_notificacion FROM notificacion WHERE titulo='PRUEBA-E6 del medico';")"

entrar pac "$PAC_USER" "$CLAVE"
entrar med cfernandez password

# =============================================================
echo
echo "-- EL LISTADO ---------------------------------------"
chk "el centro abre" "200" "$(get pac "$RUTA?accion=index")"
tiene "muestra el aviso sin leer"   "$TMP/body" "PRUEBA-E6 sin leer"
tiene "y el ya leído"               "$TMP/body" "PRUEBA-E6 ya leido"
no_tiene "NO muestra el del médico" "$TMP/body" "PRUEBA-E6 del medico"
tiene "marca el que falta leer"     "$TMP/body" "Sin leer"
tiene "con el nombre legible del tipo" "$TMP/body" "Turno confirmado"

echo
echo "-- FILTROS ------------------------------------------"
get pac "$RUTA?accion=index&estado=no_leidas" >/dev/null
tiene    "sin leer: está el que falta" "$TMP/body" "PRUEBA-E6 sin leer"
no_tiene "y no el leído"               "$TMP/body" "PRUEBA-E6 ya leido"
get pac "$RUTA?accion=index&estado=leidas" >/dev/null
tiene    "leídas: está el leído"       "$TMP/body" "PRUEBA-E6 ya leido"
no_tiene "y no el que falta"           "$TMP/body" "PRUEBA-E6 sin leer"
get pac "$RUTA?accion=index&tipo=turno_confirmado" >/dev/null
tiene    "por tipo: trae el de ese tipo" "$TMP/body" "PRUEBA-E6 sin leer"
no_tiene "y no el de otro tipo"          "$TMP/body" "PRUEBA-E6 ya leido"
chk "un tipo inventado no rompe"  "200" "$(get pac "$RUTA?accion=index&tipo=no_existe")"
tiene "y se ignora: vuelve a mostrar todo" "$TMP/body" "PRUEBA-E6 ya leido"
chk "estado como arreglo no rompe" "200" "$(get pac "$RUTA?accion=index&estado[]=x")"
chk "tipo como arreglo no rompe"   "200" "$(get pac "$RUTA?accion=index&tipo[]=x")"
chk "página 999 no rompe"          "200" "$(get pac "$RUTA?accion=index&pagina=999")"
chk "página negativa no rompe"     "200" "$(get pac "$RUTA?accion=index&pagina=-5")"

# =============================================================
echo
echo "-- ABRIR UN AVISO -----------------------------------"
R="$(crudo pac "$RUTA?accion=leer&id=$N_SINLEER")"
chk "abrir redirige"                      "302" "${R%%|*}"
chk "al destino que guardó el aviso" "/mediturnos/recetas.php" "${R#*|}"
chk "y queda marcado como leído"            "1" \
    "$(my "SELECT leida_en IS NOT NULL FROM notificacion WHERE id_notificacion=$N_SINLEER;")"

# El aviso de otra cuenta: no se abre ni se marca.
R="$(crudo pac "$RUTA?accion=leer&id=$N_AJENO")"
chk "el aviso del médico no se abre: vuelve al listado con aviso" "si" \
    "$(case "${R#*|}" in *"accion=index"*"err="*) echo si;; *) echo "${R#*|}";; esac)"
chk "y sigue sin leer para su dueño" "0" \
    "$(my "SELECT leida_en IS NOT NULL FROM notificacion WHERE id_notificacion=$N_AJENO;")"

chk "un id inexistente tampoco rompe" "302" "$(crudo pac "$RUTA?accion=leer&id=99999999" | cut -d'|' -f1)"

# =============================================================
echo
echo "-- REDIRECCIÓN ABIERTA ------------------------------"
# url_accion la escribe el propio sistema, así que hoy no puede traer
# esto. Se prueba igual: el valor termina en una cabecera Location, y el
# control tiene que cubrir al código que todavía no se escribió.
probar_destino() {  # probar_destino "lo guardado" "descripción"
    $MYSQL -e "UPDATE notificacion SET url_accion='$1', leida_en=NULL
               WHERE id_notificacion=$N_SINLEER;"
    local r
    r="$(crudo pac "$RUTA?accion=leer&id=$N_SINLEER")"
    local destino="${r#*|}"
    case "$destino" in
        /mediturnos/dashboard.php) OK=$((OK+1)); echo "[OK]    $2 → va al panel";;
        *) MAL=$((MAL+1)); echo "[MAL]   $2 → redirige a: $destino";;
    esac
}
probar_destino 'https://sitio-ajeno.example/phishing' "una URL con esquema"
probar_destino '//sitio-ajeno.example/phishing'       "un //host sin esquema"
probar_destino 'javascript:alert(1)'                  "un javascript:"
probar_destino 'data:text/html,<script>alert(1)</script>' "un data:"
probar_destino '\\\\sitio-ajeno.example\\phishing'    "una ruta con barras invertidas"
probar_destino 'dashboard.php
Set-Cookie: robada=1'                                 "un salto de línea (inyección de cabecera)"

# Y el caso normal sigue funcionando.
$MYSQL -e "UPDATE notificacion SET url_accion='historial.php', leida_en=NULL
           WHERE id_notificacion=$N_SINLEER;"
R="$(crudo pac "$RUTA?accion=leer&id=$N_SINLEER")"
chk "una ruta interna normal sí se respeta" "/mediturnos/historial.php" "${R#*|}"

# =============================================================
echo
echo "-- MARCAR TODAS -------------------------------------"
$MYSQL -e "UPDATE notificacion SET leida_en=NULL WHERE titulo LIKE 'PRUEBA-E6%';"
# Dos defensas distintas, y las dos hacen falta:
#   · exigir POST frena una etiqueta <img> apuntada a esta dirección;
#   · exigir el token frena un formulario preparado en otro sitio.
# Se prueban por separado porque fallan distinto.
R="$(crudo pac "$RUTA?accion=leerTodas")"
chk "por GET: rechazado con 405" "405" "${R%%|*}"
R="$(post pac "$RUTA?accion=leerTodas" "relleno=1")"
chk "POST sin token: rechazado con 403" "403" "${R%%|*}"
chk "y siguen sin leer"           "2" \
    "$(my "SELECT COUNT(*) FROM notificacion WHERE id_usuario=$PAC_ID AND leida_en IS NULL AND titulo LIKE 'PRUEBA-E6%';")"

T="$(token pac "$RUTA?accion=index")"
R="$(post pac "$RUTA?accion=leerTodas" "csrf_token=$T")"
chk "con token: funciona" "si" \
    "$(case "${R#*|}" in *"msg=leidas"*) echo si;; *) echo "${R#*|}";; esac)"
chk "las dos del paciente quedaron leídas" "0" \
    "$(my "SELECT COUNT(*) FROM notificacion WHERE id_usuario=$PAC_ID AND leida_en IS NULL;")"
chk "la del médico NO se tocó" "0" \
    "$(my "SELECT leida_en IS NOT NULL FROM notificacion WHERE id_notificacion=$N_AJENO;")"

# =============================================================
echo
echo "-- ELIMINAR -----------------------------------------"
R="$(crudo pac "$RUTA?accion=eliminar&id=$N_LEIDO")"
chk "borrar por GET: rechazado con 405" "405" "${R%%|*}"
R="$(post pac "$RUTA?accion=eliminar" "id=$N_LEIDO")"
chk "POST sin token: rechazado con 403" "403" "${R%%|*}"
chk "y el aviso sigue ahí"        "1" \
    "$(my "SELECT COUNT(*) FROM notificacion WHERE id_notificacion=$N_LEIDO;")"

T="$(token pac "$RUTA?accion=index")"
# El aviso del MÉDICO, con la sesión del paciente: el DELETE lleva el
# dueño en el WHERE, así que no borra nada.
R="$(post pac "$RUTA?accion=eliminar" "csrf_token=$T" "id=$N_AJENO")"
chk "no se puede borrar el aviso de otra cuenta" "si" \
    "$(case "${R#*|}" in *"err="*) echo si;; *) echo "${R#*|}";; esac)"
chk "y el del médico sigue existiendo" "1" \
    "$(my "SELECT COUNT(*) FROM notificacion WHERE id_notificacion=$N_AJENO;")"

R="$(post pac "$RUTA?accion=eliminar" "csrf_token=$T" "id=$N_LEIDO")"
chk "el propio sí se borra" "si" \
    "$(case "${R#*|}" in *"msg=borrada"*) echo si;; *) echo "${R#*|}";; esac)"
chk "y desapareció de la base" "0" \
    "$(my "SELECT COUNT(*) FROM notificacion WHERE id_notificacion=$N_LEIDO;")"

echo
echo "-- VACIAR LAS LEÍDAS --------------------------------"
$MYSQL -e "
INSERT INTO notificacion (id_usuario, tipo, titulo, mensaje, leida_en)
VALUES ($PAC_ID, 'turno_cancelado', 'PRUEBA-E6 leido A', 'a', NOW()),
       ($PAC_ID, 'turno_cancelado', 'PRUEBA-E6 leido B', 'b', NOW());
UPDATE notificacion SET leida_en = NULL WHERE id_notificacion = $N_SINLEER;"
T="$(token pac "$RUTA?accion=index")"
# 🚨 Esta es la que estaba abierta: un GET sin token borraba los
# avisos leídos. Comprobado contra el servidor local: devolvía 302
# y borraba tres notificaciones.
R="$(crudo pac "$RUTA?accion=eliminarLeidas")"
chk "vaciar por GET: rechazado con 405" "405" "${R%%|*}"
chk "y NO borró nada" "2" \
    "$(my "SELECT COUNT(*) FROM notificacion WHERE id_usuario=$PAC_ID AND leida_en IS NOT NULL;")"

R="$(post pac "$RUTA?accion=eliminarLeidas" "csrf_token=$T")"
chk "vaciar las leídas funciona" "si" \
    "$(case "${R#*|}" in *"msg=limpiada"*) echo si;; *) echo "${R#*|}";; esac)"
chk "no quedó ninguna leída"  "0" \
    "$(my "SELECT COUNT(*) FROM notificacion WHERE id_usuario=$PAC_ID AND leida_en IS NOT NULL;")"
chk "pero la SIN LEER sobrevivió" "1" \
    "$(my "SELECT COUNT(*) FROM notificacion WHERE id_notificacion=$N_SINLEER;")"

# =============================================================
echo
echo "-- EL CAMPANITA -------------------------------------"
get pac dashboard.php >/dev/null
tiene "aparece en el panel"       "$TMP/body" "topbar-campana"
tiene "con el globo del contador" "$TMP/body" "topbar-campana-globo"
tiene "y el enlace en el menú"    "$TMP/body" "ControladorNotificacion.php?accion=index"

$MYSQL -e "UPDATE notificacion SET leida_en=NOW() WHERE id_usuario=$PAC_ID;"
get pac dashboard.php >/dev/null
tiene    "sin avisos pendientes el campanita sigue" "$TMP/body" "topbar-campana"
no_tiene "pero el globo desaparece"                 "$TMP/body" "topbar-campana-globo"

get med dashboard.php >/dev/null
tiene "el médico también lo tiene" "$TMP/body" "topbar-campana"

# =============================================================
echo
echo "-- ESCAPADO -----------------------------------------"
$MYSQL -e "
INSERT INTO notificacion (id_usuario, tipo, titulo, mensaje, url_accion)
VALUES ($PAC_ID, 'mensaje_medico', 'PRUEBA-E6 <script>alert(1)</script>',
        '<img src=x onerror=alert(2)>', 'historial.php');"
get pac "$RUTA?accion=index" >/dev/null
no_tiene "el <script> del título no sale crudo" "$TMP/body" "<script>alert(1)</script>"
no_tiene "ni el onerror del mensaje"            "$TMP/body" "<img src=x onerror"
tiene    "salen escapados"                      "$TMP/body" "&lt;script&gt;"

# =============================================================
echo
echo "-- SIN SESIÓN ---------------------------------------"
C="$(curl -s -L -o "$TMP/body" -w '%{http_code}' "$SITIO/$RUTA?accion=index")"
tiene "el centro sin sesión manda al login" "$TMP/body" "Iniciar sesión"
chk "la tarea por línea de comandos no se sirve por la web" "403" \
    "$(curl -s -o /dev/null -w '%{http_code}' "$SITIO/tareas/ejecutar.php")"

#!/usr/bin/env bash
# =============================================================
# pruebas/receta_http.sh — El circuito de recetas por HTTP
# =============================================================
# Verifica la etapa 5 como la usa una persona: entrando al sitio,
# mandando los formularios y, sobre todo, intentando lo que NO debería
# poder hacer. Lo que el guión del modelo no puede probar —CSRF, roles,
# IDOR, escapado en el HTML— se prueba acá.
#
# Necesita Apache y MySQL levantados. Se corre desde Git Bash:
#
#     bash pruebas/receta_http.sh
#
# ── SOBRE LAS CONTRASEÑAS ────────────────────────────────────
# Sólo `cfernandez` conserva la del seed. A las otras dos cuentas que
# hace falta usar se les pone una temporal y se les RESTAURA el hash
# original al terminar, pase lo que pase (trap EXIT). El guión verifica
# al final que quedaron como estaban.
# =============================================================
set -uo pipefail

SITIO="http://localhost/mediturnos"
# --default-character-set=utf8mb4 NO es decorativo: sin él el cliente de
# MySQL en Windows habla la página de códigos de la consola, y un texto
# con acentos vuelve distinto de como se guardó. Las comparaciones de
# este guión fallaban por eso, no por un error del sistema.
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root -N -B --default-character-set=utf8mb4 mediturnos"
PHP="/c/xampp/php/php.exe"
TMP="$(mktemp -d)"

OK=0; MAL=0

# ── Consultar la base ────────────────────────────────────────
# Envoltorio en vez de llamar a $MYSQL suelto, por dos motivos que ya
# costaron un rato de depuración:
#
#   · El cliente de Windows devuelve los valores con CR al final. La
#     sustitución $( ) saca los saltos de línea pero NO el CR, así que
#     "1" y "1\r" se comparan como distintos: la falla parece del
#     sistema cuando en realidad es del guión.
#   · Si el motor se cae a mitad de la corrida —ya pasó— cada consulta
#     devuelve el texto del error y las comprobaciones empiezan a
#     fallar una tras otra sin decir por qué. Acá se corta de una vez,
#     con el error a la vista.
my() {
    local salida
    if ! salida="$($MYSQL -e "$1" 2>&1)"; then
        echo; echo "!! La base no responde. Se corta la prueba:"; echo "$salida"
        exit 1
    fi
    printf %s "$salida" | tr -d '\r'
}

chk() {  # chk "descripción" esperado real
    if [ "$2" = "$3" ]; then
        OK=$((OK+1)); printf '[OK]    %s\n' "$1"
    else
        MAL=$((MAL+1)); printf '[MAL]   %s\n          esperado: %s\n          real:     %s\n' "$1" "$2" "$3"
    fi
}
chk_tiene() {  # chk_tiene "descripción" archivo "texto"
    if grep -qF -- "$3" "$2"; then OK=$((OK+1)); printf '[OK]    %s\n' "$1"
    else MAL=$((MAL+1)); printf '[MAL]   %s (no aparece: %s)\n' "$1" "$3"; fi
}
chk_no_tiene() {
    if grep -qF -- "$3" "$2"; then MAL=$((MAL+1)); printf '[MAL]   %s (aparece y no debía: %s)\n' "$1" "$3"
    else OK=$((OK+1)); printf '[OK]    %s\n' "$1"; fi
}

# ── El servidor tiene que estar vivo ─────────────────────────
# Si Apache se cae a mitad de la corrida —en este XAMPP pasa— todas las
# peticiones devuelven el código 000 y todas las comprobaciones empiezan
# a fallar. Ochenta fallas que parecen del sistema y son del servidor
# apagado. Se verifica antes de empezar, y cada petición vuelve a
# mirarlo: a la primera caída se corta, con el motivo a la vista.
vivo() {
    local c
    c="$(curl -s -o /dev/null -m 10 -w '%{http_code}' "$SITIO/login.php")"
    if [ "$c" != "200" ]; then
        echo; echo "!! Apache no responde (login.php devolvió $c). Se corta la prueba."
        exit 1
    fi
}
# ── Peticiones ───────────────────────────────────────────────
# Sin -L: lo que interesa de un POST es su redirección, que es cómo este
# proyecto comunica el resultado (?msg= / ?err=).
sesion() { echo "$TMP/cookies_$1"; }

get() {   # get sesion ruta  -> cuerpo en $TMP/body, imprime el código
    local c
    c="$(curl -s -L -m 20 -b "$(sesion "$1")" -c "$(sesion "$1")" \
         -o "$TMP/body" -w '%{http_code}' "$SITIO/$2")"
    [ "$c" = "000" ] && { echo; echo "!! Apache dejó de responder. Se corta la prueba."; exit 1; }
    echo "$c"
}
# Percent-encoda TODOS los bytes del valor. Queda ASCII puro, así que
# Windows ya no tiene nada que convertir entre bash y curl.exe.
#
# Escapar todos los bytes —y no sólo los "no seguros"— es válido en
# application/x-www-form-urlencoded y evita tener que decidir cuáles son
# seguros, que es justo donde se cometen los errores.
pct() { printf %s "$1" | od -An -tx1 -v | tr -d ' \n' | sed 's/../%&/g'; }

post() {  # post sesion ruta datos... -> imprime "codigo|location"
    local s="$1" ruta="$2"; shift 2
    local args=() nombre valor
    for d in "$@"; do
        nombre="${d%%=*}"; valor="${d#*=}"
        # --data y no --data-urlencode: el valor ya viene encodado, y
        # encodarlo dos veces convertiría cada % en %25.
        args+=(--data "$(pct "$nombre")=$(pct "$valor")")
    done
    curl -s -b "$(sesion "$s")" -c "$(sesion "$s")" \
         -o "$TMP/body" -D "$TMP/head" "${args[@]}" "$SITIO/$ruta" \
         -w '%{http_code}' >"$TMP/code"
    local loc
    loc="$(grep -i '^location:' "$TMP/head" | tr -d '\r' | sed 's/^[Ll]ocation: *//')"
    echo "$(cat "$TMP/code")|$loc"
}
token() {  # token sesion ruta -> el csrf_token del formulario
    get "$1" "$2" >/dev/null
    grep -o 'name="csrf_token" value="[a-f0-9]*"' "$TMP/body" \
        | head -1 | sed 's/.*value="//; s/"//'
}
login() {  # login sesion usuario clave
    rm -f "$(sesion "$1")"
    curl -s -c "$(sesion "$1")" -o /dev/null "$SITIO/login.php"
    curl -s -b "$(sesion "$1")" -c "$(sesion "$1")" -o /dev/null \
         --data "usuario=$(pct "$2")" --data "contrasenia=$(pct "$3")" \
         "$SITIO/login.php"
}

# =============================================================
# Datos de trabajo
# =============================================================
CLAVE='Prueba.Etapa5'
HASH="$($PHP -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$CLAVE")"

# Un turno Realizado de cfernandez cuyo paciente tenga cuenta activa, y
# que NO sea la cuenta personal del dueño del proyecto.
read -r TURNO PACIENTE PAC_USER PAC_ID <<<"$(
$MYSQL -e "
SELECT t.id_turno, t.id_paciente, up.usuario, up.id_usuario
FROM turno t
JOIN estado_turno e ON e.id_estado=t.id_estado AND e.descripcion='Realizado'
JOIN usuario up ON up.id_paciente=t.id_paciente AND up.estado='activo'
WHERE t.matricula=10001 AND up.usuario <> 'laila'
ORDER BY t.id_turno LIMIT 1;")"

# Un turno de OTRO médico, para probar que cfernandez no puede tocarlo.
TURNO_AJENO="$(my "
SELECT t.id_turno FROM turno t
JOIN estado_turno e ON e.id_estado=t.id_estado AND e.descripcion='Realizado'
WHERE t.matricula <> 10001 ORDER BY t.id_turno LIMIT 1;")"

# Otro paciente con cuenta, para los intentos cruzados.
read -r OTRO_PAC_USER OTRO_PAC_ID <<<"$(
$MYSQL -e "
SELECT u.usuario, u.id_usuario FROM usuario u
JOIN rol r ON r.id_rol=u.id_rol
WHERE r.nombre='paciente' AND u.estado='activo'
  AND u.id_paciente IS NOT NULL AND u.id_paciente <> $PACIENTE
  AND u.usuario <> 'laila'
ORDER BY u.id_usuario LIMIT 1;")"

# Otro médico con cuenta activa.
read -r OTRO_MED_USER OTRO_MED_ID OTRO_MED_MAT <<<"$(
$MYSQL -e "
SELECT u.usuario, u.id_usuario, u.matricula FROM usuario u
JOIN rol r ON r.id_rol=u.id_rol
JOIN medico m ON m.matricula=u.matricula AND m.estado='activo'
WHERE r.nombre='medico' AND u.estado='activo' AND u.matricula <> 10001
ORDER BY u.id_usuario LIMIT 1;")"

echo "turno=$TURNO paciente=$PACIENTE ($PAC_USER)  turno_ajeno=$TURNO_AJENO"
echo "otro_paciente=$OTRO_PAC_USER  otro_medico=$OTRO_MED_USER (mat $OTRO_MED_MAT)"
echo "================================================================"
vivo

# ── Contraseñas temporales, con restauración garantizada ──────
HASH_PAC="$(my "SELECT contrasenia FROM usuario WHERE id_usuario=$PAC_ID;")"
HASH_OTRO_PAC="$(my "SELECT contrasenia FROM usuario WHERE id_usuario=$OTRO_PAC_ID;")"
HASH_OTRO_MED="$(my "SELECT contrasenia FROM usuario WHERE id_usuario=$OTRO_MED_ID;")"

restaurar() {
    echo
    echo "-- RESTAURACIÓN -------------------------------------"
    $MYSQL -e "
      UPDATE usuario SET contrasenia='$HASH_PAC'      WHERE id_usuario=$PAC_ID;
      UPDATE usuario SET contrasenia='$HASH_OTRO_PAC' WHERE id_usuario=$OTRO_PAC_ID;
      UPDATE usuario SET contrasenia='$HASH_OTRO_MED' WHERE id_usuario=$OTRO_MED_ID;"
    local a b c
    a="$(my "SELECT contrasenia='$HASH_PAC'      FROM usuario WHERE id_usuario=$PAC_ID;")"
    b="$(my "SELECT contrasenia='$HASH_OTRO_PAC' FROM usuario WHERE id_usuario=$OTRO_PAC_ID;")"
    c="$(my "SELECT contrasenia='$HASH_OTRO_MED' FROM usuario WHERE id_usuario=$OTRO_MED_ID;")"
    chk "contraseña de $PAC_USER restaurada"      "1" "$a"
    chk "contraseña de $OTRO_PAC_USER restaurada" "1" "$b"
    chk "contraseña de $OTRO_MED_USER restaurada" "1" "$c"
    # Las recetas creadas por la prueba se borran; el CASCADE se lleva
    # los medicamentos y las renovaciones.
    $MYSQL -e "DELETE FROM receta WHERE id_paciente=$PACIENTE;"
    # Y los avisos que esas recetas emitieron. Antes quedaban en la base
    # apuntando a filas que ya no existen: ciento nueve, al cabo de unas
    # cuantas corridas. El borrado de la receta no se los lleva porque
    # \`notificacion\` no tiene clave foránea hacia ella — y no debe
    # tenerla: un aviso sobrevive a lo que lo originó a propósito.
    $MYSQL -e "DELETE FROM notificacion
               WHERE id_usuario IN ($PAC_ID, $OTRO_PAC_ID, $OTRO_MED_ID)
                  OR id_usuario = (SELECT id_usuario FROM usuario WHERE usuario='cfernandez');"
    local quedan
    quedan="$(my "SELECT COUNT(*) FROM receta WHERE id_paciente=$PACIENTE;")"
    chk "recetas de prueba borradas" "0" "$quedan"
    rm -rf "$TMP"
    echo "================================================================"
    printf 'TOTAL: %d OK, %d MAL\n' "$OK" "$MAL"
    [ "$MAL" -eq 0 ] || exit 1
}
trap restaurar EXIT

$MYSQL -e "
  UPDATE usuario SET contrasenia='$HASH' WHERE id_usuario IN ($PAC_ID, $OTRO_PAC_ID, $OTRO_MED_ID);"

# Punto de partida limpio.
$MYSQL -e "DELETE FROM receta WHERE id_paciente=$PACIENTE;"

RUTA_R="sistema/controladores/ControladorReceta.php"

# =============================================================
echo
echo "-- SESIONES -----------------------------------------"
login med cfernandez password
chk "el médico entra"           "200" "$(get med dashboard.php)"
chk_tiene "y ve su panel" "$TMP/body" "Renovaciones"

login pac "$PAC_USER" "$CLAVE"
chk "el paciente entra"         "200" "$(get pac recetas.php)"
chk_tiene "y ve Mis recetas" "$TMP/body" "Mis recetas"
chk_tiene "con el estado vacío" "$TMP/body" "Todavía no tenés recetas"

login otropac "$OTRO_PAC_USER" "$CLAVE"
login otromed "$OTRO_MED_USER" "$CLAVE"

# =============================================================
echo
echo "-- ROLES --------------------------------------------"
chk "el paciente NO entra al formulario de emisión" "403" \
    "$(get pac "$RUTA_R?accion=nueva&turno=$TURNO")"
chk "el paciente NO entra a la bandeja del médico"  "403" \
    "$(get pac "$RUTA_R?accion=renovaciones")"
chk "el médico NO entra a recetas.php (es del paciente)" "403" \
    "$(get med recetas.php)"

# =============================================================
echo
echo "-- EMITIR -------------------------------------------"
chk "el médico abre el formulario" "200" "$(get med "$RUTA_R?accion=nueva&turno=$TURNO")"
chk_tiene "con los renglones de medicamentos" "$TMP/body" 'name="med_nombre[]"'
chk_tiene "y el botón de agregar oculto hasta que corra el script" "$TMP/body" 'id="btnOtro"'

chk "turno de OTRO médico: 403" "403" "$(get med "$RUTA_R?accion=nueva&turno=$TURNO_AJENO")"

T="$(token med "$RUTA_R?accion=nueva&turno=$TURNO")"
chk_tiene "el formulario trae token CSRF" "$TMP/body" 'name="csrf_token"'

# Sin token: rechazado.
R="$(post med "$RUTA_R?accion=emitir" "id_turno=$TURNO" "med_nombre[]=X" "med_dosis[]=1" "med_frec[]=1")"
chk "POST emitir sin token CSRF" "403" "${R%%|*}"

# Receta sin ningún medicamento: vuelve con el error, no crea nada.
R="$(post med "$RUTA_R?accion=emitir" "csrf_token=$T" "id_turno=$TURNO" "med_nombre[]=" "med_dosis[]=" "med_frec[]=")"
case "${R#*|}" in *"accion=nueva"*"err="*) chk "receta sin medicamentos: vuelve con error" "si" "si";;
  *) chk "receta sin medicamentos: vuelve con error" "si" "${R#*|}";; esac
chk "y no se creó ninguna fila" "0" "$(my "SELECT COUNT(*) FROM receta WHERE id_paciente=$PACIENTE;")"

# Renglón a medias: tampoco.
R="$(post med "$RUTA_R?accion=emitir" "csrf_token=$T" "id_turno=$TURNO" \
     "med_nombre[]=Amoxicilina" "med_dosis[]=" "med_frec[]=")"
case "${R#*|}" in *"err="*) chk "renglón a medias: vuelve con error" "si" "si";;
  *) chk "renglón a medias: vuelve con error" "si" "${R#*|}";; esac
chk "y sigue sin crear nada" "0" "$(my "SELECT COUNT(*) FROM receta WHERE id_paciente=$PACIENTE;")"

# Un campo que llega como arreglo donde se espera texto: no puede ser un 500.
R="$(post med "$RUTA_R?accion=emitir" "csrf_token=$T" "id_turno=$TURNO" \
     "diagnostico[]=soy un arreglo" "med_nombre[]=Ibuprofeno" \
     "med_dosis[]=1 comprimido" "med_frec[]=cada 8 horas")"
chk "diagnostico como arreglo no rompe la página" "302" "${R%%|*}"
chk "y el campo opcional queda en NULL, no en basura" "1" \
    "$(my "SELECT diagnostico IS NULL FROM receta WHERE id_paciente=$PACIENTE ORDER BY id_receta DESC LIMIT 1;")"
# Esa receta era sólo para probar el arreglo: se borra para que los
# conteos de abajo cuenten lo que emite la prueba de verdad.
$MYSQL -e "DELETE FROM receta WHERE id_paciente=$PACIENTE;"

# La buena: dos medicamentos y un renglón vacío.
R="$(post med "$RUTA_R?accion=emitir" "csrf_token=$T" "id_turno=$TURNO" \
     "diagnostico=Lumbalgia aguda" "indicaciones=Tomar con las comidas, días alternos" "dias=30" \
     "med_nombre[]=Ibuprofeno"  "med_pres[]=comprimidos 400 mg" "med_dosis[]=1 comprimido" "med_frec[]=cada 8 horas"  "med_dur[]=por 7 días" "med_cant[]=2" \
     "med_nombre[]=Omeprazol"   "med_pres[]="                   "med_dosis[]=1 cápsula"   "med_frec[]=por la mañana" "med_dur[]="           "med_cant[]=1" \
     "med_nombre[]="            "med_pres[]="                   "med_dosis[]="            "med_frec[]="              "med_dur[]="           "med_cant[]=1")"
chk "emitir redirige" "302" "${R%%|*}"
RECETA="$(echo "${R#*|}" | sed -n 's/.*accion=ver&id=\([0-9]*\).*/\1/p')"
if [ -n "$RECETA" ]; then OK=$((OK+1)); echo "[OK]    la receta quedó con id $RECETA"
else MAL=$((MAL+1)); echo "[MAL]   no se pudo leer el id de la receta: ${R#*|}"; fi
chk "en la base hay 1 receta"        "1" "$(my "SELECT COUNT(*) FROM receta WHERE id_paciente=$PACIENTE;")"
chk "con 2 medicamentos"             "2" "$(my "SELECT COUNT(*) FROM receta_medicamento WHERE id_receta=$RECETA;")"
chk "el renglón vacío se descartó"   "2" "$(my "SELECT items FROM (SELECT COUNT(*) items FROM receta_medicamento WHERE id_receta=$RECETA) q;")"
chk "la firmó cfernandez"        "10001" "$(my "SELECT matricula FROM receta WHERE id_receta=$RECETA;")"
chk "cuelga del turno"         "$TURNO" "$(my "SELECT id_turno FROM receta WHERE id_receta=$RECETA;")"

# POST directo sobre un turno ajeno, sin pasar por ninguna pantalla.
T2="$(token med "$RUTA_R?accion=nueva&turno=$TURNO")"
R="$(post med "$RUTA_R?accion=emitir" "csrf_token=$T2" "id_turno=$TURNO_AJENO" \
     "med_nombre[]=X" "med_dosis[]=1" "med_frec[]=1")"
chk "emitir sobre un turno ajeno: 403" "403" "${R%%|*}"

# Un acento tiene que guardarse en UTF-8 (C3 AD = í). Se mira el
# HEX y no el texto porque el HEX es ASCII: es lo único que cruza
# el cliente de MySQL sin que Windows lo convierta.
chk "las indicaciones guardan el acento en UTF-8" "1" \
    "$(my "SELECT HEX(indicaciones) LIKE '%C3AD%' FROM receta WHERE id_receta=$RECETA;")"

# =============================================================
echo
echo "-- VER ----------------------------------------------"
chk "el médico que la firmó la ve" "200" "$(get med "$RUTA_R?accion=ver&id=$RECETA")"
chk_tiene "con los medicamentos"      "$TMP/body" "Ibuprofeno"
chk_tiene "y la dosis"                "$TMP/body" "cada 8 horas"
chk_tiene "y hasta cuándo vale"       "$TMP/body" "Válida hasta el"
chk_tiene "y la aclaración legal"     "$TMP/body" "No reemplaza a una"
chk_tiene "con el botón de anular"    "$TMP/body" "Anular"

chk "el paciente dueño la ve" "200" "$(get pac "$RUTA_R?accion=ver&id=$RECETA")"
chk_tiene "y puede pedir la renovación" "$TMP/body" "Pedir renovación"
chk_no_tiene "pero NO puede anularla"   "$TMP/body" 'value="rechazar"'

chk "OTRO paciente: 403" "403" "$(get otropac "$RUTA_R?accion=ver&id=$RECETA")"

# El otro médico no atendió a este paciente con esta cuenta: depende de
# si tiene turnos realizados con él, así que se verifica el criterio en
# vez de un número fijo.
ATENDIO="$(my "
SELECT COUNT(*) FROM turno t JOIN estado_turno e ON e.id_estado=t.id_estado
WHERE t.matricula=$OTRO_MED_MAT AND t.id_paciente=$PACIENTE AND e.descripcion='Realizado';")"
CODIGO_OTRO="$(get otromed "$RUTA_R?accion=ver&id=$RECETA")"
if [ "$ATENDIO" -gt 0 ]; then
    chk "otro médico que SÍ lo atendió, la ve" "200" "$CODIGO_OTRO"
else
    chk "otro médico que NO lo atendió: 403"   "403" "$CODIGO_OTRO"
fi

chk "receta inexistente: vuelve al panel" "200" "$(get med "$RUTA_R?accion=ver&id=99999999")"
chk_tiene "con el aviso correspondiente" "$TMP/body" "No encontramos esa receta"

# =============================================================
echo
echo "-- EL PACIENTE PIDE LA RENOVACIÓN -------------------"
get pac recetas.php >/dev/null
chk_tiene "la receta aparece en su listado"  "$TMP/body" "Ibuprofeno"
chk_tiene "con el diagnóstico"               "$TMP/body" "Lumbalgia aguda"
chk_tiene "y el botón de pedir renovación"   "$TMP/body" "Pedir renovación"

TP="$(token pac recetas.php)"
R="$(post pac "$RUTA_R?accion=solicitar" "id_receta=$RECETA")"
chk "pedir sin token CSRF" "403" "${R%%|*}"

R="$(post pac "$RUTA_R?accion=solicitar" "csrf_token=$TP" "id_receta=$RECETA" "motivo=Se me termina")"
case "${R#*|}" in *"msg=pedida"*) chk "el pedido se registra" "si" "si";;
  *) chk "el pedido se registra" "si" "${R#*|}";; esac
chk "hay 1 pedido pendiente" "1" \
    "$(my "SELECT COUNT(*) FROM renovacion_receta WHERE id_receta=$RECETA AND estado='Pendiente';")"

# Segundo pedido de la misma receta: lo frena el UNIQUE del motor.
R="$(post pac "$RUTA_R?accion=solicitar" "csrf_token=$TP" "id_receta=$RECETA" "motivo=otra vez")"
case "${R#*|}" in *"err="*) chk "el segundo pedido se rechaza" "si" "si";;
  *) chk "el segundo pedido se rechaza" "si" "${R#*|}";; esac
chk "y sigue habiendo uno solo" "1" \
    "$(my "SELECT COUNT(*) FROM renovacion_receta WHERE id_receta=$RECETA;")"

# Otro paciente pidiendo la renovación de una receta ajena.
TOP="$(token otropac recetas.php)"
R="$(post otropac "$RUTA_R?accion=solicitar" "csrf_token=$TOP" "id_receta=$RECETA")"
chk "otro paciente no puede pedirla: 403" "403" "${R%%|*}"
chk "y no creó ningún pedido" "1" \
    "$(my "SELECT COUNT(*) FROM renovacion_receta WHERE id_receta=$RECETA;")"

get pac recetas.php >/dev/null
chk_tiene "el paciente ve su pedido pendiente" "$TMP/body" "Esperando respuesta"
chk_no_tiene "y ya no se le ofrece pedir otra" "$TMP/body" "Pedir renovación"

# =============================================================
echo
echo "-- EL MÉDICO RESPONDE ------------------------------"
chk "la bandeja abre" "200" "$(get med "$RUTA_R?accion=renovaciones")"
chk_tiene "con el pedido"         "$TMP/body" "Se me termina"
chk_tiene "y los medicamentos"    "$TMP/body" "Ibuprofeno"

RENOV="$(my "SELECT id_renovacion FROM renovacion_receta WHERE id_receta=$RECETA;")"

chk "la bandeja del OTRO médico abre" "200" "$(get otromed "$RUTA_R?accion=renovaciones")"
chk_no_tiene "y NO muestra este pedido" "$TMP/body" "Se me termina"

TOM="$(token otromed "$RUTA_R?accion=renovaciones")"
if [ -z "$TOM" ]; then TOM="$(token otromed dashboard.php)"; fi
R="$(post otromed "$RUTA_R?accion=resolver" "csrf_token=$TOM" "id_renovacion=$RENOV" "decision=aprobar")"
chk "el otro médico no puede resolverlo: 403" "403" "${R%%|*}"
chk "y el pedido sigue pendiente" "Pendiente" \
    "$(my "SELECT estado FROM renovacion_receta WHERE id_renovacion=$RENOV;")"

TM="$(token med "$RUTA_R?accion=renovaciones")"
R="$(post med "$RUTA_R?accion=resolver" "id_renovacion=$RENOV" "decision=aprobar")"
chk "resolver sin token CSRF" "403" "${R%%|*}"

R="$(post med "$RUTA_R?accion=resolver" "csrf_token=$TM" "id_renovacion=$RENOV" \
     "decision=aprobar" "respuesta=Renovada por 30 dias")"
case "${R#*|}" in *"msg=aprobada"*) chk "aprobar funciona" "si" "si";;
  *) chk "aprobar funciona" "si" "${R#*|}";; esac
chk "el pedido quedó Aprobada" "Aprobada" \
    "$(my "SELECT estado FROM renovacion_receta WHERE id_renovacion=$RENOV;")"
NUEVA="$(my "SELECT id_receta_nueva FROM renovacion_receta WHERE id_renovacion=$RENOV;")"
chk "se emitió una receta nueva" "2" "$(my "SELECT COUNT(*) FROM receta WHERE id_paciente=$PACIENTE;")"
chk "con los mismos 2 medicamentos" "2" "$(my "SELECT COUNT(*) FROM receta_medicamento WHERE id_receta=$NUEVA;")"
chk "y la vieja no se modificó" "Vigente" \
    "$(my "SELECT estado FROM receta WHERE id_receta=$RECETA;")"

# Resolver dos veces.
R="$(post med "$RUTA_R?accion=resolver" "csrf_token=$TM" "id_renovacion=$RENOV" "decision=aprobar")"
case "${R#*|}" in *"err="*) chk "no se puede resolver dos veces" "si" "si";;
  *) chk "no se puede resolver dos veces" "si" "${R#*|}";; esac
chk "y NO se emitió una tercera receta" "2" \
    "$(my "SELECT COUNT(*) FROM receta WHERE id_paciente=$PACIENTE;")"

# La comparación va DENTRO de SQL: el cliente de MySQL en Windows
# devuelve el texto en la página de códigos de la consola, así que
# un acento vuelve con otros bytes y la igualdad fallaría del lado
# de bash aunque la base lo haya guardado perfecto. Sólo cruza el 1.
chk "la respuesta del médico quedó guardada" "Renovada por 30 dias" \
    "$(my "SELECT respuesta FROM renovacion_receta WHERE id_renovacion=$RENOV;")"
get pac recetas.php >/dev/null
chk_tiene "el paciente ve la respuesta" "$TMP/body" "Renovada por 30 dias"
chk_tiene "y el pedido como aprobado"   "$TMP/body" "Aprobada"

# =============================================================
echo
echo "-- ANULAR -------------------------------------------"
TOM2="$(token otromed dashboard.php)"
R="$(post otromed "$RUTA_R?accion=anular" "csrf_token=$TOM2" "id_receta=$RECETA" "motivo=no es mía")"
chk "otro médico no puede anularla: 403" "403" "${R%%|*}"
chk "sigue Vigente" "Vigente" "$(my "SELECT estado FROM receta WHERE id_receta=$RECETA;")"

TA="$(token med "$RUTA_R?accion=ver&id=$RECETA")"
R="$(post med "$RUTA_R?accion=anular" "csrf_token=$TA" "id_receta=$RECETA" "motivo=Error de carga")"
case "${R#*|}" in *"msg=anulada"*) chk "el que la firmó la anula" "si" "si";;
  *) chk "el que la firmó la anula" "si" "${R#*|}";; esac
chk "quedó Anulada" "Anulada" "$(my "SELECT estado FROM receta WHERE id_receta=$RECETA;")"
chk "con el motivo guardado" "Error de carga" \
    "$(my "SELECT anulada_motivo FROM receta WHERE id_receta=$RECETA;")"
chk "la fila NO se borró" "1" "$(my "SELECT COUNT(*) FROM receta WHERE id_receta=$RECETA;")"

get pac "$RUTA_R?accion=ver&id=$RECETA" >/dev/null
chk_tiene "el paciente ve que fue anulada" "$TMP/body" "Error de carga"
chk_no_tiene "y no puede pedir su renovación" "$TMP/body" "Pedir renovación"

# =============================================================
echo
echo "-- ESCAPADO Y BUSCADOR -----------------------------"
TX="$(token med "$RUTA_R?accion=nueva&turno=$TURNO")"
R="$(post med "$RUTA_R?accion=emitir" "csrf_token=$TX" "id_turno=$TURNO" \
     'diagnostico=<script>alert(1)</script>' \
     'med_nombre[]=<img src=x onerror=alert(2)>' "med_dosis[]=1" "med_frec[]=1")"
XSS="$(echo "${R#*|}" | sed -n 's/.*accion=ver&id=\([0-9]*\).*/\1/p')"
get med "$RUTA_R?accion=ver&id=$XSS" >/dev/null
chk_no_tiene "el <script> no sale crudo"      "$TMP/body" "<script>alert(1)</script>"
chk_no_tiene "el onerror tampoco"             "$TMP/body" "<img src=x onerror"
chk_tiene    "sale escapado"                  "$TMP/body" "&lt;script&gt;"
get pac recetas.php >/dev/null
chk_no_tiene "ni en el listado del paciente"  "$TMP/body" "<img src=x onerror"

# El buscador no toma los comodines de LIKE como comodines.
get pac "recetas.php?q=%25" >/dev/null
chk_tiene "buscar '%' no devuelve todo" "$TMP/body" "Sin resultados"
get pac "recetas.php?q=_" >/dev/null
chk_tiene "buscar '_' no devuelve todo" "$TMP/body" "Sin resultados"
get pac "recetas.php?q=Ibuprofeno" >/dev/null
chk_tiene "y una búsqueda real sí encuentra" "$TMP/body" "Ibuprofeno"

# Filtros con valores inventados: no rompen ni filtran de más.
chk "situacion inventada no rompe" "200" "$(get pac "recetas.php?situacion=Inventada")"
chk "situacion como arreglo no rompe" "200" "$(get pac "recetas.php?situacion[]=x")"
chk "q como arreglo no rompe" "200" "$(get pac "recetas.php?q[]=x")"

# =============================================================
echo
echo "-- SIN SESIÓN ---------------------------------------"
rm -f "$TMP/cookies_anon"
C="$(curl -s -L -o "$TMP/body" -w '%{http_code}' "$SITIO/recetas.php")"
chk_tiene "recetas.php sin sesión manda al login" "$TMP/body" "Iniciar sesión"
C="$(curl -s -L -o "$TMP/body" -w '%{http_code}' "$SITIO/$RUTA_R?accion=ver&id=$RECETA")"
chk_tiene "ver una receta sin sesión manda al login" "$TMP/body" "Iniciar sesión"

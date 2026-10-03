#!/usr/bin/env bash
# =============================================================
# pruebas/humo.sh — Ninguna pantalla rota, en ningún rol
# =============================================================
# Entra como administrador, médico y paciente y abre todas las pantallas
# del sistema. No verifica reglas de negocio —para eso están los otros
# dos guiones—: verifica que ninguna devuelva un código inesperado NI
# imprima un aviso de PHP.
#
# Lo segundo es el punto. Un Warning o un Notice no cambia el código de
# respuesta: la página devuelve 200 con el aviso escrito arriba del
# contenido, y así se queda hasta que alguien lo ve de casualidad. Un
# `$_GET` que llega como arreglo donde se esperaba texto es justamente
# eso, y por eso están entre las comprobaciones.
#
# Necesita Apache y MySQL levantados. Se corre desde Git Bash:
#
#     bash pruebas/humo.sh
#
# Al paciente de prueba se le pone una contraseña temporal y se le
# restaura la original al terminar; el guión lo verifica.
# =============================================================
SITIO="http://localhost/mediturnos"
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root -N -B mediturnos"
TMP=$(mktemp -d); OK=0; MAL=0
pct() { printf %s "$1" | od -An -tx1 -v | tr -d ' \n' | sed 's/../%&/g'; }
entrar() { rm -f $TMP/$1; curl -s -c $TMP/$1 -o /dev/null "$SITIO/login.php"
  curl -s -b $TMP/$1 -c $TMP/$1 -o /dev/null --data "usuario=$(pct "$2")" --data "contrasenia=$(pct "$3")" "$SITIO/login.php"; }
ver() { C=$(curl -s -L -b $TMP/$1 -o $TMP/body -w '%{http_code}' "$SITIO/$2")
  if [ "$C" = "$3" ] && ! grep -qiE "Fatal error|Warning:|Notice:|Deprecated:" $TMP/body; then
    OK=$((OK+1)); echo "[OK]    $4"
  else MAL=$((MAL+1)); echo "[MAL]   $4 (código $C)"; grep -oiE "(Fatal error|Warning|Notice|Deprecated)[^<]{0,120}" $TMP/body | head -2; fi; }

entrar adm admin password
ver adm dashboard.php 200 "panel del administrador"
ver adm "dashboard.php?err=probando+el+aviso" 200 "panel del admin con ?err="
grep -q "probando el aviso" $TMP/body && { OK=$((OK+1)); echo "[OK]    y el aviso se muestra"; } || { MAL=$((MAL+1)); echo "[MAL]   el aviso NO se muestra"; }
ver adm "dashboard.php?err[]=x" 200 "?err como arreglo no rompe"
ver adm "sistema/controladores/ControladorTurno.php?accion=index" 200 "listado de turnos"
ver adm "sistema/controladores/ControladorPago.php?accion=index" 200 "listado de pagos"

entrar med cfernandez password
ver med dashboard.php 200 "panel del médico"
grep -q "Renovaciones" $TMP/body && { OK=$((OK+1)); echo "[OK]    con la tarjeta de renovaciones"; } || { MAL=$((MAL+1)); echo "[MAL]   sin la tarjeta de renovaciones"; }
ver med "dashboard.php?err=aviso+para+el+medico" 200 "panel del médico con ?err="
grep -q "aviso para el medico" $TMP/body && { OK=$((OK+1)); echo "[OK]    y el aviso llega al médico (antes se perdía)"; } || { MAL=$((MAL+1)); echo "[MAL]   el aviso NO llega al médico"; }
T=$($MYSQL -e "SELECT t.id_turno FROM turno t JOIN estado_turno e ON e.id_estado=t.id_estado AND e.descripcion='Realizado' WHERE t.matricula=10001 ORDER BY t.id_turno LIMIT 1;" | tr -d '\r')
ver med "sistema/controladores/ControladorHistorial.php?accion=consulta&id=$T" 200 "ficha clínica (turno $T)"
grep -q "Emitir receta" $TMP/body && { OK=$((OK+1)); echo "[OK]    con el botón de emitir receta"; } || { MAL=$((MAL+1)); echo "[MAL]   sin el botón de emitir receta"; }
ver med historial.php 403 "el médico no entra al historial del paciente"
ver med "sistema/controladores/ControladorReceta.php?accion=renovaciones" 200 "bandeja de renovaciones vacía"

PAC=$($MYSQL -e "SELECT u.usuario FROM usuario u JOIN rol r ON r.id_rol=u.id_rol WHERE r.nombre='paciente' AND u.estado='activo' AND u.id_paciente IS NOT NULL AND u.usuario<>'laila' ORDER BY u.id_usuario LIMIT 1;" | tr -d '\r')
PID=$($MYSQL -e "SELECT id_usuario FROM usuario WHERE usuario='$PAC';" | tr -d '\r')
HOLD=$($MYSQL -e "SELECT contrasenia FROM usuario WHERE id_usuario=$PID;" | tr -d '\r')
H=$(/c/xampp/php/php.exe -r 'echo password_hash("Humo.2026", PASSWORD_DEFAULT);')
$MYSQL -e "UPDATE usuario SET contrasenia='$H' WHERE id_usuario=$PID;"
entrar pac "$PAC" "Humo.2026"
ver pac dashboard.php 200 "panel del paciente"
grep -q "Mis recetas" $TMP/body && { OK=$((OK+1)); echo "[OK]    con el acceso a Mis recetas"; } || { MAL=$((MAL+1)); echo "[MAL]   sin el acceso a Mis recetas"; }
ver pac "dashboard.php?msg=cancelado" 200 "panel del paciente con ?msg="
grep -q "Tu turno fue cancelado" $TMP/body && { OK=$((OK+1)); echo "[OK]    y el aviso de éxito sigue funcionando"; } || { MAL=$((MAL+1)); echo "[MAL]   el aviso de éxito se rompió"; }
ver pac "dashboard.php?msg[]=x" 200 "?msg como arreglo no rompe"
ver pac recetas.php 200 "mis recetas"
ver pac historial.php 200 "mi historial"
ver pac agendar.php 200 "agendar"
ver pac perfil.php 200 "mi perfil"
$MYSQL -e "UPDATE usuario SET contrasenia='$HOLD' WHERE id_usuario=$PID;"
R=$($MYSQL -e "SELECT contrasenia='$HOLD' FROM usuario WHERE id_usuario=$PID;" | tr -d '\r')
[ "$R" = "1" ] && { OK=$((OK+1)); echo "[OK]    contraseña de $PAC restaurada"; } || { MAL=$((MAL+1)); echo "[MAL]   contraseña NO restaurada"; }
rm -rf $TMP
echo "-------------------------------------"
printf 'HUMO: %d OK, %d MAL\n' $OK $MAL

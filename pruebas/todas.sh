#!/usr/bin/env bash
# =============================================================
# pruebas/todas.sh — Correr todo
# =============================================================
#     bash pruebas/todas.sh
#
# Corre los siete guiones en orden y termina con un resumen. Devuelve 0
# si todos pasaron y 1 si alguno falló, así que sirve para decidir si un
# cambio se puede mergear.
#
# ── POR QUÉ EXISTE ───────────────────────────────────────────
# Siete comandos que hay que acordarse de correr son siete comandos que
# un día se corren a medias. Y los guiones de PHP y los de bash se
# invocan distinto, lo que es una razón más para no tener que recordarlo.
#
# No corre en paralelo a propósito: todos escriben en la misma base de
# desarrollo, y dos a la vez se pisarían los datos de prueba.
# =============================================================
set -uo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP="/c/xampp/php/php.exe"

cd "$RAIZ" || exit 1

GUIONES=(
    "php:pruebas/receta_modelo.php"
    "php:pruebas/historial.php"
    "php:pruebas/tareas.php"
    "sh:pruebas/receta_http.sh"
    "sh:pruebas/notificaciones_http.sh"
    "sh:pruebas/historial_http.sh"
    "sh:pruebas/humo.sh"
)

FALLARON=()
RESUMEN=()

for g in "${GUIONES[@]}"; do
    tipo="${g%%:*}"; archivo="${g#*:}"
    nombre="$(basename "$archivo")"

    printf '\n\033[1m── %s %s\033[0m\n' "$nombre" "$(printf '─%.0s' $(seq 1 $((46 - ${#nombre}))))"

    if [ "$tipo" = "php" ]; then
        salida="$("$PHP" "$archivo" 2>&1)"
    else
        salida="$(bash "$archivo" 2>&1)"
    fi
    codigo=$?

    # Las líneas que importan: los fallos y el total.
    echo "$salida" | grep -E '^\[MAL\]|^ {10}|^!!|^TOTAL|^HUMO' || true

    linea="$(echo "$salida" | grep -E '^TOTAL|^HUMO' | tail -1)"
    if [ "$codigo" -eq 0 ]; then
        RESUMEN+=("  ✓ $nombre — ${linea:-sin total}")
    else
        RESUMEN+=("  ✗ $nombre — ${linea:-cortado}")
        FALLARON+=("$nombre")
    fi
done

echo
echo "════════════════════════════════════════════════════════════════"
printf '%s\n' "${RESUMEN[@]}"
echo "════════════════════════════════════════════════════════════════"

if [ ${#FALLARON[@]} -eq 0 ]; then
    echo "Todo en verde."
    exit 0
fi

printf 'Fallaron %d: %s\n' "${#FALLARON[@]}" "${FALLARON[*]}"
exit 1

<?php
// includes/busqueda.php
// -----------------------------------------------------------------
// Convertir lo que una persona escribió en un buscador a un patrón de
// LIKE. Una sola función, y vale la pena un archivo propio.
//
// ── EL PROBLEMA ──────────────────────────────────────────────
// En SQL, `%` significa "cualquier cosa" y `_` significa "cualquier
// carácter". Así que esto:
//
//     $like = '%' . $texto . '%';
//
// hace que buscar «100%» encuentre todo lo que empiece con 100, y que
// buscar «_» encuentre absolutamente todo.
//
// NO es un agujero de seguridad: el valor sigue viajando como parámetro
// de una sentencia preparada, así que no hay inyección posible. Es algo
// más sutil y más difícil de reportar: **un buscador que miente**. La
// persona escribe algo, le salen resultados que no tienen nada que ver,
// y no hay forma de que entienda por qué.
//
// ── POR QUÉ UNA FUNCIÓN COMPARTIDA ───────────────────────────
// El mismo descuido estaba en dos modelos, escrito dos veces. Cuando se
// arregló uno, el otro siguió igual — que es exactamente lo que pasa con
// el código duplicado: se corrige donde se lo encontró.
//
// Y devuelve el patrón COMPLETO, con los `%` de los extremos ya puestos,
// no sólo el texto escapado. Si devolviera el texto, cada llamador
// tendría que agregar los `%` y el que se olvidara de escapar primero
// volvería a tener el mismo problema. Así no hay forma de usarla mal.
// -----------------------------------------------------------------

if (!function_exists('patronLike')) {
    /**
     * Patrón de LIKE para buscar `$texto` en cualquier parte del campo.
     *
     *     WHERE titulo LIKE :q        →  patronLike('100%')  →  '%100\%%'
     *
     * La barra invertida se escapa PRIMERO y a propósito: al revés,
     * escaparía las barras que la propia función acaba de agregar, y
     * `_` terminaría convertido en `\\_` —una barra literal seguida de
     * cualquier carácter— en vez de en un guión bajo literal.
     */
    function patronLike(string $texto): string
    {
        return '%' . str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $texto
        ) . '%';
    }
}

<?php
// =============================================================
// sistema/vistas/componentes/icono_aviso.php
// =============================================================
// Traduce la clave de icono que devuelve `TipoAviso::config()` al SVG
// correspondiente.
//
// ── POR QUÉ LA CLAVE Y EL DIBUJO ESTÁN SEPARADOS ─────────────
// `includes/notificaciones.php` dice que un aviso de pago se dibuja con
// el icono 'tarjeta'. NO dice cómo se dibuja una tarjeta: eso es una
// decisión de presentación y no tiene por qué vivir en el archivo que
// decide a quién se le avisa y por qué canal.
//
// La separación se paga una sola vez y se cobra seguido: el día que haya
// notificaciones en el teléfono con otro juego de iconos, cambia este
// archivo y ninguno de los veinte tipos se entera.
//
// Uso:
//     echo iconoAviso(TipoAviso::config($aviso['tipo'])['icono']);
// =============================================================

if (!function_exists('iconoAviso')) {
    /**
     * Devuelve el SVG de una clave de icono.
     *
     * La clave desconocida cae en la campana, que es el icono genérico de
     * "aviso". Devolver una cadena vacía dejaría un hueco raro en la
     * lista sin que nadie entienda por qué falta sólo en algunas filas.
     */
    function iconoAviso(string $clave): string
    {
        // Sólo el contenido del <svg>: el envoltorio, con su tamaño y su
        // color, lo pone quien llama. Así el mismo icono sirve para la
        // lista (20 px) y para el campanita de la barra (18 px).
        $trazos = [
            'calendario' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
            'tilde'      => '<path d="M20 6 9 17l-5-5"/>',
            'cruz'       => '<path d="M18 6 6 18M6 6l12 12"/>',
            'reloj'      => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'tarjeta'    => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
            'documento'  => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M9 13h6M9 17h4"/>',
            'receta'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 15h6M12 12v6"/>',
            'mensaje'    => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
            'usuario'    => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'candado'    => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
            'sobre'      => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 6 10-6"/>',
            'campana'    => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
        ];

        return $trazos[$clave] ?? $trazos['campana'];
    }
}

<?php
// =============================================================
// includes/email_plantilla.php — Diseño de los correos
// =============================================================
// Un solo lugar donde vive el aspecto de TODOS los correos que manda
// MediTurnos. Antes de esto, recuperar.php armaba su HTML a mano; con
// trece correos distintos por delante, esa manera garantizaba que cada
// uno terminara pareciéndose un poco menos al anterior.
//
// Acá se arma el HTML; QUIÉN lo manda es includes/mailer.php. Son dos
// responsabilidades distintas: el diseño no debería cambiar si mañana se
// reemplaza SMTP por una API, y la plantilla tiene que poder probarse
// sin enviar nada.
//
// POR QUÉ EL HTML SE VE TAN ANTICUADO
// No es descuido. El correo NO es la web:
//   · Se maquetan TABLAS, no flexbox ni grid. Outlook usa el motor de
//     Word para renderizar y no entiende layout moderno.
//   · El CSS va EN LÍNEA. Gmail descarta casi todo lo que esté en un
//     <style> del <head>.
//   · Ancho fijo de 600 px, el máximo seguro en clientes de escritorio.
//   · Sin imágenes externas: la mayoría de los clientes las bloquea por
//     defecto, así que un logo en <img> se vería como un cuadro roto.
//     El logotipo se dibuja con texto y color de fondo.
// =============================================================

/** Paleta: los mismos valores que publico/css/estilos.css. */
const EMAIL_AZUL      = '#2563eb';
const EMAIL_AZUL_OSC  = '#1e3a8a';
const EMAIL_TEXTO     = '#0f172a';
const EMAIL_GRIS      = '#64748b';
const EMAIL_BORDE     = '#e2e8f0';
const EMAIL_FONDO     = '#f1f5f9';

/** Escape para HTML de correo. */
function eMail(?string $v): string
{
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Convierte una ruta interna en una dirección completa.
 *
 * Hace falta porque BASE_URL es una ruta RELATIVA ("/mediturnos/"): sirve
 * dentro del sitio, pero en un correo un enlace así no lleva a ninguna
 * parte — el cliente de correo está fuera del sitio y no sabe de qué
 * servidor se trata.
 *
 * Sin `HTTP_HOST` —por ejemplo si el correo lo dispara una tarea
 * programada desde la línea de comandos— se cae a `localhost`: un enlace
 * que al menos funciona desde el servidor es mejor que uno roto.
 */
function urlAbsoluta(string $ruta): string
{
    if ($ruta === '' || preg_match('#^https?://#i', $ruta)) {
        return $ruta;
    }
    $esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $esquema . '://' . $host . BASE_URL . ltrim($ruta, '/');
}

/**
 * Arma un correo completo con la identidad de MediTurnos.
 *
 * @param array $o Opciones:
 *   titulo      string   Encabezado principal (obligatorio)
 *   preheader   string   Texto de vista previa en la bandeja de entrada
 *   saludo      string   "Hola, Juan"
 *   parrafos    string[] Cuerpo del mensaje
 *   destacado   array    ['etiqueta' => ..., 'valor' => ...] caja grande
 *   datos       array    [etiqueta => valor] tabla de detalle
 *   boton       array    ['texto' => ..., 'url' => ...]
 *   aviso       string   Caja de advertencia (amarilla)
 *   nota        string   Aclaración final, en gris chico
 *   color       string   Color de la banda superior (default: azul)
 */
function emailPlantilla(array $o): string
{
    $titulo    = $o['titulo'] ?? 'MediTurnos';
    $color     = $o['color']  ?? EMAIL_AZUL;
    $preheader = $o['preheader'] ?? '';

    $html = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" '
          . '"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">'
          . '<html xmlns="http://www.w3.org/1999/xhtml"><head>'
          . '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />'
          . '<meta name="viewport" content="width=device-width, initial-scale=1.0" />'
          . '<title>' . eMail($titulo) . '</title>'
          . '</head>'
          . '<body style="margin:0;padding:0;background:' . EMAIL_FONDO . ';'
          . '-webkit-font-smoothing:antialiased;">';

    // Preheader: el texto que el cliente de correo muestra al lado del
    // asunto en la bandeja. Si no se define, ahí aparece el primer texto
    // que encuentre —normalmente "Ver en el navegador" o el logotipo—,
    // que no le dice nada a nadie. Se oculta con tamaño 0.
    if ($preheader !== '') {
        $html .= '<div style="display:none;font-size:1px;color:' . EMAIL_FONDO
               . ';line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">'
               . eMail($preheader)
               . str_repeat('&#8199;&#65279;&#847; ', 30)   // relleno invisible
               . '</div>';
    }

    $html .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" '
           . 'style="background:' . EMAIL_FONDO . ';padding:28px 12px;">'
           . '<tr><td align="center">'
           . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" '
           . 'style="width:600px;max-width:100%;background:#ffffff;border-radius:14px;'
           . 'overflow:hidden;font-family:\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;'
           . 'box-shadow:0 2px 8px rgba(15,23,42,.08);">';

    // ── Encabezado ───────────────────────────────────────────
    $html .= '<tr><td style="background:' . $color . ';padding:26px 32px;">'
           . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
           . '<td style="padding-right:11px;">'
           . '<div style="width:34px;height:34px;background:rgba(255,255,255,.22);'
           . 'border-radius:9px;text-align:center;line-height:34px;color:#ffffff;'
           . 'font-size:20px;font-weight:bold;">+</div>'
           . '</td>'
           . '<td style="color:#ffffff;font-size:19px;font-weight:bold;letter-spacing:-.3px;">'
           . 'MediTurnos</td>'
           . '</tr></table>'
           . '</td></tr>';

    // ── Cuerpo ───────────────────────────────────────────────
    $html .= '<tr><td style="padding:32px;">';

    $html .= '<h1 style="margin:0 0 6px;font-size:21px;line-height:1.3;color:'
           . EMAIL_TEXTO . ';font-weight:bold;">' . eMail($titulo) . '</h1>';

    if (!empty($o['saludo'])) {
        $html .= '<p style="margin:0 0 16px;font-size:15px;color:' . EMAIL_GRIS . ';">'
               . eMail($o['saludo']) . '</p>';
    }

    foreach (($o['parrafos'] ?? []) as $p) {
        $html .= '<p style="margin:0 0 14px;font-size:15px;line-height:1.62;color:'
               . EMAIL_TEXTO . ';">' . eMail($p) . '</p>';
    }

    // Caja destacada: para el dato que la persona vino a buscar (el
    // código de reserva, el importe). Va en grande y sola para que se
    // pueda leer sin abrir el correo del todo.
    if (!empty($o['destacado'])) {
        $html .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" '
               . 'style="margin:20px 0;background:#eff6ff;border-radius:11px;'
               . 'border-left:4px solid ' . EMAIL_AZUL . ';">'
               . '<tr><td style="padding:18px 22px;text-align:center;">'
               . '<div style="font-size:11px;letter-spacing:1.1px;text-transform:uppercase;'
               . 'color:' . EMAIL_GRIS . ';margin-bottom:6px;">'
               . eMail($o['destacado']['etiqueta'] ?? '') . '</div>'
               . '<div style="font-size:26px;font-weight:bold;color:' . EMAIL_AZUL_OSC . ';'
               . 'letter-spacing:1.5px;">' . eMail($o['destacado']['valor'] ?? '') . '</div>'
               . '</td></tr></table>';
    }

    // Tabla de detalle: etiqueta a la izquierda, valor a la derecha.
    if (!empty($o['datos'])) {
        $html .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" '
               . 'style="margin:20px 0;border:1px solid ' . EMAIL_BORDE . ';border-radius:11px;">';
        $i = 0;
        $total = count($o['datos']);
        foreach ($o['datos'] as $etiqueta => $valor) {
            $i++;
            $borde = $i < $total ? 'border-bottom:1px solid ' . EMAIL_BORDE . ';' : '';
            $html .= '<tr>'
                   . '<td style="padding:11px 18px;font-size:13px;color:' . EMAIL_GRIS . ';'
                   . $borde . 'width:42%;">' . eMail((string) $etiqueta) . '</td>'
                   . '<td style="padding:11px 18px;font-size:14px;color:' . EMAIL_TEXTO . ';'
                   . 'font-weight:bold;text-align:right;' . $borde . '">'
                   . eMail((string) $valor) . '</td>'
                   . '</tr>';
        }
        $html .= '</table>';
    }

    if (!empty($o['aviso'])) {
        $html .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" '
               . 'style="margin:18px 0;background:#fef3c7;border-radius:9px;">'
               . '<tr><td style="padding:13px 18px;font-size:13.5px;line-height:1.55;color:#92400e;">'
               . eMail($o['aviso']) . '</td></tr></table>';
    }

    // Botón: se maqueta como tabla y no como <a> con padding porque
    // Outlook ignora el padding de los enlaces y el botón quedaría del
    // tamaño del texto, sin área clickeable.
    if (!empty($o['boton']['url'])) {
        $html .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" '
               . 'style="margin:24px 0 8px;"><tr>'
               . '<td style="background:' . EMAIL_AZUL . ';border-radius:9px;">'
               . '<a href="' . eMail($o['boton']['url']) . '" '
               . 'style="display:inline-block;padding:14px 30px;color:#ffffff;'
               . 'font-size:15px;font-weight:bold;text-decoration:none;">'
               . eMail($o['boton']['texto'] ?? 'Abrir') . '</a>'
               . '</td></tr></table>';

        // La URL en texto plano: si el botón no se puede tocar (cliente
        // que bloquea enlaces, correo reenviado como texto), la persona
        // todavía puede copiarla a mano.
        $html .= '<p style="margin:4px 0 0;font-size:11.5px;color:' . EMAIL_GRIS . ';'
               . 'word-break:break-all;line-height:1.5;">'
               . 'Si el botón no funciona, copiá esta dirección en tu navegador:<br>'
               . eMail($o['boton']['url']) . '</p>';
    }

    if (!empty($o['nota'])) {
        $html .= '<p style="margin:22px 0 0;padding-top:18px;border-top:1px solid '
               . EMAIL_BORDE . ';font-size:12.5px;line-height:1.6;color:' . EMAIL_GRIS . ';">'
               . eMail($o['nota']) . '</p>';
    }

    $html .= '</td></tr>';

    // ── Pie ──────────────────────────────────────────────────
    $html .= '<tr><td style="background:#f8fafc;padding:20px 32px;border-top:1px solid '
           . EMAIL_BORDE . ';">'
           . '<p style="margin:0;font-size:12px;line-height:1.65;color:' . EMAIL_GRIS . ';">'
           . '<strong style="color:' . EMAIL_TEXTO . ';">MediTurnos</strong> — '
           . 'Sistema de gestión de turnos médicos<br>'
           . 'Este es un correo automático. No hace falta que lo respondas.'
           . '</p></td></tr>';

    $html .= '</table></td></tr></table></body></html>';

    return $html;
}

<?php
// sistema/controladores/ControladorNotificacion.php
// -----------------------------------------------------------------
// El centro de notificaciones. La pieza que faltaba: la tabla
// `notificacion` y el servicio de emisión existen desde la etapa 0, y
// todos los módulos vienen escribiendo ahí, pero no había dónde verlas.
//
// ── POR QUÉ ES UN CONTROLADOR Y NO UN ARCHIVO EN LA RAÍZ ─────
// `historial.php` y `recetas.php` están en la raíz porque son pantallas
// del Área del PACIENTE. Las notificaciones no: un médico recibe el
// pedido de renovación de una receta, y el día que haya avisos para
// administración también los va a recibir ahí. Es una pantalla de
// cualquier cuenta, así que vive donde viven las pantallas compartidas.
//
// ── TODO SALE DE LA SESIÓN ───────────────────────────────────
// Ninguna acción recibe el id de usuario por parámetro. Las lecturas
// filtran por `id_usuario` de la sesión y las escrituras lo llevan en el
// WHERE, así que `?id=` de otra persona no devuelve ni modifica nada:
// `marcarLeida()` y `eliminar()` piden el id del aviso Y el del dueño en
// la misma sentencia. No hay una ventana entre "compruebo que es mío" y
// "lo borro".
// -----------------------------------------------------------------

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/notificaciones.php';
require_once __DIR__ . '/../modelos/Notificacion.php';

verificarSesion();

$modelo    = new Notificacion($pdo);
$accion    = $_GET['accion'] ?? 'index';
$URL       = BASE_URL . 'sistema/controladores/ControladorNotificacion.php';
$idUsuario = (int) ($_SESSION['id_usuario'] ?? 0);

/**
 * Convierte la `url_accion` guardada en un destino seguro.
 *
 * ── POR QUÉ ESTO NO ES PARANOIA ──────────────────────────────
 * `url_accion` la escribe el propio sistema, así que hoy no hay forma de
 * que traiga algo raro. Pero este valor termina en una cabecera
 * `Location:`, y eso convierte cualquier descuido futuro en dos
 * agujeros concretos:
 *
 *   · Redirección abierta. Si alguna vez un aviso guardara una URL con
 *     esquema o `//otrositio.com`, el enlace llevaría a otro dominio
 *     DESDE una dirección del sistema. Es la base de un engaño de
 *     phishing: el enlace que la persona recibe y revisa es nuestro.
 *   · Inyección en la cabecera. Un salto de línea dentro del valor
 *     permite agregar cabeceras propias a la respuesta.
 *
 * El control cuesta cinco líneas y cubre al código que todavía no se
 * escribió. Ante cualquier duda, el panel.
 */
function destinoSeguro(?string $url): string
{
    $panel = BASE_URL . 'dashboard.php';

    if (!is_string($url) || trim($url) === '') {
        return $panel;
    }
    // Saltos de línea: inyección de cabeceras.
    if (preg_match('/[\r\n\0]/', $url)) {
        return $panel;
    }
    // Cualquier esquema (http:, javascript:, data:…).
    if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url)) {
        return $panel;
    }
    // `//host` y `\\host`: el navegador los toma por absolutos.
    if (str_starts_with($url, '//') || str_contains($url, '\\')) {
        return $panel;
    }

    return BASE_URL . ltrim($url, '/');
}

switch ($accion) {

    // ── El listado ──────────────────────────────────────────
    case 'index':
        // is_string en los dos: un `?estado[]=x` haría que la
        // comparación reciba un arreglo. El modelo lo compara contra
        // literales, así que no rompería, pero el filtro quedaría
        // aplicado a medias y sin explicación.
        $filtros = [
            'estado' => is_string($_GET['estado'] ?? null) ? $_GET['estado'] : '',
            'tipo'   => is_string($_GET['tipo']   ?? null) ? $_GET['tipo']   : '',
        ];
        // Un tipo inventado por la URL no se pasa al modelo: devolvería
        // cero filas y la persona no entendería por qué.
        if ($filtros['tipo'] !== '' && !TipoAviso::existe($filtros['tipo'])) {
            $filtros['tipo'] = '';
        }

        $pagina       = max(1, (int) ($_GET['pagina'] ?? 1));
        $total        = $modelo->contar($idUsuario, $filtros);
        $totalPaginas = max(1, (int) ceil($total / Notificacion::POR_PAGINA));
        // Pedir la página 50 de 3 devolvería una lista vacía sin decir
        // por qué: se acota a la última que existe.
        $pagina       = min($pagina, $totalPaginas);

        $avisos  = $modelo->listar($idUsuario, $filtros, $pagina);
        $sinLeer = $modelo->sinLeer($idUsuario);
        // Sólo los tipos que esta persona tiene: un desplegable con los
        // veinte del sistema le ofrecería a un paciente filtrar por
        // "pedido de renovación", que es un aviso de médico.
        $tipos   = $modelo->porTipo($idUsuario);
        $mensaje = is_string($_GET['err'] ?? null) ? $_GET['err'] : null;

        require __DIR__ . '/../vistas/notificaciones/index.php';
        break;

    // ── Abrir un aviso: marcarlo leído y llevar a su destino ─
    // Es GET porque es un enlace, y marcar como leído al abrirlo es
    // justamente lo que la persona espera. No es una operación
    // destructiva ni reversa nada, así que no pide CSRF.
    case 'leer':
        $id = (int) ($_GET['id'] ?? 0);

        // Se lee ANTES de marcarlo, porque hace falta su destino. La
        // consulta pide el id Y el dueño: el aviso de otra persona
        // simplemente no existe para esta.
        $aviso = $modelo->deUsuario($id, $idUsuario);

        if (!$aviso) {
            header('Location: ' . $URL . '?accion=index&err='
                . urlencode('Ese aviso no existe o ya lo eliminaste.'));
            exit;
        }

        $modelo->marcarLeida($id, $idUsuario);
        header('Location: ' . destinoSeguro($aviso['url_accion'] ?? null));
        exit;

    // ── Marcar todas como leídas ────────────────────────────
    case 'leerTodas':
        csrf_post();
        $cuantas = $modelo->marcarTodasLeidas($idUsuario);
        header('Location: ' . $URL . '?accion=index&msg=leidas&n=' . $cuantas);
        exit;

    // ── Eliminar una ────────────────────────────────────────
    case 'eliminar':
        csrf_post();
        $id = (int) ($_POST['id'] ?? 0);

        // El DELETE lleva el dueño en el WHERE: si el aviso es de otra
        // persona, no borra nada y devuelve false. No hace falta —ni
        // conviene— decir si existía: eso convertiría el formulario en
        // un detector de ids ajenos.
        if (!$modelo->eliminar($id, $idUsuario)) {
            header('Location: ' . $URL . '?accion=index&err='
                . urlencode('No encontramos ese aviso.'));
            exit;
        }

        header('Location: ' . $URL . '?accion=index&msg=borrada');
        exit;

    // ── Eliminar todas las leídas ───────────────────────────
    // Vaciar la bandeja de una vez. Sólo las LEÍDAS: borrar de un golpe
    // algo que la persona todavía no vio sería hacerle perder un aviso
    // que quizá importaba.
    case 'eliminarLeidas':
        csrf_post();
        $cuantas = $modelo->eliminarLeidas($idUsuario);
        header('Location: ' . $URL . '?accion=index&msg=limpiada&n=' . $cuantas);
        exit;

    default:
        header('Location: ' . $URL . '?accion=index');
        exit;
}

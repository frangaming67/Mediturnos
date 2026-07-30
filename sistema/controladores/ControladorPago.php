<?php
// sistema/controladores/ControladorPago.php
// Cobro de turnos: elegir cuándo pagar, pago con tarjeta (simulado),
// cobro en recepción y listado de pagos.
// -----------------------------------------------------------------
// Turno.php se importa acá, además de en ControladorTurno.php, porque
// un pago exitoso tiene que confirmar el turno asociado
// (Turno::actualizarEstado(..., 'Confirmado')). Podría pensarse que esa
// llamada debería vivir en el modelo Pago, pero Pago no conoce a Turno
// (evita una dependencia circular entre modelos); quien coordina ambos
// es este controlador, que sí conoce las dos entidades.
// -----------------------------------------------------------------

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/notificaciones.php';
require_once __DIR__ . '/../modelos/Pago.php';
require_once __DIR__ . '/../modelos/Turno.php';

verificarSesion();

/**
 * Avisa que un turno quedó confirmado y pagado.
 *
 * Está en una función porque el mismo aviso sale por dos caminos —pago
 * con tarjeta y cobro en recepción— y son el mismo hecho para quien lo
 * recibe: su turno quedó confirmado. Escribirlo dos veces garantizaría
 * que un día los dos correos digan cosas distintas.
 */
function avisarPagoAprobado(PDO $pdo, array $pago, string $referencia): void
{
    $fecha = date('d/m/Y', strtotime($pago['fecha']));
    $hora  = substr($pago['hora_inicio'], 0, 5);

    obtenerNotificador($pdo)->notificarPaciente((int) $pago['id_paciente'], new Aviso(
        TipoAviso::PAGO_APROBADO,
        'Turno confirmado',
        'Tu pago se acreditó y el turno con Dr/a. ' . $pago['medico']
            . ' del ' . $fecha . ' a las ' . $hora . ' hs quedó confirmado.',
        'sistema/controladores/ControladorPago.php?accion=comprobante&id_pago=' . (int) $pago['id_pago'],
        (int) $pago['id_turno'],
        [
            'asunto'    => 'Tu turno quedó confirmado',
            'parrafos'  => ['Recibimos tu pago. Tu turno está confirmado; abajo tenés todos los datos.'],
            // El código va destacado y solo: es lo que la persona busca
            // cuando abre el correo en la puerta de la clínica.
            'destacado' => ['etiqueta' => 'Código de reserva', 'valor' => $referencia],
            'datos'     => [
                'Paciente'     => $pago['paciente'] ?? '',
                'Profesional'  => 'Dr/a. ' . $pago['medico'],
                'Especialidad' => $pago['especialidad'],
                'Fecha'        => $fecha,
                'Hora'         => $hora . ' hs',
                'Consultorio'  => $pago['consultorio'] ?? '',
                'Cobertura'    => $pago['obra_social'] . ' — ' . $pago['plan'],
                'Importe'      => '$' . number_format((float) $pago['monto_total'], 2, ',', '.'),
                'Medio de pago'=> $pago['metodo'] ?? '',
            ],
            'aviso' => 'Llegá 10 minutos antes y traé tu DNI y la credencial de tu obra social. '
                     . 'Si no vas a poder venir, cancelá con al menos 2 horas de anticipación.',
            'nota'  => 'Podés ver, reprogramar o cancelar este turno desde tu cuenta.',
        ],
        null,
        'Ver mi comprobante'
    ));
}

$modelo = new Pago($pdo);
$accion = $_GET['accion'] ?? 'index';

$URL = BASE_URL . 'sistema/controladores/ControladorPago.php';

// obtenerPagoSeguro() se extrajo a función porque el mismo chequeo de
// IDOR (¿el pago es del paciente logueado / de un turno de este médico?)
// se necesita en 3 acciones distintas ('elegir', 'tarjeta', 'procesar',
// 'recepcion'); tenerlo repetido en cada case sería fácil de olvidar
// actualizar en alguno de los 4 lugares el día que cambie la regla.
/**
 * Resuelve el pago a partir de id_pago (o id_turno) y verifica que el
 * usuario tenga permiso para verlo/operarlo. Si algo falla, corta.
 */
function obtenerPagoSeguro(Pago $modelo, string $URL): array
{
    $idPago  = (int) ($_REQUEST['id_pago']  ?? 0);
    $idTurno = (int) ($_REQUEST['id_turno'] ?? 0);

    $pago = false;
    if ($idPago) {
        $pago = $modelo->buscarPorId($idPago);
    } elseif ($idTurno) {
        $p = $modelo->buscarPorTurno($idTurno);
        if ($p) {
            $pago = $modelo->buscarPorId((int) $p['id_pago']);
        }
    }

    if (!$pago) {
        header('Location: ' . $URL . '?accion=index&err=' . urlencode('No se encontró el pago.'));
        exit;
    }

    // Un paciente solo puede operar SUS propios pagos (evita IDOR).
    if ($_SESSION['rol'] === 'paciente'
        && (int) $pago['id_paciente'] !== (int) ($_SESSION['id_paciente'] ?? 0)) {
        http_response_code(403);
        include __DIR__ . '/../vistas/layouts/403.php';
        exit;
    }

    // Un médico solo puede ver los pagos de SUS propios turnos (evita IDOR).
    if ($_SESSION['rol'] === 'medico'
        && (int) $pago['matricula'] !== (int) ($_SESSION['matricula'] ?? 0)) {
        http_response_code(403);
        include __DIR__ . '/../vistas/layouts/403.php';
        exit;
    }

    return $pago;
}

switch ($accion) {

    // ── Elegir cómo pagar (pantalla post-reserva) ────────────
    case 'elegir':
        $pago = obtenerPagoSeguro($modelo, $URL);
        $mensaje = !empty($_GET['err']) ? urldecode($_GET['err']) : null;
        require __DIR__ . '/../vistas/pagos/elegir.php';
        break;

    // ── Formulario de pago con tarjeta ───────────────────────
    case 'tarjeta':
        $pago = obtenerPagoSeguro($modelo, $URL);

        if ($pago['estado'] !== 'Pendiente') {
            header('Location: ' . $URL . '?accion=index&err='
                . urlencode('Ese pago ya no está pendiente (estado: ' . $pago['estado'] . ').'));
            exit;
        }

        $mensaje = !empty($_GET['err']) ? urldecode($_GET['err']) : null;
        require __DIR__ . '/../vistas/pagos/tarjeta.php';
        break;

    // ── Procesar pago con tarjeta (pasarela simulada) ────────
    case 'procesar':
        csrf_verificar();
        $pago = obtenerPagoSeguro($modelo, $URL);

        $resultado = $modelo->pagarConTarjeta((int) $pago['id_pago'], [
            'numero'  => $_POST['numero']  ?? '',
            'titular' => $_POST['titular'] ?? '',
            'mes'     => $_POST['mes']     ?? '',
            'anio'    => $_POST['anio']    ?? '',
            'cvv'     => $_POST['cvv']     ?? '',
        ]);
        //Esto hace que cuando se registre correctamente, el turno asociado pase a confirmado y no quede
        //en reservado
        if ($resultado['ok']) {
            $turnoModelo = new Turno($pdo);
            $turnoModelo->actualizarEstado((int) $pago['id_turno'], 'Confirmado');

            // Se relee el pago: recién ahora tiene estado, método y
            // referencia definitivos, que son justamente los datos del
            // comprobante y del correo.
            $pagoFinal = $modelo->buscarPorId((int) $pago['id_pago']);
            avisarPagoAprobado($pdo, $pagoFinal ?: $pago, $resultado['referencia'] ?? '');

            // Se lo lleva al comprobante, no al listado: acaba de pagar y
            // lo que quiere ver es la constancia de que su turno está.
            header('Location: ' . $URL . '?accion=comprobante&id_pago=' . (int) $pago['id_pago'] . '&msg=pagado');
            exit;
        }

        // ── Pago rechazado ───────────────────────────────────────
        // El turno NO se cancela acá: la persona sigue dentro de su
        // ventana de retención y puede reintentar con otra tarjeta. Si
        // se cancelara al primer rechazo, quien se equivocó en un dígito
        // perdería el horario y tendría que empezar de cero.
        obtenerNotificador($pdo)->notificarPaciente((int) $pago['id_paciente'], new Aviso(
            TipoAviso::PAGO_RECHAZADO,
            'No pudimos procesar tu pago',
            'El pago del turno con Dr/a. ' . $pago['medico'] . ' del '
                . date('d/m/Y', strtotime($pago['fecha'])) . ' fue rechazado: '
                . $resultado['msg'],
            'sistema/controladores/ControladorPago.php?accion=elegir&id_pago=' . (int) $pago['id_pago'],
            (int) $pago['id_turno'],
            [
                'asunto'   => 'No pudimos procesar tu pago',
                'parrafos' => [
                    'Tu turno sigue reservado, pero todavía sin confirmar. '
                    . 'Podés intentar con otra tarjeta o abonar en recepción.',
                ],
                'aviso' => 'Tenés tiempo hasta el '
                         . date('d/m/Y \a \l\a\s H:i', strtotime($pago['fecha_vencimiento']))
                         . '. Después de esa hora el horario se libera.',
            ],
            null,
            'Reintentar el pago'
        ));

        header('Location: ' . $URL . '?accion=tarjeta&id_pago=' . (int) $pago['id_pago']
            . '&err=' . urlencode($resultado['msg']));
        exit;

    // ── "Prefiero pagarlo más tarde" ─────────────────────────
    // Extiende la retención corta del checkout al plazo largo. Es un
    // cambio de decisión de la persona, no una trampa: mientras estaba
    // pagando se le retenía el horario 15 minutos; al elegir pagar
    // después, se le reserva 48 horas.
    case 'diferir':
        csrf_verificar();
        $pago = obtenerPagoSeguro($modelo, $URL);
        $modelo->extenderPlazo((int) $pago['id_pago']);
        header('Location: ' . $URL . '?accion=elegir&id_pago=' . (int) $pago['id_pago'] . '&msg=diferido');
        exit;

    // ── Comprobante del turno ────────────────────────────────
    case 'comprobante':
        $pago = obtenerPagoSeguro($modelo, $URL);

        // Un comprobante es la constancia de algo que se pagó. Si el pago
        // sigue pendiente no hay nada que constar: se lo manda a pagarlo,
        // que es lo que en realidad necesita.
        if ($pago['estado'] !== 'Pagado') {
            header('Location: ' . $URL . '?accion=elegir&id_pago=' . (int) $pago['id_pago']);
            exit;
        }

        require __DIR__ . '/../vistas/pagos/comprobante.php';
        break;

    // ── Cobro en recepción (solo staff) ──────────────────────
    case 'recepcion':
        verificarRol(['admin', 'recepcionista']);
        csrf_verificar();
        $pago = obtenerPagoSeguro($modelo, $URL);

        // 'volver' sólo se acepta si es una URL interna del sistema (evita open-redirect).
        $volver = $_POST['volver'] ?? '';
        if ($volver === '' || strpos($volver, BASE_URL) !== 0) {
            $volver = $URL . '?accion=index';
        }
        $sep = strpos($volver, '?') === false ? '?' : '&';
        // que hace esto pone el pago en Pagado inmediatamente después, 
        // Turno::actualizarEstado(..., 'Confirmado') cambia el estado del turno
        if ($modelo->pagarEnRecepcion((int) $pago['id_pago'])) {
            $turnoModelo = new Turno($pdo);
            $turnoModelo->actualizarEstado((int) $pago['id_turno'], 'Confirmado');

            // Mismo aviso que con tarjeta: para el paciente es el mismo
            // hecho —su turno quedó confirmado—, sin importar por dónde
            // entró la plata.
            $pagoFinal = $modelo->buscarPorId((int) $pago['id_pago']);
            avisarPagoAprobado($pdo, $pagoFinal ?: $pago, $pagoFinal['referencia'] ?? '');

            header('Location: ' . $volver . $sep . 'msg=pagado');
            exit;
        }
        header('Location: ' . $URL . '?accion=index&err='
            . urlencode('No se pudo registrar el cobro (¿ya estaba pagado?).'));
        exit;

    // ── Listado de pagos ─────────────────────────────────────
    case 'index':
    default:
        $modelo->expirarVencidos();

        $filtros = [
            'estado'       => trim($_GET['estado']       ?? ''),
            'dni_paciente' => trim($_GET['dni_paciente'] ?? ''),
        ];
        // Un paciente sólo ve sus propios pagos.
        if ($_SESSION['rol'] === 'paciente') {
            $filtros['id_paciente'] = (int) ($_SESSION['id_paciente'] ?? 0);
        }
        // Un médico sólo ve los pagos de SUS propios turnos. Fail-closed:
        // sin matrícula válida en sesión, forzar re-login (evita que el
        // filtro caiga a 0 y el modelo, por empty(0), liste TODOS los pagos).
        if ($_SESSION['rol'] === 'medico') {
            $miMatricula = (int) ($_SESSION['matricula'] ?? 0);
            if ($miMatricula <= 0) {
                session_unset();
                session_destroy();
                header('Location: ' . BASE_URL . 'login.php?exp=1');
                exit;
            }
            $filtros['matricula'] = $miMatricula;
        }

        $pagos = $modelo->listar($filtros);
        require __DIR__ . '/../vistas/pagos/index.php';
        break;
}

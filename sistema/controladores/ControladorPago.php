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
require_once __DIR__ . '/../modelos/Pago.php';
require_once __DIR__ . '/../modelos/Turno.php';

verificarSesion();

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

            header('Location: ' . $URL . '?accion=index&msg=pagado');
            exit;
        }

        header('Location: ' . $URL . '?accion=tarjeta&id_pago=' . (int) $pago['id_pago']
            . '&err=' . urlencode($resultado['msg']));
        exit;

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

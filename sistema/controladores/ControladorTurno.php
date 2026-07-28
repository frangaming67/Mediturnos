<?php
// sistema/controladores/ControladorTurno.php
// -----------------------------------------------------------------
// Este archivo ES la URL que se visita: ?accion=index / nuevo / reservar /
// cancelar / etc. Se usa un switch($accion) en vez de 7 archivos sueltos
// porque todas esas acciones operan sobre el MISMO recurso (turno) y
// comparten el mismo require_once de arriba; separarlas multiplicaría
// los require_once sin ninguna ganancia real.
// Los casos 'horarios', 'slots' y 'ausencias' del final son endpoints
// AJAX (devuelven JSON, no HTML) pero viven acá y no en un archivo propio
// porque los consume el mismo calendario.js que ya habla con este
// controlador para reservar/consultar turnos: mantiene una sola URL base
// para todo lo relacionado a "turno" en vez de dispersar el JS entre
// varios endpoints.
// Pago se importa acá (no solo en ControladorPago) porque reservar/cancelar
// un turno siempre tiene un efecto colateral sobre su pago asociado
// (crearlo, anularlo): mantener esa relación en un solo controlador evita
// que un turno quede reservado sin pago o cancelado con un pago colgado.
// -----------------------------------------------------------------

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../modelos/Turno.php';
require_once __DIR__ . '/../modelos/Pago.php';

// verificarRol() NO se llama acá arriba (a diferencia de los otros
// controladores) porque 'index' lo puede ver cualquier rol logueado
// (cada uno filtrado a lo suyo); el control de rol más estricto se hace
// caso por caso dentro del switch (ver 'nuevo', 'reservar', 'cancelar').
verificarSesion();

$modelo  = new Turno($pdo);
$accion  = $_GET['accion'] ?? 'index';
$mensaje = null;
$tipoMsg = null;

switch ($accion) {

    // ── Listado con filtros ──────────────────────────────────
    case 'index':
        $fechaFiltro = trim($_GET['fecha'] ?? '');
        $estadoFiltro = trim($_GET['estado'] ?? '');

        $filtros = [
            'fecha'           => $fechaFiltro,
            'estado'          => $estadoFiltro,
            'matricula'       => trim($_GET['matricula']       ?? ''),
            'id_especialidad' => trim($_GET['id_especialidad'] ?? ''),
            'dni_paciente'    => trim($_GET['dni_paciente']    ?? ''),
        ];

        // Un paciente sólo puede ver sus propios turnos.
        if ($_SESSION['rol'] === 'paciente') {
            $filtros['id_paciente'] = (int)($_SESSION['id_paciente'] ?? 0);
        }

        // Un médico sólo puede ver sus propios turnos (evita ver la
        // agenda de otros médicos cambiando el filtro por URL).
        if ($_SESSION['rol'] === 'medico') {
            $miMatricula = (int)($_SESSION['matricula'] ?? 0);
            // Fail-closed: si la sesión no trae una matrícula válida (p. ej.
            // sesión iniciada antes de este control), NO caer al filtro 0
            // -que por empty(0) el modelo ignoraría, devolviendo TODOS los
            // turnos-. Forzamos re-login para rehidratar la matrícula.
            if ($miMatricula <= 0) {
                session_unset();
                session_destroy();
                header('Location: ' . BASE_URL . 'login.php?exp=1');
                exit;
            }
            $filtros['matricula'] = $miMatricula;
        }

        // Antes de listar, cancelar los turnos cuyo pago venció.
        (new Pago($pdo))->expirarVencidos();
        $modelo->marcarRealizadosAutomaticamente();
        $turnos        = $modelo->listar($filtros);
        $medicos       = $modelo->listarMedicos();
        $especialidades= $modelo->listarEspecialidades();
        require __DIR__ . '/../vistas/turnos/index.php';
        break;

    // ── Formulario nuevo turno (dos pasos, sin JS) ───────────
    case 'nuevo':
        verificarRol(['admin', 'recepcionista', 'paciente']);

        // Variables que la vista siempre necesita
        $pacientes = $modelo->listarPacientes();
        $medicos   = $modelo->listarMedicos();
        $planes    = $modelo->listarPlanes();

        // Error que puede venir de una reserva fallida
        if (!empty($_GET['err'])) {
            $mensaje = $_GET['err'];
            $tipoMsg = 'error';
        }

        $paso = (int)($_POST['paso'] ?? 1);

        // Variables del paso 2 con valores por defecto
        $slots        = [];
        $matricula    = 0;
        $fecha        = '';
        $id_plan      = 0;
        $nro_afiliado = '';
        $id_paciente  = 0;
        $medicoNombre = '';

        if ($paso === 2) {
            $matricula    = (int)($_POST['matricula']    ?? 0);
            $fecha        = trim($_POST['fecha']         ?? '');
            $id_plan      = (int)($_POST['id_plan']      ?? 0);
            $nro_afiliado = trim($_POST['nro_afiliado']  ?? '');
            $id_paciente  = ($_SESSION['rol'] === 'paciente')
                ? (int)($_SESSION['id_paciente'] ?? 0)
                : (int)($_POST['id_paciente'] ?? 0);

            if (!$matricula || !$fecha || !$id_plan || !$id_paciente) {
                $mensaje = 'Completá todos los campos obligatorios.';
                $tipoMsg = 'error';
                $paso    = 1;
            } else {
                $slots = $modelo->obtenerSlots($matricula, $fecha);

                $medRaw       = array_filter($medicos, fn($m) => $m['matricula'] == $matricula);
                $medicoNombre = reset($medRaw)['nombre_completo'] ?? '';

                if (empty($slots)) {
                    $mensaje = 'El médico no atiende ese día o no tiene horarios disponibles.';
                    $tipoMsg = 'error';
                    $paso    = 1;
                }
            }
        }

        require __DIR__ . '/../vistas/turnos/nuevo.php';
        break;

    // ── Reservar turno ───────────────────────────────────────
    case 'reservar':
        verificarRol(['admin', 'recepcionista', 'paciente']);
        csrf_verificar();

        $id_paciente = ($_SESSION['rol'] === 'paciente')
            ? (int)($_SESSION['id_paciente'] ?? 0)
            : (int)($_POST['id_paciente'] ?? 0);

        // Dos orígenes posibles:
        //  - nuevo.php  → un único campo "slot" = "hora_full|id_consultorio|id_especialidad"
        //  - dashboard  → campos separados (hora_inicio, id_consultorio, id_especialidad)
        if (!empty($_POST['slot'])) {
            $partes          = explode('|', $_POST['slot']);
            $hora_inicio     = $partes[0]      ?? '';
            $id_consultorio  = (int)($partes[1] ?? 0);
            $id_especialidad = (int)($partes[2] ?? 0);
        } else {
            $hora_inicio     = trim($_POST['hora_inicio']      ?? '');
            $id_consultorio  = (int)($_POST['id_consultorio']  ?? 0);
            $id_especialidad = (int)($_POST['id_especialidad'] ?? 0);
        }

        $datos = [
            'fecha'           => trim($_POST['fecha']       ?? ''),
            'hora_inicio'     => $hora_inicio,
            'id_paciente'     => $id_paciente,
            'matricula'       => (int)($_POST['matricula']  ?? 0),
            'id_especialidad' => $id_especialidad,
            'id_consultorio'  => $id_consultorio,
            'id_plan'         => (int)($_POST['id_plan']    ?? 0),
            'nro_afiliado'    => trim($_POST['nro_afiliado'] ?? ''),
        ];

        if (empty($datos['fecha']) || empty($datos['hora_inicio'])
            || !$datos['id_paciente'] || !$datos['matricula']
            || !$datos['id_especialidad'] || !$datos['id_consultorio'] || !$datos['id_plan']) {
            header('Location: ' . BASE_URL . 'sistema/controladores/ControladorTurno.php?accion=nuevo&err=' . urlencode('Datos incompletos. Volvé a seleccionar el turno.'));
            exit;
        }

        // Guard de integridad: no permitir reservar un horario que ya pasó.
        // El formulario y el calendario ya ocultan estos slots (Turno::obtenerSlots),
        // pero un POST tardío o manipulado podría traerlos; sin este control el pago
        // nacería vencido y el turno se cancelaría solo al instante.
        if (strtotime($datos['fecha'] . ' ' . $datos['hora_inicio']) <= time()) {
            header('Location: ' . BASE_URL . 'sistema/controladores/ControladorTurno.php?accion=nuevo&err=' . urlencode('Ese horario ya pasó. Elegí un turno a futuro.'));
            exit;
        }

        try {
            $idTurno = $modelo->reservar($datos);

            // Generar el pago pendiente y llevar al paciente a la pantalla
            // donde elige pagar ahora (tarjeta) o más tarde.
            try {
                $idPago = (new Pago($pdo))->crearParaTurno($idTurno, $datos);
                header('Location: ' . BASE_URL . 'sistema/controladores/ControladorPago.php?accion=elegir&id_pago=' . $idPago);
                exit;
            } catch (PDOException $ep) {
                // El turno quedó reservado pero el pago no se pudo crear.
                error_log('ControladorTurno crearPago: ' . $ep->getMessage());
                header('Location: ' . BASE_URL . 'sistema/controladores/ControladorTurno.php?accion=index&msg=reservado');
                exit;
            }
        } catch (Exception $e) {
            // Incluye la RuntimeException de "slot ya reservado" (control
            // de concurrencia) y cualquier PDOException de base.
            header('Location: ' . BASE_URL . 'sistema/controladores/ControladorTurno.php?accion=nuevo&err=' . urlencode($e->getMessage()));
            exit;
        }
        break;

    // ── Cancelar turno (llama al SP) ─────────────────────────
    case 'cancelar':
        verificarRol(['admin', 'recepcionista', 'paciente']);
        csrf_verificar();
        $id  = (int)($_POST['id_turno']    ?? 0);
        $obs = trim($_POST['observacion']  ?? 'Cancelado por el usuario');

        // Un paciente solo puede cancelar SUS propios turnos (evita IDOR).
        if ($_SESSION['rol'] === 'paciente') {
            $t = $modelo->buscarPorId($id);
            if (!$t || (int)$t['id_paciente'] !== (int)($_SESSION['id_paciente'] ?? 0)) {
                header('Location: ' . BASE_URL . 'sistema/controladores/ControladorTurno.php?accion=index&err=' . urlencode('No tenés permiso para cancelar ese turno.'));
                exit;
            }
        }

        try {
            $modelo->cancelar($id, $obs);
            // Si el turno tenía un pago pendiente, anularlo.
            (new Pago($pdo))->anularPorTurno($id);
            header('Location: ' . BASE_URL . 'sistema/controladores/ControladorTurno.php?accion=index&msg=cancelado');
            exit;
        } catch (RuntimeException $e) {
            // El SP CancelarTurno rechazó la cancelación (turno inexistente
            // o ya finalizado): mostramos su mensaje al usuario.
            header('Location: ' . BASE_URL . 'sistema/controladores/ControladorTurno.php?accion=index&err=' . urlencode($e->getMessage()));
            exit;
        } catch (PDOException $e) {
            error_log('ControladorTurno cancelar: ' . $e->getMessage());
            header('Location: ' . BASE_URL . 'sistema/controladores/ControladorTurno.php?accion=index&err=' . urlencode('No se pudo cancelar el turno.'));
            exit;
        }
        break;

    // ── Cambiar estado (Confirmado / Realizado / Ausente) ────
    case 'cambiarEstado':
        verificarRol(['admin', 'recepcionista', 'medico']);
        csrf_verificar();
        $id     = (int)($_POST['id_turno'] ?? 0);
        $estado = trim($_POST['estado']   ?? '');
        $obs    = trim($_POST['observacion'] ?? '');

        $estadosValidos = ['Confirmado', 'Realizado', 'Ausente', 'Cancelado'];
        if (!in_array($estado, $estadosValidos, true)) {
            header('Location: ' . BASE_URL . 'sistema/controladores/ControladorTurno.php?accion=index&err=estado_invalido');
            exit;
        }

        // Un médico sólo puede cambiar el estado de SUS propios turnos (evita IDOR).
        if ($_SESSION['rol'] === 'medico') {
            $t = $modelo->buscarPorId($id);
            if (!$t || (int)$t['matricula'] !== (int)($_SESSION['matricula'] ?? 0)) {
                header('Location: ' . BASE_URL . 'sistema/controladores/ControladorTurno.php?accion=index&err=' . urlencode('No tenés permiso para modificar ese turno.'));
                exit;
            }
        }

        // Cancelar NO es un cambio de estado más: pasa por el SP CancelarTurno
        // (que valida que el turno no esté ya finalizado) y anula el pago
        // pendiente, igual que la acción 'cancelar'. Así no quedan turnos
        // Realizados pasados a Cancelado ni pagos colgados en 'Pendiente'.
        if ($estado === 'Cancelado') {
            try {
                $modelo->cancelar($id, $obs !== '' ? $obs : 'Cancelado por el usuario');
                (new Pago($pdo))->anularPorTurno($id);
                header('Location: ' . BASE_URL . 'sistema/controladores/ControladorTurno.php?accion=index&msg=cancelado');
                exit;
            } catch (RuntimeException $e) {
                header('Location: ' . BASE_URL . 'sistema/controladores/ControladorTurno.php?accion=index&err=' . urlencode($e->getMessage()));
                exit;
            } catch (PDOException $e) {
                error_log('ControladorTurno cambiarEstado/cancelar: ' . $e->getMessage());
                header('Location: ' . BASE_URL . 'sistema/controladores/ControladorTurno.php?accion=index&err=' . urlencode('No se pudo cancelar el turno.'));
                exit;
            }
        }

        $modelo->actualizarEstado($id, $estado, $obs);
        header('Location: ' . BASE_URL . 'sistema/controladores/ControladorTurno.php?accion=index&msg=estado_ok');
        exit;

    // ── Historial de un turno ────────────────────────────────
    case 'historial':
        $id      = (int)($_GET['id'] ?? 0);
        $turno   = $modelo->buscarPorId($id);

        // Un paciente solo puede ver el historial de SUS turnos (evita IDOR).
        if ($_SESSION['rol'] === 'paciente'
            && (!$turno || (int)$turno['id_paciente'] !== (int)($_SESSION['id_paciente'] ?? 0))) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'No autorizado']);
            exit;
        }

        // Un médico solo puede ver el historial de SUS turnos (evita IDOR).
        if ($_SESSION['rol'] === 'medico'
            && (!$turno || (int)$turno['matricula'] !== (int)($_SESSION['matricula'] ?? 0))) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'No autorizado']);
            exit;
        }

        $historial = $turno ? $modelo->obtenerHistorial($id) : [];
        // Renderizar inline como JSON para modal (consumido con JS)
        header('Content-Type: application/json');
        echo json_encode(['turno' => $turno, 'historial' => $historial]);
        exit;
    // ── Horarios de un médico (días que trabaja) ─────────
    case 'horarios':
    verificarSesion();
    $matricula = (int)($_GET['matricula'] ?? 0);
    $stmt = $pdo->prepare(
        "SELECT DISTINCT dia_semana FROM horario_atencion
         WHERE matricula = :m ORDER BY dia_semana"
    );
    $stmt->execute([':m' => $matricula]);
    header('Content-Type: application/json');
    echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
    exit;

// ── Slots disponibles para un médico en una fecha ────
case 'slots':
    verificarSesion();
    $matricula = (int)($_GET['matricula'] ?? 0);
    $fecha     = trim($_GET['fecha'] ?? '');

    header('Content-Type: application/json');

    if (!$matricula || !$fecha) {
        echo json_encode([]);
        exit;
    }

    // Toda la lógica (ausencia del médico, horarios del día y slots libres)
    // vive en Turno::obtenerSlots(); el endpoint AJAX sólo la expone como JSON.
    // obtenerSlots() ya devuelve [] si el médico está ausente ese día.
    echo json_encode($modelo->obtenerSlots($matricula, $fecha));
    exit;

// ── Fechas en que un médico está ausente (para el calendario) ─
case 'ausencias':
    verificarSesion();
    $matricula = (int)($_GET['matricula'] ?? 0);
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare(
            "SELECT fecha FROM ausencia_medico
             WHERE matricula = :m AND fecha >= CURDATE()
             ORDER BY fecha"
        );
        $stmt->execute([':m' => $matricula]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {
        // Si todavía no se corrió ausencias_medico.sql, no rompe el calendario.
        error_log('ControladorTurno ausencias: ' . $e->getMessage());
        echo json_encode([]);
    }
    exit;

    default:
        header('Location: ' . BASE_URL . 'sistema/controladores/ControladorTurno.php?accion=index');
        exit;
}

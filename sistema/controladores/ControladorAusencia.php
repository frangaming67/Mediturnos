<?php
// apartado que se encarga de gestionar las ausencias de los medicos
// Sólo admin y recepcionista. Al registrar una ausencia se cancelan
// los turnos del día y ese día deja de ofrecerse como disponible.
// -----------------------------------------------------------------
// La cancelación en cascada de los turnos de ese día vive dentro de
// Ausencia::registrar() (en el modelo) y no acá en el controlador,
// porque es una regla de negocio que debe cumplirse SIEMPRE que se
// registre una ausencia, sin importar qué controlador la dispare; y
// porque necesita ser atómica junto con el INSERT de la ausencia
// (si se cancelan turnos pero falla el insert, o viceversa, quedaría
// data inconsistente).
// -----------------------------------------------------------------

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../modelos/Ausencia.php';

verificarSesion();
verificarRol(['admin', 'recepcionista']);
csrf_verificar();

$modelo = new Ausencia($pdo);
$accion = $_GET['accion'] ?? 'index';

$BASEC = BASE_URL . 'sistema/controladores/ControladorAusencia.php';

switch ($accion) {

    // ── Listado + formularios (en modales) ───────────────────
    case 'index':
        $filtros  = ['matricula' => (int)($_GET['matricula'] ?? 0)];
        $ausencias = $modelo->listar($filtros);
        $medicos   = $modelo->listarMedicos();
        require __DIR__ . '/../vistas/ausencias/index.php';
        break;

    // ── Registrar una ausencia (POST) ────────────────────────
    case 'registrar':
        $matricula = (int)($_POST['matricula'] ?? 0);
        $fecha     = trim($_POST['fecha']  ?? '');
        $motivo    = trim($_POST['motivo'] ?? '');

        // Validaciones básicas
        if (!$matricula || $fecha === '') {
            header('Location: ' . $BASEC . '?accion=index&err=' . urlencode('Elegí un médico y una fecha.'));
            exit;
        }
        // No permitir marcar ausencias en días ya pasados (no tiene sentido).
        if ($fecha < date('Y-m-d')) {
            header('Location: ' . $BASEC . '?accion=index&err=' . urlencode('No se puede registrar una ausencia en una fecha pasada.'));
            exit;
        }

        try {
            $cancelados = $modelo->registrar($matricula, $fecha, $motivo, (int)($_SESSION['id_usuario'] ?? 0) ?: null);
            // Se informa cuántos turnos se cancelaron automáticamente.
            header('Location: ' . $BASEC . '?accion=index&msg=registrada&n=' . $cancelados);
            exit;
        } catch (RuntimeException $e) {
            // Médico ya marcado ese día (clave única).
            header('Location: ' . $BASEC . '?accion=index&err=' . urlencode($e->getMessage()));
            exit;
        } catch (PDOException $e) {
            error_log('ControladorAusencia registrar: ' . $e->getMessage());
            header('Location: ' . $BASEC . '?accion=index&err=' . urlencode('No se pudo registrar la ausencia. Intentá de nuevo.'));
            exit;
        }
        break;

    // ── Deshacer una ausencia (POST) ─────────────────────────
    case 'eliminar':
        $id = (int)($_POST['id_ausencia'] ?? 0);
        try {
            $modelo->eliminar($id);
            header('Location: ' . $BASEC . '?accion=index&msg=eliminada');
            exit;
        } catch (PDOException $e) {
            error_log('ControladorAusencia eliminar: ' . $e->getMessage());
            header('Location: ' . $BASEC . '?accion=index&err=' . urlencode('No se pudo deshacer la ausencia.'));
            exit;
        }
        break;

    default:
        header('Location: ' . $BASEC . '?accion=index');
        exit;
}

<?php
// sistema/controladores/ControladorObraSocial.php — ABM de obras sociales y planes
// -----------------------------------------------------------------
// Este controlador gestiona TRES entidades relacionadas (obra_social,
// plan_os y descuento_medico_os) en un solo archivo y con un solo switch,
// en vez de un controlador por tabla. Se decidió así porque las tres
// viven bajo una misma pantalla de administración (una obra social sin
// planes no sirve de nada, y un descuento no existe sin una obra social
// y un médico) y comparten el mismo modelo ObraSocial.php. Separarlas en
// 3 controladores obligaría a repetir el require_once/verificarRol/
// csrf_verificar tres veces para algo que el usuario percibe como una
// sola sección del sistema.
// -----------------------------------------------------------------

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../modelos/ObraSocial.php';

verificarSesion();
verificarRol(['admin']);   // Obras sociales, planes y descuentos: solo admin
csrf_verificar();

$modelo  = new ObraSocial($pdo);
$accion  = $_GET['accion'] ?? 'index';
$mensaje = null;
$tipoMsg = null;

switch ($accion) {

    // ── Listado (obras sociales + planes) ────────────────────
    case 'index':
        $filtros = ['nombre' => trim($_GET['nombre'] ?? '')];
        $obras   = $modelo->listar($filtros);
        $planes  = $modelo->listarPlanes();
        require __DIR__ . '/../vistas/obras/index.php';
        break;

    // ── Obra social: nuevo / guardar ─────────────────────────
    case 'nuevo':
        require __DIR__ . '/../vistas/obras/nuevo.php';
        break;

    case 'guardar':
        $datos = [
            'nombre' => trim($_POST['nombre'] ?? ''),
            'cuit'   => trim($_POST['cuit']   ?? ''),
        ];
        if (empty($datos['nombre']) || empty($datos['cuit'])) {
            $mensaje = 'Nombre y CUIT son obligatorios.';
            $tipoMsg = 'error';
            require __DIR__ . '/../vistas/obras/nuevo.php';
            break;
        }
        if ($modelo->existeCuit($datos['cuit'])) {
            $mensaje = 'Ya existe una obra social con ese CUIT.';
            $tipoMsg = 'error';
            require __DIR__ . '/../vistas/obras/nuevo.php';
            break;
        }
        try {
            $modelo->crear($datos);
            header('Location: ' . BASE_URL . 'sistema/controladores/ControladorObraSocial.php?accion=index&msg=creado');
            exit;
        } catch (PDOException $e) {
            error_log('ControladorObraSocial guardar: ' . $e->getMessage());
            $mensaje = 'No se pudo guardar la obra social. Intentá de nuevo.';
            $tipoMsg = 'error';
            require __DIR__ . '/../vistas/obras/nuevo.php';
        }
        break;

    // ── Obra social: editar / actualizar ─────────────────────
    case 'editar':
        $id   = (int)($_GET['id'] ?? 0);
        $obra = $modelo->buscarPorId($id);
        if (!$obra) { header('Location: ' . BASE_URL . 'sistema/controladores/ControladorObraSocial.php'); exit; }
        require __DIR__ . '/../vistas/obras/editar.php';
        break;

    case 'actualizar':
        $id    = (int)($_POST['id'] ?? 0);
        $datos = [
            'nombre' => trim($_POST['nombre'] ?? ''),
            'cuit'   => trim($_POST['cuit']   ?? ''),
        ];
        if (empty($datos['nombre']) || empty($datos['cuit'])) {
            $mensaje = 'Nombre y CUIT son obligatorios.';
            $tipoMsg = 'error';
            $obra    = $modelo->buscarPorId($id);
            require __DIR__ . '/../vistas/obras/editar.php';
            break;
        }
        if ($modelo->existeCuit($datos['cuit'], $id)) {
            $mensaje = 'Ya existe otra obra social con ese CUIT.';
            $tipoMsg = 'error';
            $obra    = $modelo->buscarPorId($id);
            require __DIR__ . '/../vistas/obras/editar.php';
            break;
        }
        try {
            $modelo->actualizar($id, $datos);
            header('Location: ' . BASE_URL . 'sistema/controladores/ControladorObraSocial.php?accion=index&msg=actualizado');
            exit;
        } catch (PDOException $e) {
            error_log('ControladorObraSocial actualizar: ' . $e->getMessage());
            $mensaje = 'No se pudo actualizar la obra social. Intentá de nuevo.';
            $tipoMsg = 'error';
            $obra    = $modelo->buscarPorId($id);
            require __DIR__ . '/../vistas/obras/editar.php';
        }
        break;

    // ── Plan: nuevo / guardar ────────────────────────────────
    case 'nuevoPlan':
        $obras = $modelo->listarSimple();
        require __DIR__ . '/../vistas/obras/plan_nuevo.php';
        break;

    case 'guardarPlan':
        $datos = [
            'id_obra_social'       => (int)($_POST['id_obra_social'] ?? 0),
            'nombre_plan'          => trim($_POST['nombre_plan'] ?? ''),
            'porcentaje_cobertura' => trim($_POST['porcentaje_cobertura'] ?? ''),
        ];
        if (!$datos['id_obra_social'] || empty($datos['nombre_plan'])) {
            $mensaje = 'Obra social y nombre del plan son obligatorios.';
            $tipoMsg = 'error';
            $obras   = $modelo->listarSimple();
            require __DIR__ . '/../vistas/obras/plan_nuevo.php';
            break;
        }
        try {
            $modelo->crearPlan($datos);
            header('Location: ' . BASE_URL . 'sistema/controladores/ControladorObraSocial.php?accion=index&msg=plan_creado');
            exit;
        } catch (PDOException $e) {
            error_log('ControladorObraSocial guardarPlan: ' . $e->getMessage());
            $mensaje = 'No se pudo guardar el plan. Intentá de nuevo.';
            $tipoMsg = 'error';
            $obras   = $modelo->listarSimple();
            require __DIR__ . '/../vistas/obras/plan_nuevo.php';
        }
        break;

    // ── Plan: editar / actualizar ────────────────────────────
    case 'editarPlan':
        $id    = (int)($_GET['id'] ?? 0);
        $plan  = $modelo->buscarPlanPorId($id);
        if (!$plan) { header('Location: ' . BASE_URL . 'sistema/controladores/ControladorObraSocial.php'); exit; }
        $obras = $modelo->listarSimple();
        require __DIR__ . '/../vistas/obras/plan_editar.php';
        break;

    case 'actualizarPlan':
        $id    = (int)($_POST['id'] ?? 0);
        $datos = [
            'id_obra_social'       => (int)($_POST['id_obra_social'] ?? 0),
            'nombre_plan'          => trim($_POST['nombre_plan'] ?? ''),
            'porcentaje_cobertura' => trim($_POST['porcentaje_cobertura'] ?? ''),
        ];
        if (!$datos['id_obra_social'] || empty($datos['nombre_plan'])) {
            $mensaje = 'Obra social y nombre del plan son obligatorios.';
            $tipoMsg = 'error';
            $plan    = $modelo->buscarPlanPorId($id);
            $obras   = $modelo->listarSimple();
            require __DIR__ . '/../vistas/obras/plan_editar.php';
            break;
        }
        try {
            $modelo->actualizarPlan($id, $datos);
            header('Location: ' . BASE_URL . 'sistema/controladores/ControladorObraSocial.php?accion=index&msg=plan_actualizado');
            exit;
        } catch (PDOException $e) {
            error_log('ControladorObraSocial actualizarPlan: ' . $e->getMessage());
            $mensaje = 'No se pudo actualizar el plan. Intentá de nuevo.';
            $tipoMsg = 'error';
            $plan    = $modelo->buscarPlanPorId($id);
            $obras   = $modelo->listarSimple();
            require __DIR__ . '/../vistas/obras/plan_editar.php';
        }
        break;

    // ── Descuentos por obra social y médico ──────────────────
    case 'descuentos':
        $filtros = [
            'id_obra_social' => (int)($_GET['id_obra_social'] ?? 0),
            'matricula'      => (int)($_GET['matricula'] ?? 0),
        ];
        $descuentos = $modelo->listarDescuentos($filtros);
        $obras      = $modelo->listarSimple();
        $medicos    = $modelo->listarMedicos();
        require __DIR__ . '/../vistas/obras/descuentos.php';
        break;

    case 'guardarDescuento':
        $idOS      = (int)($_POST['id_obra_social'] ?? 0);
        $matricula = (int)($_POST['matricula'] ?? 0);
        $pct       = (float)str_replace(',', '.', $_POST['porcentaje_descuento'] ?? '0');

        if (!$idOS || !$matricula) {
            header('Location: ' . BASE_URL . 'sistema/controladores/ControladorObraSocial.php?accion=descuentos&err=' . urlencode('Elegí obra social y médico.'));
            exit;
        }
        if ($pct < 0 || $pct > 100) {
            header('Location: ' . BASE_URL . 'sistema/controladores/ControladorObraSocial.php?accion=descuentos&err=' . urlencode('El descuento debe estar entre 0 y 100%.'));
            exit;
        }
        try {
            $modelo->guardarDescuento($idOS, $matricula, $pct);
            header('Location: ' . BASE_URL . 'sistema/controladores/ControladorObraSocial.php?accion=descuentos&msg=desc_guardado');
            exit;
        } catch (PDOException $e) {
            error_log('ControladorObraSocial guardarDescuento: ' . $e->getMessage());
            header('Location: ' . BASE_URL . 'sistema/controladores/ControladorObraSocial.php?accion=descuentos&err=' . urlencode('No se pudo guardar el descuento.'));
            exit;
        }

    case 'eliminarDescuento':
        $idDesc = (int)($_POST['id_descuento'] ?? 0);
        try {
            $modelo->eliminarDescuento($idDesc);
            header('Location: ' . BASE_URL . 'sistema/controladores/ControladorObraSocial.php?accion=descuentos&msg=desc_eliminado');
            exit;
        } catch (PDOException $e) {
            error_log('ControladorObraSocial eliminarDescuento: ' . $e->getMessage());
            header('Location: ' . BASE_URL . 'sistema/controladores/ControladorObraSocial.php?accion=descuentos&err=' . urlencode('No se pudo eliminar el descuento.'));
            exit;
        }

    default:
        header('Location: ' . BASE_URL . 'sistema/controladores/ControladorObraSocial.php?accion=index');
        exit;
}

<?php
// sistema/controladores/ControladorPaciente.php — Entry point para /pacientes
// -----------------------------------------------------------------
// A diferencia de ControladorMedico, acá NO hay verificarPermiso() por
// caso: admin y recepcionista tienen exactamente los mismos permisos
// sobre pacientes (crear, editar, listar), no hace falta el segundo nivel
// de control fino. El registro público de pacientes (sin sesión) es un
// flujo totalmente distinto y vive en registro.php + ControladorAuth,
// no acá — este controlador es solo para la gestión interna (ABM) que
// hace el staff.
// -----------------------------------------------------------------

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../modelos/Paciente.php';

verificarSesion();
verificarRol(['admin', 'recepcionista']);  // Gestión de pacientes: nunca un paciente
csrf_verificar();

$modelo  = new Paciente($pdo);
$accion  = $_GET['accion'] ?? 'index';
$mensaje = null;
$tipoMsg = null;

switch ($accion) {

    // ── Listado ──────────────────────────────────────────────
    case 'index':
        $filtros   = [
            'nombre'   => trim($_GET['nombre']   ?? ''),
            'apellido' => trim($_GET['apellido'] ?? ''),
            'dni'      => trim($_GET['dni']      ?? ''),
        ];

        // Paginación: la página nunca puede ser menor a 1 ni mayor al total
        // de páginas (si alguien pone ?pagina=999 a mano, se lo acota).
        $total       = $modelo->contar($filtros);
        $porPagina   = Paciente::POR_PAGINA;
        $totalPaginas = max(1, (int) ceil($total / $porPagina));
        $pagina      = max(1, (int) ($_GET['pagina'] ?? 1));
        $pagina      = min($pagina, $totalPaginas);

        $pacientes   = $modelo->listar($filtros, $pagina);
        require __DIR__ . '/../vistas/pacientes/index.php';
        break;

    // ── Formulario nuevo ─────────────────────────────────────
    case 'nuevo':
        verificarRol(['admin', 'recepcionista']);
        require __DIR__ . '/../vistas/pacientes/nuevo.php';
        break;

    // ── Guardar nuevo ────────────────────────────────────────
    case 'guardar':
        verificarRol(['admin', 'recepcionista']);

        $datos = [
            'dni'      => trim($_POST['dni']      ?? ''),
            'nombre'   => trim($_POST['nombre']   ?? ''),
            'apellido' => trim($_POST['apellido'] ?? ''),
            'fecha_nac'=> trim($_POST['fecha_nac'] ?? ''),
            'telefono' => trim($_POST['telefono'] ?? ''),
            'email'    => trim($_POST['email']    ?? ''),
        ];

        // Validaciones
        if (empty($datos['dni']) || empty($datos['nombre']) || empty($datos['apellido'])) {
            $mensaje = 'DNI, nombre y apellido son obligatorios.';
            $tipoMsg = 'error';
            require __DIR__ . '/../vistas/pacientes/nuevo.php';
            break;
        }
        if ($modelo->existeDni($datos['dni'])) {
            $mensaje = 'Ya existe un paciente registrado con ese DNI.';
            $tipoMsg = 'error';
            require __DIR__ . '/../vistas/pacientes/nuevo.php';
            break;
        }

        try {
            $modelo->crear($datos);
            header('Location: ' . BASE_URL . 'sistema/controladores/ControladorPaciente.php?accion=index&msg=creado');
            exit;
        } catch (PDOException $e) {
            error_log('ControladorPaciente guardar: ' . $e->getMessage());
            $mensaje = 'No se pudo guardar el paciente. Revisá los datos e intentá de nuevo.';
            $tipoMsg = 'error';
            require __DIR__ . '/../vistas/pacientes/nuevo.php';
        }
        break;

    // ── Formulario editar ────────────────────────────────────
    case 'editar':
        verificarRol(['admin', 'recepcionista']);
        $id      = (int)($_GET['id'] ?? 0);
        $paciente = $modelo->buscarPorId($id);
        if (!$paciente) { header('Location: ' . BASE_URL . 'sistema/controladores/ControladorPaciente.php'); exit; }
        require __DIR__ . '/../vistas/pacientes/editar.php';
        break;

    // ── Guardar edición ──────────────────────────────────────
    case 'actualizar':
        verificarRol(['admin', 'recepcionista']);
        $id    = (int)($_POST['id'] ?? 0);
        $datos = [
            'dni'      => trim($_POST['dni']      ?? ''),
            'nombre'   => trim($_POST['nombre']   ?? ''),
            'apellido' => trim($_POST['apellido'] ?? ''),
            'fecha_nac'=> trim($_POST['fecha_nac'] ?? ''),
            'telefono' => trim($_POST['telefono'] ?? ''),
            'email'    => trim($_POST['email']    ?? ''),
        ];

        if (empty($datos['dni']) || empty($datos['nombre']) || empty($datos['apellido'])) {
            $mensaje = 'DNI, nombre y apellido son obligatorios.';
            $tipoMsg = 'error';
            $paciente = $modelo->buscarPorId($id);
            require __DIR__ . '/../vistas/pacientes/editar.php';
            break;
        }
        if ($modelo->existeDni($datos['dni'], $id)) {
            $mensaje  = 'Ya existe otro paciente con ese DNI.';
            $tipoMsg  = 'error';
            $paciente = $modelo->buscarPorId($id);
            require __DIR__ . '/../vistas/pacientes/editar.php';
            break;
        }

        try {
            $modelo->actualizar($id, $datos);
            header('Location: ' . BASE_URL . 'sistema/controladores/ControladorPaciente.php?accion=index&msg=actualizado');
            exit;
        } catch (PDOException $e) {
            error_log('ControladorPaciente actualizar: ' . $e->getMessage());
            $mensaje  = 'No se pudo actualizar el paciente. Revisá los datos e intentá de nuevo.';
            $tipoMsg  = 'error';
            $paciente = $modelo->buscarPorId($id);
            require __DIR__ . '/../vistas/pacientes/editar.php';
        }
        break;

    default:
        header('Location: ' . BASE_URL . 'sistema/controladores/ControladorPaciente.php?accion=index');
        exit;
}

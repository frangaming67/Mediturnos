<?php
// sistema/controladores/ControladorConsultorio.php — ABM de consultorios
// -----------------------------------------------------------------
// Solo 'admin' (ni siquiera recepcionista) porque un consultorio es un
// dato de infraestructura física del centro médico (número, piso,
// equipamiento), no algo operativo del día a día como sí lo es un turno
// o un paciente. Se agrupan 'numero'+'piso' en existeNumeroPiso() porque
// juntos forman la clave real que identifica un consultorio (dos pisos
// distintos pueden tener ambos un "consultorio 3").
// -----------------------------------------------------------------

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../modelos/Consultorio.php';

verificarSesion();
verificarRol(['admin']);   // Consultorios: solo admin
csrf_verificar();

$modelo  = new Consultorio($pdo);
$accion  = $_GET['accion'] ?? 'index';
$mensaje = null;
$tipoMsg = null;

switch ($accion) {

    case 'index':
        $consultorios = $modelo->listar();
        require __DIR__ . '/../vistas/consultorios/index.php';
        break;

    case 'nuevo':
        require __DIR__ . '/../vistas/consultorios/nuevo.php';
        break;

    case 'guardar':
        $datos = [
            'numero'                   => trim($_POST['numero'] ?? ''),
            'piso'                     => trim($_POST['piso']   ?? ''),
            'descripcion_equipamiento' => trim($_POST['descripcion_equipamiento'] ?? ''),
        ];
        if ($datos['numero'] === '' || $datos['piso'] === ''
            || !ctype_digit($datos['numero']) || !ctype_digit($datos['piso'])) {
            $mensaje = 'Número y piso son obligatorios y deben ser numéricos.';
            $tipoMsg = 'error';
            require __DIR__ . '/../vistas/consultorios/nuevo.php';
            break;
        }
        if ($modelo->existeNumeroPiso((int)$datos['numero'], (int)$datos['piso'])) {
            $mensaje = 'Ya existe un consultorio con ese número en ese piso.';
            $tipoMsg = 'error';
            require __DIR__ . '/../vistas/consultorios/nuevo.php';
            break;
        }
        try {
            $modelo->crear($datos);
            header('Location: ' . BASE_URL . 'sistema/controladores/ControladorConsultorio.php?accion=index&msg=creado');
            exit;
        } catch (PDOException $e) {
            error_log('ControladorConsultorio guardar: ' . $e->getMessage());
            $mensaje = 'No se pudo guardar el consultorio. Intentá de nuevo.';
            $tipoMsg = 'error';
            require __DIR__ . '/../vistas/consultorios/nuevo.php';
        }
        break;

    case 'editar':
        $id          = (int)($_GET['id'] ?? 0);
        $consultorio = $modelo->buscarPorId($id);
        if (!$consultorio) { header('Location: ' . BASE_URL . 'sistema/controladores/ControladorConsultorio.php'); exit; }
        require __DIR__ . '/../vistas/consultorios/editar.php';
        break;

    case 'actualizar':
        $id    = (int)($_POST['id'] ?? 0);
        $datos = [
            'numero'                   => trim($_POST['numero'] ?? ''),
            'piso'                     => trim($_POST['piso']   ?? ''),
            'descripcion_equipamiento' => trim($_POST['descripcion_equipamiento'] ?? ''),
        ];
        if ($datos['numero'] === '' || $datos['piso'] === ''
            || !ctype_digit($datos['numero']) || !ctype_digit($datos['piso'])) {
            $mensaje     = 'Número y piso son obligatorios y deben ser numéricos.';
            $tipoMsg     = 'error';
            $consultorio = $modelo->buscarPorId($id);
            require __DIR__ . '/../vistas/consultorios/editar.php';
            break;
        }
        if ($modelo->existeNumeroPiso((int)$datos['numero'], (int)$datos['piso'], $id)) {
            $mensaje     = 'Ya existe otro consultorio con ese número en ese piso.';
            $tipoMsg     = 'error';
            $consultorio = $modelo->buscarPorId($id);
            require __DIR__ . '/../vistas/consultorios/editar.php';
            break;
        }
        try {
            $modelo->actualizar($id, $datos);
            header('Location: ' . BASE_URL . 'sistema/controladores/ControladorConsultorio.php?accion=index&msg=actualizado');
            exit;
        } catch (PDOException $e) {
            error_log('ControladorConsultorio actualizar: ' . $e->getMessage());
            $mensaje     = 'No se pudo actualizar el consultorio. Intentá de nuevo.';
            $tipoMsg     = 'error';
            $consultorio = $modelo->buscarPorId($id);
            require __DIR__ . '/../vistas/consultorios/editar.php';
        }
        break;

    default:
        header('Location: ' . BASE_URL . 'sistema/controladores/ControladorConsultorio.php?accion=index');
        exit;
}

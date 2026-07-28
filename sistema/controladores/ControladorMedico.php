<?php
// sistema/controladores/ControladorMedico.php
// -----------------------------------------------------------------
// Combina dos niveles de control de acceso, y por eso aparecen los dos:
//   verificarRol() acá arriba   → filtro GRUESO: solo admin/recepcionista
//                                  pueden entrar a este controlador (un
//                                  paciente ni siquiera llega al switch).
//   verificarPermiso() en cada  → filtro FINO: dentro de ese grupo, el
//   case sensible                 recepcionista tiene 'medicos.ver' pero
//                                  NO 'medicos.crear'/'medicos.baja', que
//                                  quedan reservados al admin. Sin este
//                                  segundo nivel, cualquier recepcionista
//                                  podría dar de alta o baja médicos.
// -----------------------------------------------------------------

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../modelos/Medico.php';

verificarSesion();
verificarRol(['admin', 'recepcionista']);
csrf_verificar();

$modelo  = new Medico($pdo);
$accion  = $_GET['accion'] ?? 'index';
$mensaje = null;
$tipoMsg = null;

switch ($accion) {

    case 'index':
        $filtros = [
            'nombre'         => trim($_GET['nombre']         ?? ''),
            'apellido'       => trim($_GET['apellido']       ?? ''),
            'id_especialidad'=> (int)($_GET['id_especialidad'] ?? 0),
            'estado'         => trim($_GET['estado']         ?? ''),
        ];
        $medicos       = $modelo->listar($filtros);
        $especialidades = $modelo->obtenerEspecialidades();
        require __DIR__ . '/../vistas/medicos/index.php';
        break;

    case 'nuevo':
        verificarPermiso('medicos.crear');   // el recepcionista solo tiene medicos.ver
        $especialidades = $modelo->obtenerEspecialidades();
        require __DIR__ . '/../vistas/medicos/nuevo.php';
        break;

    case 'guardar':
        verificarPermiso('medicos.crear');
        $datos = [
            'matricula'     => trim($_POST['matricula']  ?? ''),
            'nombre'        => trim($_POST['nombre']     ?? ''),
            'apellido'      => trim($_POST['apellido']   ?? ''),
            'telefono'      => trim($_POST['telefono']   ?? ''),
            'email'         => trim($_POST['email']      ?? ''),
            'especialidades'=> $_POST['especialidades']  ?? [],
        ];

        if (empty($datos['matricula']) || empty($datos['nombre']) || empty($datos['apellido'])) {
            $mensaje        = 'Matrícula, nombre y apellido son obligatorios.';
            $tipoMsg        = 'error';
            $especialidades = $modelo->obtenerEspecialidades();
            require __DIR__ . '/../vistas/medicos/nuevo.php';
            break;
        }

        try {
            $modelo->crear($datos);
            header('Location: ' . BASE_URL . 'sistema/controladores/ControladorMedico.php?accion=index&msg=creado');
            exit;
        } catch (PDOException $e) {
            error_log('ControladorMedico guardar: ' . $e->getMessage());
            $mensaje        = ((int)$e->getCode() === 23000 || $e->getCode() === '23000')
                ? 'Ya existe un médico con esa matrícula.'
                : 'No se pudo guardar el médico. Revisá los datos e intentá de nuevo.';
            $tipoMsg        = 'error';
            $especialidades = $modelo->obtenerEspecialidades();
            require __DIR__ . '/../vistas/medicos/nuevo.php';
        }
        break;

    case 'editar':
        verificarPermiso('medicos.editar');   // el recepcionista solo tiene medicos.ver
        $matricula      = (int)($_GET['id'] ?? 0);
        $medico         = $modelo->buscarPorMatricula($matricula);
        if (!$medico) { header('Location: ' . BASE_URL . 'sistema/controladores/ControladorMedico.php'); exit; }
        $especialidades = $modelo->obtenerEspecialidades();
        require __DIR__ . '/../vistas/medicos/editar.php';
        break;

    case 'actualizar':
        verificarPermiso('medicos.editar');
        $matricula = (int)($_POST['matricula'] ?? 0);
        $datos = [
            'nombre'        => trim($_POST['nombre']     ?? ''),
            'apellido'      => trim($_POST['apellido']   ?? ''),
            'telefono'      => trim($_POST['telefono']   ?? ''),
            'email'         => trim($_POST['email']      ?? ''),
            'especialidades'=> $_POST['especialidades']  ?? [],
        ];

        if (empty($datos['nombre']) || empty($datos['apellido'])) {
            $mensaje        = 'Nombre y apellido son obligatorios.';
            $tipoMsg        = 'error';
            $medico         = $modelo->buscarPorMatricula($matricula);
            $especialidades = $modelo->obtenerEspecialidades();
            require __DIR__ . '/../vistas/medicos/editar.php';
            break;
        }

        try {
            $modelo->actualizar($matricula, $datos);
            header('Location: ' . BASE_URL . 'sistema/controladores/ControladorMedico.php?accion=index&msg=actualizado');
            exit;
        } catch (PDOException $e) {
            error_log('ControladorMedico actualizar: ' . $e->getMessage());
            $mensaje        = 'No se pudo actualizar el médico. Revisá los datos e intentá de nuevo.';
            $tipoMsg        = 'error';
            $medico         = $modelo->buscarPorMatricula($matricula);
            $especialidades = $modelo->obtenerEspecialidades();
            require __DIR__ . '/../vistas/medicos/editar.php';
        }
        break;

    case 'baja':
        verificarPermiso('medicos.baja');   // solo el admin lo tiene
        $matricula = (int)($_POST['matricula'] ?? 0);
        if ($matricula > 0) {
            $modelo->darDeBaja($matricula);
        }
        header('Location: ' . BASE_URL . 'sistema/controladores/ControladorMedico.php?accion=index&msg=baja');
        exit;

    case 'reactivar':
        verificarPermiso('medicos.baja');   // solo el admin lo tiene
        $matricula = (int)($_POST['matricula'] ?? 0);
        if ($matricula > 0) {
            $modelo->reactivar($matricula);
        }
        header('Location: ' . BASE_URL . 'sistema/controladores/ControladorMedico.php?accion=index&msg=alta');
        exit;

    default:
        header('Location: ' . BASE_URL . 'sistema/controladores/ControladorMedico.php?accion=index');
        exit;
}

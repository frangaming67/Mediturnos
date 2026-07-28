<?php
// sistema/controladores/ControladorHorario.php — ABM de horarios de atención

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../modelos/Horario.php';

verificarSesion();
verificarRol(['admin', 'recepcionista']);
csrf_verificar();

$modelo  = new Horario($pdo);
$accion  = $_GET['accion'] ?? 'index';
$mensaje = null;
$tipoMsg = null;

// validarHorario() y leerDatosHorario() viven ACÁ (no en el modelo Horario)
// porque son responsabilidad de la CAPA WEB: leen directamente de $_POST y
// validan formato de entrada (campos vacíos, día de semana válido). La
// regla de negocio real —si dos horarios se pisan— sí vive en el modelo
// (Horario::haySolapamiento), porque esa comprobación necesita consultar
// la base y aplica sin importar de dónde vengan los datos.
// Se declaran como funciones sueltas y no se repite el código en 'guardar'
// y 'actualizar' porque ambos casos arman y validan el mismo shape de
// datos; solo cambia si el destino es un INSERT o un UPDATE.

/**
 * Valida los datos de un horario. Devuelve string de error o null si OK.
 */
function validarHorario(array $d): ?string
{
    if (!$d['matricula'] || !$d['id_especialidad'] || !$d['id_consultorio']
        || empty($d['dia_semana']) || empty($d['hora_inicio']) || empty($d['hora_fin'])) {
        return 'Todos los campos son obligatorios.';
    }
    if (!in_array($d['dia_semana'], Horario::DIAS, true)) {
        return 'Día de la semana inválido.';
    }
    if ($d['hora_inicio'] >= $d['hora_fin']) {
        return 'La hora de inicio debe ser anterior a la hora de fin.';
    }
    return null;
}

function leerDatosHorario(): array
{
    return [
        'matricula'       => (int)($_POST['matricula']       ?? 0),
        'id_especialidad' => (int)($_POST['id_especialidad'] ?? 0),
        'id_consultorio'  => (int)($_POST['id_consultorio']  ?? 0),
        'dia_semana'      => trim($_POST['dia_semana']  ?? ''),
        'hora_inicio'     => trim($_POST['hora_inicio'] ?? ''),
        'hora_fin'        => trim($_POST['hora_fin']    ?? ''),
    ];
}

switch ($accion) {

    case 'index':
        $filtros  = ['matricula' => (int)($_GET['matricula'] ?? 0)];
        $horarios = $modelo->listar($filtros);
        $medicos  = $modelo->listarMedicos();
        require __DIR__ . '/../vistas/horarios/index.php';
        break;

    case 'nuevo':
        $medicos        = $modelo->listarMedicos();
        $especialidades = $modelo->listarEspecialidades();
        $consultorios   = $modelo->listarConsultorios();
        require __DIR__ . '/../vistas/horarios/nuevo.php';
        break;

    case 'guardar':
        $datos = leerDatosHorario();
        $err   = validarHorario($datos);
        if (!$err && $modelo->haySolapamiento($datos['matricula'], $datos['dia_semana'], $datos['hora_inicio'], $datos['hora_fin'])) {
            $err = 'El médico ya tiene un horario que se superpone ese día.';
        }
        if ($err) {
            $mensaje        = $err;
            $tipoMsg        = 'error';
            $medicos        = $modelo->listarMedicos();
            $especialidades = $modelo->listarEspecialidades();
            $consultorios   = $modelo->listarConsultorios();
            require __DIR__ . '/../vistas/horarios/nuevo.php';
            break;
        }
        try {
            $modelo->crear($datos);
            header('Location: ' . BASE_URL . 'sistema/controladores/ControladorHorario.php?accion=index&msg=creado');
            exit;
        } catch (PDOException $e) {
            error_log('ControladorHorario guardar: ' . $e->getMessage());
            $mensaje        = 'No se pudo guardar el horario. Intentá de nuevo.';
            $tipoMsg        = 'error';
            $medicos        = $modelo->listarMedicos();
            $especialidades = $modelo->listarEspecialidades();
            $consultorios   = $modelo->listarConsultorios();
            require __DIR__ . '/../vistas/horarios/nuevo.php';
        }
        break;

    case 'editar':
        $id      = (int)($_GET['id'] ?? 0);
        $horario = $modelo->buscarPorId($id);
        if (!$horario) { header('Location: ' . BASE_URL . 'sistema/controladores/ControladorHorario.php'); exit; }
        $medicos        = $modelo->listarMedicos();
        $especialidades = $modelo->listarEspecialidades();
        $consultorios   = $modelo->listarConsultorios();
        require __DIR__ . '/../vistas/horarios/editar.php';
        break;

    case 'actualizar':
        $id    = (int)($_POST['id'] ?? 0);
        $datos = leerDatosHorario();
        $err   = validarHorario($datos);
        if (!$err && $modelo->haySolapamiento($datos['matricula'], $datos['dia_semana'], $datos['hora_inicio'], $datos['hora_fin'], $id)) {
            $err = 'El médico ya tiene un horario que se superpone ese día.';
        }
        if ($err) {
            $mensaje        = $err;
            $tipoMsg        = 'error';
            $horario        = $modelo->buscarPorId($id);
            $medicos        = $modelo->listarMedicos();
            $especialidades = $modelo->listarEspecialidades();
            $consultorios   = $modelo->listarConsultorios();
            require __DIR__ . '/../vistas/horarios/editar.php';
            break;
        }
        try {
            $modelo->actualizar($id, $datos);
            header('Location: ' . BASE_URL . 'sistema/controladores/ControladorHorario.php?accion=index&msg=actualizado');
            exit;
        } catch (PDOException $e) {
            error_log('ControladorHorario actualizar: ' . $e->getMessage());
            $mensaje        = 'No se pudo actualizar el horario. Intentá de nuevo.';
            $tipoMsg        = 'error';
            $horario        = $modelo->buscarPorId($id);
            $medicos        = $modelo->listarMedicos();
            $especialidades = $modelo->listarEspecialidades();
            $consultorios   = $modelo->listarConsultorios();
            require __DIR__ . '/../vistas/horarios/editar.php';
        }
        break;

    default:
        header('Location: ' . BASE_URL . 'sistema/controladores/ControladorHorario.php?accion=index');
        exit;
}

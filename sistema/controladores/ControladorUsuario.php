<?php
// sistema/controladores/ControladorUsuario.php
// -----------------------------------------------------------------
// Solo 'admin': un usuario del sistema (login/contraseña/rol) es distinto
// de un paciente o un médico (que son datos clínicos/administrativos);
// gestionar QUIÉN puede entrar al sistema y con qué rol es la operación
// más sensible de toda la app, por eso queda fuera del alcance de
// recepcionista. El caso 'baja' bloquea explícitamente que un admin se
// dé de baja a sí mismo (línea "$id === $_SESSION['id_usuario']"),
// porque si no existiría el camino para quedarse sin ningún admin activo
// y bloquear la gestión de usuarios por completo.
// -----------------------------------------------------------------

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../modelos/Usuario.php';

verificarSesion();
verificarRol(['admin']);    // Solo administradores gestionan usuarios
csrf_verificar();

$modelo  = new Usuario($pdo);
$accion  = $_GET['accion'] ?? 'index';
$mensaje = null;
$tipoMsg = null;

switch ($accion) {

    case 'index':
        $filtros = [
            'id_rol'   => (int)($_GET['id_rol']   ?? 0),
            'estado'   => trim($_GET['estado']    ?? ''),
            'busqueda' => trim($_GET['busqueda']  ?? ''),
        ];
        // Paginación (acota ?pagina=999 escrito a mano)
        $total        = $modelo->contar($filtros);
        $totalPaginas = max(1, (int) ceil($total / Usuario::POR_PAGINA));
        $pagina       = min(max(1, (int) ($_GET['pagina'] ?? 1)), $totalPaginas);

        $usuarios = $modelo->listar($filtros, $pagina);
        $roles    = $modelo->listarRoles();
        require __DIR__ . '/../vistas/usuarios/index.php';
        break;

    case 'nuevo':
        $roles = $modelo->listarRoles();
        require __DIR__ . '/../vistas/usuarios/nuevo.php';
        break;

    case 'guardar':
        $datos = [
            'nombre'      => trim($_POST['nombre']      ?? ''),
            'apellido'    => trim($_POST['apellido']    ?? ''),
            'usuario'     => trim($_POST['usuario']     ?? ''),
            'email'       => trim($_POST['email']       ?? ''),
            'contrasenia' => trim($_POST['contrasenia'] ?? ''),
            'id_rol'      => (int)($_POST['id_rol']     ?? 0),
            'id_paciente' => trim($_POST['id_paciente'] ?? ''),
            'matricula'   => trim($_POST['matricula']   ?? ''),
        ];

        if (empty($datos['nombre']) || empty($datos['apellido'])
            || empty($datos['usuario']) || empty($datos['email'])
            || empty($datos['contrasenia']) || !$datos['id_rol']) {
            $mensaje = 'Nombre, apellido, usuario, email, contraseña y rol son obligatorios.';
            $tipoMsg = 'error';
            $roles   = $modelo->listarRoles();
            require __DIR__ . '/../vistas/usuarios/nuevo.php';
            break;
        }

        if (!filter_var($datos['email'], FILTER_VALIDATE_EMAIL)) {
            $mensaje = 'El email no tiene un formato válido.';
            $tipoMsg = 'error';
            $roles   = $modelo->listarRoles();
            require __DIR__ . '/../vistas/usuarios/nuevo.php';
            break;
        }

        if ($modelo->existeUsuario($datos['usuario'])) {
            $mensaje = 'El nombre de usuario ya está en uso.';
            $tipoMsg = 'error';
            $roles   = $modelo->listarRoles();
            require __DIR__ . '/../vistas/usuarios/nuevo.php';
            break;
        }

        if ($modelo->existeEmail($datos['email'])) {
            $mensaje = 'Ya existe un usuario con ese email.';
            $tipoMsg = 'error';
            $roles   = $modelo->listarRoles();
            require __DIR__ . '/../vistas/usuarios/nuevo.php';
            break;
        }

        try {
            $modelo->crear($datos);
            header('Location: ' . BASE_URL . 'sistema/controladores/ControladorUsuario.php?accion=index&msg=creado');
            exit;
        } catch (PDOException $e) {
            error_log('ControladorUsuario guardar: ' . $e->getMessage());
            $mensaje = 'No se pudo crear el usuario. Revisá los datos e intentá de nuevo.';
            $tipoMsg = 'error';
            $roles   = $modelo->listarRoles();
            require __DIR__ . '/../vistas/usuarios/nuevo.php';
        }
        break;

    case 'editar':
        $id      = (int)($_GET['id'] ?? 0);
        $usuario = $modelo->buscarPorId($id);

        if (!$usuario) { 
            header('Location: ' . BASE_URL . 'sistema/controladores/ControladorUsuario.php'); 
            exit; 
        }

        $roles   = $modelo->listarRoles();
        require __DIR__ . '/../vistas/usuarios/editar.php';
        break;

    case 'actualizar':
        $id   = (int)($_POST['id'] ?? 0);
        
        $datos = [
            'nombre'   => trim($_POST['nombre']   ?? ''),
            'apellido' => trim($_POST['apellido'] ?? ''),
            'email'    => trim($_POST['email']    ?? ''),
            'id_rol'   => (int)($_POST['id_rol']  ?? 0),
            'estado'   => trim($_POST['estado']   ?? 'activo'),
        ];

        if (empty($datos['nombre']) || empty($datos['apellido']) || !$datos['id_rol']) {
            $mensaje = 'Nombre, apellido y rol son obligatorios.';
            $tipoMsg = 'error';
            $usuario = $modelo->buscarPorId($id);
            $roles   = $modelo->listarRoles();
            require __DIR__ . '/../vistas/usuarios/editar.php';
            break;
        }

        try {
            $modelo->actualizar($id, $datos);
            header('Location: ' . BASE_URL . 'sistema/controladores/ControladorUsuario.php?accion=index&msg=actualizado');
            exit;
        } catch (PDOException $e) {
            error_log('ControladorUsuario actualizar: ' . $e->getMessage());
            $mensaje = 'No se pudo actualizar el usuario. Revisá los datos e intentá de nuevo.';
            $tipoMsg = 'error';
            $usuario = $modelo->buscarPorId($id);
            $roles   = $modelo->listarRoles();
            require __DIR__ . '/../vistas/usuarios/editar.php';
        }
        break;

    case 'baja':
        $id = (int)($_POST['id'] ?? 0);
        // No se puede dar de baja el propio usuario
        if ($id === (int)($_SESSION['id_usuario'] ?? 0)) {
            header('Location: ' . BASE_URL . 'sistema/controladores/ControladorUsuario.php?accion=index&err=self');
            exit;
        }
        $modelo->darDeBaja($id);
        header('Location: ' . BASE_URL . 'sistema/controladores/ControladorUsuario.php?accion=index&msg=baja');
        exit;
    default:
        header('Location: ' . BASE_URL . 'sistema/controladores/ControladorUsuario.php?accion=index');
    exit;
}

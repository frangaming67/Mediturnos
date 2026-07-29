<?php
// sistema/controladores/ControladorAuth.php
// Clase de autenticación — incluida desde login.php y logout.php
// -----------------------------------------------------------------
// Es el ÚNICO controlador que se escribió como CLASE en vez de script
// con switch($accion). El motivo: los demás controladores (ControladorTurno,
// ControladorMedico, etc.) SON la URL que el navegador visita directamente
// (?accion=index / nuevo / guardar), así que un switch que lee $_GET/$_POST
// tiene sentido. ControladorAuth, en cambio, nunca se visita solo: lo
// incluyen login.php, registro.php y logout.php, cada uno con SU propio
// flujo (formulario + POST) y necesitan invocar un método puntual
// (login(), registrar(), logout()) en el momento justo — eso pide una
// clase con métodos, no un router por acción.
// -----------------------------------------------------------------

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../includes/validacion.php';
require_once __DIR__ . '/../modelos/Usuario.php';

class ControladorAuth
{
    private Usuario $modelo;

    public function __construct(PDO $pdo)
    {
        $this->modelo = new Usuario($pdo);
    }

    /**
     * Procesa el formulario de login.
     * Retorna un string de error o null si fue exitoso (ya redirigió).
     */
    public function login(string $usuarioInput, string $passInput): ?string
    {
        if (empty($usuarioInput) || empty($passInput)) {
            return 'Completá todos los campos.';
        }

        $user = $this->modelo->buscarParaLogin($usuarioInput);

        if (!$user || !password_verify($passInput, $user['contrasenia'])) {
            return 'Usuario o contraseña incorrectos.';
        }

        // Regenerar ID de sesión — previene Session Fixation
        session_regenerate_id(true);

        // Guardar datos en sesión
        $_SESSION['id_usuario']   = $user['id_usuario'];
        $_SESSION['nombre']       = $user['nombre'];
        $_SESSION['apellido']     = $user['apellido'];
        $_SESSION['rol']          = $user['rol'];
        $_SESSION['permisos']     = $user['permisos'];
        $_SESSION['id_paciente']   = $user['id_paciente'];
        $_SESSION['matricula']     = $user['matricula'];
        $_SESSION['ultimo_acceso'] = time();
        // Registrar login en DB
        $this->modelo->registrarLogin($user['id_usuario']);

        // Redirigir al dashboard
        header('Location: ' . BASE_URL . 'dashboard.php');
        exit;
    }


    /**
     * Registro público de un paciente.
     * Retorna un string de error, o null si fue exitoso (ya redirigió a login).
     */
    /**
     * Longitud mínima de contraseña.
     *
     * Se conserva como alias de Validacion::PASS_MIN porque registro.php
     * ya la lee así para dibujar el texto de ayuda y el validador de
     * JavaScript. El valor real vive en un solo lugar.
     */
    public const PASS_MIN = Validacion::PASS_MIN;

    public function registrar(array $datos): ?string
    {
        // ── Obligatorios ─────────────────────────────────────────
        // TODA esta validación se repite acá aunque el formulario ya
        // valide en el navegador: el JavaScript se puede desactivar y el
        // POST se puede armar a mano. El servidor es la única frontera
        // que un atacante no controla.
        if (empty($datos['nombre']) || empty($datos['apellido']) || empty($datos['dni'])
            || empty($datos['telefono']) || empty($datos['email'])
            || empty($datos['usuario'])
            || empty($datos['contrasenia']) || empty($datos['contrasenia2'])) {
            return 'Completá todos los campos obligatorios.';
        }

        // ── Campo por campo ──────────────────────────────────────
        // Las reglas viven en includes/validacion.php, compartidas con
        // el perfil y con el restablecimiento de contraseña: son las
        // mismas tres pantallas pidiendo los mismos datos, y tenerlas
        // escritas una sola vez es lo que impide que se separen con el
        // tiempo. Cada método devuelve el mensaje de error o null.
        if ($err = Validacion::nombre($datos['nombre'], 'El nombre'))       return $err;
        if ($err = Validacion::nombre($datos['apellido'], 'El apellido'))   return $err;
        if ($err = Validacion::password($datos['contrasenia'], $datos['contrasenia2'])) return $err;
        if ($err = Validacion::usuario($datos['usuario']))                  return $err;
        if ($err = Validacion::dni($datos['dni']))                          return $err;
        if ($err = Validacion::telefono($datos['telefono']))                return $err;
        if ($err = Validacion::email($datos['email']))                      return $err;
        if ($err = Validacion::fechaNac((string) ($datos['fecha_nac'] ?? ''))) return $err;
        if ($err = Validacion::sexo((string) ($datos['sexo'] ?? '')))       return $err;
        if ($err = Validacion::direccion((string) ($datos['direccion'] ?? ''))) return $err;

        // ── Obra social (opcional) ───────────────────────────────
        // Se comprueba contra la base que el plan exista: el <select> del
        // navegador se puede editar y mandar cualquier id.
        if (!empty($datos['id_plan'])) {
            if (!$this->modelo->existePlan((int) $datos['id_plan'])) {
                return 'La obra social seleccionada no es válida.';
            }
            if ($err = Validacion::nroAfiliado((string) ($datos['nro_afiliado'] ?? ''))) {
                return $err;
            }
        } else {
            // Sin obra social no puede quedar un número de afiliado colgado.
            $datos['nro_afiliado'] = null;
        }

        // ── Unicidad ─────────────────────────────────────────────
        if ($this->modelo->existeUsuario($datos['usuario'])) {
            return 'El nombre de usuario ya está en uso.';
        }

        if ($this->modelo->existeDni($datos['dni'])) {
            return 'Ya existe un paciente registrado con ese DNI.';
        }

        if ($this->modelo->existeEmail($datos['email'])) {
            return 'Ya existe una cuenta registrada con ese email.';
        }

        try {
            $this->modelo->registrarPaciente($datos);
        } catch (PDOException $e) {
            error_log('Registro paciente falló: ' . $e->getMessage());
            return 'No se pudo crear la cuenta. Intentá nuevamente.';
        }

        // Cuenta creada: volver al login con mensaje de éxito
        header('Location: ' . BASE_URL . 'login.php?msg=registro_ok');
        exit;
    }

    /**
     * Cierra sesión completamente (3 pasos).
     */
    public function logout(): void
    {
        // se verifica si existe una sesión activa, si no la hay se inicia
        if (session_status() === PHP_SESSION_NONE) session_start();
        //se usa para leer la sesión y registrar quien cerro sesion 
        if (isset($_SESSION['id_usuario'])) {
            $this->modelo->registrarLogout($_SESSION['id_usuario']);
        }

        // Paso 1: vaciar array
        $_SESSION = [];

        // Paso 2: eliminar cookie del navegador
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }

        // Paso 3: destruir sesión del servidor
        session_destroy();

        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Location: ' . BASE_URL . 'login.php?msg=logout_ok');
        exit;
    }
}

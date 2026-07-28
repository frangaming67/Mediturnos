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
    /** Longitud mínima de contraseña (misma regla que restablecer.php). */
    public const PASS_MIN = 8;

    /** Sexos aceptados: deben coincidir con el ENUM de paciente.sexo. */
    private const SEXOS = ['F', 'M', 'X', 'prefiero_no_decir'];

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

        // ── Contraseña ───────────────────────────────────────────
        if (strlen($datos['contrasenia']) < self::PASS_MIN) {
            return 'La contraseña debe tener al menos ' . self::PASS_MIN . ' caracteres.';
        }
        if (!preg_match('/[A-Za-z]/', $datos['contrasenia'])) {
            return 'La contraseña debe incluir al menos una letra.';
        }
        if (!preg_match('/\d/', $datos['contrasenia'])) {
            return 'La contraseña debe incluir al menos un número.';
        }
        if ($datos['contrasenia'] !== $datos['contrasenia2']) {
            return 'Las contraseñas no coinciden.';
        }

        // ── Nombre de usuario ────────────────────────────────────
        // Se acota el juego de caracteres para que no puedan colarse
        // espacios ni símbolos raros que después compliquen el login.
        if (!preg_match('/^[a-zA-Z0-9._-]{4,40}$/', $datos['usuario'])) {
            return 'El usuario debe tener entre 4 y 40 caracteres y sólo letras, números, punto, guion o guion bajo.';
        }

        // ── Documento ────────────────────────────────────────────
        if (!ctype_digit($datos['dni']) || strlen($datos['dni']) < 7 || strlen($datos['dni']) > 9) {
            return 'El DNI debe tener entre 7 y 9 dígitos, sin puntos.';
        }

        // ── Teléfono ─────────────────────────────────────────────
        // Se permiten separadores de escritura habituales y se valida la
        // cantidad de dígitos reales.
        $telDigitos = preg_replace('/\D/', '', $datos['telefono']);
        if (strlen($telDigitos) < 8 || strlen($telDigitos) > 15) {
            return 'El teléfono debe tener entre 8 y 15 dígitos.';
        }

        // ── Email ────────────────────────────────────────────────
        if (!filter_var($datos['email'], FILTER_VALIDATE_EMAIL)) {
            return 'El email no tiene un formato válido.';
        }
        if (strlen($datos['email']) > 120) {
            return 'El email es demasiado largo.';
        }

        // ── Fecha de nacimiento (opcional, pero si viene debe ser real) ──
        if (!empty($datos['fecha_nac'])) {
            $f = DateTime::createFromFormat('Y-m-d', $datos['fecha_nac']);
            if (!$f || $f->format('Y-m-d') !== $datos['fecha_nac']) {
                return 'La fecha de nacimiento no es válida.';
            }
            if ($f > new DateTime('today')) {
                return 'La fecha de nacimiento no puede ser futura.';
            }
            if ((int) $f->format('Y') < 1900) {
                return 'Revisá la fecha de nacimiento.';
            }
        }

        // ── Sexo (opcional; si viene, debe estar en el ENUM) ─────
        if (!empty($datos['sexo']) && !in_array($datos['sexo'], self::SEXOS, true)) {
            return 'El sexo seleccionado no es válido.';
        }

        // ── Dirección (opcional) ─────────────────────────────────
        if (!empty($datos['direccion']) && mb_strlen($datos['direccion']) > 150) {
            return 'La dirección es demasiado larga (máximo 150 caracteres).';
        }

        // ── Obra social (opcional) ───────────────────────────────
        // Se comprueba contra la base que el plan exista: el <select> del
        // navegador se puede editar y mandar cualquier id.
        if (!empty($datos['id_plan'])) {
            if (!$this->modelo->existePlan((int) $datos['id_plan'])) {
                return 'La obra social seleccionada no es válida.';
            }
            if (!empty($datos['nro_afiliado'])
                && !preg_match('/^[A-Za-z0-9\/-]{3,50}$/', $datos['nro_afiliado'])) {
                return 'El número de afiliado sólo admite letras, números, guiones y barras (3 a 50 caracteres).';
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

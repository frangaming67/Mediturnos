<?php
// includes/auth.php
// -----------------------------------------------------------------
// Sirve para proteger la app y controlar quién puede entrar y qué puede hacer.
// Vive en includes/ (fuera de sistema/) porque no es parte del patrón MVC:
// no es un modelo (no toca tablas de negocio), no es un controlador (no
// procesa una acción concreta) ni una vista (no dibuja HTML, salvo el 403).
// Es infraestructura transversal que usan TODOS los controladores y
// dashboard.php por igual, así que se factorizó en funciones sueltas
// (no una clase) para poder llamarlas con un simple require_once + nombre
// de función, sin tener que instanciar nada en cada archivo.
// -----------------------------------------------------------------

/**
 * Verifica que haya sesión activa.
 * Si no hay sesión o expiró, redirige a login.php.
 */
function verificarSesion(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['id_usuario'])) {
        header('Location: ' . BASE_URL . 'login.php');
        exit;
    }

    // Timeout de inactividad: 30 minutos, si alguien se pasa de los 30 minutos sin hacer nada, 
    // se cierra la sesión automáticamente 
    $timeout = 30 * 60;
    if (isset($_SESSION['ultimo_acceso']) && (time() - $_SESSION['ultimo_acceso']) > $timeout) {
        session_unset();
        session_destroy();
        header('Location: ' . BASE_URL . 'login.php?exp=1');
        exit;
    }

    $_SESSION['ultimo_acceso'] = time();
}

/**
 * Verifica que el usuario tenga uno de los roles permitidos.
 * Llamar DESPUÉS de verificarSesion().
 * Es el control "grueso" (por rol: admin/recepcionista/medico/paciente);
 * para permisos puntuales dentro de un rol está verificarPermiso() más abajo.
 */
function verificarRol(array $rolesPermitidos): void
{
    if (!in_array($_SESSION['rol'] ?? '', $rolesPermitidos, true)) {
        http_response_code(403);
        include __DIR__ . '/../sistema/vistas/layouts/403.php';
        exit;
    }
}

/**
 * Verifica si el usuario tiene un permiso específico.
 */
function tienePermiso(string $permiso): bool
{
    return in_array($permiso, $_SESSION['permisos'] ?? [], true);
}

/**
 * Exige un permiso concreto (de la tabla rol_permiso). Si no lo tiene,
 * muestra la pantalla 403 y corta. Es el control fino: complementa a
 * verificarRol(), que solo mira el rol. Llamar DESPUÉS de verificarSesion().
 */
function verificarPermiso(string $permiso): void
{
    if (!tienePermiso($permiso)) {
        http_response_code(403);
        include __DIR__ . '/../sistema/vistas/layouts/403.php';
        exit;
    }
}

// ── CSRF ──────────────────────────────────────────────────────────
// Estas tres funciones van juntas y acá (no en un archivo aparte) porque
// comparten el mismo dato ($_SESSION['csrf_token']) y siempre se usan en
// combo dentro de un mismo formulario: csrf_field() imprime el input
// oculto en la vista, csrf_verificar() lo chequea en el controlador que
// recibe el POST. Separarlas en dos archivos distintos solo agregaría
// un require_once más sin ninguna ventaja real.

/**
 * Token CSRF de la sesión (lo genera la primera vez que se pide).
 */
function csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Imprime el campo oculto con el token, para incluir dentro de un <form>.
 */
function csrf_field(): void
{
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

/**
 * Valida el token en peticiones POST. Si no coincide, corta la ejecución.
 * Llamar al inicio de los controladores que procesan formularios.
 */
function csrf_verificar(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }
    $enviado = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $enviado)) {
        http_response_code(403);
        die('Token de seguridad inválido. Recargá la página e intentá de nuevo.');
    }
}

/**
 * Devuelve datos del usuario en sesión.
 */
function usuarioActual(): array
{
    return [
        'id'       => $_SESSION['id_usuario']   ?? null,
        'nombre'   => $_SESSION['nombre']        ?? '',
        'apellido' => $_SESSION['apellido']      ?? '',
        'rol'      => $_SESSION['rol']           ?? '',
        'permisos' => $_SESSION['permisos']      ?? [],
    ];
}

<?php
// logout.php
// -----------------------------------------------------------------
// Está en la raíz, junto a login.php, por la misma razón: es una URL
// pública corta. No es un "ControladorAuth->logout()" porque no dibuja
// nada ni recibe un formulario: es una acción de un solo paso (limpiar
// sesión + redirigir), así que no se justifica una capa de controlador.
// Sí usa el modelo Usuario para dejar registro en la DB de cuándo se
// desloguea cada usuario (auditoría), separado de la lógica de sesión
// de PHP que va después.
// -----------------------------------------------------------------
session_start();
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/sistema/modelos/Usuario.php';

// ─── REGISTRAR LOGOUT EN LA DB ───────────────────────
if (isset($_SESSION['id_usuario'])) {
    try {
        $modelo = new Usuario($pdo);
        $modelo->registrarLogout((int)$_SESSION['id_usuario']);
    } catch (PDOException $e) {
        error_log("Error al registrar logout: " . $e->getMessage());
    }
}

// Se hacen los 3 pasos (vaciar array, borrar cookie, destruir sesión)
// y no solo session_destroy(), porque session_destroy() sola borra los
// DATOS del lado servidor pero deja la cookie de sesión viva en el
// navegador; sin los 3 pasos, un logout "a medias" podría dejar rastros
// reusables. Es la secuencia recomendada por el manual de PHP.

// ─── PASO 1: Vaciar el array $_SESSION ───────────────
$_SESSION = [];

// ─── PASO 2: Eliminar la cookie del navegador ────────
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(
        session_name(), '',
        time() - 42000,
        $p['path'], $p['domain'],
        $p['secure'], $p['httponly']
    );
}

// ─── PASO 3: Destruir la sesión en el servidor ───────
session_destroy();

// ─── REDIRIGIR ───────────────────────────────────────
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Location: ' . '/mediturnos/login.php?msg=logout_ok');
exit;
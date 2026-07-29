<?php
// =============================================================
// dashboard.php — Router de vistas del dashboard
// =============================================================
// Responsabilidad ÚNICA: arrancar la app (sesión, conexión, auth),
// renderizar el layout y derivar a la vista que corresponde según el
// rol. La lógica y el HTML viven en dashboard/ y los componentes en
// dashboard/componentes/.
// =============================================================

session_start();
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/includes/auth.php';
// Turno se instancia acá (y no en cada dashboard_*.php) porque tanto la
// vista de admin (agenda de hoy, KPIs) como la de paciente (calendario)
// necesitan el mismo modelo; se arma una sola vez y se pasa por variable
// a los require_once de más abajo, en vez de duplicar el `new Turno($pdo)`.
require_once __DIR__ . '/sistema/modelos/Turno.php';

// Corta acá mismo si no hay sesión o expiró (ver includes/auth.php).
// Va ANTES de leer $_SESSION['rol'] porque si no hay sesión esa clave
// ni siquiera existe.
verificarSesion();

$rol    = $_SESSION['rol'];
$modelo = new Turno($pdo);

// El paciente no ve un "Dashboard": ve el resumen de SU cuenta. La
// palabra importa — nadie que viene a atenderse piensa en tableros.
// $cssExtra tiene que quedar definido ANTES del navbar, que es quien
// imprime los <link> del <head>.
if ($rol === 'paciente') {
    $paginaTitulo = 'Inicio';
    $breadcrumb   = 'Mi cuenta';
    $cssExtra     = ['paciente.css'];
} else {
    $paginaTitulo = 'Dashboard';
    $breadcrumb   = 'Inicio';
}

// El navbar es común a los dos roles, por eso se incluye una sola vez
// acá arriba y no dentro de cada dashboard_*.php (evitaría duplicar el
// require_once y el riesgo de que un rol lo tenga y el otro no).
require_once __DIR__ . '/sistema/vistas/layouts/navbar.php';

// Esta es la única bifurcación por rol de todo el archivo: el dashboard
// del paciente (reservar turno) y el del staff (KPIs + agenda + gestión)
// son pantallas tan distintas en contenido que no tiene sentido meterlas
// en un solo archivo con if/else salpicados por todos lados; cada una
// vive en su propio archivo dentro de dashboard/.
// Tres pantallas, una por tipo de usuario:
//   paciente → calendario de reserva
//   medico   → SU agenda del día y sus pacientes (antes caía en la vista
//              de admin, donde veía KPIs de toda la clínica que no le
//              correspondían y no tenía forma de ver su propia agenda)
//   admin / recepcionista → gestión general
if ($rol === 'paciente') {
    require_once __DIR__ . '/dashboard/dashboard_paciente.php';
} elseif ($rol === 'medico') {
    require_once __DIR__ . '/dashboard/dashboard_medico.php';
} else {
    require_once __DIR__ . '/dashboard/dashboard_admin.php';
}

require_once __DIR__ . '/sistema/vistas/layouts/footer.php';

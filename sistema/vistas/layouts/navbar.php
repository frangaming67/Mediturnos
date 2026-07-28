<?php
// sistema/vistas/layouts/navbar.php
// -----------------------------------------------------------------
// Este archivo hace de "apertura" del layout: abre <html><body>, dibuja
// el sidebar/topbar y deja <main class="contenido"> abierto sin cerrar
// (footer.php es quien lo cierra). Se partió en dos archivos (navbar +
// footer) en vez de una sola función renderLayout($contenido) porque así
// cada vista puede escribir su HTML de body en el medio con sintaxis PHP
// normal (etiquetas de apertura/cierre de PHP mezcladas con HTML), sin
// tener que armar el contenido como un string aparte para pasarlo como
// parámetro.
// El resaltado del link activo compara basename($_SERVER['PHP_SELF'])
// contra el nombre del controlador (no la $accion): PHP_SELF identifica
// bien en qué CONTROLADOR está el usuario, no en qué acción, así que le
// alcanza para resaltar la sección de la sidebar sin depender de cada
// $_GET['accion'] particular — la única excepción es el ítem
// "Descuentos" de Obras sociales, que sí necesita mirar $_GET['accion']
// porque comparte controlador con "Obras sociales".
// -----------------------------------------------------------------
// Requiere que BASE_URL y $_SESSION['rol'] estén definidos antes de incluir.
// Variable propia del navbar: NO usar $usuario para no pisar datos que la vista
// haya cargado antes (p. ej. el usuario seleccionado en usuarios/editar.php).
$usuarioSesion = usuarioActual();
$rolActual = $usuarioSesion['rol'];
$iniciales = strtoupper(substr($usuarioSesion['nombre'],0,1) . substr($usuarioSesion['apellido'],0,1));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MediTurnos<?php echo isset($paginaTitulo) ? ' — ' . htmlspecialchars($paginaTitulo) : ''; ?></title>
<?php
    // Cache-busting: agrega ?v=<fecha de modificación> a cada CSS para que
    // el navegador recargue la hoja de estilos cuando cambia (y no use una
    // versión vieja cacheada).
    $cssDir = __DIR__ . '/../../../publico/css/';
    $cssVer = fn($f) => BASE_URL . 'publico/css/' . $f
        . (is_file($cssDir . $f) ? '?v=' . filemtime($cssDir . $f) : '');
?>
    <!-- Inter: misma tipografía que la landing pública, para que el sitio
         público y el sistema interno se vean como un único producto.
         preconnect acelera la descarga; display=swap evita que el texto
         quede invisible mientras la fuente carga. -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="<?= $cssVer('estilos.css') ?>">
    <link rel="stylesheet" href="<?= $cssVer('dashboard.css') ?>">
    <link rel="stylesheet" href="<?= $cssVer('utilidades.css') ?>">
    <?php // Solo el rol médico usa medico.css: no se le carga a los demás. ?>
    <?php if ($rolActual === 'medico'): ?>
    <link rel="stylesheet" href="<?= $cssVer('medico.css') ?>">
    <?php endif; ?>
</head>
<body>
<div class="layout">

<aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
        <svg width="28" height="28" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect width="28" height="28" rx="7" fill="#2563eb"/>
            <path d="M14 6v16M6 14h16" stroke="white" stroke-width="2.5" stroke-linecap="round"/>
        </svg>
        <span>MediTurnos</span>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section">General</div>
        <a href="<?= BASE_URL ?>dashboard.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'activo' : '' ?>">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Dashboard
        </a>

        <div class="nav-section">Agenda</div>
        <a href="<?= BASE_URL ?>sistema/controladores/ControladorTurno.php?accion=index" class="nav-link <?= (basename($_SERVER['PHP_SELF']) === 'ControladorTurno.php') ? 'activo' : '' ?>">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
            Turnos
        </a>
        <a href="<?= BASE_URL ?>sistema/controladores/ControladorPago.php?accion=index" class="nav-link <?= (basename($_SERVER['PHP_SELF']) === 'ControladorPago.php') ? 'activo' : '' ?>">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
            Pagos
        </a>

        <?php if (in_array($rolActual, ['admin','recepcionista'])): ?>
        <div class="nav-section">Gestión</div>
        <a href="<?= BASE_URL ?>sistema/controladores/ControladorPaciente.php?accion=index" class="nav-link <?= (basename($_SERVER['PHP_SELF']) === 'ControladorPaciente.php') ? 'activo' : '' ?>">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
            Pacientes
        </a>
        <a href="<?= BASE_URL ?>sistema/controladores/ControladorMedico.php?accion=index" class="nav-link <?= (basename($_SERVER['PHP_SELF']) === 'ControladorMedico.php') ? 'activo' : '' ?>">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4.5 6.375a4.125 4.125 0 1 1 8.25 0 4.125 4.125 0 0 1-8.25 0Z"/><path d="M14.25 8.625a3.375 3.375 0 1 1 6.75 0 3.375 3.375 0 0 1-6.75 0Z"/><path d="M1.5 19.125a7.125 7.125 0 0 1 14.25 0v.003l-.001.119a.75.75 0 0 1-.363.63 13.067 13.067 0 0 1-6.761 1.873c-2.472 0-4.786-.684-6.76-1.873a.75.75 0 0 1-.364-.63l-.001-.122Z"/></svg>
            Médicos
        </a>
        <a href="<?= BASE_URL ?>sistema/controladores/ControladorHorario.php?accion=index" class="nav-link <?= (basename($_SERVER['PHP_SELF']) === 'ControladorHorario.php') ? 'activo' : '' ?>">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
            Horarios
        </a>
        <a href="<?= BASE_URL ?>sistema/controladores/ControladorAusencia.php?accion=index" class="nav-link <?= (basename($_SERVER['PHP_SELF']) === 'ControladorAusencia.php') ? 'activo' : '' ?>">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/></svg>
            Ausencias
        </a>
        <?php endif; ?>

        <?php if ($rolActual === 'admin'): ?>
        <a href="<?= BASE_URL ?>sistema/controladores/ControladorObraSocial.php?accion=index" class="nav-link <?= (in_array(basename($_SERVER['PHP_SELF']), ['ControladorObraSocial.php']) && ($_GET['accion'] ?? '') !== 'descuentos') ? 'activo' : '' ?>">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 12v9H4v-9"/><path d="M2 7h20v5H2z"/><path d="M12 22V7M12 7a3 3 0 1 0-3-3 3 3 0 0 0 3 3Z"/></svg>
            Obras sociales
        </a>
        <a href="<?= BASE_URL ?>sistema/controladores/ControladorObraSocial.php?accion=descuentos" class="nav-link <?= (($_GET['accion'] ?? '') === 'descuentos') ? 'activo' : '' ?>">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/></svg>
            Descuentos
        </a>
        <a href="<?= BASE_URL ?>sistema/controladores/ControladorConsultorio.php?accion=index" class="nav-link <?= (basename($_SERVER['PHP_SELF']) === 'ControladorConsultorio.php') ? 'activo' : '' ?>">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 9h2M9 13h2M13 9h2M13 13h2M10 21v-4h4v4"/></svg>
            Consultorios
        </a>
        <?php endif; ?>

        <?php if ($rolActual === 'admin'): ?>
        <div class="nav-section">Sistema</div>
        <a href="<?= BASE_URL ?>sistema/controladores/ControladorUsuario.php?accion=index" class="nav-link <?= (basename($_SERVER['PHP_SELF']) === 'ControladorUsuario.php') ? 'activo' : '' ?>">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 15a6 6 0 1 0 0-12 6 6 0 0 0 0 12Z"/><path d="M2.5 21a10 10 0 0 1 19 0"/></svg>
            Usuarios
        </a>
        <?php endif; ?>
    </nav>

    <div class="sidebar-user">
        <div class="avatar"><?= $iniciales ?></div>
        <div class="user-info">
            <div class="user-nombre"><?= htmlspecialchars($usuarioSesion['nombre'] . ' ' . $usuarioSesion['apellido']) ?></div>
            <div class="user-rol"><?= htmlspecialchars($rolActual) ?></div>
        </div>
        <a href="<?= BASE_URL ?>logout.php" class="btn-logout" title="Cerrar sesión">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        </a>
    </div>
</aside>

<div class="main-wrap">
    <header class="topbar">
        <button class="btn-icono" id="btnToggleSidebar" style="display:none">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <div class="topbar-title"><?= htmlspecialchars($paginaTitulo ?? 'MediTurnos') ?></div>
        <?php if (isset($breadcrumb)): ?>
        <div class="topbar-breadcrumb"><?= $breadcrumb ?></div>
        <?php endif; ?>
    </header>
    <main class="contenido">

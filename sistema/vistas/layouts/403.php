<?php
// sistema/vistas/layouts/403.php
// -----------------------------------------------------------------
// Es la ÚNICA vista del proyecto que NO pasa por navbar.php/footer.php:
// se muestra precisamente cuando el usuario no tiene permiso para estar
// donde está (ver includes/auth.php: verificarRol()/verificarPermiso()
// la incluyen con include, no con un dashboard), así que arma su propio
// <html> autocontenido en vez de depender del layout normal — usar el
// navbar supondría poder resolver menús y rol de una sesión que podría
// ni siquiera cumplir las condiciones para verlos.
// -----------------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Acceso Denegado — MediTurnos</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>publico/css/estilos.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>publico/css/utilidades.css">
</head>
<body class="pagina-403">
    <div class="error-box">
        <svg class="error-icono" width="48" height="48" fill="none" stroke="#c81e1e" stroke-width="1.5" viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>
        </svg>
        <h1 class="error-titulo">403 — Acceso denegado</h1>
        <p class="error-descripcion">No tenés permisos para ver esta página.</p>
        <a href="<?= BASE_URL ?>dashboard.php" class="btn btn-primario">Volver al inicio</a>
    </div>
</body>
</html>

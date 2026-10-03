<?php
// tareas/ejecutar.php
// -----------------------------------------------------------------
// Punto de entrada de las tareas por tiempo, para la línea de comandos.
//
//     php tareas/ejecutar.php
//
// Pensado para un evento programado que corra una vez por hora:
//
//   Linux (crontab -e):
//     0 * * * * /usr/bin/php /ruta/al/proyecto/tareas/ejecutar.php >> /ruta/tareas.log 2>&1
//
//   Windows (Tareas programadas):
//     C:\xampp\php\php.exe C:\xampp\htdocs\mediturnos\tareas\ejecutar.php
//
// ── POR QUÉ SÓLO POR LÍNEA DE COMANDOS ───────────────────────
// Un archivo que dispara correos y que se puede pedir por URL es un
// archivo que cualquiera puede hacer correr mil veces. Los avisos no se
// duplicarían —notificarUnaVez() lo impide— pero el servidor haría el
// trabajo igual, y es trabajo que toca la base y el servidor de correo.
// Es una denegación de servicio regalada.
//
// Hay DOS barreras y a propósito:
//   · esta comprobación de PHP_SAPI, que no depende del servidor web;
//   · el .htaccess de esta carpeta, que no depende de PHP.
//
// Cualquiera de las dos sola alcanzaría. Las dos juntas siguen valiendo
// si una falla: un .htaccess que no se lee porque falta AllowOverride, o
// un PHP servido por una vía que informe otro SAPI.
// -----------------------------------------------------------------

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Esta tarea se ejecuta sólo por línea de comandos.\n");
}

require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../includes/tareas.php';

$inicio = microtime(true);

// Sin freno: si hay un evento programado, él decide cada cuánto. El freno
// de diez minutos es para el otro camino, el de las visitas al sitio.
$resultado = ejecutarTareasAhora($pdo);

$ms = (int) round((microtime(true) - $inicio) * 1000);

// Formato de una línea por corrida, con fecha: así el log del cron se
// puede leer y buscar. Un volcado del arreglo no serviría para nada.
$partes = [];
foreach ($resultado as $tarea => $cuantos) {
    $partes[] = $tarea . '=' . $cuantos;
}

printf(
    "[%s] tareas ok (%d ms) %s\n",
    date('Y-m-d H:i:s'),
    $ms,
    $partes ? implode(' ', $partes) : 'sin tareas'
);

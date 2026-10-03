<?php
// config/conexion.php
// -----------------------------------------------------------------
// Este archivo es el ÚNICO lugar del proyecto donde se abre la conexión
// a MySQL. Vive en config/ (y no, por ejemplo, dentro de cada modelo)
// porque las credenciales y los ajustes de conexión son un dato de
// INFRAESTRUCTURA, no de lógica de negocio: si mañana cambia el usuario
// de la DB o el host, se toca un solo archivo y no los 9 modelos.
// Todo lo demás (dashboard.php, cada controlador) hace
// `require_once __DIR__ . '/config/conexion.php'` y recibe la variable
// $pdo ya lista para usar.
// -----------------------------------------------------------------

// Zona horaria de la aplicación. Sin esto PHP usa la del php.ini (XAMPP trae
// Europe/Berlin por defecto), queda desfasado respecto del reloj del sistema y
// de la base, y las comparaciones de fechas hechas en PHP dan mal. Ejemplo: un
// pago con vencimiento a las 17:00 se veía "vencido" a las 14:50.
// Se fija acá y no en el php.ini para que el proyecto funcione igual en
// cualquier PC, sin depender de cómo esté configurado el XAMPP de esa máquina.
date_default_timezone_set('America/Argentina/Buenos_Aires');

// ── Configuración del entorno ────────────────────────────────
// Si existe config/entorno.php, de ahí salen las credenciales, la
// BASE_URL y el modo producción. Ese archivo NO se versiona (lleva la
// contraseña de la base); la plantilla es config/entorno.ejemplo.php.
//
// POR QUÉ ASÍ Y NO EDITANDO ESTE ARCHIVO
// Mientras hubo un solo entorno —el XAMPP de desarrollo— tener las
// credenciales escritas acá no molestaba. Con el sitio publicado hay
// dos, distintos en todo, y editar este archivo en cada despliegue es
// exactamente cómo un día terminan las credenciales de producción
// dentro del repositorio.
$rutaEntorno = __DIR__ . '/entorno.php';
if (is_file($rutaEntorno)) {
    require_once $rutaEntorno;
}

// Constantes de conexión: van como define() (globales, no cambian en
// tiempo de ejecución) y no como variables, porque BASE_URL en particular
// se usa en decenas de vistas para armar enlaces (echo corto de PHP) y
// necesita estar disponible sin tener que pasarla como parámetro por
// todos lados.
//
// El `defined() ||` hace que estos sean VALORES POR OMISIÓN: se aplican
// sólo a lo que entorno.php no haya definido. Un clon recién hecho corre
// en XAMPP sin configurar nada, que es como venía funcionando.
defined('DB_HOST')  || define('DB_HOST', 'localhost');
defined('DB_NAME')  || define('DB_NAME', 'mediturnos');
defined('DB_USER')  || define('DB_USER', 'root');
defined('DB_PASS')  || define('DB_PASS', '');
defined('BASE_URL') || define('BASE_URL', '/mediturnos/');

// ── Errores: a la vista en desarrollo, al log en producción ───
// Un error de PHP sin capturar imprime la ruta del archivo, el número de
// línea y a veces un fragmento de la consulta: es un mapa del sistema
// regalado a quien sepa provocarlo. Pero en desarrollo esos mensajes son
// justamente lo que permite arreglar las cosas.
//
// Se configura acá y no en el php.ini porque en un hosting compartido no
// siempre se puede tocar el php.ini — y porque así la configuración
// viaja con el proyecto en vez de depender de cómo está el servidor.
defined('EN_PRODUCCION') || define('EN_PRODUCCION', false);
defined('RUTA_LOG')      || define('RUTA_LOG', '');

// error_reporting queda en E_ALL en los dos casos: lo que cambia es
// QUIÉN ve los errores, no si se registran. Bajarlo en producción sería
// dejar de enterarse de los problemas, que es lo contrario de lo que se
// busca.
error_reporting(E_ALL);
ini_set('display_errors', EN_PRODUCCION ? '0' : '1');
ini_set('log_errors', '1');
if (RUTA_LOG !== '') {
    ini_set('error_log', RUTA_LOG);
}

// El try/catch está acá y no en cada archivo que usa $pdo porque es el
// único punto donde la conexión puede fallar (servidor MySQL apagado,
// credenciales mal puestas). Si fallara, no tiene sentido seguir
// ejecutando nada del sitio, por eso corta con die() en vez de dejar
// que el error se propague a medias por el resto del código.
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",DB_USER,DB_PASS,
        //errores se convienten en exdcepciones,     fetch devuelve arrays asociativos                Mejora la seguridad de las consultas preparadas (no permite emulación de prepares)
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES => false,
        // Misma zona horaria que PHP, así NOW() y las fechas calculadas en PHP
        // coinciden. Se usa el offset -03:00 y no el nombre de la zona porque
        // MySQL sólo acepta nombres si tiene cargadas las tablas de timezones,
        // que XAMPP no trae. Argentina es UTC-3 fijo (no tiene horario de verano).
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '-03:00'",]
    );
} catch (PDOException $e) {
    // El detalle técnico del error (que puede incluir la contraseña de la DB
    // en el string de conexión) va al log del servidor, NUNCA al navegador.
    error_log("Error de conexión PDO: " . $e->getMessage());

    // MODO TOLERANTE (lo activa index.php, la landing pública).
    // Una página pública no puede responder un JSON crudo si la base está
    // caída: el visitante vería basura técnica en vez del sitio. Con la
    // constante activada, la página recibe $pdo = null y decide cómo
    // degradarse (muestra el sitio sin los datos dinámicos).
    // Si la constante NO está definida —o sea, en TODO el sistema interno—
    // el comportamiento es exactamente el de antes: cortar la ejecución.
    if (defined('CONEXION_TOLERANTE') && CONEXION_TOLERANTE) {
        $pdo = null;
    } else {
        //convierte el error en un mensaje JSON y detiene el programa
        die(json_encode(['error' => 'No se pudo conectar a la base de datos.']));
    }
}

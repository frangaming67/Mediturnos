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

// Constantes de conexión: van como define() (globales, no cambian en
// tiempo de ejecución) y no como variables, porque BASE_URL en particular
// se usa en decenas de vistas para armar enlaces (echo corto de PHP) y
// necesita estar disponible sin tener que pasarla como parámetro por
// todos lados.
define('DB_HOST', 'localhost');
define('DB_NAME', 'mediturnos');
define('DB_USER', 'root');
define('DB_PASS', '');
define('BASE_URL', '/mediturnos/');

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

<?php
// config/entorno.ejemplo.php
// -----------------------------------------------------------------
// PLANTILLA. Copiar como `config/entorno.php` y completar con los datos
// del servidor. El archivo real NO se versiona: lleva la contraseña de
// la base.
//
//     cp config/entorno.ejemplo.php config/entorno.php
//
// ── PARA QUÉ EXISTE ──────────────────────────────────────────
// Hasta la etapa 5, `config/conexion.php` tenía las credenciales y la
// BASE_URL escritas adentro. Funcionaba porque había un solo entorno: el
// XAMPP de la máquina de desarrollo.
//
// Con el sitio publicado hay dos, y son distintos en todo: otro usuario
// de base, otra contraseña, la aplicación en la raíz del dominio en vez
// de en /mediturnos/, y los errores que NO se muestran al visitante.
// Editar conexion.php en cada despliegue es la receta para subir un día
// las credenciales de producción al repositorio.
//
// Si este archivo no existe, conexion.php usa los valores de XAMPP y
// todo sigue funcionando igual que antes. Un clon recién hecho no
// necesita configurar nada para correr en local.
// -----------------------------------------------------------------

// ── Base de datos ────────────────────────────────────────────
// En un hosting compartido el panel da estos cuatro datos al crear la
// base. El usuario NO tiene que ser root ni tener permisos de ALTER:
// ver docs/deployment.md.
define('DB_HOST', 'localhost');
define('DB_NAME', 'mediturnos');
define('DB_USER', 'mediturnos');
define('DB_PASS', 'poner-una-clave-larga-acá');

// ── Ruta pública ─────────────────────────────────────────────
// Lo que va DESPUÉS del dominio, con las dos barras.
//   · Si el sitio es https://miclinica.com         → '/'
//   · Si es https://miclinica.com/mediturnos/      → '/mediturnos/'
define('BASE_URL', '/');

// ── Producción ───────────────────────────────────────────────
// Con esto en true:
//   · los errores de PHP NO se muestran al visitante (van al log);
//   · el aviso de "modo desarrollo" del correo desaparece.
//
// POR QUÉ IMPORTA: un error de PHP sin capturar imprime la ruta del
// archivo, el número de línea y, en el peor caso, fragmentos de la
// consulta. Es un mapa del sistema regalado a quien sepa provocar el
// error. En desarrollo esos mensajes son lo que permite arreglar las
// cosas, así que el valor cambia por entorno y no se elige una vez.
define('EN_PRODUCCION', true);

// Dónde se escriben los errores. Vacío = el log del servidor.
// En un hosting compartido conviene una ruta FUERA de la carpeta
// pública: un .log dentro de public_html es un archivo descargable.
define('RUTA_LOG', '');

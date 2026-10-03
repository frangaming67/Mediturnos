<?php
// =============================================================
// includes/seguridad.php — Endurecimiento de sesión y anti-fuerza bruta
// =============================================================
// Complementa a includes/auth.php, NO lo reemplaza ni lo duplica:
//   · auth.php      → ¿quién sos? ¿qué podés hacer? (sesión, roles,
//                      permisos, CSRF)
//   · seguridad.php → ESTE archivo. Cómo se transporta y protege esa
//                      sesión (cookie endurecida, cabeceras HTTP) y
//                      cómo se frena a quien intenta adivinar claves.
//
// Se separó así porque son responsabilidades distintas: auth.php
// responde preguntas de negocio y este archivo aplica medidas de
// infraestructura que valen para TODAS las páginas por igual.
//
// USO en un punto de entrada (login.php, registro.php, etc.):
//     require_once __DIR__ . '/includes/seguridad.php';
//     iniciarSesionSegura();
//     cabecerasSeguridad();
// =============================================================

/**
 * ¿La petición del visitante llegó por HTTPS?
 *
 * En casi todo hosting compartido el certificado lo termina un proxy y no
 * el Apache donde corre PHP: el visitante entra por https:// pero a PHP
 * la petición le llega por http interno, y `$_SERVER['HTTPS']` no existe.
 * Sin mirar las cabeceras del proxy, la cookie de sesión saldría sin el
 * flag `Secure` en un sitio que SÍ tiene HTTPS.
 *
 * Las cabeceras `X-Forwarded-*` las puede falsificar quien manda la
 * petición, y está bien: lo que se consigue con eso es que la aplicación
 * crea que hay HTTPS cuando no lo hay, o sea que la cookie salga con
 * `Secure` y los enlaces con `https://`. Lo peor que le puede pasar al
 * que las falsifica es quedarse sin cookie. El error que sí tiene
 * consecuencias es el contrario —no detectar el HTTPS que existe— y es
 * justamente el que ocurre en un hosting real.
 */
if (!function_exists('esHttps')) {
    function esHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_SSL'])
            && strtolower((string) $_SERVER['HTTP_X_FORWARDED_SSL']) !== 'off') {
            return true;
        }
        return ((string) ($_SERVER['SERVER_PORT'] ?? '')) === '443';
    }
}
/**
 * Arranca la sesión con la cookie endurecida.
 *
 * Debe llamarse ANTES de cualquier salida y en lugar de session_start().
 * Es idempotente: si la sesión ya está activa no hace nada, así que
 * puede convivir con el session_start() que ya hacen otros archivos.
 */
if (!function_exists('iniciarSesionSegura')) {
    function iniciarSesionSegura(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // secure=true sólo si realmente hay HTTPS. Ponerlo fijo en true
        // rompería el login en XAMPP (que sirve por HTTP): el navegador
        // descartaría la cookie y nadie podría iniciar sesión.
        $esHttps = esHttps();

        session_set_cookie_params([
            'lifetime' => 0,          // la cookie muere al cerrar el navegador
            'path'     => '/',
            'domain'   => '',
            'secure'   => $esHttps,   // sólo viaja por HTTPS cuando lo hay
            'httponly' => true,       // JavaScript NO puede leerla (mitiga XSS)
            'samesite' => 'Lax',      // no se envía en peticiones cross-site (mitiga CSRF)
        ]);

        session_start();
    }
}

/**
 * Cabeceras de seguridad HTTP.
 *
 * Son defensa en profundidad: aunque se cuele un XSS, la CSP limita
 * mucho lo que ese script podría hacer.
 *
 * Nota sobre 'unsafe-inline': el proyecto tiene <script> y style=""
 * en las vistas, así que quitarlo rompería la aplicación. Se deja
 * documentado como deuda técnica consciente en vez de aplicar una
 * política que obligue a reescribir 30 vistas.
 */
if (!function_exists('cabecerasSeguridad')) {
    function cabecerasSeguridad(): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-Content-Type-Options: nosniff');   // sin adivinar tipos MIME
        header('X-Frame-Options: DENY');             // no embebible → anti clickjacking
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header(
            "Content-Security-Policy: default-src 'self'; "
            . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
            . "font-src 'self' https://fonts.gstatic.com; "
            . "script-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data:; "
            . "frame-ancestors 'none'"
        );
    }
}

// ── Anti fuerza bruta ────────────────────────────────────────
// Config en un solo lugar para poder ajustarla sin buscar por el código.
if (!defined('LOGIN_MAX_INTENTOS')) define('LOGIN_MAX_INTENTOS', 5);
if (!defined('LOGIN_VENTANA_MIN'))  define('LOGIN_VENTANA_MIN', 15);

/** IP del cliente, acotada para que entre en la columna. */
if (!function_exists('ipCliente')) {
    function ipCliente(): string
    {
        return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
    }
}

/**
 * Cuenta los intentos FALLIDOS recientes de (identificador + IP).
 *
 * Se combinan los dos a propósito: si el bloqueo dependiera sólo del
 * usuario, cualquiera podría dejar afuera a otra persona fallando su
 * login a propósito (denegación de servicio contra esa cuenta).
 */
if (!function_exists('intentosFallidos')) {
    function intentosFallidos(PDO $pdo, string $identificador): int
    {
        try {
            $st = $pdo->prepare(
                "SELECT COUNT(*) FROM intento_login
                 WHERE identificador = :id AND ip = :ip AND exito = 0
                   AND fecha > (NOW() - INTERVAL :min MINUTE)"
            );
            // bindValue con PARAM_INT: con EMULATE_PREPARES=false, MySQL
            // exige que el INTERVAL reciba un entero real.
            $st->bindValue(':id',  mb_substr($identificador, 0, 100));
            $st->bindValue(':ip',  ipCliente());
            $st->bindValue(':min', LOGIN_VENTANA_MIN, PDO::PARAM_INT);
            $st->execute();
            return (int) $st->fetchColumn();
        } catch (PDOException $e) {
            // Si la tabla todavía no se creó (falta correr auth_v2.sql),
            // el login debe seguir funcionando: se registra y se sigue.
            error_log('seguridad intentosFallidos: ' . $e->getMessage());
            return 0;
        }
    }
}

/** ¿Está bloqueado por superar el máximo de intentos? */
if (!function_exists('loginBloqueado')) {
    function loginBloqueado(PDO $pdo, string $identificador): bool
    {
        return intentosFallidos($pdo, $identificador) >= LOGIN_MAX_INTENTOS;
    }
}

/** Intentos que le quedan antes del bloqueo (para avisarle al usuario). */
if (!function_exists('intentosRestantes')) {
    function intentosRestantes(PDO $pdo, string $identificador): int
    {
        return max(0, LOGIN_MAX_INTENTOS - intentosFallidos($pdo, $identificador));
    }
}

/** Deja constancia del intento (exitoso o no). */
if (!function_exists('registrarIntentoLogin')) {
    function registrarIntentoLogin(PDO $pdo, string $identificador, bool $exito): void
    {
        try {
            $pdo->prepare(
                "INSERT INTO intento_login (identificador, ip, exito) VALUES (:id, :ip, :e)"
            )->execute([
                ':id' => mb_substr($identificador, 0, 100),
                ':ip' => ipCliente(),
                ':e'  => $exito ? 1 : 0,
            ]);

            // Al entrar bien se limpian los fallos previos: si no, alguien
            // que se equivocó 4 veces y acertó a la quinta seguiría a un
            // error de quedar bloqueado sin motivo.
            if ($exito) {
                $pdo->prepare(
                    "DELETE FROM intento_login
                     WHERE identificador = :id AND ip = :ip AND exito = 0"
                )->execute([
                    ':id' => mb_substr($identificador, 0, 100),
                    ':ip' => ipCliente(),
                ]);
            }
        } catch (PDOException $e) {
            error_log('seguridad registrarIntentoLogin: ' . $e->getMessage());
        }
    }
}

/** Escape corto para las vistas de autenticación. */
if (!function_exists('e')) {
    function e(?string $v): string
    {
        return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Un parámetro de la URL, SIEMPRE como texto.
 *
 * ── POR QUÉ HACE FALTA ───────────────────────────────────────
 * Cualquiera puede pedir `?msg[]=x`, y entonces `$_GET['msg']` es un
 * ARREGLO. Varias vistas lo usan como clave de un mapa de mensajes:
 *
 *     $textos[$_GET['msg']] ?? 'Listo.'
 *
 * y un arreglo como clave de arreglo es un error fatal de PHP. La página
 * muere con la ruta del archivo y el número de línea impresos — y encima
 * respondiendo 200, así que una prueba que sólo mire el código de
 * respuesta no lo detecta. Hay que mirar el cuerpo.
 *
 * `!empty()` NO alcanza: un arreglo con un elemento no está vacío. Lo
 * que hay que comprobar es el TIPO.
 *
 * Vive acá, al lado de e(), porque es lo mismo: una función de una línea
 * que evita que un dato de afuera llegue crudo a un lugar donde hace
 * daño.
 */
if (!function_exists('param')) {
    function param(string $clave, string $porOmision = ''): string
    {
        return is_string($_GET[$clave] ?? null) ? $_GET[$clave] : $porOmision;
    }
}

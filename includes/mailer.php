<?php
// =============================================================
// includes/mailer.php — Envío de correo
// =============================================================
// POR QUÉ ESTE ARCHIVO EXISTE
// Este XAMPP no puede mandar mails: `sendmail_path` está vacío y no
// hay servidor SMTP local, así que mail() falla en silencio. Tampoco
// hay Composer, con lo cual no se puede instalar PHPMailer.
//
// SOLUCIÓN: una interfaz con DOS implementaciones intercambiables.
//   · MailerArchivo → guarda el correo en almacenamiento/mails/ como
//                     un .html que se abre en el navegador. Funciona
//                     HOY, sin configurar nada. Es el modo por defecto.
//   · MailerSmtp    → envía de verdad por SMTP. Se activa cargando las
//                     credenciales en config/mail.php.
//
// La aplicación (recuperar.php) sólo conoce la interfaz: no le importa
// cuál está activa. Cambiar de una a otra no toca ni una línea del
// flujo de recuperación — es el principio de inversión de dependencias
// aplicado a un caso concreto.
// =============================================================

interface Mailer
{
    /**
     * @return bool  true si el correo se entregó (o se guardó, en modo archivo)
     */
    public function enviar(string $para, string $asunto, string $cuerpoHtml): bool;

    /** Ruta/identificador del último envío, para poder mostrarlo en desarrollo. */
    public function ultimoDestino(): ?string;
}

/**
 * Implementación de DESARROLLO: escribe el mail a disco.
 *
 * No es un "mock vacío": genera el HTML real que recibiría el usuario,
 * así el contenido del correo se puede revisar y demostrar. Es la
 * práctica habitual en entornos locales (mailhog, mailtrap, log driver
 * de Laravel…).
 */
class MailerArchivo implements Mailer
{
    private string $carpeta;
    private ?string $ultimo = null;

    public function __construct(?string $carpeta = null)
    {
        $this->carpeta = $carpeta ?? (__DIR__ . '/../almacenamiento/mails');
        if (!is_dir($this->carpeta)) {
            @mkdir($this->carpeta, 0775, true);
        }
    }

    public function enviar(string $para, string $asunto, string $cuerpoHtml): bool
    {
        // El nombre lleva fecha + destinatario saneado para poder ubicarlo,
        // y un sufijo aleatorio para que sea único.
        //
        // El sufijo NO es decoración: sin él, dos correos a la misma
        // persona dentro del mismo segundo generaban el mismo nombre y el
        // segundo pisaba al primero sin decir nada. Pasa de verdad —al
        // reservar y que falle el pago salen dos avisos casi juntos— y el
        // síntoma es el peor posible: un correo que el sistema da por
        // enviado y que no está en ninguna parte.
        $slug   = preg_replace('/[^a-zA-Z0-9._-]/', '_', $para);
        $nombre = date('Ymd_His') . '_' . $slug . '_' . bin2hex(random_bytes(3)) . '.html';
        $ruta   = $this->carpeta . '/' . $nombre;

        $encabezado = '<!doctype html><meta charset="utf-8">'
            . '<div style="font-family:system-ui,sans-serif;background:#f1f5f9;padding:14px 18px;'
            . 'border-left:4px solid #2563eb;margin-bottom:18px;font-size:13px;color:#334155">'
            . '<strong>Correo simulado (modo desarrollo)</strong><br>'
            . 'Para: ' . htmlspecialchars($para, ENT_QUOTES, 'UTF-8') . '<br>'
            . 'Asunto: ' . htmlspecialchars($asunto, ENT_QUOTES, 'UTF-8') . '<br>'
            . 'Fecha: ' . date('d/m/Y H:i:s')
            . '</div>';

        $ok = @file_put_contents($ruta, $encabezado . $cuerpoHtml) !== false;
        if ($ok) {
            $this->ultimo = $ruta;
        } else {
            error_log('MailerArchivo: no se pudo escribir ' . $ruta);
        }
        return $ok;
    }

    public function ultimoDestino(): ?string
    {
        return $this->ultimo;
    }
}

/**
 * Implementación de PRODUCCIÓN: SMTP hablado a mano por sockets.
 *
 * Se implementa sin librerías porque el proyecto no usa Composer.
 * Cubre el caso habitual (SMTP autenticado con STARTTLS o SSL), que
 * es lo que ofrecen Gmail, Outlook o cualquier hosting.
 */
class MailerSmtp implements Mailer
{
    private array $cfg;
    private ?string $ultimo = null;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    /** Último error legible, para poder diagnosticar sin abrir el log. */
    private ?string $error = null;

    public function ultimoError(): ?string
    {
        return $this->error;
    }

    /**
     * Lee una respuesta SMTP COMPLETA.
     *
     * Ojo: una respuesta puede ocupar VARIAS líneas. El servidor marca
     * que sigue habiendo más poniendo un guion en la 4ª posición
     * ("250-...") y cierra con un espacio ("250 ..."). Gmail responde
     * al EHLO con 5 líneas.
     *
     * Leer una sola dejaría las demás en el buffer y TODOS los comandos
     * siguientes leerían respuestas viejas: el diálogo se desincroniza
     * y el envío falla de formas confusas.
     */
    private function leerRespuesta($con): string
    {
        $respuesta = '';
        while (($linea = fgets($con, 515)) !== false) {
            $respuesta .= $linea;
            // Cuarto carácter: '-' = continúa, ' ' = última línea
            if (strlen($linea) < 4 || $linea[3] !== '-') {
                break;
            }
        }
        return $respuesta;
    }

    /** Envía un comando y devuelve la respuesta completa. */
    private function comando($con, string $cmd): string
    {
        fwrite($con, $cmd . "\r\n");
        return $this->leerRespuesta($con);
    }

    /** ¿La respuesta empieza con el código esperado? */
    private function esperaba(string $respuesta, string $codigo): bool
    {
        return strncmp(ltrim($respuesta), $codigo, strlen($codigo)) === 0;
    }

    /**
     * Dominios que por norma NUNCA pueden recibir correo.
     *
     * `example.com` y compañía están reservados por la RFC 2606 y
     * declaran Null MX (RFC 7505); `.test`, `.invalid` y `.localhost`
     * los reserva la RFC 6761. Mandarles un mensaje no es un error de
     * configuración: es una garantía de rebote.
     *
     * Importa porque los datos de prueba usan justamente esas
     * direcciones. Cada correo a una de ellas viaja al servidor, se
     * acepta, y vuelve horas después como un rebote a la casilla del
     * dueño del sistema. Terminás con la bandeja llena de "No se ha
     * encontrado la dirección" por cuentas que nunca existieron.
     */
    private const DOMINIOS_IMPOSIBLES = [
        'example.com', 'example.org', 'example.net', 'example.edu',
        'test', 'invalid', 'localhost', 'local',
    ];

    /**
     * 🚨 En desarrollo, el sistema sólo se escribe A SÍ MISMO.
     *
     * ── POR QUÉ HACE FALTA ADEMÁS DEL GUARDIA DE DOMINIOS ────
     * El guardia de arriba cubre `@example.com` y los demás dominios
     * reservados. Pero los 1012 pacientes de la base de desarrollo no
     * usan esos: usan direcciones inventadas en dominios REALES
     * —aboutads.info, people.com.cn, redcross.org—. Existen, aceptan
     * correo, y la casilla no está. Rebotan.
     *
     * Mientras cada aviso lo disparaba una acción sobre un paciente
     * concreto, el daño era de a uno. Con los recordatorios por tiempo la
     * tarea recorre TODOS los turnos de la ventana y le escribe a cada
     * paciente: una tanda de rebotes a la casilla del dueño del sistema,
     * y el riesgo de que el proveedor marque la cuenta por envíos a
     * direcciones inexistentes.
     *
     * Ya pasó dos veces en este proyecto con envíos de a uno. Con una
     * tarea que recorre filas, pasaría en serie.
     *
     * ── POR QUÉ NO SE APAGA EL CORREO Y LISTO ────────────────
     * Porque hay que poder probar que el correo sale de verdad, que llega
     * y que se ve bien en un cliente real. Lo que no hay que poder es
     * escribirle a un tercero. Así que en desarrollo se entrega sólo a la
     * lista blanca, que por omisión tiene una sola dirección: la casilla
     * desde la que el sistema manda.
     *
     * Para agregar otra —la casilla personal de quien prueba— va en
     * `config/mail.php`:
     *
     *     'solo_a' => ['yo@gmail.com', 'otro@gmail.com'],
     *
     * En producción (EN_PRODUCCION en true) esto no se aplica: ahí las
     * direcciones son de gente real.
     */
    private function destinatarioPermitido(string $para): bool
    {
        // defined(): mailer.php se usa también desde guiones que no
        // cargan conexion.php, y en ese caso no hay ninguna constante que
        // consultar. Sin configuración explícita se asume desarrollo, que
        // es la opción prudente: si alguien olvida definirla, el sistema
        // manda MENOS correo y no más.
        // ── 'solo_a' MANDA SIEMPRE, en cualquier entorno ───────
        // Si está configurada explícitamente, se respeta aunque sea
        // producción. Hace falta para el caso de un sitio PÚBLICO de
        // demostración: ahí cualquiera puede escribir una dirección en
        // el formulario de registro, y sin esta lista el sistema se
        // convierte en un formulario para mandar correo desde la casilla
        // del dueño a donde el visitante quiera. Eso es un vector de
        // abuso que termina con la cuenta suspendida por el proveedor.
        if (isset($this->cfg['solo_a'])) {
            $permitidas = (array) $this->cfg['solo_a'];
        } elseif (defined('EN_PRODUCCION') && EN_PRODUCCION) {
            // Producción de verdad y sin lista: las direcciones son de
            // gente real y hay que escribirles.
            return true;
        } else {
            // Desarrollo sin lista: sólo a la casilla del propio sistema.
            $permitidas = [$this->cfg['desde'] ?? ''];
        }
        if (!is_array($permitidas)) {
            $permitidas = [$permitidas];
        }

        $para = strtolower(trim($para));
        foreach ($permitidas as $p) {
            if (is_string($p) && $p !== '' && strtolower(trim($p)) === $para) {
                return true;
            }
        }
        return false;
    }

    /** ¿Esta dirección puede recibir correo, aunque sea en teoría? */
    private function entregable(string $para): bool
    {
        $arroba = strrpos($para, '@');
        if ($arroba === false) {
            return false;
        }
        $dominio = strtolower(substr($para, $arroba + 1));

        foreach (self::DOMINIOS_IMPOSIBLES as $d) {
            // Coincidencia exacta o como sufijo: "algo.example.com" y
            // "mi-pc.local" también son irremediables.
            if ($dominio === $d || str_ends_with($dominio, '.' . $d)) {
                return false;
            }
        }
        return true;
    }

    public function enviar(string $para, string $asunto, string $cuerpoHtml): bool
    {
        $this->error = null;

        // Se descarta antes de abrir el socket. Devuelve false, que es la
        // verdad —no se entregó— y deja constancia en el log.
        if (!$this->entregable($para)) {
            $this->error = 'La dirección ' . $para . ' pertenece a un dominio reservado '
                         . 'que no puede recibir correo. No se intentó el envío.';
            error_log('MailerSmtp: ' . $this->error);
            return false;
        }

        // En desarrollo, sólo a la lista blanca. Ver el comentario de
        // destinatarioPermitido(): la base de pruebas tiene mil
        // direcciones inventadas en dominios que SÍ existen.
        if (!$this->destinatarioPermitido($para)) {
            $this->error = 'Modo desarrollo: no se escribe a ' . $para
                         . '. Sólo a la casilla del sistema (o a las de "solo_a").';
            error_log('MailerSmtp: ' . $this->error);
            return false;
        }

        $host    = $this->cfg['host'];
        $puerto  = (int) $this->cfg['puerto'];
        $seguro  = strtolower($this->cfg['seguro'] ?? 'tls');   // 'tls' | 'ssl'
        $prefijo = $seguro === 'ssl' ? 'ssl://' : '';
        $saludo  = $this->cfg['dominio'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');

        $con = @fsockopen($prefijo . $host, $puerto, $errNo, $errStr, 15);
        if (!$con) {
            $this->error = "No se pudo conectar a {$host}:{$puerto} — {$errStr} ({$errNo}). "
                         . "Revisá que el puerto no esté bloqueado por el firewall o el antivirus.";
            error_log('MailerSmtp: ' . $this->error);
            return false;
        }
        // Sin timeout, una lectura podría colgar la página indefinidamente.
        stream_set_timeout($con, 15);

        try {
            // Saludo inicial del servidor
            if (!$this->esperaba($this->leerRespuesta($con), '220')) {
                throw new RuntimeException('El servidor no respondió el saludo inicial (220).');
            }

            if (!$this->esperaba($this->comando($con, 'EHLO ' . $saludo), '250')) {
                throw new RuntimeException('El servidor rechazó el EHLO.');
            }

            // STARTTLS: obligatorio en Gmail por el puerto 587
            if ($seguro === 'tls') {
                if (!$this->esperaba($this->comando($con, 'STARTTLS'), '220')) {
                    throw new RuntimeException('El servidor no aceptó STARTTLS.');
                }
                $cripto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                // Habilita también TLS 1.1/1.2 donde la constante lo permita
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                    $cripto |= STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                }
                if (!@stream_socket_enable_crypto($con, true, $cripto)) {
                    throw new RuntimeException('Falló el cifrado TLS. ¿Está habilitada la extensión openssl en PHP?');
                }
                // Tras cifrar hay que volver a presentarse
                if (!$this->esperaba($this->comando($con, 'EHLO ' . $saludo), '250')) {
                    throw new RuntimeException('El servidor rechazó el EHLO posterior a TLS.');
                }
            }

            // Autenticación
            if (!$this->esperaba($this->comando($con, 'AUTH LOGIN'), '334')) {
                throw new RuntimeException('El servidor no aceptó AUTH LOGIN.');
            }
            if (!$this->esperaba($this->comando($con, base64_encode($this->cfg['usuario'])), '334')) {
                throw new RuntimeException('El servidor rechazó el nombre de usuario.');
            }
            if (!$this->esperaba($this->comando($con, base64_encode($this->cfg['clave'])), '235')) {
                throw new RuntimeException(
                    'Autenticación rechazada. En Gmail hay que usar una CONTRASEÑA DE APLICACIÓN '
                    . '(16 caracteres), no la contraseña normal de la cuenta.'
                );
            }

            // Sobre
            $de = $this->cfg['desde'];
            if (!$this->esperaba($this->comando($con, 'MAIL FROM:<' . $de . '>'), '250')) {
                throw new RuntimeException('El servidor rechazó el remitente (' . $de . ').');
            }
            if (!$this->esperaba($this->comando($con, 'RCPT TO:<' . $para . '>'), '250')) {
                throw new RuntimeException('El servidor rechazó el destinatario (' . $para . ').');
            }
            if (!$this->esperaba($this->comando($con, 'DATA'), '354')) {
                throw new RuntimeException('El servidor no aceptó el comando DATA.');
            }

            $nombreDe  = $this->cfg['desde_nombre'] ?? 'MediTurnos';
            $cabeceras = 'From: =?UTF-8?B?' . base64_encode($nombreDe) . "?= <{$de}>\r\n"
                       . "To: <{$para}>\r\n"
                       . 'Subject: =?UTF-8?B?' . base64_encode($asunto) . "?=\r\n"
                       . 'Date: ' . date('r') . "\r\n"
                       . "MIME-Version: 1.0\r\n"
                       . "Content-Type: text/html; charset=UTF-8\r\n"
                       . "Content-Transfer-Encoding: 8bit\r\n\r\n";

            // Normalización de fin de línea a CRLF y "dot-stuffing":
            // una línea que empiece con "." cortaría el mensaje, porque
            // un punto solo es la marca de fin (RFC 5321). Se duplica.
            $cuerpo = preg_replace('/\r\n|\r|\n/', "\r\n", $cuerpoHtml);
            $cuerpo = preg_replace('/^\./m', '..', $cuerpo);

            fwrite($con, $cabeceras . $cuerpo . "\r\n.\r\n");
            if (!$this->esperaba($this->leerRespuesta($con), '250')) {
                throw new RuntimeException('El servidor no confirmó la recepción del mensaje.');
            }

            $this->comando($con, 'QUIT');
            fclose($con);

            $this->ultimo = $para;
            return true;

        } catch (RuntimeException $ex) {
            $this->error = $ex->getMessage();
            error_log('MailerSmtp: ' . $this->error);
            @fclose($con);
            return false;
        }
    }

    public function ultimoDestino(): ?string
    {
        return $this->ultimo;
    }
}

/**
 * Devuelve la implementación adecuada.
 *
 * Si existe config/mail.php con credenciales completas, usa SMTP real;
 * si no, cae al modo archivo. Así el proyecto FUNCIONA recién clonado
 * y sólo hace falta configurar algo cuando se quiere enviar de verdad.
 */
function obtenerMailer(): Mailer
{
    $ruta = __DIR__ . '/../config/mail.php';
    if (is_file($ruta)) {
        $cfg = require $ruta;
        if (!empty($cfg['host']) && !empty($cfg['usuario']) && !empty($cfg['clave'])) {
            return new MailerSmtp($cfg);
        }
    }
    return new MailerArchivo();
}

/** ¿Está corriendo en modo simulado? Lo usa la vista para avisarlo. */
function mailerEsSimulado(): bool
{
    return obtenerMailer() instanceof MailerArchivo;
}

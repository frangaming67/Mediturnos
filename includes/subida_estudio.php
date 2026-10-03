<?php
// =============================================================
// includes/subida_estudio.php — Resultados de estudios médicos
// =============================================================
// EN QUÉ SE DIFERENCIA DE subida_imagen.php
// El módulo de fotos de perfil se apoya en una defensa muy fuerte:
// re-codifica la imagen con GD, así que el archivo final lo genera el
// servidor y no conserva NADA del original. Acá eso no se puede hacer:
// un resultado de laboratorio suele ser un PDF, y un PDF no se
// "re-genera" sin perder justamente lo que importa.
//
// Como no se puede neutralizar el contenido, la defensa se mueve a
// DÓNDE vive el archivo:
//
//   · Se guarda FUERA de la carpeta pública (almacenamiento/estudios/),
//     así que no existe ninguna URL que lo alcance.
//   · Se entrega desde PHP, y sólo después de verificar quién lo pide.
//   · Un .htaccess niega todo, por si alguien mueve la carpeta adentro
//     del sitio sin darse cuenta.
//
// Y se sigue sin confiar en el nombre, la extensión ni el tipo que
// declara el navegador: los tres los controla quien sube el archivo.
// =============================================================

class SubidaEstudio
{
    /** Tamaño máximo del archivo (bytes). Un PDF de estudios rara vez supera esto. */
    public const MAX_BYTES = 8 * 1024 * 1024;      // 8 MB

    /** Tipos aceptados: tipo MIME real → extensión que se usará. */
    private const PERMITIDOS = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
    ];

    private string $carpeta;
    private ?string $error = null;

    public function __construct(?string $carpeta = null)
    {
        $this->carpeta = $carpeta ?? (__DIR__ . '/../almacenamiento/estudios');
        if (!is_dir($this->carpeta)) {
            @mkdir($this->carpeta, 0775, true);
        }
    }

    public function error(): ?string
    {
        return $this->error;
    }

    /** Traduce los códigos de error de PHP a algo que el usuario entienda. */
    private function mensajeErrorPhp(int $codigo): string
    {
        return match ($codigo) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'El archivo es demasiado grande. El máximo es '
                . round(self::MAX_BYTES / 1024 / 1024) . ' MB.',
            UPLOAD_ERR_PARTIAL    => 'El archivo se subió incompleto. Probá de nuevo.',
            UPLOAD_ERR_NO_TMP_DIR,
            UPLOAD_ERR_CANT_WRITE => 'No se pudo guardar el archivo en el servidor.',
            UPLOAD_ERR_EXTENSION  => 'Una extensión de PHP bloqueó la subida.',
            default               => 'No se pudo subir el archivo.',
        };
    }

    /**
     * Procesa el archivo y devuelve el NOMBRE guardado, o null si falla
     * (el motivo queda en error()).
     */
    public function procesar(?array $archivo): ?string
    {
        if (!$archivo || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $this->error = 'No adjuntaste ningún archivo.';
            return null;
        }

        if ($archivo['error'] !== UPLOAD_ERR_OK) {
            $this->error = $this->mensajeErrorPhp((int) $archivo['error']);
            return null;
        }

        // Que provenga de una subida HTTP real y no de una ruta del
        // servidor inyectada por el atacante.
        if (!is_uploaded_file($archivo['tmp_name'])) {
            $this->error = 'El archivo recibido no es válido.';
            return null;
        }

        if ($archivo['size'] > self::MAX_BYTES) {
            $this->error = 'El archivo supera los ' . round(self::MAX_BYTES / 1024 / 1024) . ' MB.';
            return null;
        }
        if ($archivo['size'] === 0) {
            $this->error = 'El archivo está vacío.';
            return null;
        }

        // Tipo REAL leído del contenido.
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($archivo['tmp_name']);

        if (!isset(self::PERMITIDOS[$mime])) {
            $this->error = 'Sólo se aceptan archivos PDF, JPG o PNG.';
            return null;
        }

        // Si dice ser imagen, tiene que poder decodificarse de verdad.
        // Con el PDF no se hace: no hay forma barata de validarlo más
        // allá del tipo, y por eso justamente no se sirve por URL.
        if (str_starts_with($mime, 'image/')) {
            $info = @getimagesize($archivo['tmp_name']);
            if ($info === false) {
                $this->error = 'La imagen está dañada o no se puede leer.';
                return null;
            }
        }

        // Nombre aleatorio: nunca se reutiliza el que mandó el usuario.
        // Además, al ser impredecible, adivinar la URL no sirve de nada
        // aunque el .htaccess fallara.
        $nombre = bin2hex(random_bytes(16)) . '.' . self::PERMITIDOS[$mime];
        $ruta   = $this->carpeta . '/' . $nombre;

        if (!@move_uploaded_file($archivo['tmp_name'], $ruta)) {
            $this->error = 'No se pudo guardar el archivo.';
            return null;
        }

        @chmod($ruta, 0644);
        return $nombre;
    }

    /**
     * Borra un archivo de resultado.
     * Sólo acepta nombres con el formato que genera esta clase, para que
     * no se pueda pedir el borrado de otro archivo del servidor.
     */
    public function eliminar(?string $nombre): void
    {
        if (!$this->nombreValido($nombre)) {
            return;
        }
        $ruta = $this->carpeta . '/' . $nombre;
        if (is_file($ruta)) {
            @unlink($ruta);
        }
    }

    /** ¿El nombre tiene la forma exacta que genera esta clase? */
    public function nombreValido(?string $nombre): bool
    {
        return $nombre !== null
            && preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png)$/', $nombre) === 1;
    }

    /**
     * Ruta absoluta en disco, o null si el nombre no es de los nuestros.
     *
     * Devolver null ante un nombre raro —en vez de armar la ruta igual—
     * es lo que impide un "../../config/mail.php" disfrazado de estudio.
     */
    public function rutaDe(?string $nombre): ?string
    {
        if (!$this->nombreValido($nombre)) {
            return null;
        }
        $ruta = $this->carpeta . '/' . $nombre;
        return is_file($ruta) ? $ruta : null;
    }

    /** Tipo MIME que corresponde enviar según la extensión guardada. */
    public static function tipoDe(string $nombre): string
    {
        return match (pathinfo($nombre, PATHINFO_EXTENSION)) {
            'pdf'   => 'application/pdf',
            'png'   => 'image/png',
            default => 'image/jpeg',
        };
    }
}

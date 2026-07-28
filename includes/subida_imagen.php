<?php
// =============================================================
// includes/subida_imagen.php — Subida segura de fotos de perfil
// =============================================================
// Módulo reutilizable: lo usan el registro y la pantalla de perfil.
// Se hizo aparte (y no dentro de un controlador) porque validar y
// procesar una imagen subida es una responsabilidad técnica propia,
// idéntica en los dos flujos: duplicarla sería garantizar que un día
// se corrija en un lado y en el otro no.
//
// POR QUÉ TANTA VALIDACIÓN
// Una subida de archivos es de los puntos más peligrosos de una web.
// Si sólo se mirara la extensión, alguien podría subir "foto.jpg" que
// en realidad contiene código PHP y luego pedirlo por URL para
// ejecutarlo en el servidor. Por eso acá NUNCA se confía en:
//   · el nombre del archivo   (lo elige el atacante)
//   · la extensión            (lo elige el atacante)
//   · $_FILES[...]['type']    (lo manda el NAVEGADOR, se puede falsear)
//
// Lo que sí se hace:
//   1. Verificar que la subida sea real (is_uploaded_file).
//   2. Leer el tipo REAL del contenido con finfo.
//   3. Confirmar con getimagesize() que se puede decodificar.
//   4. RE-CODIFICAR la imagen con GD. Este es el paso clave: el
//      archivo que se guarda lo genera el servidor desde cero, así
//      que cualquier carga útil escondida adentro desaparece.
//   5. Guardar con un nombre aleatorio y extensión controlada.
// =============================================================

class SubidaImagen
{
    /** Tamaño máximo del archivo subido (bytes). */
    public const MAX_BYTES = 3 * 1024 * 1024;      // 3 MB

    /** Lado máximo de la imagen guardada (px). Se reduce a escala. */
    public const MAX_LADO = 600;

    /** Calidad JPEG de salida (equilibrio nitidez / peso). */
    private const CALIDAD = 82;

    /** Tipos aceptados: tipo MIME real → extensión que se usará. */
    private const PERMITIDOS = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'jpg',   // se convierte a JPG para uniformar
        'image/webp' => 'jpg',
        'image/gif'  => 'jpg',
    ];

    private string $carpeta;
    private ?string $error = null;

    public function __construct(?string $carpeta = null)
    {
        $this->carpeta = $carpeta ?? (__DIR__ . '/../publico/img/perfiles');
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
                'La imagen es demasiado grande. El máximo es '
                . round(self::MAX_BYTES / 1024 / 1024, 1) . ' MB.',
            UPLOAD_ERR_PARTIAL    => 'La imagen se subió incompleta. Probá de nuevo.',
            UPLOAD_ERR_NO_TMP_DIR,
            UPLOAD_ERR_CANT_WRITE => 'No se pudo guardar la imagen en el servidor.',
            UPLOAD_ERR_EXTENSION  => 'Una extensión de PHP bloqueó la subida.',
            default               => 'No se pudo subir la imagen.',
        };
    }

    /**
     * Procesa el archivo y devuelve el NOMBRE guardado, o null si falla
     * (el motivo queda en error()).
     *
     * @param array|null $archivo  Entrada de $_FILES (o null si no subió nada)
     */
    public function procesar(?array $archivo): ?string
    {
        // Sin archivo no es un error: la foto es opcional.
        if (!$archivo || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ($archivo['error'] !== UPLOAD_ERR_OK) {
            $this->error = $this->mensajeErrorPhp((int) $archivo['error']);
            return null;
        }

        // 1) Que provenga realmente de una subida HTTP y no de una ruta
        //    del servidor inyectada por el atacante.
        if (!is_uploaded_file($archivo['tmp_name'])) {
            $this->error = 'El archivo recibido no es válido.';
            return null;
        }

        // 2) Tamaño (se revalida en el servidor: el atributo del HTML se
        //    puede quitar desde las herramientas del navegador).
        if ($archivo['size'] > self::MAX_BYTES) {
            $this->error = 'La imagen supera los '
                         . round(self::MAX_BYTES / 1024 / 1024, 1) . ' MB.';
            return null;
        }
        if ($archivo['size'] === 0) {
            $this->error = 'El archivo está vacío.';
            return null;
        }

        // 3) Tipo REAL leído del contenido, no del nombre ni de lo que
        //    dijo el navegador.
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($archivo['tmp_name']);

        if (!isset(self::PERMITIDOS[$mime])) {
            $this->error = 'El archivo no es una imagen válida. '
                         . 'Se aceptan JPG, PNG, WebP o GIF.';
            return null;
        }

        // 4) Que GD pueda decodificarla de verdad (un archivo puede tener
        //    cabecera de imagen y estar corrupto).
        $info = @getimagesize($archivo['tmp_name']);
        if ($info === false || empty($info[0]) || empty($info[1])) {
            $this->error = 'No pudimos leer la imagen. Probá con otro archivo.';
            return null;
        }
        [$anchoOrig, $altoOrig] = $info;

        // Techo de dimensiones: una imagen de 20000x20000 px pesa poco
        // comprimida pero al descomprimirse agota la memoria del servidor
        // (ataque conocido como "decompression bomb").
        if ($anchoOrig * $altoOrig > 50_000_000) {
            $this->error = 'La imagen tiene demasiados píxeles. Usá una más chica.';
            return null;
        }

        $origen = $this->abrir($archivo['tmp_name'], $mime);
        if (!$origen) {
            $this->error = 'No se pudo procesar la imagen.';
            return null;
        }

        // 5) Recorte cuadrado centrado + escalado.
        //    Se recorta al centro para que todas las fotos de perfil
        //    tengan la misma proporción y no se deformen al mostrarse
        //    dentro de un círculo.
        $lado = min($anchoOrig, $altoOrig);
        $offX = (int) (($anchoOrig - $lado) / 2);
        $offY = (int) (($altoOrig  - $lado) / 2);
        $destinoLado = min($lado, self::MAX_LADO);

        $destino = imagecreatetruecolor($destinoLado, $destinoLado);
        // Fondo blanco: si el PNG original tenía transparencia, al pasar
        // a JPG quedaría negra sin esto.
        imagefilledrectangle($destino, 0, 0, $destinoLado, $destinoLado,
            imagecolorallocate($destino, 255, 255, 255));

        imagecopyresampled($destino, $origen, 0, 0, $offX, $offY,
            $destinoLado, $destinoLado, $lado, $lado);

        // 6) Nombre aleatorio: nunca se reutiliza el que mandó el usuario
        //    (podría ser "../../index.php" o contener caracteres raros).
        $nombre = bin2hex(random_bytes(16)) . '.jpg';
        $ruta   = $this->carpeta . '/' . $nombre;

        // Guardar como JPEG re-codificado: el archivo final lo escribe GD
        // desde los píxeles, así que no conserva NADA del original (ni
        // metadatos EXIF con ubicación, ni código escondido).
        $ok = imagejpeg($destino, $ruta, self::CALIDAD);

        imagedestroy($origen);
        imagedestroy($destino);

        if (!$ok) {
            $this->error = 'No se pudo guardar la imagen.';
            return null;
        }

        @chmod($ruta, 0644);
        return $nombre;
    }

    /** Crea el recurso GD según el tipo real detectado. */
    private function abrir(string $ruta, string $mime)
    {
        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($ruta),
            'image/png'  => @imagecreatefrompng($ruta),
            'image/webp' => @imagecreatefromwebp($ruta),
            'image/gif'  => @imagecreatefromgif($ruta),
            default      => false,
        };
    }

    /**
     * Borra una foto anterior (al reemplazarla desde el perfil).
     * Sólo acepta nombres con el formato que genera esta clase, para que
     * no se pueda pedir el borrado de otro archivo del servidor.
     */
    public function eliminar(?string $nombre): void
    {
        if (!$nombre || !preg_match('/^[a-f0-9]{32}\.jpg$/', $nombre)) {
            return;
        }
        $ruta = $this->carpeta . '/' . $nombre;
        if (is_file($ruta)) {
            @unlink($ruta);
        }
    }

    /** URL pública de una foto, o null si el usuario no tiene. */
    public static function url(?string $nombre): ?string
    {
        if (!$nombre || !preg_match('/^[a-f0-9]{32}\.jpg$/', $nombre)) {
            return null;
        }
        return BASE_URL . 'publico/img/perfiles/' . $nombre;
    }
}

<?php
// =============================================================
// includes/validacion.php — Reglas de validación compartidas
// =============================================================
// Las MISMAS reglas hacían falta en tres lugares distintos:
//     · ControladorAuth::registrar()  → alta pública
//     · restablecer.php               → contraseña nueva por correo
//     · ControladorPerfil             → edición del propio perfil
//
// Y estaban escritas tres veces. Peor: `restablecer.php` declaraba su
// PROPIA constante `PASS_MIN = 8` sin ninguna relación con la de
// ControladorAuth. Subir el mínimo a 10 en un archivo y olvidarse del
// otro no habría dado ningún error: simplemente el sistema pediría
// contraseñas distintas según por dónde entrara el usuario, y nadie se
// enteraría hasta que alguien lo notara a mano.
//
// Acá quedan una sola vez. Cada método devuelve el MENSAJE DE ERROR
// (string) o `null` si el valor es válido — la misma convención que ya
// usaba ControladorAuth::registrar(), así los llamadores no cambian su
// forma de trabajar:
//
//     if ($err = Validacion::dni($dni)) return $err;
//
// POR QUÉ ES UNA CLASE CON MÉTODOS ESTÁTICOS Y NO FUNCIONES SUELTAS
// (como auth.php o seguridad.php): estas reglas van siempre en grupo y
// tienen nombres genéricos —`email`, `telefono`, `usuario`—. Como
// funciones globales chocarían con cualquier otra del proyecto; el
// prefijo `Validacion::` las agrupa y las mantiene inconfundibles.
//
// TODO esto corre en el SERVIDOR. El JavaScript de los formularios
// repite las mismas reglas, pero es sólo ayuda visual: se puede
// desactivar y el POST se puede armar a mano.
// =============================================================

class Validacion
{
    /** Longitud mínima de contraseña. Un solo lugar, todo el sistema. */
    public const PASS_MIN = 8;

    /** `usuario.nombre` y `paciente.nombre` son VARCHAR(30). */
    public const NOMBRE_MAX = 30;

    /** Alineado con `usuario.email` y `paciente.email` (ver sql/perfil.sql). */
    public const EMAIL_MAX = 120;

    /** `paciente.direccion` es VARCHAR(150). */
    public const DIRECCION_MAX = 150;

    /** Valores aceptados: deben coincidir con el ENUM de `paciente.sexo`. */
    public const SEXOS = ['F', 'M', 'X', 'prefiero_no_decir'];

    /**
     * Contraseña nueva (registro, recuperación y cambio desde el perfil).
     *
     * Los topes son deliberadamente modestos —largo, una letra y un
     * número— porque exigir símbolos empuja a la gente a inventar
     * variantes de la misma clave de siempre, que es peor. La defensa
     * real contra el adivinado está en el bloqueo por intentos
     * (includes/seguridad.php), no en la complejidad del texto.
     */
    public static function password(string $p, string $p2): ?string
    {
        if (strlen($p) < self::PASS_MIN) {
            return 'La contraseña debe tener al menos ' . self::PASS_MIN . ' caracteres.';
        }
        if (!preg_match('/[A-Za-z]/', $p)) {
            return 'La contraseña debe incluir al menos una letra.';
        }
        if (!preg_match('/\d/', $p)) {
            return 'La contraseña debe incluir al menos un número.';
        }
        if ($p !== $p2) {
            return 'Las contraseñas no coinciden.';
        }
        return null;
    }

    /**
     * Nombre o apellido.
     *
     * El tope de 30 no es capricho: es el ancho real de la columna. Sin
     * modo estricto, MySQL recorta el sobrante SIN avisar y la persona
     * se queda con el apellido cortado creyendo que se guardó entero.
     */
    public static function nombre(string $v, string $etiqueta = 'El nombre'): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return $etiqueta . ' no puede quedar vacío.';
        }
        if (mb_strlen($v) > self::NOMBRE_MAX) {
            return $etiqueta . ' no puede superar los ' . self::NOMBRE_MAX . ' caracteres.';
        }
        return null;
    }

    /** DNI argentino: sólo dígitos, sin puntos. */
    public static function dni(string $v): ?string
    {
        if (!ctype_digit($v) || strlen($v) < 7 || strlen($v) > 9) {
            return 'El DNI debe tener entre 7 y 9 dígitos, sin puntos.';
        }
        return null;
    }

    /**
     * Teléfono.
     *
     * Se permiten los separadores que la gente escribe naturalmente
     * (espacios, guiones, paréntesis, +) y se cuentan sólo los dígitos
     * reales. Rechazar "11 4444-1111" por tener un guion sería pelear
     * contra el usuario por algo que el sistema puede resolver solo.
     */
    public static function telefono(string $v): ?string
    {
        $digitos = preg_replace('/\D/', '', $v);
        if (strlen($digitos) < 8 || strlen($digitos) > 15) {
            return 'El teléfono debe tener entre 8 y 15 dígitos.';
        }
        return null;
    }

    /** Correo electrónico. */
    public static function email(string $v): ?string
    {
        if (!filter_var($v, FILTER_VALIDATE_EMAIL)) {
            return 'El email no tiene un formato válido.';
        }
        if (strlen($v) > self::EMAIL_MAX) {
            return 'El email es demasiado largo.';
        }
        return null;
    }

    /**
     * Nombre de usuario.
     *
     * Juego de caracteres acotado a propósito: sin espacios ni símbolos
     * raros que después compliquen escribirlo al iniciar sesión.
     */
    public static function usuario(string $v): ?string
    {
        if (!preg_match('/^[a-zA-Z0-9._-]{4,40}$/', $v)) {
            return 'El usuario debe tener entre 4 y 40 caracteres y sólo letras, '
                 . 'números, punto, guion o guion bajo.';
        }
        return null;
    }

    /**
     * Fecha de nacimiento (opcional; si viene, tiene que ser real).
     *
     * `createFromFormat` sola no alcanza: acepta "2026-02-31" y la
     * corre a marzo. Por eso se compara el resultado formateado contra
     * el texto original — si no coinciden, la fecha no existía.
     */
    public static function fechaNac(string $v): ?string
    {
        if ($v === '') {
            return null;
        }
        $f = DateTime::createFromFormat('Y-m-d', $v);
        if (!$f || $f->format('Y-m-d') !== $v) {
            return 'La fecha de nacimiento no es válida.';
        }
        if ($f > new DateTime('today')) {
            return 'La fecha de nacimiento no puede ser futura.';
        }
        if ((int) $f->format('Y') < 1900) {
            return 'Revisá la fecha de nacimiento.';
        }
        return null;
    }

    /** Sexo (opcional). Se compara contra el ENUM de la base. */
    public static function sexo(string $v): ?string
    {
        if ($v !== '' && !in_array($v, self::SEXOS, true)) {
            return 'El sexo seleccionado no es válido.';
        }
        return null;
    }

    /** Dirección (opcional). */
    public static function direccion(string $v): ?string
    {
        if (mb_strlen($v) > self::DIRECCION_MAX) {
            return 'La dirección es demasiado larga (máximo '
                 . self::DIRECCION_MAX . ' caracteres).';
        }
        return null;
    }

    /** Número de afiliado (opcional). Formato libre entre obras sociales. */
    public static function nroAfiliado(string $v): ?string
    {
        if ($v !== '' && !preg_match('/^[A-Za-z0-9\/-]{3,50}$/', $v)) {
            return 'El número de afiliado sólo admite letras, números, guiones '
                 . 'y barras (3 a 50 caracteres).';
        }
        return null;
    }
}

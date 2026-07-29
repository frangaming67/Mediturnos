<?php
// sistema/controladores/ControladorPerfil.php
// -----------------------------------------------------------------
// Controlador de la pantalla "Mi perfil". Se escribe como CLASE, igual
// que ControladorAuth y por el mismo motivo: no es una URL que el
// navegador visite (los ControladorTurno, ControladorPaciente… sí lo
// son, y por eso usan switch($_GET['accion'])). Acá el punto de entrada
// es perfil.php, que decide qué método llamar según qué formulario se
// envió.
//
// LA REGLA DE SEGURIDAD QUE ATRAVIESA TODO EL ARCHIVO
// Ningún método recibe un id por parámetro desde el navegador. Todos
// trabajan sobre $perfil, que perfil.php cargó a partir de
// $_SESSION['id_usuario']. Si el id viniera en un campo oculto del
// formulario, cualquiera podría cambiarlo y editar la cuenta de otra
// persona (IDOR). Acá eso no es posible: el dato que decide sobre qué
// fila se escribe NUNCA sale del servidor.
//
// Todos los métodos devuelven el mismo formato:
//     ['ok' => bool, 'msg' => string]
// para que perfil.php los trate a todos igual y no tenga que saber qué
// hace cada uno por dentro.
// -----------------------------------------------------------------

require_once __DIR__ . '/../../includes/validacion.php';
require_once __DIR__ . '/../../includes/subida_imagen.php';
require_once __DIR__ . '/../modelos/Perfil.php';
require_once __DIR__ . '/../modelos/Usuario.php';

class ControladorPerfil
{
    private PDO $pdo;
    private Perfil $modelo;
    private Usuario $usuarios;

    public function __construct(PDO $pdo)
    {
        $this->pdo      = $pdo;
        $this->modelo   = new Perfil($pdo);
        $this->usuarios = new Usuario($pdo);
    }

    /** Respuesta corta, para no repetir el array en cada return. */
    private function error(string $msg): array { return ['ok' => false, 'msg' => $msg]; }
    private function exito(string $msg): array { return ['ok' => true,  'msg' => $msg]; }

    // =============================================================
    // DATOS DE LA CUENTA — nombre, apellido, correo
    // =============================================================

    public function guardarCuenta(array $perfil, array $post): array
    {
        $nombre   = trim($post['nombre']   ?? '');
        $apellido = trim($post['apellido'] ?? '');
        $email    = trim($post['email']    ?? '');

        if ($err = Validacion::nombre($nombre, 'El nombre'))     return $this->error($err);
        if ($err = Validacion::nombre($apellido, 'El apellido')) return $this->error($err);
        if ($err = Validacion::email($email))                    return $this->error($err);

        // El correo es la credencial alternativa de acceso (se puede
        // entrar con usuario o con email), así que no puede repetirse.
        // Se excluye la propia cuenta: si no, guardar sin cambiar el
        // correo daría "ya está en uso" contra uno mismo.
        if ($this->usuarios->existeEmail($email, (int) $perfil['id_usuario'])) {
            return $this->error('Ese correo ya está registrado en otra cuenta.');
        }

        try {
            $this->modelo->guardarCuenta((int) $perfil['id_usuario'], [
                'nombre'      => $nombre,
                'apellido'    => $apellido,
                'email'       => $email,
                'id_paciente' => $perfil['id_paciente'],
                'matricula'   => $perfil['matricula'],
            ]);
        } catch (PDOException $e) {
            error_log('ControladorPerfil guardarCuenta: ' . $e->getMessage());
            return $this->error('No se pudieron guardar los datos. Intentá de nuevo.');
        }

        // La sesión guarda una copia del nombre para el saludo y la barra
        // lateral. Si no se actualiza acá, la persona guarda el cambio y
        // sigue viendo el nombre viejo hasta volver a entrar — parece que
        // no se guardó nada.
        $_SESSION['nombre']   = $nombre;
        $_SESSION['apellido'] = $apellido;

        return $this->exito('Datos de la cuenta actualizados.');
    }

    // =============================================================
    // DATOS PERSONALES — según el rol
    // =============================================================

    public function guardarDatos(array $perfil, array $post): array
    {
        $telefono = trim($post['telefono'] ?? '');
        if ($err = Validacion::telefono($telefono)) return $this->error($err);

        try {
            if (!empty($perfil['id_paciente'])) {
                $fechaNac  = trim($post['fecha_nac'] ?? '');
                $sexo      = trim($post['sexo']      ?? '');
                $direccion = trim($post['direccion'] ?? '');

                if ($err = Validacion::fechaNac($fechaNac))   return $this->error($err);
                if ($err = Validacion::sexo($sexo))           return $this->error($err);
                if ($err = Validacion::direccion($direccion)) return $this->error($err);

                $this->modelo->guardarDatosPaciente((int) $perfil['id_paciente'], [
                    'fecha_nac' => $fechaNac,
                    'sexo'      => $sexo,
                    'telefono'  => $telefono,
                    'direccion' => $direccion,
                ]);

            } elseif (!empty($perfil['matricula'])) {
                $this->modelo->guardarDatosMedico((int) $perfil['matricula'], $telefono);

            } else {
                // admin y recepcionista no tienen ficha: no hay dónde
                // guardar un teléfono. La vista ni siquiera dibuja esta
                // sección para ellos; el chequeo está por si el POST
                // llega igual, armado a mano.
                return $this->error('Tu cuenta no tiene una ficha con datos personales.');
            }
        } catch (PDOException $e) {
            error_log('ControladorPerfil guardarDatos: ' . $e->getMessage());
            return $this->error('No se pudieron guardar los datos. Intentá de nuevo.');
        }

        return $this->exito('Datos personales actualizados.');
    }

    // =============================================================
    // COBERTURA — obra social y número de afiliado
    // =============================================================

    public function guardarCobertura(array $perfil, array $post): array
    {
        if (empty($perfil['id_paciente'])) {
            return $this->error('Sólo las cuentas de paciente tienen cobertura médica.');
        }

        $idPlan = trim($post['id_plan'] ?? '');
        $nro    = trim($post['nro_afiliado'] ?? '');

        if ($idPlan === '') {
            // Sin obra social no puede sobrevivir un número de afiliado
            // colgado, que quedaría asociado a nada.
            $idPlan = null;
            $nro    = null;
        } else {
            // El <select> del navegador se puede editar desde las
            // herramientas de desarrollo: que el plan exista se verifica
            // contra la base, no contra lo que llegó.
            if (!ctype_digit($idPlan) || !$this->usuarios->existePlan((int) $idPlan)) {
                return $this->error('La obra social seleccionada no es válida.');
            }
            if ($err = Validacion::nroAfiliado($nro)) return $this->error($err);
            $idPlan = (int) $idPlan;
            $nro    = $nro !== '' ? $nro : null;
        }

        try {
            $this->modelo->guardarCobertura((int) $perfil['id_paciente'], $idPlan, $nro);
        } catch (PDOException $e) {
            error_log('ControladorPerfil guardarCobertura: ' . $e->getMessage());
            return $this->error('No se pudo guardar la cobertura. Intentá de nuevo.');
        }

        return $this->exito($idPlan === null
            ? 'Quedaste registrado como paciente particular.'
            : 'Cobertura actualizada.');
    }

    // =============================================================
    // FOTO
    // =============================================================

    public function guardarFoto(array $perfil, array $files): array
    {
        if (empty($files['foto']['name'])) {
            return $this->error('Elegí una imagen antes de guardar.');
        }

        // Toda la validación pesada (tipo real, re-codificación con GD,
        // nombre aleatorio) la hace SubidaImagen: es el mismo módulo que
        // usa el registro, ya probado contra un .jpg con código PHP dentro.
        $subida = new SubidaImagen();
        $nueva  = $subida->procesar($files['foto']);

        if ($nueva === null) {
            return $this->error($subida->error() ?? 'No se pudo procesar la imagen.');
        }

        try {
            $anterior = $this->modelo->guardarFoto((int) $perfil['id_usuario'], $nueva);
        } catch (PDOException $e) {
            error_log('ControladorPerfil guardarFoto: ' . $e->getMessage());
            // La imagen ya está en el disco pero la base no la conoce:
            // si no se borra queda un archivo huérfano ocupando lugar
            // para siempre, sin ninguna fila que lo referencie.
            $subida->eliminar($nueva);
            return $this->error('No se pudo guardar la foto. Intentá de nuevo.');
        }

        // Recién ahora se borra la anterior: primero la base confirmó
        // que apunta a la nueva. Al revés, un fallo del UPDATE dejaría
        // la fila apuntando a un archivo ya borrado.
        $subida->eliminar($anterior);

        $_SESSION['foto'] = $nueva;
        return $this->exito('Foto actualizada.');
    }

    public function quitarFoto(array $perfil): array
    {
        if (empty($perfil['foto'])) {
            return $this->error('No tenés ninguna foto cargada.');
        }

        try {
            $anterior = $this->modelo->guardarFoto((int) $perfil['id_usuario'], null);
        } catch (PDOException $e) {
            error_log('ControladorPerfil quitarFoto: ' . $e->getMessage());
            return $this->error('No se pudo quitar la foto. Intentá de nuevo.');
        }

        (new SubidaImagen())->eliminar($anterior);

        $_SESSION['foto'] = null;
        return $this->exito('Foto eliminada.');
    }

    // =============================================================
    // CONTRASEÑA
    // =============================================================

    /**
     * Cambio de contraseña desde la sesión abierta.
     *
     * Pide la contraseña ACTUAL aunque la persona ya esté autenticada.
     * No es burocracia: si alguien se levanta de la computadora sin
     * cerrar sesión, cualquiera que pase podría cambiarle la clave y
     * dejarlo afuera de su propia cuenta. Pedir la actual convierte ese
     * "tener la sesión abierta" en "además saber la contraseña".
     *
     * Y como eso abre la puerta a probar contraseñas de a una, se
     * reutiliza el mismo freno del login. Se usa un identificador con
     * prefijo propio ('perfil:usuario') a propósito: si compartiera el
     * contador con el login, alguien con la sesión secuestrada podría
     * fallar cinco veces acá y dejar al dueño sin poder iniciar sesión.
     */
    public function cambiarPassword(array $perfil, array $post): array
    {
        $actual = (string) ($post['password_actual'] ?? '');
        $nueva  = (string) ($post['password_nueva']  ?? '');
        $nueva2 = (string) ($post['password_nueva2'] ?? '');

        if ($actual === '') {
            return $this->error('Ingresá tu contraseña actual.');
        }

        $identificador = 'perfil:' . $perfil['usuario'];

        if (loginBloqueado($this->pdo, $identificador)) {
            return $this->error(
                'Demasiados intentos fallidos. Esperá ' . LOGIN_VENTANA_MIN
                . ' minutos antes de volver a probar.'
            );
        }

        $hash = $this->modelo->hashActual((int) $perfil['id_usuario']);
        if ($hash === null || !password_verify($actual, $hash)) {
            registrarIntentoLogin($this->pdo, $identificador, false);
            $quedan = intentosRestantes($this->pdo, $identificador);
            return $this->error(
                'La contraseña actual no es correcta.'
                . ($quedan > 0 ? ' Te quedan ' . $quedan . ' intentos.' : '')
            );
        }

        if ($err = Validacion::password($nueva, $nueva2)) {
            return $this->error($err);
        }

        // Cambiar la contraseña por la misma no cambia nada, y la
        // persona se queda creyendo que renovó su clave.
        if (password_verify($nueva, $hash)) {
            return $this->error('La contraseña nueva tiene que ser distinta de la actual.');
        }

        try {
            $this->modelo->guardarPassword((int) $perfil['id_usuario'], $nueva);
        } catch (PDOException $e) {
            error_log('ControladorPerfil cambiarPassword: ' . $e->getMessage());
            return $this->error('No se pudo cambiar la contraseña. Intentá de nuevo.');
        }

        registrarIntentoLogin($this->pdo, $identificador, true);

        // Identificador de sesión nuevo. Si alguien hubiera robado la
        // cookie, el id viejo deja de servirle en el mismo momento en
        // que el dueño cambia la clave — que es justo lo que uno espera
        // que pase al cambiarla.
        session_regenerate_id(true);

        return $this->exito('Contraseña actualizada.');
    }
}

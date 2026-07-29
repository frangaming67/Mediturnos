<?php
// sistema/modelos/Perfil.php
// -----------------------------------------------------------------
// Modelo de la pantalla de perfil: lo que cada usuario puede cambiar
// de SU PROPIA cuenta.
//
// POR QUÉ ES UN MODELO APARTE Y NO MÁS MÉTODOS EN Usuario.php
// Usuario.php gira alrededor de UNA tabla (`usuario`) y de un tema:
// quién entra al sistema. El perfil, en cambio, es una operación
// transversal: un solo formulario toca `usuario` + `paciente` (o
// `medico`) + `paciente_plan`. Meter eso adentro de Usuario haría que
// el modelo de autenticación tuviera que saber de coberturas médicas,
// que no es asunto suyo.
//
// EL PROBLEMA CENTRAL: EL NOMBRE ESTÁ DUPLICADO
// El nombre, el apellido y el correo viven en DOS tablas a la vez:
// en `usuario` (la cuenta) y en `paciente`/`medico` (la ficha). No es
// un descuido del diseño: la ficha existe aunque la persona nunca
// tenga cuenta —la recepción carga pacientes que no se registran— y
// la cuenta necesita esos datos para saludar y para el login sin
// depender de una ficha que puede no existir (admin y recepcionista
// no tienen ninguna).
//
// La consecuencia es que TODA escritura del perfil actualiza las dos
// filas, y por eso va en una transacción: si se actualizara `usuario`
// y fallara `paciente`, la persona se llamaría distinto según qué
// pantalla la mire. Un dato duplicado sólo se sostiene si se escribe
// siempre junto.
// -----------------------------------------------------------------

class Perfil
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // =============================================================
    // LECTURA
    // =============================================================

    /**
     * Todo lo que la pantalla necesita, en una sola llamada.
     *
     * Devuelve siempre las mismas claves, con null en las que no
     * apliquen al rol: así la vista pregunta por el dato y no por el
     * rol, y agregar un rol nuevo no obliga a tocar el HTML.
     */
    public function cargar(int $idUsuario): array|false
    {
        $stmt = $this->pdo->prepare(
            "SELECT u.id_usuario, u.nombre, u.apellido, u.usuario, u.email,
                    u.foto, u.estado, u.fecha_alta, u.ultimo_login,
                    u.id_paciente, u.matricula,
                    r.nombre AS rol
             FROM   usuario u
             JOIN   rol     r ON r.id_rol = u.id_rol
             WHERE  u.id_usuario = :id
             LIMIT  1"
        );
        $stmt->execute([':id' => $idUsuario]);
        $u = $stmt->fetch();
        if (!$u) return false;

        // Valores neutros por defecto. La vista los usa sin preguntar
        // de qué rol vienen.
        $u['dni']          = null;
        $u['fecha_nac']    = null;
        $u['sexo']         = null;
        $u['telefono']     = null;
        $u['direccion']    = null;
        $u['id_plan']      = null;
        $u['nro_afiliado'] = null;
        $u['obra_social']  = null;
        $u['nombre_plan']  = null;

        if (!empty($u['id_paciente'])) {
            $p = $this->pdo->prepare(
                "SELECT dni, fecha_nac, sexo, telefono, direccion
                 FROM   paciente WHERE id_paciente = :id"
            );
            $p->execute([':id' => $u['id_paciente']]);
            if ($fila = $p->fetch()) {
                $u = array_merge($u, $fila);
            }
            $u = array_merge($u, $this->cobertura((int) $u['id_paciente']));
        } elseif (!empty($u['matricula'])) {
            $m = $this->pdo->prepare(
                "SELECT telefono FROM medico WHERE matricula = :m"
            );
            $m->execute([':m' => $u['matricula']]);
            if ($fila = $m->fetch()) {
                $u['telefono'] = $fila['telefono'];
            }
        }

        return $u;
    }

    /**
     * Cobertura vigente del paciente.
     *
     * `paciente_plan` es N:M: un paciente PODRÍA tener varios planes.
     * El perfil muestra y edita uno solo, que es lo que representa la
     * realidad de esta clínica (hoy no hay ni un paciente con dos).
     * Se ordena por fecha de alta descendente para que, si alguna vez
     * hubiera más de uno, se muestre el más reciente y no uno al azar.
     */
    private function cobertura(int $idPaciente): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT pp.id_plan, pp.nro_afiliado,
                    pl.nombre_plan, os.nombre AS obra_social
             FROM   paciente_plan pp
             JOIN   plan_os       pl ON pl.id_plan        = pp.id_plan
             JOIN   obra_social   os ON os.id_obra_social = pl.id_obra_social
             WHERE  pp.id_paciente = :id
             ORDER  BY pp.fecha_alta DESC
             LIMIT  1"
        );
        $stmt->execute([':id' => $idPaciente]);
        $fila = $stmt->fetch();

        return $fila ?: [
            'id_plan'      => null,
            'nro_afiliado' => null,
            'nombre_plan'  => null,
            'obra_social'  => null,
        ];
    }

    /** Hash guardado, para verificar la contraseña actual. */
    public function hashActual(int $idUsuario): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT contrasenia FROM usuario WHERE id_usuario = :id"
        );
        $stmt->execute([':id' => $idUsuario]);
        $hash = $stmt->fetchColumn();
        return $hash === false ? null : (string) $hash;
    }

    // =============================================================
    // ESCRITURA
    // =============================================================

    /**
     * Nombre, apellido y correo: los tres datos duplicados.
     *
     * Se escriben en `usuario` y en la ficha vinculada dentro de la
     * misma transacción, por lo explicado en la cabecera del archivo.
     */
    public function guardarCuenta(int $idUsuario, array $d): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                "UPDATE usuario
                 SET    nombre = :nombre, apellido = :apellido, email = :email
                 WHERE  id_usuario = :id"
            )->execute([
                ':nombre'   => $d['nombre'],
                ':apellido' => $d['apellido'],
                ':email'    => $d['email'],
                ':id'       => $idUsuario,
            ]);

            if (!empty($d['id_paciente'])) {
                $this->pdo->prepare(
                    "UPDATE paciente
                     SET    nombre = :nombre, apellido = :apellido, email = :email
                     WHERE  id_paciente = :id"
                )->execute([
                    ':nombre'   => $d['nombre'],
                    ':apellido' => $d['apellido'],
                    ':email'    => $d['email'],
                    ':id'       => $d['id_paciente'],
                ]);
            } elseif (!empty($d['matricula'])) {
                $this->pdo->prepare(
                    "UPDATE medico
                     SET    nombre = :nombre, apellido = :apellido, email = :email
                     WHERE  matricula = :m"
                )->execute([
                    ':nombre'   => $d['nombre'],
                    ':apellido' => $d['apellido'],
                    ':email'    => $d['email'],
                    ':m'        => $d['matricula'],
                ]);
            }

            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Datos personales del paciente.
     *
     * El DNI NO está: identifica a la persona en toda la base (es la
     * clave por la que la recepción la busca y por la que se detectan
     * duplicados). Si alguien se equivocó al registrarse, lo corrige
     * el personal desde la gestión de pacientes, no la propia persona
     * en un formulario sin control.
     */
    public function guardarDatosPaciente(int $idPaciente, array $d): void
    {
        $this->pdo->prepare(
            "UPDATE paciente
             SET    fecha_nac = :fecha_nac, sexo = :sexo,
                    telefono  = :telefono,  direccion = :direccion
             WHERE  id_paciente = :id"
        )->execute([
            ':fecha_nac' => $d['fecha_nac'] !== '' ? $d['fecha_nac'] : null,
            ':sexo'      => $d['sexo']      !== '' ? $d['sexo']      : null,
            // Texto, nunca int: ver el porqué en sql/perfil.sql
            ':telefono'  => $d['telefono'],
            ':direccion' => $d['direccion'] !== '' ? $d['direccion'] : null,
            ':id'        => $idPaciente,
        ]);
    }

    /** Teléfono del médico (es el único dato propio que edita acá). */
    public function guardarDatosMedico(int $matricula, string $telefono): void
    {
        $this->pdo->prepare(
            "UPDATE medico SET telefono = :tel WHERE matricula = :m"
        )->execute([':tel' => $telefono, ':m' => $matricula]);
    }

    /**
     * Reemplaza la cobertura del paciente.
     *
     * Tres casos, y el del medio es el que importa:
     *   · sin plan          → se borra la cobertura
     *   · MISMO plan        → sólo se actualiza el número de afiliado,
     *                         conservando la fecha de alta original
     *   · plan distinto     → se borra la anterior y se da de alta la nueva
     *
     * El caso del medio existe para no reescribir `fecha_alta` cada vez
     * que alguien corrige un dígito del número de afiliado: esa fecha
     * dice desde cuándo tiene la cobertura, y perderla falsearía el
     * historial de la persona.
     */
    public function guardarCobertura(int $idPaciente, ?int $idPlan, ?string $nroAfiliado): void
    {
        $actual = $this->cobertura($idPaciente);

        $this->pdo->beginTransaction();
        try {
            if ($idPlan === null) {
                $this->pdo->prepare(
                    "DELETE FROM paciente_plan WHERE id_paciente = :id"
                )->execute([':id' => $idPaciente]);

            } elseif ((int) $actual['id_plan'] === $idPlan) {
                $this->pdo->prepare(
                    "UPDATE paciente_plan SET nro_afiliado = :nro
                     WHERE  id_paciente = :id AND id_plan = :pl"
                )->execute([
                    ':nro' => $nroAfiliado,
                    ':id'  => $idPaciente,
                    ':pl'  => $idPlan,
                ]);

            } else {
                $this->pdo->prepare(
                    "DELETE FROM paciente_plan WHERE id_paciente = :id"
                )->execute([':id' => $idPaciente]);

                $this->pdo->prepare(
                    "INSERT INTO paciente_plan (id_paciente, id_plan, nro_afiliado, fecha_alta)
                     VALUES (:id, :pl, :nro, CURDATE())"
                )->execute([
                    ':id'  => $idPaciente,
                    ':pl'  => $idPlan,
                    ':nro' => $nroAfiliado,
                ]);
            }

            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Guarda el nombre de la foto y devuelve el ANTERIOR.
     *
     * Devolver el anterior no es un detalle: quien llama necesita ese
     * nombre para borrar el archivo viejo del disco DESPUÉS de que la
     * base ya haya confirmado el cambio. Al revés —borrar primero— un
     * fallo del UPDATE dejaría a la base apuntando a un archivo que ya
     * no existe, y el usuario vería un avatar roto para siempre.
     */
    public function guardarFoto(int $idUsuario, ?string $nombre): ?string
    {
        $stmt = $this->pdo->prepare("SELECT foto FROM usuario WHERE id_usuario = :id");
        $stmt->execute([':id' => $idUsuario]);
        $anterior = $stmt->fetchColumn();

        $this->pdo->prepare(
            "UPDATE usuario SET foto = :foto WHERE id_usuario = :id"
        )->execute([':foto' => $nombre, ':id' => $idUsuario]);

        return $anterior ?: null;
    }

    /** Guarda el hash de la contraseña nueva. */
    public function guardarPassword(int $idUsuario, string $nueva): void
    {
        $this->pdo->prepare(
            "UPDATE usuario SET contrasenia = :hash WHERE id_usuario = :id"
        )->execute([
            ':hash' => password_hash($nueva, PASSWORD_DEFAULT),
            ':id'   => $idUsuario,
        ]);
    }
}

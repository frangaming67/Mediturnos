<?php
// sistema/modelos/Medico.php
// -----------------------------------------------------------------
// asignarEspecialidades() es privado y se llama desde crear() y
// actualizar(): un médico puede tener varias especialidades (relación
// N:M vía medico_especialidad), así que guardar esa lista es un paso
// interno del alta/edición, no una operación pública en sí misma —
// nadie de afuera debería poder tocar esa tabla sin pasar por crear()
// o actualizar(), que son quienes garantizan consistencia con los
// demás campos del médico.
// -----------------------------------------------------------------

class Medico
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Lista médicos con sus especialidades agrupadas.
     * Filtros: nombre, apellido, especialidad (id).
     */
    public function listar(array $filtros = []): array
    {
        $where  = ["1=1"];
        $params = [];

        if (!empty($filtros['nombre'])) {
            $where[]          = "m.nombre LIKE :nombre";
            $params[':nombre'] = '%' . $filtros['nombre'] . '%';
        }
        if (!empty($filtros['apellido'])) {
            $where[]            = "m.apellido LIKE :apellido";
            $params[':apellido'] = '%' . $filtros['apellido'] . '%';
        }
        if (!empty($filtros['id_especialidad'])) {
            $where[]               = "EXISTS (SELECT 1 FROM medico_especialidad me2
                                              WHERE me2.matricula = m.matricula
                                                AND me2.id_especialidad = :id_esp)";
            $params[':id_esp']      = $filtros['id_especialidad'];
        }
        if (!empty($filtros['estado'])) {
            $where[]           = "m.estado = :estado";
            $params[':estado'] = $filtros['estado'];
        }

        $sql = "SELECT m.matricula, m.nombre, m.apellido, m.email, m.estado,
                       GROUP_CONCAT(e.nombre ORDER BY e.nombre SEPARATOR ', ') AS especialidades
                FROM   medico m
                LEFT JOIN medico_especialidad me ON me.matricula = m.matricula
                LEFT JOIN especialidad e         ON e.id_especialidad = me.id_especialidad
                WHERE  " . implode(' AND ', $where) . "
                GROUP BY m.matricula
                ORDER BY m.apellido, m.nombre";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Profesionales que atienden una especialidad, con todo lo que el
     * paciente necesita para elegir: foto, matrícula, calificación,
     * consultorios y días de atención.
     *
     * ── LA FOTO SALE DE `usuario`, NO DE `medico` ────────────
     * No se agregó una columna `medico.foto`: el profesional ya sube su
     * foto desde su perfil y queda en `usuario.foto`. Es la misma
     * persona y la misma imagen; duplicarla obligaría a mantener dos
     * copias sincronizadas y a decidir cuál gana cuando difieran. Un
     * médico sin cuenta simplemente no tiene foto y se muestran sus
     * iniciales.
     *
     * ── POR QUÉ LA CALIFICACIÓN VA EN SUBCONSULTA ────────────
     * Es la trampa clásica de este tipo de listado. Si `calificacion` se
     * uniera con JOIN al mismo tiempo que `horario_atencion`, cada
     * calificación aparecería repetida una vez por cada franja horaria
     * del médico, y el AVG() saldría calculado sobre esas filas
     * multiplicadas. El promedio no daría mal por poco: daría cualquier
     * cosa, y encima parecería creíble. En subconsulta, cada número se
     * calcula sobre su propia tabla.
     *
     * ── EL JOIN CON HORARIOS ES INTERNO A PROPÓSITO ──────────
     * Un médico puede tener la especialidad cargada pero ningún horario
     * de atención para ella. Ofrecerlo llevaría al paciente a un
     * calendario vacío después de dos clics. Si no tiene agenda, no
     * aparece.
     */
    public function listarParaReserva(int $idEspecialidad): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT m.matricula, m.nombre, m.apellido, u.foto,
                    (SELECT ROUND(AVG(c.puntaje), 1) FROM calificacion c
                      WHERE c.matricula = m.matricula)            AS calificacion,
                    (SELECT COUNT(*) FROM calificacion c
                      WHERE c.matricula = m.matricula)            AS votos,
                    GROUP_CONCAT(DISTINCT CONCAT('Cons. ', co.numero, ' - Piso ', co.piso)
                                 ORDER BY co.numero SEPARATOR ' · ') AS consultorios,
                    GROUP_CONCAT(DISTINCT h.dia_semana)              AS dias
             FROM   medico m
             JOIN   medico_especialidad me
                    ON me.matricula = m.matricula AND me.id_especialidad = :esp
             JOIN   horario_atencion h
                    ON h.matricula = m.matricula AND h.id_especialidad = :esp2
             JOIN   consultorio co ON co.id_consultorio = h.id_consultorio
             LEFT   JOIN usuario u ON u.matricula = m.matricula
             WHERE  m.estado = 'activo'
             GROUP  BY m.matricula, m.nombre, m.apellido, u.foto
             ORDER  BY calificacion DESC, m.apellido, m.nombre"
        );
        // Dos marcadores con el mismo valor: con EMULATE_PREPARES en
        // false no se puede repetir uno.
        $stmt->execute([':esp' => $idEspecialidad, ':esp2' => $idEspecialidad]);
        return $stmt->fetchAll();
    }

    /**
     * Trae un médico con su lista de id_especialidad.
     */
    public function buscarPorMatricula(int $matricula): array|false
    {
        $stmt = $this->pdo->prepare("SELECT * FROM medico WHERE matricula = :m");
        $stmt->execute([':m' => $matricula]);
        $medico = $stmt->fetch();
        if (!$medico) return false;

        // Especialidades del médico
        $stmt2 = $this->pdo->prepare(
            "SELECT id_especialidad FROM medico_especialidad WHERE matricula = :m"
        );
        $stmt2->execute([':m' => $matricula]);
        $medico['especialidades'] = $stmt2->fetchAll(PDO::FETCH_COLUMN);
        return $medico;
    }

    /**
     * Crea un médico y asigna sus especialidades (transacción).
     */
    public function crear(array $datos): bool
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO medico (matricula, nombre, apellido, telefono, email)
                 VALUES (:matricula, :nombre, :apellido, :telefono, :email)"
            );
            $stmt->execute([
                ':matricula' => $datos['matricula'],
                ':nombre'    => $datos['nombre'],
                ':apellido'  => $datos['apellido'],
                ':telefono'  => $datos['telefono'],
                ':email'     => $datos['email'],
            ]);

            $this->asignarEspecialidades((int)$datos['matricula'], $datos['especialidades'] ?? []);
            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Actualiza un médico y reemplaza sus especialidades.
     */
    public function actualizar(int $matricula, array $datos): bool
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE medico
                 SET nombre = :nombre, apellido = :apellido,
                     telefono = :telefono, email = :email
                 WHERE matricula = :matricula"
            );
            $stmt->execute([
                ':nombre'    => $datos['nombre'],
                ':apellido'  => $datos['apellido'],
                ':telefono'  => $datos['telefono'],
                ':email'     => $datos['email'],
                ':matricula' => $matricula,
            ]);

            // Reemplazar especialidades
            $this->pdo->prepare("DELETE FROM medico_especialidad WHERE matricula = :m")
                      ->execute([':m' => $matricula]);
            $this->asignarEspecialidades($matricula, $datos['especialidades'] ?? []);
            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Baja lógica: marca el médico como 'inactivo' y registra la fecha.
     * No se borra la fila para conservar turnos, horarios y datos históricos.
     */
    public function darDeBaja(int $matricula): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE medico SET estado = 'inactivo', fecha_baja = CURDATE()
             WHERE matricula = :m"
        );
        return $stmt->execute([':m' => $matricula]);
    }

    /**
     * Reactiva (da de alta) un médico dado de baja.
     */
    public function reactivar(int $matricula): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE medico SET estado = 'activo', fecha_baja = NULL
             WHERE matricula = :m"
        );
        return $stmt->execute([':m' => $matricula]);
    }

    /**
     * Lista todas las especialidades disponibles.
     */
    public function obtenerEspecialidades(): array
    {
        return $this->pdo->query(
            "SELECT id_especialidad, nombre FROM especialidad ORDER BY nombre"
        )->fetchAll();
    }

    // ── Privado ──────────────────────────────────────────────

    private function asignarEspecialidades(int $matricula, array $ids): void
    {
        if (empty($ids)) return;
        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO medico_especialidad (matricula, id_especialidad) VALUES (:m, :e)"
        );
        foreach ($ids as $idEsp) {
            $stmt->execute([':m' => $matricula, ':e' => $idEsp]);
        }
    }
}

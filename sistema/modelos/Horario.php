<?php
// sistema/modelos/Horario.php — ABM de horarios de atención de médicos
// -----------------------------------------------------------------
// haySolapamiento() es la regla de negocio más importante de este
// modelo (evita que un médico quede con dos horarios que se pisan) y
// vive acá y no en ControladorHorario porque necesita conocer los datos
// ya guardados en la base para comparar contra ellos; el controlador
// solo la invoca cuando corresponde (crear/editar), sin saber cómo se
// implementa el chequeo.
// -----------------------------------------------------------------

class Horario
{
    private PDO $pdo;

    // La constante DIAS vive en la clase (no en el controlador) porque
    // es un dato intrínseco de qué es un "horario válido" en este
    // dominio; así el controlador y cualquier otro código pueden
    // reusar Horario::DIAS para validar sin duplicar el array.
    // Sin tilde porque así se guardan los valores en la columna
    // dia_semana de la base (evita problemas de charset/collation).
    /** Días válidos (SIN tilde, igual que se guardan en la base). */
    public const DIAS = ['Lunes','Martes','Miercoles','Jueves','Viernes','Sabado','Domingo'];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Lista horarios con nombres de médico, especialidad y consultorio.
     * Filtro opcional: matricula.
     */
    public function listar(array $filtros = []): array
    {
        $where  = [];
        $params = [];

        if (!empty($filtros['matricula'])) {
            $where[]              = "h.matricula = :matricula";
            $params[':matricula'] = $filtros['matricula'];
        }

        $sql = "SELECT h.id_horario, h.matricula, h.dia_semana,
                       h.hora_inicio, h.hora_fin,
                       h.id_especialidad, h.id_consultorio,
                       CONCAT(m.apellido, ', ', m.nombre) AS medico,
                       e.nombre AS especialidad,
                       CONCAT('Cons. ', c.numero, ' - Piso ', c.piso) AS consultorio
                FROM   horario_atencion h
                JOIN   medico       m ON m.matricula       = h.matricula
                JOIN   especialidad e ON e.id_especialidad = h.id_especialidad
                JOIN   consultorio  c ON c.id_consultorio  = h.id_consultorio";
        if ($where) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        // Ordenar por médico y por día (orden real de la semana)
        $sql .= " ORDER BY m.apellido, m.nombre,
                  FIELD(h.dia_semana,'Lunes','Martes','Miercoles','Jueves','Viernes','Sabado','Domingo'),
                  h.hora_inicio";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function buscarPorId(int $id): array|false
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM horario_atencion WHERE id_horario = :id"
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    public function crear(array $datos): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO horario_atencion
                (matricula, id_especialidad, dia_semana, hora_inicio, hora_fin, id_consultorio)
             VALUES (:m, :e, :dia, :ini, :fin, :c)"
        );
        $stmt->execute([
            ':m'   => $datos['matricula'],
            ':e'   => $datos['id_especialidad'],
            ':dia' => $datos['dia_semana'],
            ':ini' => $datos['hora_inicio'],
            ':fin' => $datos['hora_fin'],
            ':c'   => $datos['id_consultorio'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function actualizar(int $id, array $datos): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE horario_atencion
             SET matricula = :m, id_especialidad = :e, dia_semana = :dia,
                 hora_inicio = :ini, hora_fin = :fin, id_consultorio = :c
             WHERE id_horario = :id"
        );
        return $stmt->execute([
            ':m'   => $datos['matricula'],
            ':e'   => $datos['id_especialidad'],
            ':dia' => $datos['dia_semana'],
            ':ini' => $datos['hora_inicio'],
            ':fin' => $datos['hora_fin'],
            ':c'   => $datos['id_consultorio'],
            ':id'  => $id,
        ]);
    }

    /**
     * Detecta si el médico ya tiene un bloque que se SOLAPA con el nuevo
     * (mismo día, rango horario que se cruza). Excluye el propio id al editar.
     */
    public function haySolapamiento(int $matricula, string $dia, string $ini, string $fin, int $excludeId = 0): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM horario_atencion
             WHERE matricula = :m AND dia_semana = :dia
               AND id_horario <> :id
               AND hora_inicio < :fin AND hora_fin > :ini"
        );
        $stmt->execute([':m'=>$matricula, ':dia'=>$dia, ':id'=>$excludeId, ':fin'=>$fin, ':ini'=>$ini]);
        return (int) $stmt->fetchColumn() > 0;
    }

    // ── Auxiliares para los selects ──────────────────────────

    public function listarMedicos(): array
    {
        return $this->pdo->query(
            "SELECT matricula, CONCAT(apellido,', ',nombre) AS nombre_completo
             FROM medico WHERE estado = 'activo' ORDER BY apellido, nombre"
        )->fetchAll();
    }

    public function listarEspecialidades(): array
    {
        return $this->pdo->query(
            "SELECT id_especialidad, nombre FROM especialidad ORDER BY nombre"
        )->fetchAll();
    }

    public function listarConsultorios(): array
    {
        return $this->pdo->query(
            "SELECT id_consultorio, CONCAT('Cons. ',numero,' - Piso ',piso) AS nombre
             FROM consultorio ORDER BY piso, numero"
        )->fetchAll();
    }
}

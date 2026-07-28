<?php
// sistema/modelos/ObraSocial.php
// ABM de obras sociales y sus planes.
// -----------------------------------------------------------------
// Este modelo agrupa 3 entidades relacionadas (obra_social, plan_os,
// descuento_os_medico) en una sola clase, igual que su controlador:
// un plan no existe sin su obra social, y un descuento no existe sin
// un plan de por medio (a través del médico). Separarlas en 3 clases
// obligaría a inyectar unas dentro de otras solo para resolver esas
// dependencias, sin ninguna ganancia de organización real.
// listarSimple() existe además de listar() porque los <select> de los
// formularios (elegir obra social al crear un plan, por ejemplo) no
// necesitan el conteo de planes ni los demás datos que sí trae listar()
// para la tabla del ABM — pedir menos columnas es más liviano.
// -----------------------------------------------------------------

class ObraSocial
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // ── Obras sociales ───────────────────────────────────────

    /**
     * Lista obras sociales con la cantidad de planes de cada una.
     * Filtro opcional: nombre.
     */
    public function listar(array $filtros = []): array
    {
        $where  = [];
        $params = [];

        if (!empty($filtros['nombre'])) {
            $where[]           = "os.nombre LIKE :nombre";
            $params[':nombre'] = '%' . $filtros['nombre'] . '%';
        }

        $sql = "SELECT os.id_obra_social, os.nombre, os.cuit,
                       COUNT(pl.id_plan) AS cant_planes
                FROM   obra_social os
                LEFT JOIN plan_os pl ON pl.id_obra_social = os.id_obra_social";
        if ($where) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        $sql .= " GROUP BY os.id_obra_social ORDER BY os.nombre";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function buscarPorId(int $id): array|false
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM obra_social WHERE id_obra_social = :id"
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    public function crear(array $datos): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO obra_social (nombre, cuit) VALUES (:nombre, :cuit)"
        );
        $stmt->execute([
            ':nombre' => $datos['nombre'],
            ':cuit'   => $datos['cuit'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function actualizar(int $id, array $datos): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE obra_social SET nombre = :nombre, cuit = :cuit
             WHERE id_obra_social = :id"
        );
        return $stmt->execute([
            ':nombre' => $datos['nombre'],
            ':cuit'   => $datos['cuit'],
            ':id'     => $id,
        ]);
    }

    /**
     * Verifica si un CUIT ya existe (la columna es UNIQUE).
     */
    public function existeCuit(string $cuit, int $excludeId = 0): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM obra_social WHERE cuit = :c AND id_obra_social <> :id"
        );
        $stmt->execute([':c' => $cuit, ':id' => $excludeId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** Lista simple para selects (id + nombre). */
    public function listarSimple(): array
    {
        return $this->pdo->query(
            "SELECT id_obra_social, nombre FROM obra_social ORDER BY nombre"
        )->fetchAll();
    }

    // ── Planes ───────────────────────────────────────────────

    /**
     * Lista todos los planes con el nombre de su obra social.
     */
    public function listarPlanes(): array
    {
        return $this->pdo->query(
            "SELECT pl.id_plan, pl.nombre_plan, pl.porcentaje_cobertura,
                    os.nombre AS obra_social, os.id_obra_social
             FROM   plan_os pl
             JOIN   obra_social os ON os.id_obra_social = pl.id_obra_social
             ORDER  BY os.nombre, pl.nombre_plan"
        )->fetchAll();
    }

    public function buscarPlanPorId(int $id): array|false
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM plan_os WHERE id_plan = :id"
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    public function crearPlan(array $datos): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO plan_os (id_obra_social, nombre_plan, porcentaje_cobertura)
             VALUES (:id_os, :nombre, :cobertura)"
        );
        $stmt->execute([
            ':id_os'     => $datos['id_obra_social'],
            ':nombre'    => $datos['nombre_plan'],
            ':cobertura' => $datos['porcentaje_cobertura'] !== '' ? $datos['porcentaje_cobertura'] : null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function actualizarPlan(int $id, array $datos): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE plan_os
             SET id_obra_social = :id_os, nombre_plan = :nombre,
                 porcentaje_cobertura = :cobertura
             WHERE id_plan = :id"
        );
        return $stmt->execute([
            ':id_os'     => $datos['id_obra_social'],
            ':nombre'    => $datos['nombre_plan'],
            ':cobertura' => $datos['porcentaje_cobertura'] !== '' ? $datos['porcentaje_cobertura'] : null,
            ':id'        => $id,
        ]);
    }

    // ── Descuentos por obra social y médico ──────────────────

    /** Lista médicos para los selects (matrícula + nombre). */
    public function listarMedicos(): array
    {
        return $this->pdo->query(
            "SELECT matricula, CONCAT(apellido, ', ', nombre) AS nombre_completo
             FROM medico WHERE estado = 'activo' ORDER BY apellido, nombre"
        )->fetchAll();
    }

    /**
     * Lista los descuentos cargados (obra social ↔ médico).
     * Filtros opcionales: id_obra_social, matricula.
     */
    public function listarDescuentos(array $filtros = []): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filtros['id_obra_social'])) {
            $where[]        = "d.id_obra_social = :os";
            $params[':os']  = $filtros['id_obra_social'];
        }
        if (!empty($filtros['matricula'])) {
            $where[]        = "d.matricula = :m";
            $params[':m']   = $filtros['matricula'];
        }

        $sql = "SELECT d.id_descuento, d.id_obra_social, d.matricula,
                       d.porcentaje_descuento,
                       os.nombre AS obra_social,
                       CONCAT(m.apellido, ', ', m.nombre) AS medico
                FROM descuento_os_medico d
                JOIN obra_social os ON os.id_obra_social = d.id_obra_social
                JOIN medico m       ON m.matricula       = d.matricula
                WHERE " . implode(' AND ', $where) . "
                ORDER BY os.nombre, m.apellido, m.nombre";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Crea o actualiza un descuento (upsert por par obra social/médico).
     */
    public function guardarDescuento(int $idObraSocial, int $matricula, float $porcentaje): bool
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO descuento_os_medico (id_obra_social, matricula, porcentaje_descuento)
             VALUES (:os, :m, :pct)
             ON DUPLICATE KEY UPDATE porcentaje_descuento = VALUES(porcentaje_descuento)"
        );
        return $stmt->execute([
            ':os'  => $idObraSocial,
            ':m'   => $matricula,
            ':pct' => $porcentaje,
        ]);
    }

    public function eliminarDescuento(int $idDescuento): bool
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM descuento_os_medico WHERE id_descuento = :id"
        );
        return $stmt->execute([':id' => $idDescuento]);
    }
}

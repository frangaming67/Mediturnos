<?php
// sistema/modelos/Consultorio.php — ABM de consultorios
// -----------------------------------------------------------------
// existeNumeroPiso() vive acá y no en el controlador porque es una
// regla de UNICIDAD de datos (dos consultorios no pueden compartir
// número+piso): el modelo es quien conoce el esquema de la tabla y debe
// garantizar esa invariante sin importar qué controlador la llame.
// -----------------------------------------------------------------

class Consultorio
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function listar(): array
    {
        return $this->pdo->query(
            "SELECT id_consultorio, numero, piso, descripcion_equipamiento
             FROM   consultorio ORDER BY piso, numero"
        )->fetchAll();
    }

    public function buscarPorId(int $id): array|false
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM consultorio WHERE id_consultorio = :id"
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    public function crear(array $datos): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO consultorio (numero, piso, descripcion_equipamiento)
             VALUES (:numero, :piso, :desc)"
        );
        $stmt->execute([
            ':numero' => $datos['numero'],
            ':piso'   => $datos['piso'],
            ':desc'   => $datos['descripcion_equipamiento'] !== '' ? $datos['descripcion_equipamiento'] : null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function actualizar(int $id, array $datos): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE consultorio
             SET numero = :numero, piso = :piso, descripcion_equipamiento = :desc
             WHERE id_consultorio = :id"
        );
        return $stmt->execute([
            ':numero' => $datos['numero'],
            ':piso'   => $datos['piso'],
            ':desc'   => $datos['descripcion_equipamiento'] !== '' ? $datos['descripcion_equipamiento'] : null,
            ':id'     => $id,
        ]);
    }

    /**
     * Verifica si ya existe un consultorio con ese número en ese piso.
     */
    public function existeNumeroPiso(int $numero, int $piso, int $excludeId = 0): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM consultorio
             WHERE numero = :n AND piso = :p AND id_consultorio <> :id"
        );
        $stmt->execute([':n' => $numero, ':p' => $piso, ':id' => $excludeId]);
        return (int) $stmt->fetchColumn() > 0;
    }
}

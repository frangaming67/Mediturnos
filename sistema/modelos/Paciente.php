<?php
// sistema/modelos/Paciente.php
// -----------------------------------------------------------------
// Este modelo es SOLO para la gestión interna (ABM) que hace el staff
// desde ControladorPaciente. El alta pública de un paciente (cuando se
// registra solo, sin sesión, desde registro.php) pasa por
// Usuario::registrarPaciente(), no por Paciente::crear(), porque ese
// flujo necesita crear la fila en `paciente` Y en `usuario` juntas en
// una transacción — algo que este modelo, enfocado solo en la tabla
// `paciente`, no tiene por qué saber hacer.
// -----------------------------------------------------------------

class Paciente
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** Cantidad de pacientes por página en el listado. */
    public const POR_PAGINA = 25;

    /**
     * Arma el WHERE y los parámetros compartidos por listar() y contar(),
     * para que ambos filtren EXACTAMENTE igual. Si estuviera duplicado, el
     * total del paginador podría no coincidir con las filas mostradas.
     */
    private function construirFiltros(array $filtros): array
    {
        $where  = [];
        $params = [];

        if (!empty($filtros['nombre'])) {
            $where[]           = "nombre LIKE :nombre";
            $params[':nombre'] = '%' . $filtros['nombre'] . '%';
        }
        if (!empty($filtros['apellido'])) {
            $where[]             = "apellido LIKE :apellido";
            $params[':apellido'] = '%' . $filtros['apellido'] . '%';
        }
        if (!empty($filtros['dni'])) {
            $where[]        = "dni LIKE :dni";
            $params[':dni'] = '%' . $filtros['dni'] . '%';
        }

        return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
    }

    /**
     * Lista pacientes con filtros opcionales (nombre, apellido, dni),
     * PAGINADO.
     *
     * Sin el LIMIT, con la base real (1000+ pacientes) esta pantalla
     * devolvía más de 1 MB de HTML en una sola respuesta: el navegador
     * tardaba en pintarla y en un celular directamente se trababa.
     * Se traen sólo las filas de la página pedida.
     */
    public function listar(array $filtros = [], int $pagina = 1): array
    {
        [$whereSql, $params] = $this->construirFiltros($filtros);

        $porPagina = self::POR_PAGINA;
        $offset    = max(0, ($pagina - 1) * $porPagina);

        // LIMIT/OFFSET se interpolan como enteros ya casteados (no como
        // parámetros) porque con PDO::ATTR_EMULATE_PREPARES = false MySQL
        // los recibiría como string y rechazaría la consulta. El casteo a
        // int los hace seguros: no puede colarse SQL por ahí.
        $sql = "SELECT id_paciente, dni, nombre, apellido, fecha_nac, telefono, email
                FROM   paciente"
             . $whereSql
             . " ORDER BY apellido, nombre"
             . " LIMIT {$porPagina} OFFSET {$offset}";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Total de pacientes que cumplen los filtros (para el paginador). */
    public function contar(array $filtros = []): int
    {
        [$whereSql, $params] = $this->construirFiltros($filtros);

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM paciente" . $whereSql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Busca un paciente por su ID.
     */
    public function buscarPorId(int $id): array|false
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM paciente WHERE id_paciente = :id"
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    /**
     * Crea un nuevo paciente.
     */
    public function crear(array $datos): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO paciente (dni, nombre, apellido, fecha_nac, telefono, email)
             VALUES (:dni, :nombre, :apellido, :fecha_nac, :telefono, :email)"
        );
        $stmt->execute([
            ':dni' => $datos['dni'],
            ':nombre' => $datos['nombre'],
            ':apellido' => $datos['apellido'],
            ':fecha_nac'=> $datos['fecha_nac'] ?: null,
            ':telefono' => $datos['telefono'],
            ':email' => $datos['email'] ?: null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Actualiza un paciente existente.
     */
    public function actualizar(int $id, array $datos): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE paciente
             SET    dni = :dni, nombre = :nombre, apellido = :apellido,
                    fecha_nac = :fecha_nac, telefono = :telefono, email = :email
             WHERE  id_paciente = :id"
        );
        return $stmt->execute([
            ':dni' => $datos['dni'],
            ':nombre'  => $datos['nombre'],
            ':apellido' => $datos['apellido'],
            ':fecha_nac'=> $datos['fecha_nac'] ?: null,
            ':telefono' => $datos['telefono'],
            ':email' => $datos['email'] ?: null,
            ':id' => $id,
        ]);
    }

    /**
     * Verifica si un DNI ya existe (para validar duplicados).
     */
    public function existeDni(string $dni, int $excludeId = 0): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM paciente WHERE dni = :dni AND id_paciente <> :id"
        );
        $stmt->execute([':dni' => $dni, ':id' => $excludeId]);
        return (int) $stmt->fetchColumn() > 0;
    }
}

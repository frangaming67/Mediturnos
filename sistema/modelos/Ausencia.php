<?php
// sistema/modelos/Ausencia.php — Ausencias de médicos (días que no atienden)

/**
 * Gestiona las ausencias de un médico para un día puntual.
 *
 * Una ausencia significa: "el médico X no atiende el día Y". Al
 * registrarla se cancelan los turnos activos de ese día (para que el
 * paciente quede avisado) y el día deja de ofrecerse como disponible
 * (eso lo resuelve Turno::hayAusencia()).
 */
// La cancelación en cascada de turnos vive acá (dentro de registrar())
// y no en el controlador, porque es una consecuencia OBLIGATORIA de
// marcar una ausencia, sin importar quién la dispare; y porque va dentro
// de la misma transacción del INSERT — ver el comentario de registrar()
// más abajo para el motivo de atomicidad.
class Ausencia
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Lista las ausencias con el nombre del médico y de quién la cargó.
     * Filtros opcionales: matricula, desde (sólo de hoy en adelante).
     */
    public function listar(array $filtros = []): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filtros['matricula'])) {
            $where[]              = 'a.matricula = :matricula';
            $params[':matricula'] = $filtros['matricula'];
        }
        if (!empty($filtros['desde'])) {
            $where[]          = 'a.fecha >= :desde';
            $params[':desde'] = $filtros['desde'];
        }

        $sql = "SELECT a.id_ausencia, a.matricula, a.fecha, a.motivo,
                       a.fecha_registro,
                       CONCAT(m.apellido, ', ', m.nombre) AS medico,
                       CONCAT(u.nombre, ' ', u.apellido)  AS cargado_por
                FROM   ausencia_medico a
                JOIN   medico  m ON m.matricula  = a.matricula
                LEFT JOIN usuario u ON u.id_usuario = a.registrado_por
                WHERE  " . implode(' AND ', $where) . "
                ORDER BY a.fecha DESC, m.apellido";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function buscarPorId(int $id): array|false
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM ausencia_medico WHERE id_ausencia = :id"
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    /**
     * ¿El médico ya está marcado como ausente ese día?
     */
    public function existe(int $matricula, string $fecha): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM ausencia_medico
             WHERE matricula = :m AND fecha = :f"
        );
        $stmt->execute([':m' => $matricula, ':f' => $fecha]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Devuelve las fechas futuras en que un médico está ausente.
     * Se usa para "pintar" esos días como NO disponibles en el calendario.
     *
     * @return string[]  Array de fechas 'YYYY-MM-DD'
     */
    public function fechasFuturas(int $matricula): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT fecha FROM ausencia_medico
             WHERE matricula = :m AND fecha >= CURDATE()
             ORDER BY fecha"
        );
        $stmt->execute([':m' => $matricula]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Registra una ausencia y, en la MISMA transacción, cancela todos
     * los turnos activos del médico ese día.
     *
     * ATOMICIDAD: o se hace todo (se marca la ausencia Y se cancelan los
     * turnos) o no se hace nada. Si algo falla en el medio, se revierte
     * con rollBack y la base queda como estaba. Así nunca puede pasar que
     * un médico figure ausente pero sus turnos sigan "Reservados".
     *
     * Cada UPDATE del turno dispara el trigger trg_turno_after_update, que
     * guarda el cambio de estado en historial_turno. Por eso acá NO se
     * inserta el historial a mano.
     *
     * @return int  Cantidad de turnos que se cancelaron.
     * @throws RuntimeException  Si el médico ya estaba marcado ese día.
     */
    public function registrar(int $matricula, string $fecha, string $motivo, ?int $idUsuario): int
    {
        try {
            $this->pdo->beginTransaction();

            // 1) Marcar la ausencia (la clave única evita duplicados).
            $ins = $this->pdo->prepare(
                "INSERT INTO ausencia_medico (matricula, fecha, motivo, registrado_por)
                 VALUES (:m, :f, :motivo, :u)"
            );
            $ins->execute([
                ':m'      => $matricula,
                ':f'      => $fecha,
                ':motivo' => $motivo !== '' ? $motivo : null,
                ':u'      => $idUsuario,
            ]);

            // 2) Cancelar los turnos activos de ese médico ese día.
            //    No se tocan los ya 'Cancelado' ni los 'Realizado'.
            $obs = 'Cancelado: el médico no atiende el ' . date('d/m/Y', strtotime($fecha))
                 . ($motivo !== '' ? ' (' . $motivo . ')' : '');

            // El estado se normalizó a estado_turno (id_estado). Se cancelan
            // los turnos del día que NO estén ya Cancelados ni Realizados.
            $upd = $this->pdo->prepare(
                "UPDATE turno
                 SET id_estado   = (SELECT id_estado FROM estado_turno WHERE descripcion = 'Cancelado'),
                     observacion = :obs
                 WHERE matricula = :m AND fecha = :f
                   AND id_estado NOT IN (
                       SELECT id_estado FROM estado_turno
                       WHERE descripcion IN ('Cancelado', 'Realizado')
                   )"
            );
            $upd->execute([':obs' => $obs, ':m' => $matricula, ':f' => $fecha]);
            $cancelados = $upd->rowCount();

            $this->pdo->commit();
            return $cancelados;

        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            // 23000 = violación de integridad; acá sólo puede venir de la
            // clave única uq_ausencia (médico ya marcado ese día, error 1062).
            if ($e->getCode() === '23000') {
                throw new RuntimeException(
                    'Ese médico ya estaba marcado como ausente en esa fecha.'
                );
            }
            throw $e;
        }
    }

    /**
     * Deshace una ausencia (borra el registro).
     *
     * IMPORTANTE: deshacer vuelve a ABRIR la agenda de ese día (los
     * horarios se ofrecen de nuevo), pero NO restaura los turnos que ya
     * se habían cancelado: esos quedan 'Cancelado' a propósito, porque al
     * paciente ya se le había avisado. Si hace falta, se reservan de nuevo.
     */
    public function eliminar(int $id): void
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM ausencia_medico WHERE id_ausencia = :id"
        );
        $stmt->execute([':id' => $id]);
    }

    // ── Auxiliar para el select del formulario ───────────────
    public function listarMedicos(): array
    {
        return $this->pdo->query(
            "SELECT matricula, CONCAT(apellido,', ',nombre) AS nombre_completo
             FROM medico WHERE estado = 'activo' ORDER BY apellido, nombre"
        )->fetchAll();
    }
}

<?php
// sistema/modelos/Calificacion.php
// -----------------------------------------------------------------
// Calificación de un profesional por parte del paciente que se atendió
// con él.
//
// LO QUE HACE CREÍBLE A UNA CALIFICACIÓN
// No es el promedio: es quién puede dejarla. Acá sólo puede calificar
// alguien que tuvo un turno REALIZADO con ese médico, y una sola vez
// por turno.
//
// Las dos condiciones están puestas en el esquema (`id_turno` es UNIQUE
// y no hay calificación sin turno), no sólo en este archivo. Si sólo
// vivieran en PHP dependerían de que todo camino futuro se acuerde de
// comprobarlas; en el esquema, una segunda calificación del mismo turno
// es imposible aunque el código falle.
// -----------------------------------------------------------------

class Calificacion
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * ¿Puede esta persona calificar este turno? Devuelve el MOTIVO por el
     * que no, o null si sí.
     *
     * El motivo se devuelve en vez de un booleano por lo mismo que en
     * Turno::motivoNoReprogramable(): la pantalla tiene que poder
     * explicar por qué no aparece el formulario.
     */
    public function motivoNoCalificable(array $turno, int $idPaciente): ?string
    {
        if ((int) $turno['id_paciente'] !== $idPaciente) {
            return 'Sólo podés calificar tus propias consultas.';
        }
        if (($turno['estado'] ?? '') !== 'Realizado') {
            return 'Vas a poder calificar después de la consulta.';
        }
        if ($this->deTurno((int) $turno['id_turno'])) {
            return 'Ya calificaste esta consulta.';
        }
        return null;
    }

    /** La calificación de un turno, si existe. */
    public function deTurno(int $idTurno): array|false
    {
        $stmt = $this->pdo->prepare("SELECT * FROM calificacion WHERE id_turno = :id");
        $stmt->execute([':id' => $idTurno]);
        return $stmt->fetch();
    }

    /**
     * Guarda la calificación.
     *
     * El puntaje se acota en PHP además del CHECK de la base: así el
     * usuario recibe un mensaje claro en vez de un error de motor.
     */
    public function crear(int $idTurno, int $matricula, int $idPaciente,
                          int $puntaje, ?string $comentario = null): void
    {
        if ($puntaje < 1 || $puntaje > 5) {
            throw new RuntimeException('La calificación va de 1 a 5 estrellas.');
        }

        try {
            $this->pdo->prepare(
                "INSERT INTO calificacion (id_turno, matricula, id_paciente, puntaje, comentario)
                 VALUES (:t, :m, :p, :pu, :c)"
            )->execute([
                ':t'  => $idTurno,
                ':m'  => $matricula,
                ':p'  => $idPaciente,
                ':pu' => $puntaje,
                ':c'  => $comentario !== null && trim($comentario) !== ''
                            ? mb_substr(trim($comentario), 0, 400) : null,
            ]);
        } catch (PDOException $e) {
            // 23000 acá sólo puede ser el UNIQUE de id_turno: alguien
            // envió el formulario dos veces (doble clic, botón atrás).
            if ($e->getCode() === '23000') {
                throw new RuntimeException('Esta consulta ya estaba calificada.');
            }
            throw $e;
        }
    }

    /** Últimos comentarios de un médico, para mostrar en su ficha. */
    public function comentariosDeMedico(int $matricula, int $limite = 3): array
    {
        $limite = max(1, min(10, $limite));
        $stmt = $this->pdo->prepare(
            "SELECT c.puntaje, c.comentario, c.creada_en,
                    CONCAT(UPPER(LEFT(p.nombre,1)), '. ', p.apellido) AS paciente
             FROM   calificacion c
             JOIN   paciente p ON p.id_paciente = c.id_paciente
             WHERE  c.matricula = :m AND c.comentario IS NOT NULL
             ORDER  BY c.creada_en DESC
             LIMIT  {$limite}"
        );
        $stmt->execute([':m' => $matricula]);
        return $stmt->fetchAll();
    }
}

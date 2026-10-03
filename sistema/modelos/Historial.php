<?php
// sistema/modelos/Historial.php
// -----------------------------------------------------------------
// Historial clínico: consultas registradas y estudios solicitados.
//
// LA REGLA QUE ATRAVIESA TODO EL ARCHIVO
// Esto es información de salud. Ningún método devuelve datos sin decir
// DE QUIÉN son: `deDelPaciente($idPaciente)`, `puedeVer($..., $rol)`.
// No hay un `buscarPorId($id)` suelto que devuelva la consulta de
// cualquiera y deje el control en manos de quien llame.
//
// Es la misma decisión que en Notificacion.php y por el mismo motivo:
// si el control vive en el controlador, alcanza con que un controlador
// nuevo se olvide de hacerlo. Acá el modelo no expone la forma
// insegura.
// -----------------------------------------------------------------

require_once __DIR__ . '/../../includes/busqueda.php';

class Historial
{
    /**
     * Techo de filas de la línea de tiempo.
     *
     * El historial de una persona es corto por naturaleza, pero "corto
     * por naturaleza" no es una garantía: una cuenta con años de
     * tratamiento crónico devolvería todo en cada visita. Es el mismo
     * criterio y el mismo número que en Receta.
     */
    public const MAX_FILAS = 200;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // =============================================================
    // CONSULTAS (lo que el médico registra)
    // =============================================================

    /**
     * Registra o actualiza la consulta de un turno.
     *
     * Es un upsert y no un insert porque la consulta es UNA por turno
     * (`consulta.id_turno` es UNIQUE): si el médico vuelve a entrar para
     * agregar algo que se olvidó, corrige la misma ficha en vez de
     * crear una segunda que duplicaría la cita en el historial.
     */
    public function guardarConsulta(int $idTurno, int $matricula, array $d): void
    {
        $this->pdo->prepare(
            "INSERT INTO consulta (id_turno, matricula, motivo_consulta, diagnostico, indicaciones)
             VALUES (:t, :m, :motivo, :diag, :ind)
             ON DUPLICATE KEY UPDATE
                motivo_consulta = VALUES(motivo_consulta),
                diagnostico     = VALUES(diagnostico),
                indicaciones    = VALUES(indicaciones)"
        )->execute([
            ':t'      => $idTurno,
            ':m'      => $matricula,
            ':motivo' => mb_substr(trim($d['motivo_consulta']), 0, 200),
            ':diag'   => $this->textoONulo($d['diagnostico']  ?? null),
            ':ind'    => $this->textoONulo($d['indicaciones'] ?? null),
        ]);
    }

    /** La consulta de un turno, si el médico ya la registró. */
    public function consultaDeTurno(int $idTurno): array|false
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.*, CONCAT(m.apellido, ', ', m.nombre) AS medico
             FROM   consulta c
             JOIN   medico m ON m.matricula = c.matricula
             WHERE  c.id_turno = :t"
        );
        $stmt->execute([':t' => $idTurno]);
        return $stmt->fetch();
    }

    // =============================================================
    // ESTUDIOS
    // =============================================================

    /** El médico pide un estudio. Nace 'Pendiente', sin archivo. */
    public function solicitarEstudio(int $idPaciente, int $matricula, array $d, ?int $idTurno = null): int
    {
        $this->pdo->prepare(
            "INSERT INTO estudio (id_paciente, id_turno, matricula, tipo, nombre)
             VALUES (:p, :t, :m, :tipo, :nombre)"
        )->execute([
            ':p'      => $idPaciente,
            ':t'      => $idTurno,
            ':m'      => $matricula,
            ':tipo'   => mb_substr(trim($d['tipo']), 0, 60),
            ':nombre' => mb_substr(trim($d['nombre']), 0, 150),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Adjunta el resultado y lo marca disponible.
     *
     * Devuelve el nombre del archivo ANTERIOR (o null): quien llama lo
     * necesita para borrarlo del disco DESPUÉS de que la base confirmó
     * el cambio. Al revés, un fallo del UPDATE dejaría la fila apuntando
     * a un archivo que ya no existe — el mismo criterio que en la foto
     * de perfil.
     */
    public function adjuntarResultado(int $idEstudio, string $archivo): ?string
    {
        $stmt = $this->pdo->prepare("SELECT archivo FROM estudio WHERE id_estudio = :id");
        $stmt->execute([':id' => $idEstudio]);
        $anterior = $stmt->fetchColumn();

        $this->pdo->prepare(
            "UPDATE estudio
             SET archivo = :a, estado = 'Disponible', resultado_en = NOW()
             WHERE id_estudio = :id"
        )->execute([':a' => $archivo, ':id' => $idEstudio]);

        return $anterior !== false && $anterior !== null ? (string) $anterior : null;
    }

    /**
     * Un estudio, CON su dueño y su médico, para poder decidir permisos.
     *
     * No se llama buscarPorId() a propósito: el nombre tiene que dejar
     * claro que lo que devuelve todavía hay que autorizarlo, y para eso
     * está puedeVerEstudio() justo abajo.
     */
    public function estudioConDueno(int $idEstudio): array|false
    {
        $stmt = $this->pdo->prepare(
            "SELECT e.*, CONCAT(m.apellido, ', ', m.nombre) AS medico,
                    CONCAT(p.apellido, ', ', p.nombre) AS paciente
             FROM   estudio e
             JOIN   medico   m ON m.matricula   = e.matricula
             JOIN   paciente p ON p.id_paciente = e.id_paciente
             WHERE  e.id_estudio = :id"
        );
        $stmt->execute([':id' => $idEstudio]);
        return $stmt->fetch();
    }

    /**
     * ¿Puede esta persona ver este estudio?
     *
     * Tres casos y ninguno más:
     *   · el paciente dueño;
     *   · un médico que lo atendió alguna vez (no cualquiera: el que
     *     nunca lo vio no tiene por qué acceder a sus análisis);
     *   · admin y recepción, que gestionan la clínica.
     */
    public function puedeVerEstudio(array $estudio, string $rol, ?int $idPaciente, ?int $matricula): bool
    {
        if ($rol === 'admin' || $rol === 'recepcionista') {
            return true;
        }
        if ($rol === 'paciente') {
            return $idPaciente !== null && (int) $estudio['id_paciente'] === $idPaciente;
        }
        if ($rol === 'medico') {
            return $matricula !== null && $this->atendioAlPaciente($matricula, (int) $estudio['id_paciente']);
        }
        return false;
    }

    /** ¿Este médico atendió alguna vez a este paciente? */
    public function atendioAlPaciente(int $matricula, int $idPaciente): bool
    {
        // ── POR QUÉ SÓLO CUENTAN LOS TURNOS REALIZADOS ───────────
        // La primera versión contaba CUALQUIER turno entre los dos, y
        // eso abría el historial clínico de par en par:
        //
        //   · Alguien saca turno con un profesional para el mes que
        //     viene y, desde ese instante, ese profesional puede leer
        //     TODOS sus estudios anteriores — incluidos los que pidió
        //     otro médico.
        //   · Peor: si después lo cancela, la fila del turno queda con
        //     estado 'Cancelado' y el acceso NO se pierde nunca más.
        //
        // Entrar a la historia clínica de una persona se justifica por
        // haberla ATENDIDO, no por tener una cita agendada con ella. Un
        // turno futuro todavía no pasó y uno cancelado no pasó nunca.
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM   turno t
             JOIN   estado_turno e ON e.id_estado = t.id_estado
             WHERE  t.matricula = :m AND t.id_paciente = :p
               AND  e.descripcion = 'Realizado'"
        );
        $stmt->execute([':m' => $matricula, ':p' => $idPaciente]);
        return (int) $stmt->fetchColumn() > 0;
    }

    // =============================================================
    // EL HISTORIAL (lectura)
    // =============================================================

    /**
     * Línea de tiempo del paciente: consultas y estudios mezclados y
     * ordenados por fecha.
     *
     * Se arma con UNION y no con dos consultas separadas que después PHP
     * junta y ordena, porque el historial se pagina: ordenar en PHP
     * obligaría a traer TODO para poder cortar la página correcta, y un
     * paciente con años de atención haría eso en cada visita.
     *
     * Filtros: tipo ('consulta'|'estudio'), texto libre, desde, hasta.
     */
    public function timeline(int $idPaciente, array $filtros = []): array
    {
        $params = [':p1' => $idPaciente, ':p2' => $idPaciente];

        // Cada rama del UNION expone las MISMAS columnas con los mismos
        // nombres: es lo que permite ordenarlas juntas.
        $consultas = "
            SELECT 'consulta'          AS clase,
                   c.id_consulta       AS id,
                   -- ── LA FECHA ES LA DEL TURNO, NO LA DE ESCRITURA ──
                   -- Antes era `c.creada_en`, y eso ordenaba el historial
                   -- por cuándo el médico se sentó a escribir la ficha. Una
                   -- consulta de hace tres semanas registrada hoy aparecía
                   -- hoy, arriba de todo, descolgada de su turno.
                   --
                   -- El historial clínico es la línea de tiempo de la
                   -- ATENCIÓN: lo que importa es cuándo pasó la consulta.
                   -- Cuándo se escribió sigue disponible aparte, para la
                   -- vista, pero no ordena nada.
                   TIMESTAMP(t.fecha, t.hora_inicio) AS fecha,
                   c.creada_en         AS registrada_en,
                   c.motivo_consulta   AS titulo,
                   e.nombre            AS subtitulo,
                   c.diagnostico       AS detalle,
                   c.indicaciones      AS extra,
                   CONCAT(m.apellido, ', ', m.nombre) AS medico,
                   NULL                AS archivo,
                   NULL                AS estado,
                   t.fecha             AS fecha_turno,
                   c.id_turno          AS id_turno
            FROM   consulta c
            JOIN   turno t        ON t.id_turno        = c.id_turno
            JOIN   medico m       ON m.matricula       = c.matricula
            JOIN   especialidad e ON e.id_especialidad = t.id_especialidad
            WHERE  t.id_paciente = :p1";

        $estudios = "
            SELECT 'estudio'           AS clase,
                   es.id_estudio       AS id,
                   es.solicitado_en    AS fecha,
                   -- El UNION exige las mismas columnas en las dos ramas.
                   NULL                AS registrada_en,
                   es.nombre           AS titulo,
                   es.tipo             AS subtitulo,
                   NULL                AS detalle,
                   NULL                AS extra,
                   CONCAT(m.apellido, ', ', m.nombre) AS medico,
                   es.archivo          AS archivo,
                   es.estado           AS estado,
                   NULL                AS fecha_turno,
                   es.id_turno         AS id_turno
            FROM   estudio es
            JOIN   medico m ON m.matricula = es.matricula
            WHERE  es.id_paciente = :p2";

        // Los filtros se aplican AFUERA del UNION, sobre el resultado
        // combinado: escribirlos dos veces (uno por rama) sería la forma
        // más rápida de que un día filtren distinto.
        $where  = ['1=1'];

        if (!empty($filtros['clase']) && in_array($filtros['clase'], ['consulta', 'estudio'], true)) {
            $where[]           = 'clase = :clase';
            $params[':clase']  = $filtros['clase'];
        }
        if (!empty($filtros['q'])) {
            $where[] = '(titulo LIKE :q1 OR subtitulo LIKE :q2 OR medico LIKE :q3 OR detalle LIKE :q4)';
            // patronLike() escapa los comodines. Sin eso, buscar «100%»
            // devolvía todo lo que empiece con 100 y buscar «_»
            // devolvía absolutamente todo: no es un agujero —el valor
            // va como parámetro— pero es un buscador que miente.
            $like = patronLike((string) $filtros['q']);
            // Cuatro marcadores distintos con el mismo valor: con
            // EMULATE_PREPARES en false no se puede repetir uno.
            $params[':q1'] = $like; $params[':q2'] = $like;
            $params[':q3'] = $like; $params[':q4'] = $like;
        }
        if (!empty($filtros['desde'])) {
            $where[]           = 'DATE(fecha) >= :desde';
            $params[':desde']  = $filtros['desde'];
        }
        if (!empty($filtros['hasta'])) {
            $where[]           = 'DATE(fecha) <= :hasta';
            $params[':hasta']  = $filtros['hasta'];
        }

        // ── POR QUÉ HAY UN TECHO ─────────────────────────────────
        // El historial de una persona con años de atención crónica son
        // cientos de filas, y sin límite se traían TODAS en cada visita
        // para dibujarlas todas en una sola página. Se corta acá y la
        // pantalla avisa que hay más.
        //
        // Interpolado y no como parámetro: con EMULATE_PREPARES en false
        // MySQL recibiría el LIMIT como string y rechazaría la consulta.
        // Es seguro porque es una constante de la clase, no entra nada de
        // afuera.
        $sql = "SELECT * FROM ( {$consultas} UNION ALL {$estudios} ) AS h
                WHERE " . implode(' AND ', $where) . "
                ORDER BY fecha DESC
                LIMIT " . (int) self::MAX_FILAS;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Contadores para la cabecera del historial. */
    public function resumen(int $idPaciente): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
               (SELECT COUNT(*) FROM consulta c JOIN turno t ON t.id_turno = c.id_turno
                 WHERE t.id_paciente = :p1)                                    AS consultas,
               (SELECT COUNT(*) FROM estudio WHERE id_paciente = :p2)          AS estudios,
               (SELECT COUNT(*) FROM estudio WHERE id_paciente = :p3
                  AND estado = 'Disponible')                                   AS resultados,
               (SELECT MAX(t.fecha) FROM turno t
                 WHERE t.id_paciente = :p4 AND t.id_estado =
                       (SELECT id_estado FROM estado_turno WHERE descripcion = 'Realizado'))
                                                                               AS ultima_visita"
        );
        $stmt->execute([':p1' => $idPaciente, ':p2' => $idPaciente,
                        ':p3' => $idPaciente, ':p4' => $idPaciente]);
        return $stmt->fetch() ?: [];
    }

    /** Estudios de un paciente, para la ficha que ve su médico. */
    public function estudiosDePaciente(int $idPaciente): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT e.*, CONCAT(m.apellido, ', ', m.nombre) AS medico
             FROM   estudio e
             JOIN   medico m ON m.matricula = e.matricula
             WHERE  e.id_paciente = :p
             ORDER  BY e.solicitado_en DESC"
        );
        $stmt->execute([':p' => $idPaciente]);
        return $stmt->fetchAll();
    }

    /**
     * ¿Este estudio lo pidió este profesional?
     *
     * Hace falta porque VER el historial de alguien y MODIFICARLO son
     * dos permisos distintos. Un médico que lo atendió puede leer todos
     * sus estudios —eso es atención clínica— pero sólo puede cargar el
     * resultado de los que él mismo pidió.
     *
     * Sin esta distinción, cualquier médico con un turno del paciente
     * podía reemplazar el resultado que había subido otro profesional,
     * y el archivo original se borraba del disco en el mismo paso.
     */
    public function estudioEsDe(int $idEstudio, int $matricula): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM estudio WHERE id_estudio = :e AND matricula = :m"
        );
        $stmt->execute([':e' => $idEstudio, ':m' => $matricula]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** Texto opcional: vacío se guarda como NULL, no como cadena vacía. */
    private function textoONulo(?string $v): ?string
    {
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }
}

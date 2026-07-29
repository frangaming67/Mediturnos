<?php
// sistema/modelos/Turno.php
// -----------------------------------------------------------------
// Es el modelo más grande del proyecto porque "turno" es la entidad
// central del sistema: casi todo lo demás (médico, paciente, horario,
// pago) existe para poder crear y resolver un turno.
// Dos decisiones de diseño importantes están concentradas acá:
//   1) reservar() y cancelar() NO arman el INSERT/UPDATE a mano: llaman
//      a stored procedures (ReservarTurno / CancelarTurno) que viven en
//      la base. Se hizo así porque esas dos operaciones necesitan una
//      validación atómica (¿el slot sigue libre justo en este instante?)
//      que, hecha en dos pasos desde PHP (primero SELECT para chequear,
//      después INSERT), tiene una ventana de carrera: dos usuarios
//      podrían pasar el chequeo al mismo tiempo y reservar el mismo
//      horario. El SP + el índice único uq_turno_slot cierran esa
//      ventana a nivel de motor de base de datos, no de código PHP.
//   2) listar() consulta la vista SQL v_turnos_detalle en vez de armar
//      los mismos 4-5 JOIN acá con PHP. La vista concentra ese JOIN una
//      sola vez en la base; si mañana se agrega una columna al listado,
//      se toca la vista y no hay que tocar el modelo.
// -----------------------------------------------------------------

class Turno
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // =============================================================
    // AGENDA DEL MÉDICO
    // =============================================================
    // Consultas de la pantalla propia del rol `medico`. Están acotadas
    // SIEMPRE por matrícula: un médico solo puede ver su propia agenda
    // y sus propios pacientes. El filtro va en el SQL (no en PHP) para
    // que sea imposible saltearlo desde la vista.

    /**
     * Turnos de un médico en una fecha, con los datos del paciente ya
     * resueltos (nombre, DNI, edad) y el estado del pago.
     */
    public function agendaMedico(int $matricula, string $fecha): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT t.id_turno, t.hora_inicio, t.observacion,
                    et.descripcion                       AS estado,
                    t.id_paciente,
                    CONCAT(p.apellido, ', ', p.nombre)   AS paciente,
                    p.dni                                AS paciente_dni,
                    TIMESTAMPDIFF(YEAR, p.fecha_nac, CURDATE()) AS edad,
                    e.nombre                             AS especialidad,
                    CONCAT('Cons. ', c.numero, ' - Piso ', c.piso) AS consultorio,
                    pg.estado                            AS estado_pago,
                    pg.monto_total
             FROM   turno t
             JOIN   estado_turno et ON et.id_estado      = t.id_estado
             JOIN   paciente     p  ON p.id_paciente     = t.id_paciente
             JOIN   especialidad e  ON e.id_especialidad = t.id_especialidad
             JOIN   consultorio  c  ON c.id_consultorio  = t.id_consultorio
             LEFT   JOIN pago    pg ON pg.id_turno       = t.id_turno
             WHERE  t.matricula = :m AND t.fecha = :f
             ORDER  BY t.hora_inicio"
        );
        $stmt->execute([':m' => $matricula, ':f' => $fecha]);
        return $stmt->fetchAll();
    }

    /**
     * Contadores del día para el encabezado de la agenda.
     *
     * Se apoyan en el catálogo real de estado_turno (no se inventan
     * estados nuevos):
     *   - pendientes = Reservado  (todavía sin confirmar/pagar)
     *   - en_espera  = Confirmado (ya pagó, esperando ser atendido)
     *   - atendidos  = Realizado
     *   - ausentes   = Ausente
     */
    public function kpisMedico(int $matricula, string $fecha): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                SUM(et.descripcion = 'Reservado')  AS pendientes,
                SUM(et.descripcion = 'Confirmado') AS en_espera,
                SUM(et.descripcion = 'Realizado')  AS atendidos,
                SUM(et.descripcion = 'Ausente')    AS ausentes,
                SUM(et.descripcion <> 'Cancelado') AS total_activos
             FROM   turno t
             JOIN   estado_turno et ON et.id_estado = t.id_estado
             WHERE  t.matricula = :m AND t.fecha = :f"
        );
        $stmt->execute([':m' => $matricula, ':f' => $fecha]);
        $r = $stmt->fetch() ?: [];

        // SUM() devuelve NULL si no hay filas: se normaliza a 0 para que
        // la vista no tenga que preocuparse por nulls.
        foreach (['pendientes', 'en_espera', 'atendidos', 'ausentes', 'total_activos'] as $k) {
            $r[$k] = (int) ($r[$k] ?? 0);
        }
        return $r;
    }

    /**
     * Cantidad de turnos por día de la semana actual (lunes a domingo),
     * para el gráfico de barras "Citas de la semana".
     * Devuelve siempre 7 posiciones, aunque algún día no tenga turnos.
     */
    public function citasSemanaMedico(int $matricula): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT DAYOFWEEK(t.fecha) AS dow, COUNT(*) AS cantidad
             FROM   turno t
             JOIN   estado_turno et ON et.id_estado = t.id_estado
             WHERE  t.matricula = :m
               AND  et.descripcion <> 'Cancelado'
               AND  YEARWEEK(t.fecha, 1) = YEARWEEK(CURDATE(), 1)
             GROUP  BY DAYOFWEEK(t.fecha)"
        );
        $stmt->execute([':m' => $matricula]);

        // DAYOFWEEK: 1=Domingo … 7=Sábado. Se reordena a Lun-Dom.
        $mapa = [];
        foreach ($stmt->fetchAll() as $f) {
            $mapa[(int) $f['dow']] = (int) $f['cantidad'];
        }
        $orden  = [2 => 'Lun', 3 => 'Mar', 4 => 'Mié', 5 => 'Jue', 6 => 'Vie', 7 => 'Sáb', 1 => 'Dom'];
        $semana = [];
        foreach ($orden as $dow => $etiqueta) {
            $semana[] = ['dia' => $etiqueta, 'cantidad' => $mapa[$dow] ?? 0, 'dow' => $dow];
        }
        return $semana;
    }

    /**
     * Pacientes que alguna vez tuvieron turno con este médico, con la
     * fecha de su última consulta. Filtro opcional por nombre o DNI.
     */
    public function pacientesDelMedico(int $matricula, string $busqueda = ''): array
    {
        $where  = 't.matricula = :m';
        $params = [':m' => $matricula];

        if ($busqueda !== '') {
            $where .= ' AND (p.nombre LIKE :q1 OR p.apellido LIKE :q2 OR p.dni LIKE :q3)';
            $like = '%' . $busqueda . '%';
            $params[':q1'] = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
        }

        $stmt = $this->pdo->prepare(
            "SELECT p.id_paciente, p.dni, p.nombre, p.apellido, p.telefono, p.email,
                    TIMESTAMPDIFF(YEAR, p.fecha_nac, CURDATE()) AS edad,
                    COUNT(t.id_turno) AS consultas,
                    MAX(t.fecha)      AS ultima_consulta
             FROM   turno t
             JOIN   paciente p ON p.id_paciente = t.id_paciente
             WHERE  {$where}
             GROUP  BY p.id_paciente, p.dni, p.nombre, p.apellido, p.telefono, p.email, p.fecha_nac
             ORDER  BY ultima_consulta DESC
             LIMIT  60"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Lista turnos con JOINs completos.
     * Filtros: fecha, estado, matricula, id_paciente, id_especialidad.
     */
    public function listar(array $filtros = []): array
    {
        $where  = ["1=1"];
        $params = [];

        // Los filtros operan sobre las columnas de la vista v_turnos_detalle
        // (que ya expone fecha, estado, matricula, etc. resueltas).
        if (!empty($filtros['fecha'])) {
            $where[]        = "fecha = :fecha";
            $params[':fecha'] = $filtros['fecha'];
        }
        if (!empty($filtros['estado'])) {
            $where[]          = "LOWER(estado) = LOWER(:estado)";
            $params[':estado'] = $filtros['estado'];
        }
        if (!empty($filtros['matricula'])) {
            $where[]             = "matricula = :matricula";
            $params[':matricula'] = $filtros['matricula'];
        }
        if (!empty($filtros['id_paciente'])) {
            $where[]               = "id_paciente = :id_paciente";
            $params[':id_paciente'] = $filtros['id_paciente'];
        }
        if (!empty($filtros['id_especialidad'])) {
            $where[]                  = "id_especialidad = :id_especialidad";
            $params[':id_especialidad'] = $filtros['id_especialidad'];
        }
        if (!empty($filtros['dni_paciente'])) {
            $where[]               = "paciente_dni LIKE :dni";
            $params[':dni']         = '%' . $filtros['dni_paciente'] . '%';
        }

        // El JOIN completo vive ahora en la vista SQL v_turnos_detalle.
        // El modelo sólo le agrega los filtros y el orden.
        $sql = "SELECT * FROM v_turnos_detalle WHERE " . implode(' AND ', $where) . " ORDER BY fecha DESC, hora_inicio DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Busca un turno por ID con todos sus datos.
     */
    public function buscarPorId(int $id): array|false
    {
        $stmt = $this->pdo->prepare(
            "SELECT t.*, CONCAT(p.apellido,', ',p.nombre) AS paciente,
                    CONCAT(m.apellido,', ',m.nombre) AS medico
             FROM turno t
             JOIN paciente p ON p.id_paciente = t.id_paciente
             JOIN medico   m ON m.matricula   = t.matricula
             WHERE t.id_turno = :id"
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    /**
     * Reserva un turno llamando al stored procedure ReservarTurno.
     *
     * El SP encapsula la lógica de negocio: valida que el médico no tenga
     * ya un turno activo en ese horario (SIGNAL SQLSTATE '45000' si lo hay)
     * y, si está libre, inserta el turno y devuelve el nuevo id por el
     * parámetro OUT. El INSERT dispara el trigger de historial.
     *
     * Control de concurrencia (ACID): el chequeo del SP da un mensaje
     * temprano, pero la garantía REAL contra la doble reserva es el índice
     * único uq_turno_slot. Si dos pacientes reservan el mismo slot a la vez,
     * el INSERT del segundo es rechazado con el error 1062 (SQLSTATE 23000).
     *
     * Retorna el nuevo id_turno. Lanza RuntimeException si el slot ya fue
     * tomado por otra persona.
     */
public function reservar(array $datos): int
{
    try {
        // El OUT del SP se guarda en la variable de sesión @id_turno y se
        // recupera con un SELECT posterior (forma portable con PDO nativo).
        $stmt = $this->pdo->prepare(
            "CALL ReservarTurno(
                :fecha, :hora, :id_paciente, :matricula,
                :id_especialidad, :id_consultorio, :id_plan,
                @id_turno
            )"
        );
        $stmt->execute([
            ':fecha' => $datos['fecha'],
            ':hora' => $datos['hora_inicio'],
            ':id_paciente' => $datos['id_paciente'],
            ':matricula' => $datos['matricula'],
            ':id_especialidad' => $datos['id_especialidad'],
            ':id_consultorio' => $datos['id_consultorio'],
            ':id_plan' => $datos['id_plan'],
        ]);
        $stmt->closeCursor();

        $idTurno = (int)$this->pdo->query('SELECT @id_turno')->fetchColumn();

        if (!empty($datos['nro_afiliado'])) {
            $up = $this->pdo->prepare(
                "INSERT INTO paciente_plan (id_paciente, id_plan, nro_afiliado, fecha_alta)
                 VALUES (:p, :pl, :nro, CURDATE())
                 ON DUPLICATE KEY UPDATE nro_afiliado = VALUES(nro_afiliado)"
            );
            $up->execute([
                ':p'   => $datos['id_paciente'],
                ':pl'  => $datos['id_plan'],
                ':nro' => $datos['nro_afiliado'],
            ]);
        }

        return $idTurno;

    } catch (PDOException $e) {
        // SQLSTATE 45000 = el SP detectó el conflicto y avisó con un mensaje
        // claro (errorInfo[2] lo trae limpio, sin el prefijo de PDO).
        if ($e->getCode() === '45000') {
            throw new RuntimeException(
                $e->errorInfo[2] ?? 'El médico ya tiene un turno asignado en ese horario.'
            );
        }

        // SQLSTATE 23000 = violación de integridad; en este INSERT sólo
        // puede provenir del índice único del slot (error MySQL 1062),
        // es decir una reserva simultánea que ganó la carrera.
        if ($e->getCode() === '23000') {
            throw new RuntimeException(
                'Ese turno acaba de ser reservado por otra persona. '
                . 'Por favor, elegí otro horario.'
            );
        }

        throw $e; // cualquier otro error de base se propaga tal cual
    }
}

    /**
     * Cancela un turno llamando al stored procedure CancelarTurno.
     *
     * El SP aplica la regla de negocio: rechaza cancelar un turno
     * inexistente o ya finalizado (Cancelado/Realizado) con SIGNAL
     * SQLSTATE '45000'. El UPDATE que hace el SP dispara el trigger
     * trg_turno_after_update, que registra el cambio en historial_turno;
     * por eso acá NO se inserta el historial a mano (quedaría duplicado).
     *
     * Lanza RuntimeException con el motivo si el SP rechaza la cancelación.
     */
    public function cancelar(int $idTurno, string $observacion = ''): void
    {
        try {
            $stmt = $this->pdo->prepare("CALL CancelarTurno(:id, :obs)");
            $stmt->execute([':id' => $idTurno, ':obs' => $observacion]);
            $stmt->closeCursor();
        } catch (PDOException $e) {
            // errorInfo[2] trae el MESSAGE_TEXT limpio del SIGNAL del SP.
            if ($e->getCode() === '45000') {
                throw new RuntimeException($e->errorInfo[2] ?? 'No se pudo cancelar el turno.');
            }
            throw $e;
        }
    }


    // =============================================================
    // ÁREA DEL PACIENTE
    // =============================================================

    /**
     * Los próximos turnos de un paciente, del más cercano al más lejano.
     *
     * "Próximo" es lo que todavía no ocurrió Y sigue vigente: se excluyen
     * los cancelados y los ya realizados. Sin ese filtro, el panel podría
     * anunciarle al paciente como "tu próxima cita" un turno que él mismo
     * canceló.
     *
     * La comparación combina fecha y hora en un solo TIMESTAMP: comparar
     * sólo por fecha dejaría entrar los turnos de HOY que ya pasaron.
     */
    public function proximosDePaciente(int $idPaciente, int $limite = 5): array
    {
        $limite = max(1, min(20, $limite));   // acotado: va interpolado

        $stmt = $this->pdo->prepare(
            "SELECT * FROM v_turnos_detalle
             WHERE id_paciente = :p
               AND estado NOT IN ('Cancelado', 'Realizado', 'Ausente')
               AND TIMESTAMP(fecha, hora_inicio) >= NOW()
             ORDER BY fecha ASC, hora_inicio ASC
             LIMIT {$limite}"
        );
        $stmt->execute([':p' => $idPaciente]);
        return $stmt->fetchAll();
    }

    /**
     * Qué días puede atenderse un paciente con este médico en esta
     * especialidad, para pintar el calendario del paso 3.
     *
     * ── POR QUÉ NO SE LLAMA A obtenerSlots() DÍA POR DÍA ─────
     * Sería lo obvio y es lo que hay que evitar: para un mes son 30
     * llamadas, cada una con sus propias consultas. Acá se resuelve con
     * TRES consultas para todo el rango:
     *
     *   1. Los horarios del médico → cuántos turnos entran en cada día
     *      de la semana (se calcula una vez, no por fecha).
     *   2. Las ausencias del período.
     *   3. Cuántos turnos activos ya tiene por fecha.
     *
     * y después se comparan los tres en memoria.
     *
     * El único día que se calcula exacto es HOY, con obtenerSlots(): las
     * horas que ya pasaron reducen la disponibilidad y el conteo por día
     * de la semana no las contempla. Un día que figure libre y al entrar
     * no tenga horarios es de las cosas que más desconfianza generan.
     *
     * Devuelve, por fecha: si se puede reservar y por qué no, cuando no.
     */
    public function diasDisponibles(int $matricula, int $idEspecialidad,
                                    string $desde, int $cantidadDias = 28): array
    {
        // 1) Cuántos turnos entran por día de la semana
        $stmt = $this->pdo->prepare(
            "SELECT h.dia_semana, h.hora_inicio, h.hora_fin, e.duracion_turno_min
             FROM   horario_atencion h
             JOIN   especialidad e ON e.id_especialidad = h.id_especialidad
             WHERE  h.matricula = :m AND h.id_especialidad = :esp"
        );
        $stmt->execute([':m' => $matricula, ':esp' => $idEspecialidad]);

        $cupoPorDia = [];
        foreach ($stmt->fetchAll() as $h) {
            $duracion = max(5, (int) ($h['duracion_turno_min'] ?? 20));
            $minutos  = (strtotime($h['hora_fin']) - strtotime($h['hora_inicio'])) / 60;
            $cupoPorDia[$h['dia_semana']] = ($cupoPorDia[$h['dia_semana']] ?? 0)
                                          + (int) floor($minutos / $duracion);
        }

        $hasta = date('Y-m-d', strtotime($desde . ' +' . ($cantidadDias - 1) . ' days'));

        // 2) Ausencias del período
        $ausentes = [];
        try {
            $st = $this->pdo->prepare(
                "SELECT fecha FROM ausencia_medico
                 WHERE matricula = :m AND fecha BETWEEN :d1 AND :d2"
            );
            $st->execute([':m' => $matricula, ':d1' => $desde, ':d2' => $hasta]);
            $ausentes = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException $e) {
            // Sin la migración de ausencias el calendario sigue andando.
            error_log('Turno diasDisponibles ausencias: ' . $e->getMessage());
        }

        // 3) Turnos activos ya tomados, por fecha
        $st = $this->pdo->prepare(
            "SELECT fecha, COUNT(*) AS tomados
             FROM   turno
             WHERE  matricula = :m AND fecha BETWEEN :d1 AND :d2
               AND  id_estado <> (SELECT id_estado FROM estado_turno WHERE descripcion = 'Cancelado')
             GROUP  BY fecha"
        );
        $st->execute([':m' => $matricula, ':d1' => $desde, ':d2' => $hasta]);
        $tomados = [];
        foreach ($st->fetchAll() as $r) {
            $tomados[$r['fecha']] = (int) $r['tomados'];
        }

        $nombreDia = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miercoles',
                      4 => 'Jueves', 5 => 'Viernes', 6 => 'Sabado', 7 => 'Domingo'];
        $hoy  = date('Y-m-d');
        $dias = [];

        for ($i = 0; $i < $cantidadDias; $i++) {
            $fecha = date('Y-m-d', strtotime($desde . ' +' . $i . ' days'));
            $cupo  = $cupoPorDia[$nombreDia[(int) date('N', strtotime($fecha))]] ?? 0;
            $libres = max(0, $cupo - ($tomados[$fecha] ?? 0));

            $motivo = null;
            if ($fecha < $hoy)                  $motivo = 'pasado';
            elseif ($cupo === 0)                $motivo = 'sin_agenda';
            elseif (isset($ausentes[$fecha]))   $motivo = 'ausente';
            elseif ($libres === 0)              $motivo = 'completo';

            // Hoy se calcula exacto: las horas que ya pasaron no cuentan.
            if ($motivo === null && $fecha === $hoy) {
                $libres = count($this->obtenerSlots($matricula, $fecha));
                if ($libres === 0) $motivo = 'completo';
            }

            $dias[] = [
                'fecha'      => $fecha,
                'disponible' => $motivo === null,
                'motivo'     => $motivo,
                'libres'     => $motivo === null ? $libres : 0,
            ];
        }

        return $dias;
    }

    /**
     * Especialidades que se pueden reservar HOY, con su precio y cuántos
     * profesionales las atienden.
     *
     * Sólo aparecen las que tienen al menos un médico activo CON agenda
     * cargada: ofrecer una especialidad que después no muestra ningún
     * profesional es hacerle perder un clic a la persona para llegar a
     * una pantalla vacía.
     */
    public function especialidadesParaReserva(): array
    {
        return $this->pdo->query(
            "SELECT e.id_especialidad, e.nombre, e.precio_consulta, e.duracion_turno_min,
                    COUNT(DISTINCT m.matricula) AS medicos
             FROM   especialidad e
             JOIN   horario_atencion h ON h.id_especialidad = e.id_especialidad
             JOIN   medico m ON m.matricula = h.matricula AND m.estado = 'activo'
             GROUP  BY e.id_especialidad, e.nombre, e.precio_consulta, e.duracion_turno_min
             ORDER  BY e.nombre"
        )->fetchAll();
    }

    /** Nombre, duración y precio de una especialidad. */
    public function datosEspecialidad(int $idEspecialidad): array|false
    {
        $stmt = $this->pdo->prepare(
            "SELECT id_especialidad, nombre, duracion_turno_min, precio_consulta
             FROM especialidad WHERE id_especialidad = :id"
        );
        $stmt->execute([':id' => $idEspecialidad]);
        return $stmt->fetch();
    }

    /**
     * Un turno con TODO resuelto (médico, especialidad, consultorio, obra
     * social, pago), vía la vista v_turnos_detalle.
     *
     * Es distinto de buscarPorId(), que devuelve la fila cruda de `turno`
     * con dos nombres pegados: ese sirve para operar (cancelar, mover),
     * este para MOSTRAR. Reusar el primero en la pantalla de detalle
     * obligaría a la vista a hacer cinco consultas más por su cuenta.
     */
    public function detalleDeTurno(int $idTurno): array|false
    {
        $stmt = $this->pdo->prepare("SELECT * FROM v_turnos_detalle WHERE id_turno = :id");
        $stmt->execute([':id' => $idTurno]);
        return $stmt->fetch();
    }

    /**
     * Contadores del panel del paciente, en UNA sola consulta.
     *
     * Se resuelve con SUM(CASE...) en vez de cinco COUNT(*) separados
     * porque son cinco números sobre las mismas filas: una consulta que
     * recorre la tabla una vez es preferible a cinco que la recorren
     * cinco veces para responder lo mismo.
     */
    public function resumenPaciente(int $idPaciente): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN estado NOT IN ('Cancelado','Realizado','Ausente')
                          AND TIMESTAMP(fecha, hora_inicio) >= NOW()
                     THEN 1 ELSE 0 END)                        AS proximos,
                SUM(CASE WHEN estado = 'Realizado' THEN 1 ELSE 0 END)  AS realizados,
                SUM(CASE WHEN estado = 'Cancelado' THEN 1 ELSE 0 END)  AS cancelados,
                SUM(CASE WHEN estado_pago = 'Pendiente' THEN 1 ELSE 0 END) AS pagos_pendientes
             FROM v_turnos_detalle
             WHERE id_paciente = :p"
        );
        $stmt->execute([':p' => $idPaciente]);
        $r = $stmt->fetch() ?: [];

        // COUNT sobre cero filas devuelve NULL en las sumas: se normaliza
        // para que la vista no tenga que preguntar por null en cada dato.
        foreach (['total','proximos','realizados','cancelados','pagos_pendientes'] as $k) {
            $r[$k] = (int) ($r[$k] ?? 0);
        }
        return $r;
    }

    /**
     * Coberturas con las que ESTE paciente puede sacar un turno.
     *
     * ── POR QUÉ EXISTE ESTE MÉTODO ───────────────────────────
     * Antes, el formulario de reserva ofrecía los QUINCE planes del
     * sistema y nadie verificaba que el elegido fuera del paciente. El
     * descuento sale de `descuento_os_medico`, así que cualquiera podía
     * elegir la obra social con mejor convenio y pagar menos.
     *
     * Con los datos reales el agujero era total: IOMA tiene 100% de
     * descuento con la Dra. González. Al quedar el monto en cero,
     * Pago::crearParaTurno() da el pago por saldado automáticamente y el
     * turno se confirma solo. Es decir: turno gratis, sin tocar una línea
     * de HTML, sólo eligiendo del desplegable que el sistema ofrecía.
     *
     * Ahora se ofrece únicamente lo que la persona tiene cargado, más la
     * opción de pagar de su bolsillo.
     *
     * El plan particular se identifica por el nombre de la obra social
     * ('Particular'): es la convención con la que están cargados los
     * datos. Si esa obra social no existiera, un paciente sin cobertura
     * no puede reservar — y el formulario se lo dice, en vez de dejarlo
     * elegir la de otro.
     */
    public function planesDePaciente(int $idPaciente): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT pl.id_plan,
                    CONCAT(os.nombre, ' - ', pl.nombre_plan) AS nombre,
                    pl.porcentaje_cobertura,
                    os.nombre AS obra_social,
                    CASE WHEN pp.id_paciente IS NULL THEN 0 ELSE 1 END AS es_propio
             FROM   plan_os pl
             JOIN   obra_social os ON os.id_obra_social = pl.id_obra_social
             LEFT   JOIN paciente_plan pp
                    ON pp.id_plan = pl.id_plan AND pp.id_paciente = :p
             WHERE  pp.id_paciente IS NOT NULL
                OR  LOWER(os.nombre) = 'particular'
             ORDER  BY es_propio DESC, os.nombre, pl.nombre_plan"
        );
        $stmt->execute([':p' => $idPaciente]);
        return $stmt->fetchAll();
    }

    /**
     * ¿Este paciente puede usar este plan?
     *
     * Se comprueba en el SERVIDOR al reservar, no sólo al dibujar el
     * desplegable: el `<select>` se edita desde las herramientas del
     * navegador y el POST se arma a mano.
     */
    public function planPermitido(int $idPaciente, int $idPlan): bool
    {
        foreach ($this->planesDePaciente($idPaciente) as $pl) {
            if ((int) $pl['id_plan'] === $idPlan) {
                return true;
            }
        }
        return false;
    }

    /** Horas mínimas de antelación para poder mover o cancelar un turno. */
    public const HORAS_ANTELACION = 2;

    /**
     * ¿Se puede reprogramar este turno? Devuelve el MOTIVO por el que no,
     * o null si sí se puede.
     *
     * Devuelve el motivo en vez de un booleano a propósito: la pantalla
     * necesita explicarle a la persona por qué el botón no está, y un
     * `false` pelado obligaría a repetir estas mismas condiciones en la
     * vista para adivinar cuál se incumplió.
     */
    public function motivoNoReprogramable(array $turno): ?string
    {
        $estado = $turno['estado'] ?? '';

        if (in_array($estado, ['Cancelado', 'Realizado', 'Ausente'], true)) {
            return 'Este turno ya está ' . mb_strtolower($estado) . '.';
        }

        $inicio = strtotime($turno['fecha'] . ' ' . $turno['hora_inicio']);
        if ($inicio <= time()) {
            return 'Este turno ya pasó.';
        }
        if ($inicio - time() < self::HORAS_ANTELACION * 3600) {
            return 'Faltan menos de ' . self::HORAS_ANTELACION . ' horas: '
                 . 'para cambiarlo tenés que llamar a la clínica.';
        }
        return null;
    }

    /**
     * Mueve un turno a otro día u horario.
     *
     * NO se cancela y se vuelve a reservar: eso perdería el pago ya
     * hecho, generaría un turno nuevo con otro número y dejaría al
     * paciente sin su comprobante. Se actualiza el mismo turno.
     *
     * La garantía contra la doble reserva es la MISMA de siempre y no
     * hace falta programarla: `slot_unico` y `slot_consultorio` son
     * columnas generadas a partir de fecha y hora, así que al moverlas se
     * recalculan y el índice único rechaza el UPDATE si ese horario ya
     * está tomado. El motor protege el reprograma igual que la reserva.
     */
    public function reprogramar(int $idTurno, string $fecha, string $hora,
                                int $idConsultorio, string $observacion = ''): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE turno
                 SET fecha = :fecha, hora_inicio = :hora,
                     id_consultorio = :cons, observacion = :obs
                 WHERE id_turno = :id"
            );
            $stmt->execute([
                ':fecha' => $fecha,
                ':hora'  => $hora,
                ':cons'  => $idConsultorio,
                ':obs'   => $observacion !== '' ? $observacion : null,
                ':id'    => $idTurno,
            ]);
        } catch (PDOException $e) {
            // 23000/1062 = índice único: alguien tomó ese horario primero.
            if ($e->getCode() === '23000') {
                throw new RuntimeException(
                    'Ese horario acaba de ser tomado por otra persona. Elegí otro.'
                );
            }
            throw $e;
        }
    }

    /**
     * Actualiza el estado de un turno directamente (sin SP, para
     * estados intermedios como Confirmado / Realizado / Ausente).
     */
    public function actualizarEstado(int $idTurno, string $estado, string $observacion = ''): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE turno
             SET    id_estado = (SELECT id_estado FROM estado_turno WHERE descripcion = :estado),
                    observacion = :obs
             WHERE  id_turno = :id"
        );
        return $stmt->execute([':estado' => $estado, ':obs' => $observacion, ':id' => $idTurno]);
    }

    public function marcarRealizadosAutomaticamente(): int
    {
        // Cualquier turno ACTIVO (Reservado o Confirmado) cuya hora ya pasó se
        // da por Realizado. Los Reservado impagos ya fueron cancelados por
        // Pago::expirarVencidos() (el plazo vence siempre <= inicio del turno),
        // así que en la práctica los que sobreviven hasta acá están pagados.
        $sql = "
            UPDATE turno
            SET id_estado = (SELECT id_estado FROM estado_turno WHERE descripcion = 'Realizado'),
                observacion = 'Realizado automáticamente por paso del horario'
            WHERE (fecha < CURDATE()
                   OR (fecha = CURDATE() AND hora_inicio <= CURTIME()))
              AND id_estado IN (
                  SELECT id_estado FROM estado_turno
                  WHERE descripcion IN ('Reservado', 'Confirmado')
              )
        ";

        return $this->pdo->exec($sql);
    }
    /**
     * Historial de cambios de un turno.
     */
    public function obtenerHistorial(int $idTurno): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM historial_turno WHERE id_turno = :id ORDER BY fecha_cambio ASC"
        );
        $stmt->execute([':id' => $idTurno]);
        return $stmt->fetchAll();
    }

    // ── Auxiliares para los selects del formulario ───────────

    public function listarPacientes(): array
    {
        return $this->pdo->query(
            "SELECT id_paciente, CONCAT(apellido,', ',nombre) AS nombre_completo, dni
             FROM paciente ORDER BY apellido, nombre"
        )->fetchAll();
    }

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

    public function listarPlanes(): array
    {
        return $this->pdo->query(
            "SELECT pl.id_plan, CONCAT(os.nombre,' - ',pl.nombre_plan) AS nombre_completo,
                    pl.porcentaje_cobertura
             FROM plan_os pl JOIN obra_social os ON os.id_obra_social = pl.id_obra_social
             ORDER BY os.nombre, pl.nombre_plan"
        )->fetchAll();
    }

    public function listarConsultorios(): array
    {
        return $this->pdo->query(
            "SELECT id_consultorio, CONCAT('Consultorio ',numero,' - Piso ',piso) AS nombre
             FROM consultorio ORDER BY piso, numero"
        )->fetchAll();
    }

    /**
     * Devuelve los slots libres de un médico en una fecha dada.
     * Cada slot trae hora, id_consultorio, id_especialidad, nombres descriptivos.
     */
    public function obtenerSlots(int $matricula, string $fecha): array
    {
        // Si el médico está marcado como ausente ese día, NO hay slots:
        // ni el paciente ni la recepción pueden reservar para esa fecha.
        if ($this->hayAusencia($matricula, $fecha)) {
            return [];
        }

        $num  = (int)date('N', strtotime($fecha));
        $mapa = [1=>'Lunes',2=>'Martes',3=>'Miercoles',
                 4=>'Jueves',5=>'Viernes',6=>'Sabado',7=>'Domingo'];
        $dia  = $mapa[$num];

        $stmt = $this->pdo->prepare(
            "SELECT h.hora_inicio, h.hora_fin,
                    h.id_consultorio, h.id_especialidad,
                    e.nombre AS especialidad,
                    e.duracion_turno_min,
                    CONCAT('Cons. ',c.numero,' - Piso ',c.piso) AS consultorio
             FROM   horario_atencion h
             JOIN   especialidad e ON e.id_especialidad = h.id_especialidad
             JOIN   consultorio  c ON c.id_consultorio  = h.id_consultorio
             WHERE  h.matricula = :m AND h.dia_semana = :dia"
        );
        $stmt->execute([':m' => $matricula, ':dia' => $dia]);
        $horarios = $stmt->fetchAll();

        // Qué está ocupado ese día, en UNA sola consulta.
        //
        // Antes se preguntaba a la base slot por slot: una jornada de 8 a 16
        // con turnos de 20 minutos son 24 consultas para dibujar una grilla,
        // y el calendario del paciente la pide cada vez que toca un día. Un
        // día completo de turnos son pocas decenas de filas: traerlas juntas
        // y comparar en PHP da el mismo resultado con una sola ida a la base.
        $st = $this->pdo->prepare(
            "SELECT hora_inicio, matricula, id_consultorio
             FROM   turno
             WHERE  fecha = :f
               AND  id_estado <> (SELECT id_estado FROM estado_turno WHERE descripcion = 'Cancelado')"
        );
        $st->execute([':f' => $fecha]);

        // Dos índices en memoria: por médico y por consultorio. Un slot está
        // tomado si cae en cualquiera de los dos —un profesional no puede
        // atender a dos personas a la vez, y un consultorio tampoco—.
        $ocupadoMedico = [];
        $ocupadoCons   = [];
        foreach ($st->fetchAll() as $o) {
            $ocupadoMedico[$o['matricula'] . '|' . $o['hora_inicio']]      = true;
            $ocupadoCons[$o['id_consultorio'] . '|' . $o['hora_inicio']]   = true;
        }

        $ahora = time();
        $slots = [];
        foreach ($horarios as $h) {
            $duracion = (int)($h['duracion_turno_min'] ?? 20);
            $ini = strtotime($h['hora_inicio']);
            $fin = strtotime($h['hora_fin']);

            for ($t = $ini; $t < $fin; $t += $duracion * 60) {
                $hora = date('H:i:s', $t);

                // No ofrecer horarios que ya pasaron (turno de hoy a una hora
                // anterior a la actual). Sin esto, el pago nacería con el plazo
                // vencido -su tope es el inicio del turno- y Pago::expirarVencidos()
                // cancelaría el turno automáticamente apenas se crea.
                if (strtotime($fecha . ' ' . $hora) <= $ahora) {
                    continue;
                }

                if (isset($ocupadoMedico[$matricula . '|' . $hora])
                    || isset($ocupadoCons[$h['id_consultorio'] . '|' . $hora])) {
                    continue;
                }

                $slots[] = [
                    'hora' => substr($hora, 0, 5),
                    'hora_full' => $hora,
                    'id_consultorio'  => $h['id_consultorio'],
                    'id_especialidad' => $h['id_especialidad'],
                    'especialidad' => $h['especialidad'],
                    'consultorio' => $h['consultorio'],
                ];
            }
        }
        return $slots;
    }

    /**
     * ¿El médico está marcado como AUSENTE ese día?
     *
     * Es la "fuente de verdad" del bloqueo: la usan tanto obtenerSlots()
     * (formulario de nuevo turno, paso 2) como la acción AJAX 'slots' del
     * controlador (calendario del paciente). Si devuelve true, ese día no
     * se ofrece ningún horario.
     *
     * Se consulta en un try/catch tolerante: si todavía no se corrió la
     * migración ausencias_medico.sql (la tabla no existe), se asume que no
     * hay ausencias y el sistema sigue funcionando como antes.
     */
    public function hayAusencia(int $matricula, string $fecha): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM ausencia_medico
                 WHERE matricula = :m AND fecha = :f"
            );
            $stmt->execute([':m' => $matricula, ':f' => $fecha]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            error_log('Turno hayAusencia (¿falta correr ausencias_medico.sql?): ' . $e->getMessage());
            return false;
        }
    }

    /**
     * KPIs para el dashboard.
     */
    public function kpis(): array
    {
        // Se usa CURDATE() de MySQL (no date() de PHP) para que "hoy" sea
        // siempre la fecha del servidor de base. Si PHP y MySQL están en
        // zonas horarias distintas, date('Y-m-d') puede caer en otro día y
        // los KPIs de turnos darían 0 aunque haya turnos cargados hoy.
        $row = $this->pdo->query(
            "SELECT
                COALESCE((SELECT COUNT(*) FROM turno t JOIN estado_turno e ON e.id_estado = t.id_estado WHERE DATE(t.fecha) = CURDATE() AND e.descripcion = 'Reservado'), 0) AS reservados_hoy,
                COALESCE((SELECT COUNT(*) FROM turno t JOIN estado_turno e ON e.id_estado = t.id_estado WHERE DATE(t.fecha) = CURDATE() AND e.descripcion = 'Realizado'), 0) AS realizados_hoy,
                COALESCE((SELECT COUNT(*) FROM turno t JOIN estado_turno e ON e.id_estado = t.id_estado WHERE DATE(t.fecha) = CURDATE() AND e.descripcion = 'Cancelado'), 0) AS cancelados_hoy,
                COALESCE((SELECT COUNT(*) FROM turno WHERE DATE(fecha) = CURDATE()), 0) AS total_hoy,
                COALESCE((SELECT COUNT(*) FROM paciente), 0) AS total_pacientes,
                COALESCE((SELECT COUNT(*) FROM medico WHERE estado = 'activo'), 0) AS total_medicos"
        )->fetch();
        return $row ?: [];
    }

    /**
     * Agenda del día: los turnos de hoy ya resueltos (paciente, médico,
     * especialidad, consultorio), vía la vista SQL v_agenda_hoy.
     * Si se pasa $matricula, la agenda se restringe a ese médico (lo
     * usa el dashboard cuando el que mira es un médico, no un gestor).
     * Tolerante: si la vista todavía no se creó (falta correr vistas.sql),
     * devuelve [] sin romper el dashboard.
     */
    public function agendaHoy(?int $matricula = null): array
    {
        try {
            if ($matricula) {
                $stmt = $this->pdo->prepare("SELECT * FROM v_agenda_hoy WHERE matricula = :m");
                $stmt->execute([':m' => $matricula]);
                return $stmt->fetchAll();
            }
            return $this->pdo->query("SELECT * FROM v_agenda_hoy")->fetchAll();
        } catch (PDOException $e) {
            error_log('Turno agendaHoy (¿falta correr vistas.sql?): ' . $e->getMessage());
            return [];
        }
    }
}

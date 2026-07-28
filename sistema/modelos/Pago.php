<?php
// sistema/modelos/Pago.php
// Pagos de turnos + cálculo de monto con descuento por obra social/médico.
// -----------------------------------------------------------------
// Es un modelo separado de Turno.php (aunque un pago no existe sin un
// turno) porque tiene su PROPIO ciclo de vida y sus propias reglas:
// vencimiento a las HORAS_PLAZO, cálculo de descuento, validación de
// tarjeta. Meter todo eso dentro de Turno.php mezclaría dos
// responsabilidades distintas (agendar vs. cobrar) en una clase gigante.
// pagarConTarjeta() es una PASARELA SIMULADA (Luhn + reglas de formato,
// sin llamar a ningún banco real) a propósito: el objetivo académico es
// mostrar el circuito completo de cobro sin depender de un servicio de
// pago externo real, que requeriría credenciales y no funcionaría
// offline en la demo.
// -----------------------------------------------------------------

class Pago
{
    /** Horas que tiene el paciente para pagar un turno "para después". */
    private const HORAS_PLAZO = 48;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // ── Cálculo de precio ────────────────────────────────────

    /** Precio de lista de una especialidad. */
    public function precioEspecialidad(int $idEspecialidad): float
    {
        $stmt = $this->pdo->prepare(
            "SELECT precio_consulta FROM especialidad WHERE id_especialidad = :id"
        );
        $stmt->execute([':id' => $idEspecialidad]);
        return (float) ($stmt->fetchColumn() ?: 0);
    }

    /**
     * Descuento (%) que aplica una obra social para un médico dado.
     * Si no hay convenio cargado para ese par, devuelve 0.
     */
    public function descuento(int $idObraSocial, int $matricula): float
    {
        $stmt = $this->pdo->prepare(
            "SELECT porcentaje_descuento FROM descuento_os_medico
             WHERE id_obra_social = :os AND matricula = :m"
        );
        $stmt->execute([':os' => $idObraSocial, ':m' => $matricula]);
        $val = $stmt->fetchColumn();
        return $val === false ? 0.0 : (float) $val;
    }

    /** Obra social a la que pertenece un plan. */
    public function obraSocialDePlan(int $idPlan): array|false
    {
        $stmt = $this->pdo->prepare(
            "SELECT os.id_obra_social, os.nombre
             FROM plan_os pl JOIN obra_social os ON os.id_obra_social = pl.id_obra_social
             WHERE pl.id_plan = :id"
        );
        $stmt->execute([':id' => $idPlan]);
        return $stmt->fetch();
    }

    /**
     * Calcula el detalle de un cobro: precio base, % de descuento (según
     * la obra social del plan y el médico) y total a pagar.
     */
    public function calcular(int $idEspecialidad, int $idPlan, int $matricula): array
    {
        $base = $this->precioEspecialidad($idEspecialidad);
        $os   = $this->obraSocialDePlan($idPlan);
        $idOS = $os ? (int) $os['id_obra_social'] : 0;
        $pct  = $idOS ? $this->descuento($idOS, $matricula) : 0.0;

        $total = round($base * (1 - $pct / 100), 2);

        return [
            'monto_base' => $base,
            'porcentaje_descuento' => $pct,
            'monto_total' => $total,
            'id_obra_social' => $idOS,
            'obra_social'  => $os['nombre'] ?? '',
        ];
    }

    // ── Creación del pago ────────────────────────────────────

    /**
     * Crea el pago Pendiente de un turno recién reservado.
     * Calcula el monto con descuento y la fecha de vencimiento
     * (HORAS_PLAZO desde ahora, nunca después del inicio del turno).
     * Retorna el id_pago.
     */
    public function crearParaTurno(int $idTurno, array $datos): int
    {
        $calc = $this->calcular(
            (int) $datos['id_especialidad'],
            (int) $datos['id_plan'],
            (int) $datos['matricula']
        );

        // Vencimiento: ahora + plazo, pero nunca pasado el inicio del turno.
        $inicioTurno = strtotime($datos['fecha'] . ' ' . $datos['hora_inicio']);
        $limitePlazo = time() + self::HORAS_PLAZO * 3600;
        $vencTs      = min($limitePlazo, $inicioTurno);
        $vencimiento = date('Y-m-d H:i:s', $vencTs);

        // Cobertura 100% (total 0): el turno queda saldado automáticamente,
        // sin pasar por ninguna pasarela ni cobro en recepción.
        $sinCargo = $calc['monto_total'] <= 0.0;

        // monto_total NO se inserta: es una columna GENERADA (STORED) que
        // la base deriva de monto_base y porcentaje_descuento.
        $stmt = $this->pdo->prepare(
            "INSERT INTO pago
                (id_turno, monto_base, porcentaje_descuento,
                 estado, metodo, fecha_pago, referencia, fecha_vencimiento)
             VALUES
                (:id_turno, :base, :pct,
                 :estado, :metodo, :fecha_pago, :ref, :venc)"
        );
        $stmt->execute([
            ':id_turno' => $idTurno,
            ':base' => $calc['monto_base'],
            ':pct' => $calc['porcentaje_descuento'],
            ':estado' => $sinCargo ? 'Pagado' : 'Pendiente',
            ':metodo' => $sinCargo ? 'Bonificado' : null,
            ':fecha_pago' => $sinCargo ? date('Y-m-d H:i:s') : null,
            ':ref' => $sinCargo ? 'BONIF-' . strtoupper(bin2hex(random_bytes(4))) : null,
            ':venc' => $vencimiento,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    // ── Consultas ────────────────────────────────────────────

    public function buscarPorId(int $idPago): array|false
    {
        $stmt = $this->pdo->prepare(
            "SELECT pg.*, t.fecha, t.hora_inicio, et.descripcion AS estado_turno,
                    t.id_paciente, t.matricula,
                    CONCAT(m.apellido, ', ', m.nombre) AS medico,
                    e.nombre AS especialidad,
                    os.nombre AS obra_social, pl.nombre_plan AS plan
             FROM pago pg
             JOIN turno t        ON t.id_turno        = pg.id_turno
             JOIN estado_turno et ON et.id_estado     = t.id_estado
             JOIN medico m       ON m.matricula       = t.matricula
             JOIN especialidad e ON e.id_especialidad = t.id_especialidad
             JOIN plan_os pl     ON pl.id_plan        = t.id_plan
             JOIN obra_social os ON os.id_obra_social = pl.id_obra_social
             WHERE pg.id_pago = :id"
        );
        $stmt->execute([':id' => $idPago]);
        return $stmt->fetch();
    }

    public function buscarPorTurno(int $idTurno): array|false
    {
        $stmt = $this->pdo->prepare("SELECT * FROM pago WHERE id_turno = :id");
        $stmt->execute([':id' => $idTurno]);
        return $stmt->fetch();
    }

    /**
     * Lista pagos con datos del turno. Filtros: estado, id_paciente, dni.
     */
    public function listar(array $filtros = []): array
    {
        $where  = ['1=1'];
        $params = [];

        // Los filtros operan sobre las columnas de la vista v_pagos_detalle.
        if (!empty($filtros['estado'])) {
            $where[]           = "estado = :estado";
            $params[':estado'] = $filtros['estado'];
        }
        if (!empty($filtros['id_paciente'])) {
            $where[]               = "id_paciente = :idp";
            $params[':idp']        = $filtros['id_paciente'];
        }
        if (!empty($filtros['matricula'])) {
            $where[]               = "matricula = :mat";
            $params[':mat']        = $filtros['matricula'];
        }
        if (!empty($filtros['dni_paciente'])) {
            $where[]       = "paciente_dni LIKE :dni";
            $params[':dni'] = '%' . $filtros['dni_paciente'] . '%';
        }

        // El JOIN pago→turno→paciente/médico/obra social vive ahora en la
        // vista SQL v_pagos_detalle. El modelo sólo filtra y ordena.
        $sql = "SELECT *
                FROM v_pagos_detalle
                WHERE " . implode(' AND ', $where) . "
                ORDER BY fecha_creacion DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Pagos en estado Pendiente, ordenados por vencimiento (los que están
     * por vencer primero), vía la vista SQL v_pagos_pendientes.
     */
    public function pendientes(): array
    {
        try {
            return $this->pdo->query("SELECT * FROM v_pagos_pendientes")->fetchAll();
        } catch (PDOException $e) {
            error_log('Pago pendientes (¿falta correr vistas.sql?): ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Recaudación por médico (cantidad de turnos pagados y total cobrado),
     * vía la vista SQL de agregación v_recaudacion_medico.
     */
    public function recaudacionPorMedico(): array
    {
        try {
            return $this->pdo->query("SELECT * FROM v_recaudacion_medico")->fetchAll();
        } catch (PDOException $e) {
            error_log('Pago recaudacionPorMedico (¿falta correr vistas.sql?): ' . $e->getMessage());
            return [];
        }
    }

    // ── Acciones de cobro ────────────────────────────────────

    /**
     * Procesa un pago con tarjeta (pasarela SIMULADA).
     * Valida número (Luhn), vencimiento y CVV. Aprueba salvo la tarjeta
     * de prueba de rechazo (termina en 0002).
     *
     * Retorna ['ok' => bool, 'msg' => string, 'referencia' => string|null].
     */
    public function pagarConTarjeta(int $idPago, array $tarjeta): array
    {
        $pago = $this->buscarPorId($idPago);
        if (!$pago) {
            return ['ok' => false, 'msg' => 'No se encontró el pago.'];
        }
        if ($pago['estado'] === 'Pagado') {
            return ['ok' => false, 'msg' => 'Este turno ya estaba pagado.'];
        }
        if ($pago['estado'] !== 'Pendiente') {
            return ['ok' => false, 'msg' => 'Este pago ya no se puede abonar (estado: ' . $pago['estado'] . ').'];
        }
        if (strtotime($pago['fecha_vencimiento']) < time()) {
            return ['ok' => false, 'msg' => 'El plazo de pago venció. El turno fue cancelado.'];
        }

        $numero  = preg_replace('/\D/', '', $tarjeta['numero'] ?? '');
        $titular = trim($tarjeta['titular'] ?? '');
        $mes     = (int) ($tarjeta['mes'] ?? 0);
        $anio    = (int) ($tarjeta['anio'] ?? 0);
        $cvv     = preg_replace('/\D/', '', $tarjeta['cvv'] ?? '');

        // Validaciones de formato
        if ($titular === '') {
            return ['ok' => false, 'msg' => 'Ingresá el nombre del titular.'];
        }
        if (strlen($numero) < 13 || strlen($numero) > 19 || !$this->luhnValido($numero)) {
            return ['ok' => false, 'msg' => 'El número de tarjeta no es válido.'];
        }
        if ($mes < 1 || $mes > 12) {
            return ['ok' => false, 'msg' => 'El mes de vencimiento no es válido.'];
        }
        if ($anio < 100) {           // formato YY → 20YY
            $anio += 2000;
        }
        // Vencida si el último día del mes/año ya pasó
        $finMes = strtotime(sprintf('%04d-%02d-01', $anio, $mes) . ' +1 month');
        if ($finMes <= time()) {
            return ['ok' => false, 'msg' => 'La tarjeta está vencida.'];
        }
        if (strlen($cvv) < 3 || strlen($cvv) > 4) {
            return ['ok' => false, 'msg' => 'El código de seguridad (CVV) no es válido.'];
        }

        // Pasarela simulada: tarjeta de prueba de rechazo
        if (substr($numero, -4) === '0002') {
            return ['ok' => false, 'msg' => 'La tarjeta fue rechazada por el emisor. Probá con otra.'];
        }

        // Aprobado → registrar el pago
        $referencia = 'MT-' . strtoupper(bin2hex(random_bytes(4)));
        $stmt = $this->pdo->prepare(
            "UPDATE pago
             SET estado = 'Pagado', metodo = 'Tarjeta', fecha_pago = NOW(),
                 tarjeta_ult4 = :ult4, tarjeta_titular = :titular, referencia = :ref
             WHERE id_pago = :id AND estado = 'Pendiente'"
        );
        $stmt->execute([
            ':ult4'    => substr($numero, -4),
            ':titular' => mb_substr($titular, 0, 100),
            ':ref'     => $referencia,
            ':id'      => $idPago,
        ]);

        if ($stmt->rowCount() === 0) {
            return ['ok' => false, 'msg' => 'El pago no se pudo registrar. Recargá e intentá de nuevo.'];
        }

        return ['ok' => true, 'msg' => 'Pago aprobado.', 'referencia' => $referencia];
    }

    /** Registra el pago como cobrado en recepción (solo staff). */
    public function pagarEnRecepcion(int $idPago): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE pago
             SET estado = 'Pagado', metodo = 'Recepcion', fecha_pago = NOW(),
                 referencia = :ref
             WHERE id_pago = :id AND estado IN ('Pendiente', 'Vencido')"
        );
        $stmt->execute([
            ':ref' => 'REC-' . strtoupper(bin2hex(random_bytes(4))),
            ':id'  => $idPago,
        ]);
        return $stmt->rowCount() > 0;
    }

    /** Anula el pago pendiente de un turno (al cancelarse el turno). */
    public function anularPorTurno(int $idTurno): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE pago SET estado = 'Anulado'
                 WHERE id_turno = :id AND estado = 'Pendiente'"
            );
            $stmt->execute([':id' => $idTurno]);
        } catch (PDOException $e) {
            error_log('Pago anularPorTurno: ' . $e->getMessage());
        }
    }

    /**
     * Cancela los turnos cuyo pago Pendiente venció.
     * - El pago pasa a 'Vencido'.
     * - El turno pasa a 'Cancelado' (libera el slot vía slot_unico).
     * Tolerante: si la tabla pago no existe todavía, no rompe nada.
     * Retorna cuántos turnos se cancelaron.
     */
    public function expirarVencidos(): int
    {
        try {
            $rows = $this->pdo->query(
                "SELECT pg.id_pago, pg.id_turno
                 FROM pago pg
                 JOIN turno t ON t.id_turno = pg.id_turno
                 JOIN estado_turno e ON e.id_estado = t.id_estado
                 WHERE pg.estado = 'Pendiente'
                   AND pg.fecha_vencimiento < NOW()
                   AND e.descripcion NOT IN ('Cancelado', 'Realizado')"
            )->fetchAll();

            if (!$rows) {
                return 0;
            }

            $this->pdo->beginTransaction();
            $updPago  = $this->pdo->prepare("UPDATE pago SET estado = 'Vencido' WHERE id_pago = :id");
            $updTurno = $this->pdo->prepare(
                "UPDATE turno
                 SET id_estado   = (SELECT id_estado FROM estado_turno WHERE descripcion = 'Cancelado'),
                     observacion = :obs
                 WHERE id_turno = :id"
            );
            foreach ($rows as $r) {
                $updPago->execute([':id' => $r['id_pago']]);
                $updTurno->execute([
                    ':obs' => 'Cancelado automáticamente por falta de pago en el plazo.',
                    ':id'  => $r['id_turno'],
                ]);
            }
            $this->pdo->commit();
            return count($rows);

        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('Pago expirarVencidos: ' . $e->getMessage());
            return 0;
        }
    }

    // ── Helpers ──────────────────────────────────────────────

    /** Algoritmo de Luhn para validar el número de tarjeta. */
    private function luhnValido(string $numero): bool
    {
        $suma = 0;
        $alt  = false;
        for ($i = strlen($numero) - 1; $i >= 0; $i--) {
            $d = (int) $numero[$i];
            if ($alt) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $suma += $d;
            $alt = !$alt;
        }
        return $suma % 10 === 0;
    }
}

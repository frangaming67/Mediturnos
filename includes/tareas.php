<?php
// includes/tareas.php
// -----------------------------------------------------------------
// Avisos que NO los dispara una acción de nadie, sino el paso del
// tiempo: "tenés turno mañana", "te queda poco para pagar".
//
// EN QUÉ SE DIFERENCIAN DEL RESTO
// Todos los demás avisos del sistema salen de algo que alguien hizo: se
// reservó un turno, se aprobó un pago, se emitió una receta. El
// controlador que atiende esa acción avisa y listo.
//
// Estos no tienen un disparador. Nadie hace nada el día antes de un
// turno: justamente de eso se trata el recordatorio. Así que hace falta
// algo que los produzca sin que haya una petición de por medio.
//
// ── CÓMO SE EJECUTAN, Y POR QUÉ ASÍ ──────────────────────────
// Lo correcto es un evento programado del sistema operativo (cron en
// Linux, Tareas programadas en Windows) que corra `tareas/ejecutar.php`
// una vez por hora. Ese es el camino recomendado y está documentado en
// docs/deployment.md.
//
// Pero un hosting compartido no siempre da cron, y el proyecto no puede
// quedarse sin recordatorios por eso. Entonces hay un segundo camino:
// `dashboard.php` llama a ejecutarTareas() con un freno de diez minutos.
// La primera visita de cualquier usuario después de ese rato dispara los
// avisos de TODOS — las tareas son globales, no del usuario que entró.
//
// Es la misma solución de compromiso que ya tiene `expirarVencidos()`, y
// tiene el mismo defecto: si nadie entra al sitio, nadie recibe su
// recordatorio. Con cron configurado, ese agujero desaparece. Queda
// anotado en la deuda técnica.
//
// ── LO QUE HACE QUE SEA SEGURO REPETIRLO ─────────────────────
// Todo pasa por notificarUnaVez(), que mira si ya existe un aviso de ese
// tipo para esa referencia y ese usuario. Correr esto mil veces produce
// exactamente los mismos avisos que correrlo una. Sin eso, el paciente
// recibiría un correo por cada página que abriera alguien.
// -----------------------------------------------------------------

require_once __DIR__ . '/notificaciones.php';

/** Cada cuánto, como mínimo, se repite el intento durante una sesión. */
const TAREAS_FRENO_SEGUNDOS = 600;

/**
 * Corre las tareas, con el freno puesto.
 *
 * Pensada para llamarse desde una página. Devuelve lo que emitió, o un
 * arreglo vacío si el freno todavía no se soltó.
 *
 * El freno vive en la sesión y no en la base a propósito: una tabla de
 * "última corrida" sería un dato de infraestructura más que mantener, y
 * el costo de que dos sesiones distintas intenten a la vez es cero —
 * notificarUnaVez() se encarga de que el segundo intento no duplique
 * nada.
 */
function ejecutarTareas(PDO $pdo): array
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $ultima = (int) ($_SESSION['tareas_ultima_corrida'] ?? 0);
        if (time() - $ultima < TAREAS_FRENO_SEGUNDOS) {
            return [];
        }
        $_SESSION['tareas_ultima_corrida'] = time();
    }

    return ejecutarTareasAhora($pdo);
}

/**
 * Corre las tareas sin freno. La usa el punto de entrada de la línea de
 * comandos, que ya corre cuando corresponde.
 *
 * NUNCA lanza excepción: es el mismo criterio que el Notificador. Si
 * falla una tarea, la otra tiene que correr igual, y sobre todo la
 * página que la llamó no puede morirse por un recordatorio.
 */
function ejecutarTareasAhora(PDO $pdo): array
{
    $resultado = [];

    foreach (['recordatoriosDeTurno', 'avisosDePagoPorVencer'] as $tarea) {
        try {
            $resultado[$tarea] = $tarea($pdo);
        } catch (Throwable $e) {
            error_log('Tareas[' . $tarea . ']: ' . $e->getMessage());
            $resultado[$tarea] = 0;
        }
    }

    return $resultado;
}

/**
 * "Tenés turno mañana."
 *
 * Ventana de 24 a 48 horas según cuándo corra, no "mañana" calendario:
 * un turno a las 9 de la mañana avisado a las 23:50 del día anterior
 * llega diez minutos antes de que la persona se vaya a dormir y es
 * inútil. Con una ventana de horas, el aviso sale cuando faltan entre
 * 24 y 36 horas, que es cuando todavía se puede reorganizar el día o
 * cancelar sin perder el turno.
 *
 * @return int Cuántos avisos nuevos se emitieron.
 */
function recordatoriosDeTurno(PDO $pdo, int $horasDesde = 24, int $horasHasta = 36): int
{
    // Los estados activos: un turno cancelado o ya realizado no necesita
    // recordatorio. Se filtra por descripción y no por id para no
    // depender de que los ids de estado_turno no cambien.
    $stmt = $pdo->prepare(
        "SELECT id_turno, id_paciente, fecha, hora_inicio, medico, especialidad, consultorio,
                estado_pago, monto_total
         FROM   v_turnos_detalle
         WHERE  estado IN ('Reservado', 'Confirmado')
           AND  TIMESTAMP(fecha, hora_inicio) BETWEEN
                DATE_ADD(NOW(), INTERVAL :desde HOUR) AND DATE_ADD(NOW(), INTERVAL :hasta HOUR)"
    );
    $stmt->execute([':desde' => $horasDesde, ':hasta' => $horasHasta]);

    $notificador = obtenerNotificador($pdo);
    $emitidos    = 0;

    foreach ($stmt->fetchAll() as $t) {
        $cuando = date('d/m/Y', strtotime($t['fecha'])) . ' a las '
                . substr($t['hora_inicio'], 0, 5) . ' hs';

        $datos = [
            'Profesional'  => 'Dr/a. ' . $t['medico'],
            'Especialidad' => $t['especialidad'],
            'Cuándo'       => $cuando,
            'Consultorio'  => $t['consultorio'],
        ];

        // Si además debe plata, el recordatorio es el último momento útil
        // para decírselo: después el turno se libera solo.
        $aviso = null;
        if (($t['estado_pago'] ?? '') === 'Pendiente') {
            $aviso = 'Este turno todavía no está abonado. Si no se paga, se libera.';
        }

        $r = $notificador->notificarPaciente((int) $t['id_paciente'], new Aviso(
            TipoAviso::TURNO_RECORDATORIO,
            'Te recordamos tu turno',
            'Mañana tenés consulta con Dr/a. ' . $t['medico'] . ', ' . $cuando . '.',
            'sistema/controladores/ControladorTurno.php?accion=detalle&id=' . (int) $t['id_turno'],
            (int) $t['id_turno'],
            [
                'asunto'   => 'Recordatorio: tenés turno mañana',
                'parrafos' => ['Te escribimos para que no se te pase. Si no vas a poder '
                             . 'venir, cancelalo desde tu cuenta así el horario queda '
                             . 'libre para otra persona.'],
                'datos'    => $datos,
                'aviso'    => $aviso,
            ],
            null,
            'Ver mi turno'
        ), true);

        // notificarUnaVez devuelve un arreglo vacío si ya se había
        // avisado: así se sabe si este aviso es nuevo.
        if ($r !== []) {
            $emitidos++;
        }
    }

    return $emitidos;
}

/**
 * "Te queda poco para pagar."
 *
 * Seis horas antes del vencimiento. Antes sería ruido —el plazo normal es
 * de 48 horas— y después ya no sirve de nada.
 *
 * @return int Cuántos avisos nuevos se emitieron.
 */
function avisosDePagoPorVencer(PDO $pdo, int $horas = 6): int
{
    $stmt = $pdo->prepare(
        "SELECT p.id_pago, p.id_turno, p.monto_total, p.fecha_vencimiento,
                t.id_paciente, v.medico, v.fecha, v.hora_inicio
         FROM   pago p
         JOIN   turno t ON t.id_turno = p.id_turno
         JOIN   v_turnos_detalle v ON v.id_turno = p.id_turno
         WHERE  p.estado = 'Pendiente'
           AND  p.fecha_vencimiento > NOW()
           AND  p.fecha_vencimiento <= DATE_ADD(NOW(), INTERVAL :horas HOUR)"
    );
    $stmt->execute([':horas' => $horas]);

    $notificador = obtenerNotificador($pdo);
    $emitidos    = 0;

    foreach ($stmt->fetchAll() as $p) {
        $r = $notificador->notificarPaciente((int) $p['id_paciente'], new Aviso(
            TipoAviso::PAGO_POR_VENCER,
            'Tu turno está por liberarse',
            'Queda poco tiempo para abonar el turno del '
                . date('d/m/Y', strtotime($p['fecha'])) . '.',
            'sistema/controladores/ControladorPago.php?accion=elegir&id_pago=' . (int) $p['id_pago'],
            (int) $p['id_pago'],
            [
                'asunto'    => 'Tu turno está por liberarse',
                'parrafos'  => ['Si el pago no se registra antes de la hora límite, el '
                              . 'horario queda disponible para otra persona y hay que '
                              . 'reservar de nuevo.'],
                'datos'     => [
                    'Turno'       => date('d/m/Y', strtotime($p['fecha'])) . ' a las '
                                   . substr($p['hora_inicio'], 0, 5) . ' hs',
                    'Profesional' => 'Dr/a. ' . $p['medico'],
                    'Importe'     => '$' . number_format((float) $p['monto_total'], 2, ',', '.'),
                ],
                'destacado' => [
                    'etiqueta' => 'Tenés tiempo hasta',
                    'valor'    => date('d/m/Y H:i', strtotime($p['fecha_vencimiento'])),
                ],
            ],
            null,
            'Pagar ahora'
        ), true);

        if ($r !== []) {
            $emitidos++;
        }
    }

    return $emitidos;
}

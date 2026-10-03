<?php
// =============================================================
// pruebas/tareas.php — Recordatorios y avisos por vencimiento
// =============================================================
// Crea un turno dentro de la ventana del recordatorio y un pago a punto
// de vencer, corre las tareas y verifica que avisen UNA sola vez.
//
//     php pruebas/tareas.php
//
// ── EL CORREO ────────────────────────────────────────────────
// Si `config/mail.php` tiene credenciales, el sistema manda correos DE
// VERDAD. Este guión trabaja con pacientes reales de la base de
// desarrollo, cuyas direcciones no existen, así que mandaría y rebotaría.
// Por eso se niega a correr con el SMTP activo en vez de arreglarlo solo:
// mover la configuración de correo del usuario es decisión del usuario.
// =============================================================

require __DIR__ . '/../config/conexion.php';
require __DIR__ . '/../includes/tareas.php';

$ok = 0; $mal = 0;
function chk(string $q, $esperado, $real): void {
    global $ok, $mal;
    if ($esperado === $real) { $ok++; echo "[OK]    $q\n"; }
    else {
        $mal++;
        printf("[MAL]   %s\n          esperado: %s\n          real:     %s\n",
            $q, var_export($esperado, true), var_export($real, true));
    }
}
function chkq(string $q, bool $c): void { chk($q, true, $c); }

if (!mailerEsSimulado()) {
    fwrite(STDERR,
        "\n!! El correo está configurado con credenciales reales.\n" .
        "   Este guión usa pacientes de la base de desarrollo, con direcciones\n" .
        "   que no existen: mandaría correos que van a rebotar.\n\n" .
        "   Apagá el SMTP antes de correrlo:\n" .
        "       mv config/mail.php config/mail.php.apagado\n\n");
    exit(1);
}
echo "correo en modo archivo: no se manda nada\n";

// ── Datos de trabajo ─────────────────────────────────────────
// Un paciente con cuenta ACTIVA: sin cuenta no hay a quién avisarle, y la
// prueba daría un cero que parece un error y es un dato que falta.
$fila = $pdo->query(
    "SELECT p.id_paciente, u.id_usuario, u.usuario
     FROM   paciente p
     JOIN   usuario u ON u.id_paciente = p.id_paciente AND u.estado = 'activo'
     ORDER  BY p.id_paciente LIMIT 1"
)->fetch();
$pac  = (int) $fila['id_paciente'];
$usr  = (int) $fila['id_usuario'];

$mat  = (int) $pdo->query("SELECT matricula FROM medico WHERE estado='activo' ORDER BY matricula LIMIT 1")->fetchColumn();
$esp  = (int) $pdo->query("SELECT id_especialidad FROM especialidad ORDER BY id_especialidad LIMIT 1")->fetchColumn();
$cons = (int) $pdo->query("SELECT id_consultorio FROM consultorio ORDER BY id_consultorio LIMIT 1")->fetchColumn();
$plan = (int) $pdo->query("SELECT id_plan FROM plan_os ORDER BY id_plan LIMIT 1")->fetchColumn();
$eConf = (int) $pdo->query("SELECT id_estado FROM estado_turno WHERE descripcion='Confirmado'")->fetchColumn();
$eCanc = (int) $pdo->query("SELECT id_estado FROM estado_turno WHERE descripcion='Cancelado'")->fetchColumn();

printf("paciente=%d (cuenta %s)  medico=%d\n", $pac, $fila['usuario'], $mat);
echo str_repeat('=', 64), "\n";

$creados = ['turno' => [], 'notif' => []];

/** Inserta un turno a N horas de ahora, esquivando los horarios ya ocupados. */
function turnoEn(PDO $pdo, int $horas, int $estado, array $d): int {
    // El minuto se fuerza a 00 y los segundos a 00: `hora_inicio` es TIME
    // y el índice único de concurrencia compara la hora exacta.
    $ts = strtotime("+{$horas} hours");
    // Se busca un hueco libre moviendo la hora: la base de desarrollo
    // tiene 48 turnos y el UNIQUE de slot rechazaría una colisión.
    for ($i = 0; $i < 24; $i++) {
        $fecha = date('Y-m-d', $ts);
        $hora  = date('H', $ts + $i * 3600) . ':00:00';
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO turno (fecha, hora_inicio, id_estado, id_paciente,
                                    matricula, id_especialidad, id_consultorio, id_plan)
                 VALUES (:f, :h, :e, :p, :m, :esp, :c, :pl)"
            );
            $stmt->execute([':f' => $fecha, ':h' => $hora, ':e' => $estado,
                ':p' => $d['pac'], ':m' => $d['mat'], ':esp' => $d['esp'],
                ':c' => $d['cons'], ':pl' => $d['plan']]);
            return (int) $pdo->lastInsertId();
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') { throw $e; }
            // Horario ocupado: se prueba el siguiente.
        }
    }
    throw new RuntimeException('No se encontró un horario libre para la prueba.');
}

$d = compact('pac', 'mat', 'esp', 'cons', 'plan');

// Punto de partida: ningún aviso previo de estos tipos para este usuario.
$pdo->prepare("DELETE FROM notificacion WHERE id_usuario = :u AND tipo IN ('turno_recordatorio','pago_por_vencer')")
    ->execute([':u' => $usr]);

// =============================================================
echo "\n-- RECORDATORIO DE TURNO -----------------------------\n";

// Dentro de la ventana (24-36 h).
$t1 = turnoEn($pdo, 30, $eConf, $d);
$creados['turno'][] = $t1;

$n = recordatoriosDeTurno($pdo);
chkq("emite al menos un recordatorio", $n >= 1);

$av = $pdo->prepare(
    "SELECT * FROM notificacion
     WHERE id_usuario = :u AND tipo = 'turno_recordatorio' AND id_referencia = :t");
$av->execute([':u' => $usr, ':t' => $t1]);
$aviso = $av->fetch();
chkq("quedó el aviso en la base", $aviso !== false);
chk("con el título correcto", 'Te recordamos tu turno', $aviso['titulo'] ?? null);
chkq("apunta al detalle del turno",
     str_contains((string) ($aviso['url_accion'] ?? ''), 'accion=detalle&id=' . $t1));
// `?? 'x'` NO sirve acá: el operador se dispara justamente con NULL,
// que es el valor que se quiere comprobar. La clave existe en la fila.
chk("nace sin leer", null, $aviso['leida_en']);
chkq("se registró el envío del correo", !empty($aviso['email_enviado_en']));

// La segunda corrida NO puede duplicar: es lo que hace que sea seguro
// llamar a esto desde cada visita al sitio.
$antes = (int) $pdo->query("SELECT COUNT(*) FROM notificacion WHERE tipo='turno_recordatorio'")->fetchColumn();
recordatoriosDeTurno($pdo);
recordatoriosDeTurno($pdo);
$despues = (int) $pdo->query("SELECT COUNT(*) FROM notificacion WHERE tipo='turno_recordatorio'")->fetchColumn();
chk("dos corridas más no duplican nada", $antes, $despues);

// ── Lo que NO tiene que avisar ───────────────────────────────
// Fuera de la ventana: falta demasiado.
$t2 = turnoEn($pdo, 24 * 9, $eConf, $d);
$creados['turno'][] = $t2;
recordatoriosDeTurno($pdo);
chk("un turno a nueve días todavía no se avisa", 0, (int) $pdo->query(
    "SELECT COUNT(*) FROM notificacion WHERE tipo='turno_recordatorio' AND id_referencia=$t2")->fetchColumn());

// Cancelado: no se recuerda un turno que no va a existir.
$t3 = turnoEn($pdo, 30, $eCanc, $d);
$creados['turno'][] = $t3;
recordatoriosDeTurno($pdo);
chk("un turno cancelado no se recuerda", 0, (int) $pdo->query(
    "SELECT COUNT(*) FROM notificacion WHERE tipo='turno_recordatorio' AND id_referencia=$t3")->fetchColumn());

// =============================================================
echo "\n-- PAGO POR VENCER ----------------------------------\n";

// Un pago del turno t2, venciendo en tres horas.
$precio = (float) $pdo->query("SELECT precio_consulta FROM especialidad WHERE id_especialidad=$esp")->fetchColumn();
$pdo->prepare(
    "INSERT INTO pago (id_turno, monto_base, porcentaje_descuento, estado,
                       fecha_creacion, fecha_vencimiento)
     VALUES (:t, :m, 0, 'Pendiente', NOW(), DATE_ADD(NOW(), INTERVAL 3 HOUR))"
)->execute([':t' => $t2, ':m' => $precio]);
$idPago = (int) $pdo->lastInsertId();

$n = avisosDePagoPorVencer($pdo);
chkq("emite el aviso de vencimiento", $n >= 1);

$av->closeCursor();
$stmt = $pdo->prepare(
    "SELECT * FROM notificacion
     WHERE id_usuario = :u AND tipo = 'pago_por_vencer' AND id_referencia = :p");
$stmt->execute([':u' => $usr, ':p' => $idPago]);
$avPago = $stmt->fetch();
chkq("quedó el aviso en la base", $avPago !== false);
chkq("lleva a la pantalla de pago",
     str_contains((string) ($avPago['url_accion'] ?? ''), 'id_pago=' . $idPago));

$antes = (int) $pdo->query("SELECT COUNT(*) FROM notificacion WHERE tipo='pago_por_vencer'")->fetchColumn();
avisosDePagoPorVencer($pdo);
$despues = (int) $pdo->query("SELECT COUNT(*) FROM notificacion WHERE tipo='pago_por_vencer'")->fetchColumn();
chk("tampoco duplica", $antes, $despues);

// Un pago ya vencido no se avisa: avisar después no sirve de nada.
$pdo->exec("UPDATE pago SET fecha_vencimiento = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id_pago = $idPago");
$pdo->prepare("DELETE FROM notificacion WHERE tipo='pago_por_vencer' AND id_referencia = :p")
    ->execute([':p' => $idPago]);
avisosDePagoPorVencer($pdo);
chk("un pago ya vencido no se avisa", 0, (int) $pdo->query(
    "SELECT COUNT(*) FROM notificacion WHERE tipo='pago_por_vencer' AND id_referencia=$idPago")->fetchColumn());

// =============================================================
echo "\n-- EL CORREDOR --------------------------------------\n";

$r = ejecutarTareasAhora($pdo);
chkq("ejecutarTareasAhora() informa las dos tareas",
     isset($r['recordatoriosDeTurno'], $r['avisosDePagoPorVencer']));

// Nunca lanza excepción: si una tarea falla, la página que la llamó no
// puede morirse por un recordatorio.
// Una base VACÍA: las dos consultas fallan porque no existen ni las
// vistas ni las tablas. El corredor tiene que devolver ceros y seguir —
// si lanzara, una página se caería por no poder mandar un recordatorio.
$pdo->exec("DROP DATABASE IF EXISTS mediturnos_tareas_prueba");
$pdo->exec("CREATE DATABASE mediturnos_tareas_prueba");
$pdoVacio = new PDO('mysql:host=' . DB_HOST . ';dbname=mediturnos_tareas_prueba;charset=utf8mb4',
    DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                       PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
try {
    $r2 = ejecutarTareasAhora($pdoVacio);
    chkq("con la base vacía no lanza excepción", is_array($r2));
    chk("y devuelve cero en las dos tareas", [0, 0],
        [$r2['recordatoriosDeTurno'], $r2['avisosDePagoPorVencer']]);
} catch (Throwable $e) {
    chk("con la base vacía no lanza excepción", 'sin excepción',
        get_class($e) . ': ' . $e->getMessage());
}
$pdoVacio = null;
$pdo->exec("DROP DATABASE IF EXISTS mediturnos_tareas_prueba");

// =============================================================
echo "\n-- LIMPIEZA ------------------------------------------\n";

$pdo->prepare("DELETE FROM notificacion WHERE id_usuario = :u AND tipo IN ('turno_recordatorio','pago_por_vencer')")
    ->execute([':u' => $usr]);
foreach ($creados['turno'] as $id) {
    $pdo->exec("DELETE FROM pago WHERE id_turno = $id");
    $pdo->exec("DELETE FROM historial_turno WHERE id_turno = $id");
    $pdo->exec("DELETE FROM turno WHERE id_turno = $id");
}
chk("los turnos de prueba se borraron", 0, (int) $pdo->query(
    "SELECT COUNT(*) FROM turno WHERE id_turno IN (" . implode(',', $creados['turno']) . ")")->fetchColumn());
chk("los avisos de prueba se borraron", 0, (int) $pdo->query(
    "SELECT COUNT(*) FROM notificacion WHERE id_usuario = $usr
       AND tipo IN ('turno_recordatorio','pago_por_vencer')")->fetchColumn());
chk("la base de desarrollo quedó con sus 48 turnos", 48, (int) $pdo->query(
    "SELECT COUNT(*) FROM turno")->fetchColumn());

echo "\n", str_repeat('=', 64), "\n";
printf("TOTAL: %d OK, %d MAL\n", $ok, $mal);
exit($mal > 0 ? 1 : 0);

<?php
// =============================================================
// pruebas/historial.php — La línea de tiempo clínica
// =============================================================
// Cubre los tres arreglos del modelo que salieron de revisar los
// hallazgos que habían quedado sin verificar:
//
//   1. el buscador escapa los comodines de LIKE;
//   2. una consulta se ubica en la fecha del TURNO, no en la fecha en que
//      el médico se sentó a escribir la ficha;
//   3. la línea de tiempo tiene un techo de filas.
//
//     php pruebas/historial.php
//
// Crea un historial temporal sobre turnos reales y lo borra al terminar.
// =============================================================

require __DIR__ . '/../config/conexion.php';
require __DIR__ . '/../sistema/modelos/Historial.php';

$ok = 0; $mal = 0;
function chk(string $q, $esperado, $real): void {
    global $ok, $mal;
    if ($esperado === $real) { $ok++; echo "[OK]    $q\n"; }
    else { $mal++; printf("[MAL]   %s\n          esperado: %s\n          real:     %s\n",
        $q, var_export($esperado, true), var_export($real, true)); }
}
function chkq(string $q, bool $c): void { chk($q, true, $c); }

$m = new Historial($pdo);

// ── Un turno realizado, viejo, para trabajar ─────────────────
$t = $pdo->query(
    "SELECT t.id_turno, t.id_paciente, t.matricula, t.fecha, t.hora_inicio
     FROM   turno t JOIN estado_turno e ON e.id_estado = t.id_estado
     WHERE  e.descripcion = 'Realizado'
     ORDER  BY t.fecha ASC LIMIT 1"
)->fetch();
$pac   = (int) $t['id_paciente'];
$turno = (int) $t['id_turno'];
$mat   = (int) $t['matricula'];

printf("turno %d del %s, paciente %d\n", $turno, $t['fecha'], $pac);
echo str_repeat('=', 64), "\n";

// Punto de partida: sin historial de este paciente.
$pdo->exec("DELETE FROM estudio WHERE id_paciente = $pac");
$pdo->prepare("DELETE FROM consulta WHERE id_turno IN
    (SELECT id_turno FROM turno WHERE id_paciente = :p)")->execute([':p' => $pac]);

$limpiar = function () use ($pdo, $pac) {
    $pdo->exec("DELETE FROM estudio WHERE id_paciente = $pac");
    $pdo->prepare("DELETE FROM consulta WHERE id_turno IN
        (SELECT id_turno FROM turno WHERE id_paciente = :p)")->execute([':p' => $pac]);
};

// =============================================================
echo "\n-- LA FECHA ES LA DEL TURNO -------------------------\n";

$m->guardarConsulta($turno, $mat, [
    'motivo_consulta' => 'Control de rutina',
    'diagnostico'     => 'Sin particularidades',
    'indicaciones'    => 'Volver en seis meses',
]);

$linea = $m->timeline($pac);
$cons  = null;
foreach ($linea as $h) { if ($h['clase'] === 'consulta') { $cons = $h; } }

chkq("la consulta aparece en la línea de tiempo", $cons !== null);
chk("se ubica en la fecha del turno", substr((string) $t['fecha'], 0, 10),
    substr((string) $cons['fecha'], 0, 10));
chk("y NO en la fecha de hoy, que es cuando se escribió",
    false, substr((string) $cons['fecha'], 0, 10) === date('Y-m-d'));
chk("pero la fecha de escritura sigue disponible aparte", date('Y-m-d'),
    substr((string) $cons['registrada_en'], 0, 10));

// Con dos registros de fechas distintas, el orden tiene que seguir la
// atención y no la escritura.
$idEst = $m->solicitarEstudio($pac, $mat, ['tipo' => 'Laboratorio', 'nombre' => 'Hemograma'], $turno);
$linea = $m->timeline($pac);
chk("dos registros en la línea", 2, count($linea));
chk("el estudio pedido HOY va primero", 'estudio', $linea[0]['clase']);
chk("y la consulta vieja, segunda", 'consulta', $linea[1]['clase']);

// =============================================================
echo "\n-- EL BUSCADOR NO MIENTE ----------------------------\n";

// Un dato que contiene de verdad los caracteres comodín.
$m->guardarConsulta($turno, $mat, [
    'motivo_consulta' => 'Descuento 50% y guion_bajo',
    'diagnostico'     => null, 'indicaciones' => null,
]);

chk("buscar '%' encuentra SÓLO la fila que lo tiene literal", 1,
    count($m->timeline($pac, ['q' => '%'])));
chk("buscar '_' idem", 1, count($m->timeline($pac, ['q' => '_'])));
chk("buscar '50%' encuentra esa fila", 1, count($m->timeline($pac, ['q' => '50%'])));
chk("buscar '%%%' no devuelve nada: no hay tres por ciento seguidos", 0,
    count($m->timeline($pac, ['q' => '%%%'])));
chk("una búsqueda normal sigue funcionando", 1,
    count($m->timeline($pac, ['q' => 'Hemograma'])));
chk("y un texto inexistente no trae nada", 0,
    count($m->timeline($pac, ['q' => 'zzzznoexiste'])));

// La prueba de fondo: sin escapar, '%' traería TODO.
chkq("sin escapar traería las dos filas; con escapado trae una",
     count($m->timeline($pac, ['q' => '%'])) < count($m->timeline($pac)));

// =============================================================
echo "\n-- OTROS FILTROS ------------------------------------\n";

chk("filtro por clase=consulta", 1, count($m->timeline($pac, ['clase' => 'consulta'])));
chk("filtro por clase=estudio",  1, count($m->timeline($pac, ['clase' => 'estudio'])));
chk("una clase inventada se ignora", 2, count($m->timeline($pac, ['clase' => 'inventada'])));
chk("filtro desde hoy: sólo el estudio", 1,
    count($m->timeline($pac, ['desde' => date('Y-m-d')])));
chk("filtro hasta ayer: sólo la consulta vieja", 1,
    count($m->timeline($pac, ['hasta' => date('Y-m-d', strtotime('-1 day'))])));

// =============================================================
echo "\n-- EL TECHO DE FILAS -------------------------------\n";

chkq("MAX_FILAS es un número razonable",
     Historial::MAX_FILAS > 0 && Historial::MAX_FILAS <= 1000);

// Se cargan más estudios que el techo y se comprueba que corte.
$cuantos = Historial::MAX_FILAS + 5;
$stmt = $pdo->prepare(
    "INSERT INTO estudio (id_paciente, matricula, tipo, nombre, solicitado_en)
     VALUES (:p, :m, 'Laboratorio', :n, NOW())"
);
for ($i = 0; $i < $cuantos; $i++) {
    $stmt->execute([':p' => $pac, ':m' => $mat, ':n' => 'Estudio de carga ' . $i]);
}
$total = (int) $pdo->query("SELECT COUNT(*) FROM estudio WHERE id_paciente = $pac")->fetchColumn();
chkq("hay más filas en la base que el techo", $total > Historial::MAX_FILAS);
chk("la línea de tiempo corta en el techo", Historial::MAX_FILAS,
    count($m->timeline($pac)));
chk("el resumen sigue contando TODO, no sólo lo que se muestra", $total,
    (int) $m->resumen($pac)['estudios']);

// =============================================================
echo "\n-- LIMPIEZA ------------------------------------------\n";
$limpiar();
chk("sin estudios de prueba", 0, (int) $pdo->query(
    "SELECT COUNT(*) FROM estudio WHERE id_paciente = $pac")->fetchColumn());
chk("sin consultas de prueba", 0, (int) $pdo->query("SELECT COUNT(*) FROM consulta")->fetchColumn());
chk("los 48 turnos siguen ahí", 48, (int) $pdo->query("SELECT COUNT(*) FROM turno")->fetchColumn());

echo "\n", str_repeat('=', 64), "\n";
printf("TOTAL: %d OK, %d MAL\n", $ok, $mal);
exit($mal > 0 ? 1 : 0);

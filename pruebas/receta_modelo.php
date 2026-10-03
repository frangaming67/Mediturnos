<?php
// Verificación del modelo Receta. Trabaja sobre un paciente real y
// limpia TODO lo que crea al terminar.
require __DIR__ . '/../config/conexion.php';
require __DIR__ . '/../sistema/modelos/Receta.php';

$ok = 0; $mal = 0;
function chk(string $q, $esperado, $real) {
    global $ok, $mal;
    $bien = $esperado === $real;
    $bien ? $ok++ : $mal++;
    printf("%s  %s%s\n", $bien ? '[OK]  ' : '[MAL] ', $q,
        $bien ? '' : "\n        esperado: " . var_export($esperado, true)
                   . "\n        real:     " . var_export($real, true));
}
function chkq(string $q, bool $cond) { chk($q, true, $cond); }

$m = new Receta($pdo);

// ── Datos de trabajo ─────────────────────────────────────────
$pac  = (int) $pdo->query("SELECT id_paciente FROM paciente ORDER BY id_paciente LIMIT 1")->fetchColumn();
$meds = $pdo->query("SELECT matricula FROM medico WHERE estado='activo' ORDER BY matricula LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
[$mat, $otro] = $meds;
echo "paciente=$pac  medico=$mat  otro_medico=$otro\n";
echo str_repeat('=', 64), "\n";

$creadas = [];

// =============================================================
echo "\n-- EMITIR --------------------------------------------\n";

$items = [
    ['nombre' => 'Ibuprofeno', 'presentacion' => 'comprimidos 400 mg',
     'dosis' => '1 comprimido', 'frecuencia' => 'cada 8 horas',
     'duracion' => 'por 7 días', 'cantidad' => 2],
    ['nombre' => 'Omeprazol', 'dosis' => '1 cápsula', 'frecuencia' => 'por la mañana'],
    // Renglón entero vacío: se descarta en silencio.
    ['nombre' => '', 'dosis' => '', 'frecuencia' => ''],
];
$id1 = $m->emitir($pac, $mat, ['diagnostico' => 'Lumbalgia aguda',
                               'indicaciones' => 'Tomar con las comidas'], $items);
$creadas[] = $id1;
chkq("emitir() devuelve un id", $id1 > 0);
chk("descarta el renglón vacío y guarda 2 medicamentos", 2, count($m->itemsDe($id1)));

// Un renglón a medias NO se descarta en silencio.
try {
    $m->emitir($pac, $mat, [], [['nombre' => 'Amoxicilina', 'dosis' => '', 'frecuencia' => '']]);
    chk("renglón a medias rechazado", 'excepcion', 'pasó');
} catch (InvalidArgumentException $e) {
    chkq("renglón a medias rechazado: " . $e->getMessage(), true);
}

// Una receta sin ningún medicamento no existe.
try {
    $m->emitir($pac, $mat, [], [['nombre' => '', 'dosis' => '', 'frecuencia' => '']]);
    chk("receta sin medicamentos rechazada", 'excepcion', 'pasó');
} catch (InvalidArgumentException $e) {
    chkq("receta sin medicamentos rechazada", true);
}

// Y ninguna de las dos dejó una cabecera huérfana.
$antes = (int) $pdo->query("SELECT COUNT(*) FROM receta WHERE id_paciente=$pac")->fetchColumn();
chk("no quedaron recetas huérfanas de los intentos fallidos", 1, $antes);

// Vigencia fuera de rango cae al valor por omisión.
$id2 = $m->emitir($pac, $mat, ['dias' => 9999], [['nombre' => 'Paracetamol',
        'dosis' => '1 g', 'frecuencia' => 'cada 8 horas']]);
$creadas[] = $id2;
$r2 = $m->conDueno($id2);
chk("dias fuera de rango usa " . Receta::VIGENCIA_DIAS, Receta::VIGENCIA_DIAS, (int) $r2['dias_restantes']);

// =============================================================
echo "\n-- LECTURA Y SITUACIÓN -------------------------------\n";

$r1 = $m->conDueno($id1);
chk("situacion de una receta nueva", 'Vigente', $r1['situacion']);
chk("cuenta los medicamentos", 2, (int) $r1['items']);
chk("concatena los nombres", 'Ibuprofeno · Omeprazol', $r1['medicamentos']);
chk("dias_restantes", Receta::VIGENCIA_DIAS, (int) $r1['dias_restantes']);
chk("trae el dueño", $pac, (int) $r1['id_paciente']);
chkq("trae el estado del médico", in_array($r1['medico_estado'], ['activo', 'inactivo'], true));

$lista = $m->deDelPaciente($pac);
chk("deDelPaciente() lista las dos", 2, count($lista));

$res = $m->resumenPaciente($pac);
chk("resumen: total", 2, $res['total']);
chk("resumen: vigentes", 2, $res['vigentes']);
chk("resumen: vencidas", 0, $res['vencidas']);

// Una receta vencida a mano: la situación tiene que cambiar sola.
$pdo->exec("UPDATE receta SET emitida_el = DATE_SUB(CURDATE(), INTERVAL 60 DAY),
            vence_el = DATE_SUB(CURDATE(), INTERVAL 30 DAY) WHERE id_receta = $id2");
chk("vence_el pasado => situacion Vencida", 'Vencida', $m->conDueno($id2)['situacion']);
chk("resumen cuenta la vencida", 1, $m->resumenPaciente($pac)['vencidas']);
chk("filtro situacion=Vencida trae una", 1, count($m->deDelPaciente($pac, ['situacion' => 'Vencida'])));
chk("filtro situacion=Vigente trae una", 1, count($m->deDelPaciente($pac, ['situacion' => 'Vigente'])));
chk("filtro situacion inventada se ignora", 2, count($m->deDelPaciente($pac, ['situacion' => 'Inventada'])));

// =============================================================
echo "\n-- BUSCADOR ------------------------------------------\n";

chk("busca por medicamento", 1, count($m->deDelPaciente($pac, ['q' => 'Ibuprofeno'])));
chk("busca por diagnóstico", 1, count($m->deDelPaciente($pac, ['q' => 'Lumbalgia'])));
chk("texto inexistente no trae nada", 0, count($m->deDelPaciente($pac, ['q' => 'zzzznada'])));
// Los comodines de LIKE escapados: '%' es un texto, no "todo".
chk("'%' se busca literal, no trae todo", 0, count($m->deDelPaciente($pac, ['q' => '%'])));
chk("'_' se busca literal, no trae todo", 0, count($m->deDelPaciente($pac, ['q' => '_'])));

// =============================================================
echo "\n-- PERMISOS ------------------------------------------\n";

chkq("el paciente ve lo suyo",            $m->puedeVer($r1, 'paciente', $pac, null));
chkq("otro paciente NO lo ve",           !$m->puedeVer($r1, 'paciente', $pac + 99999, null));
chkq("paciente sin ficha NO lo ve",      !$m->puedeVer($r1, 'paciente', null, null));
chkq("el médico que firmó la ve",         $m->puedeVer($r1, 'medico', null, $mat));
chkq("otro médico que NO atendió, no",   !$m->puedeVer($r1, 'medico', null, $otro, false));
chkq("otro médico que SÍ atendió, sí",    $m->puedeVer($r1, 'medico', null, $otro, true));
chkq("admin sí",                          $m->puedeVer($r1, 'admin', null, null));
chkq("recepcion sí",                      $m->puedeVer($r1, 'recepcion', null, null));
chkq("un rol inventado no",              !$m->puedeVer($r1, 'cualquiera', null, null));

// =============================================================
echo "\n-- RENOVACIÓN ----------------------------------------\n";

chk("una receta vigente es renovable", null, $m->motivoNoRenovable($m->conDueno($id1)));

$ren = $m->solicitarRenovacion($id1, $pac, 'Se me termina la semana que viene');
chkq("solicitarRenovacion() devuelve un id", $ren > 0);
chk("el segundo pedido del mismo devuelve null", null, $m->solicitarRenovacion($id1, $pac, 'otra vez'));
chkq("con pedido pendiente ya no es renovable",
     str_contains((string) $m->motivoNoRenovable($m->conDueno($id1)), 'esperando respuesta'));

chk("aparece en la bandeja del médico", 1, count($m->renovacionesPendientes($mat)));
chk("NO aparece en la bandeja de otro médico", 0, count($m->renovacionesPendientes($otro)));
chk("contador de pendientes", 1, $m->pendientesDeMedico($mat));
chk("el paciente ve su pedido", 1, count($m->renovacionesDePaciente($pac)));

// Otro médico no puede resolver un pedido ajeno.
try {
    $m->resolverRenovacion($ren, $otro, true, 'me la apropio');
    chk("otro médico NO puede resolver", 'excepcion', 'pasó');
} catch (RuntimeException $e) {
    chkq("otro médico NO puede resolver: " . $e->getMessage(), true);
}
chk("y el pedido sigue pendiente", 'Pendiente', $m->renovacionConDatos($ren)['estado']);

// Aprobar: nace una receta nueva con los MISMOS medicamentos.
$idNueva = $m->resolverRenovacion($ren, $mat, true, 'Renovada por 30 días');
$creadas[] = $idNueva;
chkq("aprobar devuelve el id de la receta nueva", $idNueva > 0 && $idNueva !== $id1);
chk("la nueva copió los 2 medicamentos", 2, count($m->itemsDe($idNueva)));
chk("copió nombre y dosis del primero", 'Ibuprofeno', $m->itemsDe($idNueva)[0]['nombre']);
chk("copió la cantidad", 2, (int) $m->itemsDe($idNueva)[0]['cantidad']);
chk("copió el diagnóstico", 'Lumbalgia aguda', $m->conDueno($idNueva)['diagnostico']);
chk("la nueva está vigente", 'Vigente', $m->conDueno($idNueva)['situacion']);
chk("la nueva no cuelga de ningún turno", null, $m->conDueno($idNueva)['id_turno']);

$pedido = $m->renovacionConDatos($ren);
chk("el pedido quedó Aprobada", 'Aprobada', $pedido['estado']);
chk("y enlazado a la receta nueva", $idNueva, (int) $pedido['id_receta_nueva']);
chkq("con fecha de resolución", !empty($pedido['resuelta_en']));
chk("y con quién la resolvió", $mat, (int) $pedido['matricula']);

// La receta VIEJA no se tocó: sigue siendo el registro de lo que valía.
$viejaDespues = $m->conDueno($id1);
chk("la vieja conserva su vencimiento", $r1['vence_el'], $viejaDespues['vence_el']);
chk("la vieja sigue Vigente (no se anuló)", 'Vigente', $viejaDespues['situacion']);
chkq("pero ya no es renovable: hay que renovar la nueva",
     str_contains((string) $m->motivoNoRenovable($viejaDespues), 'más reciente'));

// Resolver dos veces el mismo pedido.
try {
    $m->resolverRenovacion($ren, $mat, true, 'otra vez');
    chk("no se puede resolver dos veces", 'excepcion', 'pasó');
} catch (RuntimeException $e) {
    chkq("no se puede resolver dos veces: " . $e->getMessage(), true);
}
chk("y NO se emitió una segunda receta", 3, (int) $pdo->query(
    "SELECT COUNT(*) FROM receta WHERE id_paciente=$pac")->fetchColumn());

// Rechazar: no nace ninguna receta.
$ren2 = $m->solicitarRenovacion($idNueva, $pac, 'otra más');
chk("rechazar no devuelve receta nueva", null, $m->resolverRenovacion($ren2, $mat, false, 'Pasá por consultorio'));
chk("sigue habiendo 3 recetas", 3, (int) $pdo->query(
    "SELECT COUNT(*) FROM receta WHERE id_paciente=$pac")->fetchColumn());
chk("el pedido quedó Rechazada", 'Rechazada', $m->renovacionConDatos($ren2)['estado']);
chk("con la respuesta del médico", 'Pasá por consultorio', $m->renovacionConDatos($ren2)['respuesta']);
// Rechazada libera el UNIQUE: se puede volver a pedir.
$ren3 = $m->solicitarRenovacion($idNueva, $pac, 'insisto');
chkq("tras un rechazo se puede volver a pedir", $ren3 > 0);
$m->resolverRenovacion($ren3, $mat, false, 'no');

// =============================================================
echo "\n-- ANULAR --------------------------------------------\n";

chk("otro médico NO puede anular", false, $m->anular($id1, $otro, 'no es mía'));
chk("sigue Vigente", 'Vigente', $m->conDueno($id1)['situacion']);
chk("el que la firmó sí", true, $m->anular($id1, $mat, 'Error de carga'));
chk("situacion Anulada", 'Anulada', $m->conDueno($id1)['situacion']);
chk("guarda el motivo", 'Error de carga', $m->conDueno($id1)['anulada_motivo']);
chk("anular dos veces devuelve false", false, $m->anular($id1, $mat, 'otra vez'));
chkq("una anulada no es renovable",
     str_contains((string) $m->motivoNoRenovable($m->conDueno($id1)), 'anulada'));
chk("la fila NO se borró: el historial la conserva", 1, (int) $pdo->query(
    "SELECT COUNT(*) FROM receta WHERE id_receta=$id1")->fetchColumn());

// =============================================================
echo "\n-- MÉDICO INACTIVO -----------------------------------\n";
// Si el que firmó ya no atiende, nadie puede resolver el pedido:
// el paciente tiene que saberlo ANTES de pedir y quedarse esperando.
$pdo->exec("UPDATE medico SET estado='inactivo' WHERE matricula=$mat");
chkq("médico inactivo => no renovable",
     str_contains((string) $m->motivoNoRenovable($m->conDueno($idNueva)), 'ya no atiende'));
$pdo->exec("UPDATE medico SET estado='activo' WHERE matricula=$mat");
chk("médico reactivado => renovable otra vez", null, $m->motivoNoRenovable($m->conDueno($idNueva)));

// =============================================================
echo "\n-- LIMPIEZA ------------------------------------------\n";
$pdo->exec("DELETE FROM receta WHERE id_paciente = $pac");
chk("no quedó ninguna receta de prueba", 0, (int) $pdo->query(
    "SELECT COUNT(*) FROM receta WHERE id_paciente=$pac")->fetchColumn());
chk("ON DELETE CASCADE se llevó los medicamentos", 0, (int) $pdo->query(
    "SELECT COUNT(*) FROM receta_medicamento")->fetchColumn());
chk("ON DELETE CASCADE se llevó las renovaciones", 0, (int) $pdo->query(
    "SELECT COUNT(*) FROM renovacion_receta")->fetchColumn());
chk("el médico quedó activo como estaba", 'activo', $pdo->query(
    "SELECT estado FROM medico WHERE matricula=$mat")->fetchColumn());

echo "\n", str_repeat('=', 64), "\n";
printf("TOTAL: %d OK, %d MAL\n", $ok, $mal);
exit($mal > 0 ? 1 : 0);

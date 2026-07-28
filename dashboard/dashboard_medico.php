<?php
// =============================================================
// dashboard/dashboard_medico.php — Pantalla propia del rol MÉDICO
// =============================================================
// Hasta ahora el rol `medico` caía en dashboard_admin.php, una pantalla
// pensada para el staff de gestión: veía KPIs de toda la clínica y
// paneles que no le correspondían, y no tenía forma de ver SU agenda.
//
// Esta vista responde a lo que un médico necesita al empezar el día:
//   1) cuántos pacientes tiene y en qué estado,
//   2) la lista ordenada de su jornada,
//   3) su carga semanal,
//   4) sus pacientes, para consultar una ficha.
//
// TODO sale de la base y está acotado por la matrícula de la sesión:
// un médico no puede ver la agenda de otro (el filtro va en el SQL).
// =============================================================

$matricula = (int) ($_SESSION['matricula'] ?? 0);

// Un usuario con rol médico pero sin matrícula vinculada es un dato
// inconsistente: no se le puede armar la agenda. Se avisa en vez de
// mostrar una pantalla vacía sin explicación.
if ($matricula <= 0) {
    echo '<div class="alerta alerta-error">'
       . 'Tu usuario tiene rol <strong>médico</strong> pero no tiene una matrícula vinculada, '
       . 'así que no podemos mostrar tu agenda. Pedile a un administrador que asocie tu cuenta '
       . 'a tu matrícula desde <em>Usuarios</em>.'
       . '</div>';
    return;
}

$hoy = date('Y-m-d');

$kpis      = $modelo->kpisMedico($matricula, $hoy);
$agenda    = $modelo->agendaMedico($matricula, $hoy);
$semana    = $modelo->citasSemanaMedico($matricula);
$busqueda  = trim($_GET['q'] ?? '');
$pacientes = $modelo->pacientesDelMedico($matricula, $busqueda);

$URL_TURNO = BASE_URL . 'sistema/controladores/ControladorTurno.php';

// Días en español para el encabezado (date('l') devuelve inglés)
$diasES  = ['Monday'=>'Lunes','Tuesday'=>'Martes','Wednesday'=>'Miércoles',
            'Thursday'=>'Jueves','Friday'=>'Viernes','Saturday'=>'Sábado','Sunday'=>'Domingo'];
$mesesES = [1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',5=>'mayo',6=>'junio',
            7=>'julio',8=>'agosto',9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre'];
$fechaLarga = $diasES[date('l')] . ' ' . date('j') . ' de ' . $mesesES[(int) date('n')];

/** Iniciales del paciente para el avatar. */
$iniciales = function (string $nombreCompleto): string {
    $partes = preg_split('/[\s,]+/', trim($nombreCompleto));
    $a = mb_substr($partes[0] ?? '', 0, 1);
    $b = mb_substr($partes[1] ?? '', 0, 1);
    return mb_strtoupper($a . $b);
};

/** Color estable del avatar, derivado del id (mismo paciente = mismo color). */
$colorAvatar = function (int $id): string {
    $paletas = [
        ['#3b82f6', '#1e40af'], ['#8b5cf6', '#6d28d9'], ['#ec4899', '#be185d'],
        ['#0ea5e9', '#0369a1'], ['#16a34a', '#15803d'], ['#f59e0b', '#b45309'],
    ];
    [$c1, $c2] = $paletas[$id % count($paletas)];
    return "linear-gradient(135deg, {$c1}, {$c2})";
};

$maxSemana = max(1, max(array_column($semana, 'cantidad')));
$totalSemana = array_sum(array_column($semana, 'cantidad'));
?>

<!-- ── Encabezado ─────────────────────────────────────────── -->
<div class="med-head">
    <div>
        <h2 class="med-titulo">Agenda del día</h2>
        <p class="med-sub"><?= htmlspecialchars($fechaLarga) ?> · <?= (int) $kpis['total_activos'] ?> turno<?= $kpis['total_activos'] === 1 ? '' : 's' ?></p>
    </div>
    <a href="<?= $URL_TURNO ?>?accion=index&fecha=<?= $hoy ?>&matricula=<?= $matricula ?>" class="btn btn-secundario btn-sm">
        Ver todos mis turnos
    </a>
</div>

<!-- ── KPIs del día ───────────────────────────────────────── -->
<div class="kpi-grid">
    <div class="kpi-card">
        <span class="kpi-label">En espera</span>
        <span class="kpi-valor" style="color:var(--amarillo)"><?= (int) $kpis['en_espera'] ?></span>
        <span class="kpi-pie">Confirmados, aguardando atención</span>
    </div>
    <div class="kpi-card">
        <span class="kpi-label">Atendidos</span>
        <span class="kpi-valor" style="color:var(--verde)"><?= (int) $kpis['atendidos'] ?></span>
        <span class="kpi-pie">Consultas ya realizadas</span>
    </div>
    <div class="kpi-card">
        <span class="kpi-label">Pendientes</span>
        <span class="kpi-valor" style="color:var(--azul)"><?= (int) $kpis['pendientes'] ?></span>
        <span class="kpi-pie">Reservados sin confirmar</span>
    </div>
    <div class="kpi-card">
        <span class="kpi-label">Ausentes</span>
        <span class="kpi-valor" style="color:var(--gris)"><?= (int) $kpis['ausentes'] ?></span>
        <span class="kpi-pie">No se presentaron</span>
    </div>
</div>

<div class="med-cols">

    <!-- ── Turnos del día ─────────────────────────────────── -->
    <div class="panel">
        <div class="panel-header">
            <span class="panel-titulo">Turnos de hoy</span>
        </div>
        <div class="panel-body">
            <?php if (empty($agenda)): ?>
                <div class="estado-vacio">
                    <div class="estado-vacio-ico" aria-hidden="true">
                        <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                    </div>
                    <p class="estado-vacio-titulo">No tenés turnos para hoy</p>
                    <p class="estado-vacio-texto">Cuando un paciente reserve con vos, va a aparecer acá.</p>
                </div>
            <?php else: ?>
                <?php foreach ($agenda as $t):
                    $est   = $t['estado'];
                    $esFin = in_array($est, ['Realizado', 'Cancelado', 'Ausente'], true);
                ?>
                <div class="appt <?= $est === 'Confirmado' ? 'appt--activo' : '' ?> <?= $esFin ? 'appt--cerrado' : '' ?>">
                    <div class="appt-hora"><?= substr($t['hora_inicio'], 0, 5) ?></div>

                    <div class="appt-av" style="background:<?= $colorAvatar((int) $t['id_paciente']) ?>" aria-hidden="true">
                        <?= htmlspecialchars($iniciales($t['paciente'])) ?>
                    </div>

                    <div class="appt-info">
                        <div class="appt-nombre">
                            <?= htmlspecialchars($t['paciente']) ?>
                            <span class="badge badge-<?= strtolower($est) ?>"><?= htmlspecialchars($est) ?></span>
                        </div>
                        <div class="appt-meta">
                            <?= $t['edad'] !== null ? (int) $t['edad'] . ' años · ' : '' ?>
                            DNI <?= htmlspecialchars($t['paciente_dni']) ?> ·
                            <?= htmlspecialchars($t['especialidad']) ?> ·
                            <?= htmlspecialchars($t['consultorio']) ?>
                            <?php if (!empty($t['estado_pago'])): ?>
                                · Pago: <?= htmlspecialchars($t['estado_pago']) ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="appt-accion">
                        <?php if (!$esFin): ?>
                            <!-- Acción REAL: marca el turno como Realizado usando la
                                 acción cambiarEstado que ya existe en el controlador. -->
                            <form method="POST" action="<?= $URL_TURNO ?>?accion=cambiarEstado" class="d-inline">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="id_turno" value="<?= (int) $t['id_turno'] ?>">
                                <input type="hidden" name="estado" value="Realizado">
                                <input type="hidden" name="observacion" value="Consulta atendida por el profesional">
                                <button type="submit" class="btn btn-primario btn-sm">Atender</button>
                            </form>
                        <?php else: ?>
                            <button type="button" class="btn btn-secundario btn-sm"
                                    onclick="verHistorialMedico(<?= (int) $t['id_turno'] ?>)">
                                Ver historial
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Carga semanal ──────────────────────────────────── -->
    <div class="panel">
        <div class="panel-header">
            <span class="panel-titulo">Citas de la semana</span>
        </div>
        <div class="panel-body">
            <div class="barras" role="img"
                 aria-label="Turnos por día de esta semana: <?= htmlspecialchars(implode(', ', array_map(fn($d) => $d['dia'] . ' ' . $d['cantidad'], $semana))) ?>">
                <?php foreach ($semana as $d):
                    $hoyDow = (int) date('w') + 1;          // date('w'): 0=Dom → DAYOFWEEK: 1=Dom
                    $esHoy  = ((int) $d['dow'] === $hoyDow);
                    $alto   = $d['cantidad'] > 0 ? max(8, round($d['cantidad'] / $maxSemana * 100)) : 3;
                ?>
                <div class="barra-col">
                    <span class="barra-valor"><?= (int) $d['cantidad'] ?></span>
                    <div class="barra <?= $esHoy ? 'barra--hoy' : '' ?>" style="height:<?= $alto ?>%"></div>
                    <span class="barra-dia"><?= $d['dia'] ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="barras-pie">
                <span>Total de la semana</span>
                <strong><?= (int) $totalSemana ?> turno<?= $totalSemana === 1 ? '' : 's' ?></strong>
            </div>
        </div>
    </div>
</div>

<!-- ── Mis pacientes ──────────────────────────────────────── -->
<div class="panel">
    <div class="panel-header">
        <span class="panel-titulo">Mis pacientes</span>
        <form method="GET" action="" class="med-buscador">
            <input type="hidden" name="q_activo" value="1">
            <label for="q" class="visualmente-oculto">Buscar paciente por nombre o DNI</label>
            <input type="search" name="q" id="q" class="form-control form-control--sm"
                   placeholder="Buscar por nombre o DNI…" value="<?= htmlspecialchars($busqueda) ?>">
            <button type="submit" class="btn btn-secundario btn-sm">Buscar</button>
            <?php if ($busqueda !== ''): ?>
                <a href="<?= BASE_URL ?>dashboard.php" class="btn btn-secundario btn-sm">Limpiar</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="tabla-wrap">
        <table>
            <thead>
                <tr>
                    <th>Paciente</th><th>DNI</th><th>Edad</th>
                    <th>Consultas</th><th>Última consulta</th><th>Contacto</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($pacientes)): ?>
                <tr><td colspan="6" class="td-vacio">
                    <?= $busqueda !== ''
                        ? 'No encontramos pacientes que coincidan con “' . htmlspecialchars($busqueda) . '”.'
                        : 'Todavía no atendiste a ningún paciente.' ?>
                </td></tr>
            <?php else: foreach ($pacientes as $p): ?>
                <tr>
                    <td>
                        <div class="celda-paciente">
                            <span class="mini-av" style="background:<?= $colorAvatar((int) $p['id_paciente']) ?>" aria-hidden="true">
                                <?= htmlspecialchars($iniciales($p['apellido'] . ', ' . $p['nombre'])) ?>
                            </span>
                            <?= htmlspecialchars($p['apellido'] . ', ' . $p['nombre']) ?>
                        </div>
                    </td>
                    <td><?= htmlspecialchars($p['dni']) ?></td>
                    <td><?= $p['edad'] !== null ? (int) $p['edad'] : '—' ?></td>
                    <td><?= (int) $p['consultas'] ?></td>
                    <td class="nowrap"><?= date('d/m/Y', strtotime($p['ultima_consulta'])) ?></td>
                    <td class="texto-meta"><?= htmlspecialchars($p['telefono'] ?: $p['email'] ?: '—') ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal de historial (reutiliza el endpoint accion=historial) -->
<div class="modal-overlay" id="modal-historial-med">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-titulo">Historial del turno</span>
            <button class="btn-cerrar-modal" type="button" aria-label="Cerrar">✕</button>
        </div>
        <div class="modal-body" id="hist-med-cuerpo">
            <p class="texto-cargando">Cargando…</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secundario btn-cerrar-modal">Cerrar</button>
        </div>
    </div>
</div>

<script>
// Historial del turno. Se construye con textContent (NUNCA innerHTML con
// datos de la base) para que una observación con HTML no pueda ejecutarse.
function verHistorialMedico(id) {
    const overlay = document.getElementById('modal-historial-med');
    const cuerpo  = document.getElementById('hist-med-cuerpo');
    overlay.classList.add('abierto');

    const mensaje = (txt, clase) => {
        const p = document.createElement('p');
        p.className = clase;
        p.textContent = txt;
        cuerpo.replaceChildren(p);
    };
    mensaje('Cargando…', 'texto-cargando');

    fetch('<?= $URL_TURNO ?>?accion=historial&id=' + encodeURIComponent(id))
        .then(r => { if (!r.ok) throw new Error('http ' + r.status); return r.json(); })
        .then(data => {
            if (!data.historial || data.historial.length === 0) {
                mensaje('Sin registros en el historial.', 'texto-cargando');
                return;
            }
            const wrap  = document.createElement('div');
            wrap.className = 'tabla-wrap';
            const tabla = document.createElement('table');

            const thead = document.createElement('thead');
            const trh   = document.createElement('tr');
            ['Fecha', 'Desde', 'Hasta', 'Observación'].forEach(t => {
                const th = document.createElement('th');
                th.textContent = t;
                trh.appendChild(th);
            });
            thead.appendChild(trh);
            tabla.appendChild(thead);

            const tbody = document.createElement('tbody');
            data.historial.forEach(h => {
                const tr = document.createElement('tr');
                [h.fecha_cambio, h.estado_anterior ?? '—', h.estado_nuevo, h.observacion ?? '']
                    .forEach(v => {
                        const td = document.createElement('td');
                        td.textContent = v;
                        tr.appendChild(td);
                    });
                tbody.appendChild(tr);
            });
            tabla.appendChild(tbody);
            wrap.appendChild(tabla);
            cuerpo.replaceChildren(wrap);
        })
        .catch(() => mensaje('No se pudo cargar el historial. Intentá de nuevo.', 'texto-error'));
}
</script>

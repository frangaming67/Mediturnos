<?php
// sistema/vistas/ausencias/index.php — Ausencias de médicos
// -----------------------------------------------------------------
// El mensaje de éxito arma el texto "Se cancelaron N turnos" leyendo
// $_GET['n'] (que puso el controlador en el redirect tras registrar()).
// Se eligió pasar ese número por la URL, en vez de recalcularlo acá con
// una consulta nueva, porque el controlador YA lo tiene (es el retorno
// de Ausencia::registrar()) — pedirlo de nuevo sería una consulta
// redundante solo para mostrar un número que ya se conocía.
// -----------------------------------------------------------------
$paginaTitulo = 'Ausencias de médicos';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / Ausencias';
require __DIR__ . '/../layouts/navbar.php';

$BASEC = BASE_URL . 'sistema/controladores/ControladorAusencia.php';
$matriculaSel = (int)($_GET['matricula'] ?? 0);

// Mensajes de operación (patrón PRG)
$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';
$n   = (int)($_GET['n'] ?? 0);

$mensajes = [
    'registrada' => 'Ausencia registrada. Se cancelaron ' . $n . ' turno' . ($n === 1 ? '' : 's') . ' de ese día.',
    'eliminada'  => 'Ausencia deshecha. El día vuelve a estar disponible (los turnos ya cancelados no se reactivan).',
];
?>

<?php if ($msg && isset($mensajes[$msg])): ?>
<div class="alerta alerta-exito"><?= htmlspecialchars($mensajes[$msg]) ?></div>
<?php endif; ?>
<?php if ($err): ?>
<div class="alerta alerta-error"><?= htmlspecialchars(urldecode($err)) ?></div>
<?php endif; ?>

<div class="alerta alerta-info mb-20">
    Si un médico no va a atender un día, registrá la ausencia acá: se
    <strong>cancelan automáticamente</strong> los turnos de ese día y el
    día <strong>deja de ofrecerse</strong> como disponible a los pacientes.
</div>

<div class="panel">
    <!-- Filtro por médico -->
    <form method="GET" action="">
        <input type="hidden" name="accion" value="index">
        <div class="filtros-bar">
            <div class="form-group">
                <label>Médico</label>
                <select name="matricula" class="form-control">
                    <option value="0">— Todos —</option>
                    <?php foreach ($medicos as $m): ?>
                    <option value="<?= $m['matricula'] ?>" <?= $matriculaSel == $m['matricula'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($m['nombre_completo']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group form-group--fin">
                <label>&nbsp;</label>
                <div class="btn-grupo">
                    <button type="submit" class="btn btn-primario btn-sm">Filtrar</button>
                    <a href="?accion=index" class="btn btn-secundario btn-sm">Limpiar</a>
                </div>
            </div>
        </div>
    </form>

    <div class="panel-header panel-header--sep">
        <span class="panel-titulo"><?= count($ausencias) ?> ausencia(s)</span>
        <button class="btn btn-primario btn-sm" data-modal="modal-ausencia">+ Registrar ausencia</button>
    </div>

    <div class="tabla-wrap">
        <table>
            <thead>
                <tr><th>Fecha</th><th>Médico</th><th>Motivo</th><th>Cargada por</th><th>Registrada</th><th>Acciones</th></tr>
            </thead>
            <tbody>
            <?php if (empty($ausencias)): ?>
                <tr><td colspan="6" class="td-vacio">No hay ausencias registradas.</td></tr>
            <?php else: foreach ($ausencias as $a): ?>
                <tr>
                    <td class="nowrap"><?= date('d/m/Y', strtotime($a['fecha'])) ?></td>
                    <td><?= htmlspecialchars($a['medico']) ?></td>
                    <td><?= htmlspecialchars($a['motivo'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($a['cargado_por'] ?? '—') ?></td>
                    <td class="nowrap"><small class="texto-gris"><?= htmlspecialchars($a['fecha_registro']) ?></small></td>
                    <td class="nowrap">
                        <button class="btn-icono peligro"
                            data-modal="modal-deshacer"
                            onclick="document.getElementById('deshacerId').value='<?= $a['id_ausencia'] ?>';document.getElementById('deshacerInfo').textContent='<?= htmlspecialchars($a['medico'], ENT_QUOTES) ?> — <?= date('d/m/Y', strtotime($a['fecha'])) ?>';"
                            title="Deshacer ausencia">
                            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"/></svg>
                        </button>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal registrar ausencia -->
<div class="modal-overlay" id="modal-ausencia">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-titulo">Registrar ausencia de un médico</span>
            <button class="btn-cerrar-modal">✕</button>
        </div>
        <form method="POST" action="<?= $BASEC ?>?accion=registrar">
            <?php csrf_field(); ?>
            <div class="modal-body">
                <div class="form-group mb-14">
                    <label>Médico <span class="req">*</span></label>
                    <select name="matricula" class="form-control" required>
                        <option value="">— Seleccioná —</option>
                        <?php foreach ($medicos as $m): ?>
                        <option value="<?= $m['matricula'] ?>" <?= $matriculaSel == $m['matricula'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($m['nombre_completo']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group mb-14">
                    <label>Fecha <span class="req">*</span></label>
                    <input type="date" name="fecha" class="form-control"
                           value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label>Motivo</label>
                    <input type="text" name="motivo" class="form-control" placeholder="Ej: Licencia médica, congreso, etc.">
                </div>
                <p class="texto-ayuda mt-12">
                    Al confirmar se cancelarán los turnos activos de ese médico
                    para ese día y dejará de ofrecerse como disponible.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secundario btn-cerrar-modal">Volver</button>
                <button type="submit" class="btn btn-peligro">Registrar y cancelar turnos</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal deshacer ausencia -->
<div class="modal-overlay" id="modal-deshacer">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-titulo">Deshacer ausencia</span>
            <button class="btn-cerrar-modal">✕</button>
        </div>
        <form method="POST" action="<?= $BASEC ?>?accion=eliminar">
            <?php csrf_field(); ?>
            <div class="modal-body">
                <input type="hidden" name="id_ausencia" id="deshacerId">
                <p>¿Deshacer la ausencia de <strong id="deshacerInfo"></strong>?</p>
                <p class="texto-ayuda mt-12">
                    El día vuelve a estar disponible para reservar. Los turnos
                    que ya se cancelaron NO se reactivan automáticamente.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secundario btn-cerrar-modal">Volver</button>
                <button type="submit" class="btn btn-primario">Deshacer</button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../layouts/footer.php'; ?>

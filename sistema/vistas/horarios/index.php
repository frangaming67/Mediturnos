<?php
// sistema/vistas/horarios/index.php — mismo patrón ABM que medicos/ (ver ahí el detalle)
$paginaTitulo = 'Horarios de atención';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / Horarios';
require __DIR__ . '/../layouts/navbar.php';

$BASEC = BASE_URL . 'sistema/controladores/ControladorHorario.php';
$msgs = ['creado' => 'Horario creado.', 'actualizado' => 'Horario actualizado.'];
$msg  = $_GET['msg'] ?? '';
$matriculaSel = (int)($_GET['matricula'] ?? 0);
?>

<?php if ($msg && isset($msgs[$msg])): ?>
<div class="alerta alerta-exito"><?= $msgs[$msg] ?></div>
<?php endif; ?>

<div class="panel">
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
        <span class="panel-titulo"><?= count($horarios) ?> horario(s)</span>
        <a href="<?= $BASEC ?>?accion=nuevo" class="btn btn-primario btn-sm">+ Nuevo horario</a>
    </div>

    <div class="tabla-wrap">
        <table>
            <thead>
                <tr><th>Médico</th><th>Día</th><th>Desde</th><th>Hasta</th><th>Especialidad</th><th>Consultorio</th><th>Acciones</th></tr>
            </thead>
            <tbody>
            <?php if (empty($horarios)): ?>
                <tr><td colspan="7" class="td-vacio">No hay horarios cargados.</td></tr>
            <?php else: foreach ($horarios as $h): ?>
                <tr>
                    <td><?= htmlspecialchars($h['medico']) ?></td>
                    <td><?= htmlspecialchars($h['dia_semana']) ?></td>
                    <td><?= substr($h['hora_inicio'], 0, 5) ?></td>
                    <td><?= substr($h['hora_fin'], 0, 5) ?></td>
                    <td><?= htmlspecialchars($h['especialidad']) ?></td>
                    <td><?= htmlspecialchars($h['consultorio']) ?></td>
                    <td class="nowrap">
                        <a href="<?= $BASEC ?>?accion=editar&id=<?= $h['id_horario'] ?>" class="btn-icono" title="Editar">
                            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../layouts/footer.php'; ?>

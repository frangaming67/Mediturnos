<?php
// sistema/vistas/obras/plan_editar.php — ver el motivo del prefijo "plan_" en plan_nuevo.php
$paginaTitulo = 'Editar plan';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / <a href="' . BASE_URL . 'sistema/controladores/ControladorObraSocial.php?accion=index">Obras sociales</a> / Editar plan';
require __DIR__ . '/../layouts/navbar.php';
?>

<?php if ($mensaje): ?>
<div class="alerta alerta-<?= $tipoMsg==='error'?'error':'exito' ?>"><?= htmlspecialchars($mensaje) ?></div>
<?php endif; ?>

<div class="panel panel-sm">
    <div class="panel-header"><span class="panel-titulo">Editar plan #<?= $plan['id_plan'] ?></span></div>
    <div class="panel-body">
        <form method="POST" action="<?= BASE_URL ?>sistema/controladores/ControladorObraSocial.php?accion=actualizarPlan" novalidate>
            <?php csrf_field(); ?>
            <input type="hidden" name="id" value="<?= $plan['id_plan'] ?>">
            <div class="form-grid mb-18">
                <div class="form-group">
                    <label>Obra social <span class="req">*</span></label>
                    <select name="id_obra_social" class="form-control" required>
                        <option value="">— Seleccioná —</option>
                        <?php foreach ($obras as $o): ?>
                        <option value="<?= $o['id_obra_social'] ?>"
                            <?= ($_POST['id_obra_social'] ?? $plan['id_obra_social']) == $o['id_obra_social'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($o['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Nombre del plan <span class="req">*</span></label>
                    <input type="text" name="nombre_plan" class="form-control" required maxlength="100"
                        value="<?= htmlspecialchars($_POST['nombre_plan'] ?? $plan['nombre_plan']) ?>">
                </div>
                <div class="form-group">
                    <label>Cobertura (%)</label>
                    <input type="number" name="porcentaje_cobertura" class="form-control"
                        min="0" max="100" step="0.01"
                        value="<?= htmlspecialchars($_POST['porcentaje_cobertura'] ?? ($plan['porcentaje_cobertura'] ?? '')) ?>">
                </div>
            </div>
            <div class="form-acciones">
                <button type="submit" class="btn btn-primario">Actualizar</button>
                <a href="<?= BASE_URL ?>sistema/controladores/ControladorObraSocial.php?accion=index" class="btn btn-secundario">Cancelar</a>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../layouts/footer.php'; ?>

<?php
// sistema/vistas/obras/plan_nuevo.php
// -----------------------------------------------------------------
// Es un archivo aparte de obras/nuevo.php (y no un solo formulario con
// un toggle "¿es plan u obra social?") porque son dos entidades con
// campos totalmente distintos (nombre+CUIT vs. obra_social+nombre_plan+
// cobertura); nombrarlos "plan_nuevo"/"plan_editar" con el prefijo
// "plan_" alcanza para dejar clara la relación con obra social sin
// anidar una subcarpeta extra.
// -----------------------------------------------------------------
$paginaTitulo = 'Nuevo plan';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / <a href="' . BASE_URL . 'sistema/controladores/ControladorObraSocial.php?accion=index">Obras sociales</a> / Nuevo plan';
require __DIR__ . '/../layouts/navbar.php';
?>

<?php if ($mensaje): ?>
<div class="alerta alerta-<?= $tipoMsg==='error'?'error':'exito' ?>"><?= htmlspecialchars($mensaje) ?></div>
<?php endif; ?>

<div class="panel panel-sm">
    <div class="panel-header"><span class="panel-titulo">Registrar plan</span></div>
    <div class="panel-body">
        <form method="POST" action="<?= BASE_URL ?>sistema/controladores/ControladorObraSocial.php?accion=guardarPlan" novalidate>
            <?php csrf_field(); ?>
            <div class="form-grid mb-18">
                <div class="form-group">
                    <label>Obra social <span class="req">*</span></label>
                    <select name="id_obra_social" class="form-control" required>
                        <option value="">— Seleccioná —</option>
                        <?php foreach ($obras as $o): ?>
                        <option value="<?= $o['id_obra_social'] ?>"
                            <?= ($_POST['id_obra_social'] ?? '') == $o['id_obra_social'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($o['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Nombre del plan <span class="req">*</span></label>
                    <input type="text" name="nombre_plan" class="form-control" required maxlength="100"
                        value="<?= htmlspecialchars($_POST['nombre_plan'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Cobertura (%)</label>
                    <input type="number" name="porcentaje_cobertura" class="form-control"
                        min="0" max="100" step="0.01"
                        value="<?= htmlspecialchars($_POST['porcentaje_cobertura'] ?? '') ?>">
                </div>
            </div>
            <div class="form-acciones">
                <button type="submit" class="btn btn-primario">Guardar</button>
                <a href="<?= BASE_URL ?>sistema/controladores/ControladorObraSocial.php?accion=index" class="btn btn-secundario">Cancelar</a>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../layouts/footer.php'; ?>

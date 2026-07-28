<?php
// sistema/vistas/consultorios/editar.php — mismo patrón ABM que medicos/ (ver ahí el detalle)
$paginaTitulo = 'Editar consultorio';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / <a href="' . BASE_URL . 'sistema/controladores/ControladorConsultorio.php?accion=index">Consultorios</a> / Editar';
require __DIR__ . '/../layouts/navbar.php';
?>

<?php if ($mensaje): ?>
<div class="alerta alerta-<?= $tipoMsg==='error'?'error':'exito' ?>"><?= htmlspecialchars($mensaje) ?></div>
<?php endif; ?>

<div class="panel panel-sm">
    <div class="panel-header"><span class="panel-titulo">Editar consultorio #<?= $consultorio['id_consultorio'] ?></span></div>
    <div class="panel-body">
        <form method="POST" action="<?= BASE_URL ?>sistema/controladores/ControladorConsultorio.php?accion=actualizar" novalidate>
            <?php csrf_field(); ?>
            <input type="hidden" name="id" value="<?= $consultorio['id_consultorio'] ?>">
            <div class="form-grid mb-18">
                <div class="form-group">
                    <label>Número <span class="req">*</span></label>
                    <input type="number" name="numero" class="form-control" required min="1"
                        value="<?= htmlspecialchars($_POST['numero'] ?? $consultorio['numero']) ?>">
                </div>
                <div class="form-group">
                    <label>Piso <span class="req">*</span></label>
                    <input type="number" name="piso" class="form-control" required min="0"
                        value="<?= htmlspecialchars($_POST['piso'] ?? $consultorio['piso']) ?>">
                </div>
                <div class="form-group form-group--full">
                    <label>Equipamiento</label>
                    <textarea name="descripcion_equipamiento" class="form-control" rows="4" maxlength="600"><?= htmlspecialchars($_POST['descripcion_equipamiento'] ?? ($consultorio['descripcion_equipamiento'] ?? '')) ?></textarea>
                </div>
            </div>
            <div class="form-acciones">
                <button type="submit" class="btn btn-primario">Actualizar</button>
                <a href="<?= BASE_URL ?>sistema/controladores/ControladorConsultorio.php?accion=index" class="btn btn-secundario">Cancelar</a>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../layouts/footer.php'; ?>

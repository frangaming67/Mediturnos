<?php
// sistema/vistas/obras/nuevo.php — ABM de obra social; ver obras/index.php para el contexto general
$paginaTitulo = 'Nueva obra social';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / <a href="' . BASE_URL . 'sistema/controladores/ControladorObraSocial.php?accion=index">Obras sociales</a> / Nueva';
require __DIR__ . '/../layouts/navbar.php';
?>

<?php if ($mensaje): ?>
<div class="alerta alerta-<?= $tipoMsg==='error'?'error':'exito' ?>"><?= htmlspecialchars($mensaje) ?></div>
<?php endif; ?>

<div class="panel panel-sm">
    <div class="panel-header"><span class="panel-titulo">Registrar obra social</span></div>
    <div class="panel-body">
        <form method="POST" action="<?= BASE_URL ?>sistema/controladores/ControladorObraSocial.php?accion=guardar" novalidate>
            <?php csrf_field(); ?>
            <div class="form-grid mb-18">
                <div class="form-group">
                    <label>Nombre <span class="req">*</span></label>
                    <input type="text" name="nombre" class="form-control" required maxlength="30"
                        value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>CUIT <span class="req">*</span></label>
                    <input type="text" name="cuit" class="form-control" required maxlength="20"
                        placeholder="30-12345678-9"
                        value="<?= htmlspecialchars($_POST['cuit'] ?? '') ?>">
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

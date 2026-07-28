<?php
// sistema/vistas/obras/editar.php — ABM de obra social; ver obras/index.php para el contexto general
$paginaTitulo = 'Editar obra social';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / <a href="' . BASE_URL . 'sistema/controladores/ControladorObraSocial.php?accion=index">Obras sociales</a> / Editar';
require __DIR__ . '/../layouts/navbar.php';
?>

<?php if ($mensaje): ?>
<div class="alerta alerta-<?= $tipoMsg==='error'?'error':'exito' ?>"><?= htmlspecialchars($mensaje) ?></div>
<?php endif; ?>

<div class="panel panel-sm">
    <div class="panel-header"><span class="panel-titulo">Editar obra social #<?= $obra['id_obra_social'] ?></span></div>
    <div class="panel-body">
        <form method="POST" action="<?= BASE_URL ?>sistema/controladores/ControladorObraSocial.php?accion=actualizar" novalidate>
            <?php csrf_field(); ?>
            <input type="hidden" name="id" value="<?= $obra['id_obra_social'] ?>">
            <div class="form-grid mb-18">
                <div class="form-group">
                    <label>Nombre <span class="req">*</span></label>
                    <input type="text" name="nombre" class="form-control" required maxlength="30"
                        value="<?= htmlspecialchars($_POST['nombre'] ?? $obra['nombre']) ?>">
                </div>
                <div class="form-group">
                    <label>CUIT <span class="req">*</span></label>
                    <input type="text" name="cuit" class="form-control" required maxlength="20"
                        value="<?= htmlspecialchars($_POST['cuit'] ?? $obra['cuit']) ?>">
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

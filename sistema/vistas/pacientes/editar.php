<?php
// sistema/vistas/pacientes/editar.php — mismo patrón ABM que medicos/ (ver ahí el detalle)
$paginaTitulo = 'Editar paciente';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / <a href="' . BASE_URL . 'sistema/controladores/ControladorPaciente.php?accion=index">Pacientes</a> / Editar';
require __DIR__ . '/../layouts/navbar.php';
?>

<?php if ($mensaje): ?>
<div class="alerta alerta-<?= $tipoMsg==='error'?'error':'exito' ?>"><?= htmlspecialchars($mensaje) ?></div>
<?php endif; ?>

<div class="panel panel-sm">
    <div class="panel-header"><span class="panel-titulo">Editar paciente #<?= $paciente['id_paciente'] ?></span></div>
    <div class="panel-body">
        <form method="POST" action="<?= BASE_URL ?>sistema/controladores/ControladorPaciente.php?accion=actualizar" novalidate>
            <?php csrf_field(); ?>
            <input type="hidden" name="id" value="<?= $paciente['id_paciente'] ?>">
            <div class="form-grid mb-18">
                <div class="form-group">
                    <label>DNI <span class="req">*</span></label>
                    <input type="text" name="dni" class="form-control" required maxlength="20"
                        value="<?= htmlspecialchars($_POST['dni'] ?? $paciente['dni']) ?>">
                </div>
                <div class="form-group">
                    <label>Nombre <span class="req">*</span></label>
                    <input type="text" name="nombre" class="form-control" required maxlength="80"
                        value="<?= htmlspecialchars($_POST['nombre'] ?? $paciente['nombre']) ?>">
                </div>
                <div class="form-group">
                    <label>Apellido <span class="req">*</span></label>
                    <input type="text" name="apellido" class="form-control" required maxlength="80"
                        value="<?= htmlspecialchars($_POST['apellido'] ?? $paciente['apellido']) ?>">
                </div>
                <div class="form-group">
                    <label>Fecha de nacimiento</label>
                    <input type="date" name="fecha_nac" class="form-control"
                        value="<?= htmlspecialchars($_POST['fecha_nac'] ?? ($paciente['fecha_nac'] ?? '')) ?>">
                </div>
                <div class="form-group">
                    <label>Teléfono</label>
                    <input type="text" name="telefono" class="form-control" maxlength="30"
                        value="<?= htmlspecialchars($_POST['telefono'] ?? ($paciente['telefono'] ?? '')) ?>">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" class="form-control" maxlength="120"
                        value="<?= htmlspecialchars($_POST['email'] ?? ($paciente['email'] ?? '')) ?>">
                </div>
            </div>
            <div class="form-acciones">
                <button type="submit" class="btn btn-primario">Actualizar</button>
                <a href="<?= BASE_URL ?>sistema/controladores/ControladorPaciente.php?accion=index" class="btn btn-secundario">Cancelar</a>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../layouts/footer.php'; ?>

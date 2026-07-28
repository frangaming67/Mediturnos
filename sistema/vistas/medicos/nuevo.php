<?php
// sistema/vistas/medicos/nuevo.php — ver el patrón ABM explicado en medicos/index.php
// Los checkboxes de especialidades postean como especialidades[] (array)
// porque un médico puede tener varias a la vez (relación N:M); el modelo
// (Medico::asignarEspecialidades) es quien resuelve esa lista contra la
// tabla intermedia medico_especialidad.
$paginaTitulo = 'Nuevo médico';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / <a href="' . BASE_URL . 'sistema/controladores/ControladorMedico.php?accion=index">Médicos</a> / Nuevo';
require __DIR__ . '/../layouts/navbar.php';
?>

<?php if ($mensaje): ?>
<div class="alerta alerta-<?= $tipoMsg==='error'?'error':'exito' ?>"><?= htmlspecialchars($mensaje) ?></div>
<?php endif; ?>

<div class="panel panel-sm">
    <div class="panel-header"><span class="panel-titulo">Registrar médico</span></div>
    <div class="panel-body">
        <form method="POST" action="<?= BASE_URL ?>sistema/controladores/ControladorMedico.php?accion=guardar" novalidate>
            <?php csrf_field(); ?>
            <div class="form-grid mb-18">
                <div class="form-group">
                    <label>Matrícula <span class="req">*</span></label>
                    <input type="number" name="matricula" class="form-control" required
                        value="<?= htmlspecialchars($_POST['matricula'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Nombre <span class="req">*</span></label>
                    <input type="text" name="nombre" class="form-control" required maxlength="80"
                        value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Apellido <span class="req">*</span></label>
                    <input type="text" name="apellido" class="form-control" required maxlength="80"
                        value="<?= htmlspecialchars($_POST['apellido'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Teléfono</label>
                    <input type="text" name="telefono" class="form-control" maxlength="30"
                        value="<?= htmlspecialchars($_POST['telefono'] ?? '') ?>">
                </div>
                <div class="form-group col-full">
                    <label>Email</label>
                    <input type="email" name="email" class="form-control" maxlength="120"
                        value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>
            </div>

            <div class="form-group mb-18">
                <label>Especialidades</label>
                <div class="checkbox-group">
                    <?php foreach($especialidades as $e): ?>
                    <label>
                        <input type="checkbox" name="especialidades[]" value="<?= $e['id_especialidad'] ?>"
                            <?= in_array($e['id_especialidad'], $_POST['especialidades'] ?? []) ? 'checked' : '' ?>>
                        <span><?= htmlspecialchars($e['nombre']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-acciones">
                <button type="submit" class="btn btn-primario">Guardar</button>
                <a href="<?= BASE_URL ?>sistema/controladores/ControladorMedico.php?accion=index" class="btn btn-secundario">Cancelar</a>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../layouts/footer.php'; ?>

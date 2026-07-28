<?php
// sistema/vistas/medicos/editar.php — ver el patrón ABM explicado en medicos/index.php
// $_POST['x'] ?? $medico['x'] en cada value: si el form vuelve por un
// error de validación, se repuebla con lo que el usuario tipeó
// ($_POST); si es la primera carga, con lo que ya está en la base
// ($medico). Ese orden importa: al revés, un error de validación
// perdería lo que el usuario acababa de escribir.
$paginaTitulo = 'Editar médico';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / <a href="' . BASE_URL . 'sistema/controladores/ControladorMedico.php?accion=index">Médicos</a> / Editar';
require __DIR__ . '/../layouts/navbar.php';
?>

<?php if ($mensaje): ?>
<div class="alerta alerta-<?= $tipoMsg==='error'?'error':'exito' ?>"><?= htmlspecialchars($mensaje) ?></div>
<?php endif; ?>

<div class="panel panel-sm">
    <div class="panel-header"><span class="panel-titulo">Editar médico — Matrícula <?= $medico['matricula'] ?></span></div>
    <div class="panel-body">
        <form method="POST" action="<?= BASE_URL ?>sistema/controladores/ControladorMedico.php?accion=actualizar" novalidate>
            <?php csrf_field(); ?>
            <input type="hidden" name="matricula" value="<?= $medico['matricula'] ?>">
            <div class="form-grid mb-18">
                <div class="form-group">
                    <label>Nombre <span class="req">*</span></label>
                    <input type="text" name="nombre" class="form-control" required
                        value="<?= htmlspecialchars($_POST['nombre'] ?? $medico['nombre']) ?>">
                </div>
                <div class="form-group">
                    <label>Apellido <span class="req">*</span></label>
                    <input type="text" name="apellido" class="form-control" required
                        value="<?= htmlspecialchars($_POST['apellido'] ?? $medico['apellido']) ?>">
                </div>
                <div class="form-group">
                    <label>Teléfono</label>
                    <input type="text" name="telefono" class="form-control"
                        value="<?= htmlspecialchars($_POST['telefono'] ?? ($medico['telefono'] ?? '')) ?>">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" class="form-control"
                        value="<?= htmlspecialchars($_POST['email'] ?? ($medico['email'] ?? '')) ?>">
                </div>
            </div>

            <div class="form-group mb-18">
                <label>Especialidades</label>
                <div class="checkbox-group">
                    <?php
                    $espAsignadas = $_POST['especialidades'] ?? $medico['especialidades'];
                    foreach($especialidades as $e):
                    ?>
                    <label>
                        <input type="checkbox" name="especialidades[]" value="<?= $e['id_especialidad'] ?>"
                            <?= in_array($e['id_especialidad'], $espAsignadas) ? 'checked' : '' ?>>
                        <span><?= htmlspecialchars($e['nombre']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-acciones">
                <button type="submit" class="btn btn-primario">Actualizar</button>
                <a href="<?= BASE_URL ?>sistema/controladores/ControladorMedico.php?accion=index" class="btn btn-secundario">Cancelar</a>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../layouts/footer.php'; ?>

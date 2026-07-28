<?php
// sistema/vistas/horarios/nuevo.php — mismo patrón ABM que medicos/ (ver ahí el detalle)
// El <select> de días recorre Horario::DIAS (la constante del modelo) en
// vez de tener el array de días copiado acá en la vista: si el día se
// escribe distinto en algún lado, se rompe el filtro contra la base;
// tener una sola fuente de verdad evita ese desalineamiento.
$paginaTitulo = 'Nuevo horario';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / <a href="' . BASE_URL . 'sistema/controladores/ControladorHorario.php?accion=index">Horarios</a> / Nuevo';
require __DIR__ . '/../layouts/navbar.php';
?>

<?php if ($mensaje): ?>
<div class="alerta alerta-<?= $tipoMsg==='error'?'error':'exito' ?>"><?= htmlspecialchars($mensaje) ?></div>
<?php endif; ?>

<div class="panel panel-sm">
    <div class="panel-header"><span class="panel-titulo">Registrar horario de atención</span></div>
    <div class="panel-body">
        <form method="POST" action="<?= BASE_URL ?>sistema/controladores/ControladorHorario.php?accion=guardar" novalidate>
            <?php csrf_field(); ?>
            <div class="form-grid mb-18">
                <div class="form-group">
                    <label>Médico <span class="req">*</span></label>
                    <select name="matricula" class="form-control" required>
                        <option value="">— Seleccioná —</option>
                        <?php foreach ($medicos as $m): ?>
                        <option value="<?= $m['matricula'] ?>" <?= ($_POST['matricula'] ?? '') == $m['matricula'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($m['nombre_completo']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Especialidad <span class="req">*</span></label>
                    <select name="id_especialidad" class="form-control" required>
                        <option value="">— Seleccioná —</option>
                        <?php foreach ($especialidades as $e): ?>
                        <option value="<?= $e['id_especialidad'] ?>" <?= ($_POST['id_especialidad'] ?? '') == $e['id_especialidad'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($e['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Día <span class="req">*</span></label>
                    <select name="dia_semana" class="form-control" required>
                        <option value="">— Seleccioná —</option>
                        <?php foreach (Horario::DIAS as $d): ?>
                        <option value="<?= $d ?>" <?= ($_POST['dia_semana'] ?? '') === $d ? 'selected' : '' ?>><?= $d ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Consultorio <span class="req">*</span></label>
                    <select name="id_consultorio" class="form-control" required>
                        <option value="">— Seleccioná —</option>
                        <?php foreach ($consultorios as $c): ?>
                        <option value="<?= $c['id_consultorio'] ?>" <?= ($_POST['id_consultorio'] ?? '') == $c['id_consultorio'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Hora inicio <span class="req">*</span></label>
                    <input type="time" name="hora_inicio" class="form-control" required
                        value="<?= htmlspecialchars($_POST['hora_inicio'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Hora fin <span class="req">*</span></label>
                    <input type="time" name="hora_fin" class="form-control" required
                        value="<?= htmlspecialchars($_POST['hora_fin'] ?? '') ?>">
                </div>
            </div>
            <div class="form-acciones">
                <button type="submit" class="btn btn-primario">Guardar</button>
                <a href="<?= BASE_URL ?>sistema/controladores/ControladorHorario.php?accion=index" class="btn btn-secundario">Cancelar</a>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../layouts/footer.php'; ?>

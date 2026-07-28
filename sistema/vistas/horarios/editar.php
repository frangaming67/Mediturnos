<?php
// sistema/vistas/horarios/editar.php — mismo patrón ABM que medicos/ (ver ahí el detalle)
$paginaTitulo = 'Editar horario';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / <a href="' . BASE_URL . 'sistema/controladores/ControladorHorario.php?accion=index">Horarios</a> / Editar';
require __DIR__ . '/../layouts/navbar.php';
?>

<?php if ($mensaje): ?>
<div class="alerta alerta-<?= $tipoMsg==='error'?'error':'exito' ?>"><?= htmlspecialchars($mensaje) ?></div>
<?php endif; ?>

<div class="panel panel-sm">
    <div class="panel-header"><span class="panel-titulo">Editar horario #<?= $horario['id_horario'] ?></span></div>
    <div class="panel-body">
        <form method="POST" action="<?= BASE_URL ?>sistema/controladores/ControladorHorario.php?accion=actualizar" novalidate>
            <?php csrf_field(); ?>
            <input type="hidden" name="id" value="<?= $horario['id_horario'] ?>">
            <div class="form-grid mb-18">
                <div class="form-group">
                    <label>Médico <span class="req">*</span></label>
                    <select name="matricula" class="form-control" required>
                        <option value="">— Seleccioná —</option>
                        <?php foreach ($medicos as $m): ?>
                        <option value="<?= $m['matricula'] ?>" <?= ($_POST['matricula'] ?? $horario['matricula']) == $m['matricula'] ? 'selected' : '' ?>>
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
                        <option value="<?= $e['id_especialidad'] ?>" <?= ($_POST['id_especialidad'] ?? $horario['id_especialidad']) == $e['id_especialidad'] ? 'selected' : '' ?>>
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
                        <option value="<?= $d ?>" <?= ($_POST['dia_semana'] ?? $horario['dia_semana']) === $d ? 'selected' : '' ?>><?= $d ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Consultorio <span class="req">*</span></label>
                    <select name="id_consultorio" class="form-control" required>
                        <option value="">— Seleccioná —</option>
                        <?php foreach ($consultorios as $c): ?>
                        <option value="<?= $c['id_consultorio'] ?>" <?= ($_POST['id_consultorio'] ?? $horario['id_consultorio']) == $c['id_consultorio'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Hora inicio <span class="req">*</span></label>
                    <input type="time" name="hora_inicio" class="form-control" required
                        value="<?= htmlspecialchars($_POST['hora_inicio'] ?? substr($horario['hora_inicio'],0,5)) ?>">
                </div>
                <div class="form-group">
                    <label>Hora fin <span class="req">*</span></label>
                    <input type="time" name="hora_fin" class="form-control" required
                        value="<?= htmlspecialchars($_POST['hora_fin'] ?? substr($horario['hora_fin'],0,5)) ?>">
                </div>
            </div>
            <div class="form-acciones">
                <button type="submit" class="btn btn-primario">Actualizar</button>
                <a href="<?= BASE_URL ?>sistema/controladores/ControladorHorario.php?accion=index" class="btn btn-secundario">Cancelar</a>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../layouts/footer.php'; ?>

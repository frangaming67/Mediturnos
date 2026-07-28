<?php
// sistema/vistas/usuarios/nuevo.php — mismo patrón ABM que medicos/ (ver ahí el detalle)
// Los campos "ID paciente" / "Matrícula médico" vinculado quedan sueltos
// (sin JS que los muestre/oculte según el rol elegido) a propósito: es
// más simple mostrar los dos siempre y que el admin solo llene el que
// corresponda, en vez de sumar JavaScript para un formulario que usa
// el admin (no un usuario final) y rara vez.
$paginaTitulo = 'Nuevo usuario';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / <a href="' . BASE_URL . 'sistema/controladores/ControladorUsuario.php?accion=index">Usuarios</a> / Nuevo';
require __DIR__ . '/../layouts/navbar.php';
?>

<?php if ($mensaje): ?>
<div class="alerta alerta-<?= $tipoMsg==='error'?'error':'exito' ?>"><?= htmlspecialchars($mensaje) ?></div>
<?php endif; ?>

<div class="panel panel-sm">
    <div class="panel-header"><span class="panel-titulo">Crear usuario</span></div>
    <div class="panel-body">
        <form method="POST" action="<?= BASE_URL ?>sistema/controladores/ControladorUsuario.php?accion=guardar" novalidate>
            <?php csrf_field(); ?>
            <div class="form-grid mb-18">
                <div class="form-group">
                    <label>Nombre <span class="req">*</span></label>
                    <input type="text" name="nombre" class="form-control" required
                        value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Apellido <span class="req">*</span></label>
                    <input type="text" name="apellido" class="form-control" required
                        value="<?= htmlspecialchars($_POST['apellido'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Nombre de usuario <span class="req">*</span></label>
                    <input type="text" name="usuario" class="form-control" required autocomplete="off"
                        value="<?= htmlspecialchars($_POST['usuario'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Email <span class="req">*</span></label>
                    <input type="email" name="email" class="form-control" required
                        value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Contraseña <span class="req">*</span></label>
                    <input type="password" name="contrasenia" class="form-control" required autocomplete="new-password">
                    <span class="form-hint">Mínimo 8 caracteres recomendados</span>
                </div>
                <div class="form-group">
                    <label>Rol <span class="req">*</span></label>
                    <select name="id_rol" class="form-control" required>
                        <option value="">— Seleccioná —</option>
                        <?php foreach($roles as $r): ?>
                        <option value="<?= $r['id_rol'] ?>"
                            <?= ($_POST['id_rol'] ?? '') == $r['id_rol'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($r['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>ID paciente vinculado</label>
                    <input type="number" name="id_paciente" class="form-control"
                        value="<?= htmlspecialchars($_POST['id_paciente'] ?? '') ?>">
                    <span class="form-hint">Solo si el rol es Paciente</span>
                </div>
                <div class="form-group">
                    <label>Matrícula médico vinculado</label>
                    <input type="number" name="matricula" class="form-control"
                        value="<?= htmlspecialchars($_POST['matricula'] ?? '') ?>">
                    <span class="form-hint">Solo si el rol es Médico</span>
                </div>
            </div>
            <div class="form-acciones">
                <button type="submit" class="btn btn-primario">Crear usuario</button>
                <a href="<?= BASE_URL ?>sistema/controladores/ControladorUsuario.php?accion=index" class="btn btn-secundario">Cancelar</a>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../layouts/footer.php'; ?>

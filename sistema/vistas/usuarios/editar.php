<?php
// sistema/vistas/usuarios/editar.php — mismo patrón ABM que medicos/ (ver ahí el detalle)
// No tiene campo de contraseña: cambiarla es una operación sensible
// aparte (requeriría su propio flujo de confirmación) que este proyecto
// no implementa; Usuario::actualizar() ni siquiera toca esa columna.
$paginaTitulo = 'Editar usuario';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / <a href="' . BASE_URL . 'sistema/controladores/ControladorUsuario.php?accion=index">Usuarios</a> / Editar';
require __DIR__ . '/../layouts/navbar.php';
// $usuario ?? [] es una defensa contra el caso borde en que buscarPorId()
// no encuentre el registro (ver ControladorUsuario: ahí ya se redirige
// si $usuario es false, así que en la práctica esta vista siempre recibe
// datos válidos, pero se deja explícito para que la vista no dependa
// ciegamente de esa garantía externa).
$usuario = $usuario ?? [];
$mensaje = $mensaje ?? null;
$tipoMsg = $tipoMsg ?? null;
?>

<?php if (!empty($mensaje)): ?>
<div class="alerta alerta-<?= $tipoMsg==='error'?'error':'exito' ?>">
    <?= htmlspecialchars($mensaje) ?>
</div>
<?php endif; ?>

<div class="panel panel-sm">
        <div class="panel-header">
            <span class="panel-titulo">
                Editar usuario:
                <?= htmlspecialchars($usuario['nombre'] . ' ' . $usuario['apellido']) ?>
            </span>
        </div>
    <div class="panel-body">
        <form method="POST" action="<?= BASE_URL ?>sistema/controladores/ControladorUsuario.php?accion=actualizar" novalidate>
            <?php csrf_field(); ?>
            <input type="hidden" name="id" value="<?= htmlspecialchars($usuario['id_usuario'] ?? $_GET['id'] ?? '') ?>">
            <div class="form-grid mb-18">
                <div class="form-group">
                    <label>Nombre <span class="req">*</span></label>
                    <input type="text" name="nombre" class="form-control" required
                        value="<?= htmlspecialchars($_POST['nombre'] ?? $usuario['nombre']) ?>">
                </div>
                <div class="form-group">
                    <label>Apellido <span class="req">*</span></label>
                    <input type="text" name="apellido" class="form-control" required
                        value="<?= htmlspecialchars($_POST['apellido'] ?? $usuario['apellido']) ?>">
                </div>
                <div class="form-group">                            
                    <label>Email</label>
                    <input type="email" name="email" class="form-control"
                        value="<?= htmlspecialchars($_POST['email'] ?? ($usuario['email'] ?? '')) ?>">
                </div>
                <div class="form-group">
                    <label>Rol <span class="req">*</span></label>
                    <select name="id_rol" class="form-control" required>
                        <?php foreach($roles as $r): ?>
                        <option value="<?= $r['id_rol'] ?>"
                            <?= ($usuario['id_rol'] ?? '') == $r['id_rol'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($r['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Estado</label>
                    <select name="estado" class="form-control">
                        <option value="activo"
                            <?= ($usuario['estado'] ?? '') === 'activo' ? 'selected' : '' ?>>
                            Activo
                        </option>
                        <option value="inactivo"
                            <?= ($usuario['estado'] ?? '') === 'inactivo' ? 'selected' : '' ?>>
                            Inactivo
                        </option>
                    </select>
                </div>
            </div>
            <div class="form-acciones">
                <button type="submit" class="btn btn-primario">Actualizar</button>
                <a href="<?= BASE_URL ?>sistema/controladores/ControladorUsuario.php?accion=index" class="btn btn-secundario">Cancelar</a>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../layouts/footer.php'; ?>

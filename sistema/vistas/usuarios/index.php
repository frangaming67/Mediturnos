<?php
// sistema/vistas/usuarios/index.php
// -----------------------------------------------------------------
// Mismo patrón ABM que medicos/pacientes/consultorios, pero acá el botón
// "Dar de baja" además compara $u['id_usuario'] != $_SESSION['id_usuario']
// para ocultarse en la propia fila del admin logueado: es el reflejo en
// la vista de la misma regla que ControladorUsuario ya aplica en el
// servidor (ver el bloque "self" en el case 'baja'). Repetir el chequeo
// acá no reemplaza al del controlador (ese es el que de verdad protege),
// pero evita mostrarle al admin un botón que sabemos que va a fallar.
// -----------------------------------------------------------------
$paginaTitulo = 'Usuarios del sistema';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / Usuarios';
require __DIR__ . '/../layouts/navbar.php';

$msgs = ['creado'=>'Usuario creado.','actualizado'=>'Usuario actualizado.','baja'=>'Usuario dado de baja.'];
$errs = ['self'=>'No podés darte de baja a vos mismo.'];
$msg  = $_GET['msg'] ?? '';
$err  = $_GET['err'] ?? '';
?>

<?php if ($msg && isset($msgs[$msg])): ?>
<div class="alerta alerta-exito"><?= $msgs[$msg] ?></div>
<?php endif; ?>
<?php if ($err && isset($errs[$err])): ?>
<div class="alerta alerta-error"><?= $errs[$err] ?></div>
<?php endif; ?>

<div class="panel">
    <!-- Filtros -->
    <form method="GET" action="">
        <input type="hidden" name="accion" value="index">
        <div class="filtros-bar">
            <div class="form-group">
                <label>Rol</label>
                <select name="id_rol" class="form-control">
                    <option value="">Todos</option>
                    <?php foreach($roles as $r): ?>
                    <option value="<?= $r['id_rol'] ?>" <?= ($filtros['id_rol'] ?? 0)==$r['id_rol']?'selected':'' ?>>
                        <?= htmlspecialchars(ucfirst($r['nombre'])) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Estado</label>
                <select name="estado" class="form-control">
                    <option value="">Todos</option>
                    <option value="activo"   <?= ($filtros['estado'] ?? '')==='activo'   ?'selected':'' ?>>Activo</option>
                    <option value="inactivo" <?= ($filtros['estado'] ?? '')==='inactivo' ?'selected':'' ?>>Inactivo</option>
                </select>
            </div>
            <div class="form-group">
                <label>Buscar</label>
                <input type="text" name="busqueda" class="form-control" placeholder="Usuario, nombre o apellido..."
                    value="<?= htmlspecialchars($filtros['busqueda'] ?? '') ?>">
            </div>
            <div class="form-group form-group--fin">
                <label>&nbsp;</label>
                <div class="btn-grupo">
                    <button type="submit" class="btn btn-primario btn-sm">Filtrar</button>
                    <a href="?accion=index" class="btn btn-secundario btn-sm">Limpiar</a>
                </div>
            </div>
        </div>
    </form>

    <div class="panel-header panel-header--sep">
        <!-- Total filtrado, no las filas de esta página (siempre serían 25) -->
        <span class="panel-titulo"><?= (int) $total ?> usuario<?= $total === 1 ? '' : 's' ?></span>
        <a href="<?= BASE_URL ?>sistema/controladores/ControladorUsuario.php?accion=nuevo" class="btn btn-primario btn-sm">
            + Nuevo usuario
        </a>
    </div>
    <div class="tabla-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th><th>Usuario</th><th>Apellido</th><th>Nombre</th>
                    <th>Rol</th><th>Estado</th><th>Último login</th><th>Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php if(empty($usuarios)): ?>
                <tr><td colspan="8" class="td-vacio">No hay usuarios registrados.</td></tr>
            <?php else: ?>
                <?php foreach($usuarios as $u): ?>
                <tr>
                    <td><?= $u['id_usuario'] ?></td>
                    <td><?= htmlspecialchars($u['usuario']) ?></td>
                    <td><?= htmlspecialchars($u['apellido']) ?></td>
                    <td><?= htmlspecialchars($u['nombre']) ?></td>
                    <td><?= htmlspecialchars($u['rol']) ?></td>
                    <td>
                        <span class="badge badge-<?= $u['estado'] === 'activo' ? 'activo' : 'inactivo' ?>">
                            <?= ucfirst($u['estado']) ?>
                        </span>
                    </td>
                    <td class="texto-meta">
                        <?= $u['ultimo_login'] ? date('d/m/Y H:i', strtotime($u['ultimo_login'])) : '—' ?>
                    </td>
                    <td class="nowrap">
                        <a href="<?= BASE_URL ?>sistema/controladores/ControladorUsuario.php?accion=editar&id=<?= $u['id_usuario'] ?>"
                           class="btn-icono" title="Editar">
                            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </a>
                        <?php if($u['estado'] === 'activo' && $u['id_usuario'] != $_SESSION['id_usuario']): ?>
                        <form method="POST" action="<?= BASE_URL ?>sistema/controladores/ControladorUsuario.php?accion=baja"
                              class="d-inline"
                              onsubmit="return confirm('¿Dar de baja a este usuario?')">
                            <input type="hidden" name="id" value="<?= $u['id_usuario'] ?>">
                            <button type="submit" class="btn-icono peligro" title="Dar de baja">
                                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="17" y1="8" x2="23" y2="14"/><line x1="23" y1="8" x2="17" y2="14"/></svg>
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php require __DIR__ . '/../componentes/paginador.php'; ?>
</div>

<?php require __DIR__ . '/../layouts/footer.php'; ?>

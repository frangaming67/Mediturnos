<?php
// sistema/vistas/obras/index.php
// -----------------------------------------------------------------
// Esta vista sola muestra DOS tablas (obras sociales y planes) en vez
// de separarlas en dos pantallas, porque son la unidad natural de
// trabajo del admin: al crear una obra social lo siguiente que hace es
// cargarle sus planes, y verlas juntas evita ir y volver entre pantallas.
// El enlace a "Descuentos por médico" lleva a otro archivo (descuentos.php)
// y no a un tercer bloque acá mismo porque esa pantalla tiene su PROPIO
// formulario de alta (elegir obra social + médico + %) y ya la
// combinación de las dos tablas de acá deja el panel bastante cargado.
// -----------------------------------------------------------------
$paginaTitulo = 'Obras sociales';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / Obras sociales';
require __DIR__ . '/../layouts/navbar.php';

$BASEC = BASE_URL . 'sistema/controladores/ControladorObraSocial.php';
$msgs = [
    'creado'           => 'Obra social creada.',
    'actualizado'      => 'Obra social actualizada.',
    'plan_creado'      => 'Plan creado.',
    'plan_actualizado' => 'Plan actualizado.',
];
$msg = $_GET['msg'] ?? '';
?>

<?php if ($msg && isset($msgs[$msg])): ?>
<div class="alerta alerta-exito"><?= $msgs[$msg] ?></div>
<?php endif; ?>

<!-- ── Obras sociales ─────────────────────────────────── -->
<div class="panel">
    <form method="GET" action="">
        <input type="hidden" name="accion" value="index">
        <div class="filtros-bar">
            <div class="form-group">
                <label>Nombre</label>
                <input type="text" name="nombre" class="form-control" placeholder="Buscar..."
                    value="<?= htmlspecialchars($filtros['nombre']) ?>">
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
        <span class="panel-titulo"><?= count($obras) ?> obra(s) social(es)</span>
        <div class="btn-grupo">
            <a href="<?= $BASEC ?>?accion=descuentos" class="btn btn-secundario btn-sm">Descuentos por médico</a>
            <a href="<?= $BASEC ?>?accion=nuevo" class="btn btn-primario btn-sm">+ Nueva obra social</a>
        </div>
    </div>

    <div class="tabla-wrap">
        <table>
            <thead>
                <tr><th>ID</th><th>Nombre</th><th>CUIT</th><th>Planes</th><th>Acciones</th></tr>
            </thead>
            <tbody>
            <?php if (empty($obras)): ?>
                <tr><td colspan="5" class="td-vacio">No se encontraron obras sociales.</td></tr>
            <?php else: foreach ($obras as $o): ?>
                <tr>
                    <td><?= $o['id_obra_social'] ?></td>
                    <td><?= htmlspecialchars($o['nombre']) ?></td>
                    <td><?= htmlspecialchars($o['cuit'] ?: '—') ?></td>
                    <td><?= (int)$o['cant_planes'] ?></td>
                    <td class="nowrap">
                        <a href="<?= $BASEC ?>?accion=editar&id=<?= $o['id_obra_social'] ?>" class="btn-icono" title="Editar">
                            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Planes ─────────────────────────────────────────── -->
<div class="panel">
    <div class="panel-header panel-header--sep">
        <span class="panel-titulo"><?= count($planes) ?> plan(es)</span>
        <a href="<?= $BASEC ?>?accion=nuevoPlan" class="btn btn-primario btn-sm">+ Nuevo plan</a>
    </div>
    <div class="tabla-wrap">
        <table>
            <thead>
                <tr><th>ID</th><th>Obra social</th><th>Plan</th><th>Cobertura</th><th>Acciones</th></tr>
            </thead>
            <tbody>
            <?php if (empty($planes)): ?>
                <tr><td colspan="5" class="td-vacio">No hay planes cargados.</td></tr>
            <?php else: foreach ($planes as $pl): ?>
                <tr>
                    <td><?= $pl['id_plan'] ?></td>
                    <td><?= htmlspecialchars($pl['obra_social']) ?></td>
                    <td><?= htmlspecialchars($pl['nombre_plan']) ?></td>
                    <td><?= $pl['porcentaje_cobertura'] !== null ? (float)$pl['porcentaje_cobertura'] . '%' : '—' ?></td>
                    <td class="nowrap">
                        <a href="<?= $BASEC ?>?accion=editarPlan&id=<?= $pl['id_plan'] ?>" class="btn-icono" title="Editar">
                            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../layouts/footer.php'; ?>

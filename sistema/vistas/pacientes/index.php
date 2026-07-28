<?php
// sistema/vistas/pacientes/index.php — mismo patrón ABM que medicos/ (ver ahí el detalle)
// El botón "Nuevo turno para este paciente" (icono de calendario) enlaza
// a ControladorTurno con id_paciente por querystring en vez de abrir un
// modal acá mismo: reservar un turno es un flujo de varios pasos
// (elegir médico, fecha, plan) que ya vive completo en turnos/nuevo.php;
// duplicar ese flujo dentro del ABM de pacientes sería reinventar la
// misma pantalla dos veces.
$paginaTitulo = 'Pacientes';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / Pacientes';
require __DIR__ . '/../layouts/navbar.php';

$msgs = ['creado'=>'Paciente registrado.','actualizado'=>'Paciente actualizado.'];
$msg  = $_GET['msg'] ?? '';
?>

<?php if ($msg && isset($msgs[$msg])): ?>
<div class="alerta alerta-exito"><?= $msgs[$msg] ?></div>
<?php endif; ?>

<div class="panel">
    <form method="GET" action="">
        <input type="hidden" name="accion" value="index">
        <div class="filtros-bar">
            <div class="form-group">
                <label>Apellido</label>
                <input type="text" name="apellido" class="form-control" placeholder="Buscar..."
                    value="<?= htmlspecialchars($filtros['apellido']) ?>">
            </div>
            <div class="form-group">
                <label>Nombre</label>
                <input type="text" name="nombre" class="form-control" placeholder="Buscar..."
                    value="<?= htmlspecialchars($filtros['nombre']) ?>">
            </div>
            <div class="form-group">
                <label>DNI</label>
                <input type="text" name="dni" class="form-control" placeholder="Buscar..."
                    value="<?= htmlspecialchars($filtros['dni']) ?>">
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
        <!-- Muestra el TOTAL que cumple el filtro, no las filas de esta
             página: con paginación, count($pacientes) diría siempre 25. -->
        <span class="panel-titulo"><?= (int) $total ?> paciente<?= $total === 1 ? '' : 's' ?></span>
        <a href="<?= BASE_URL ?>sistema/controladores/ControladorPaciente.php?accion=nuevo" class="btn btn-primario btn-sm">
            + Nuevo paciente
        </a>
    </div>

    <div class="tabla-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th><th>DNI</th><th>Apellido</th><th>Nombre</th>
                    <th>Nac.</th><th>Teléfono</th><th>Email</th><th>Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php if(empty($pacientes)): ?>
                <tr><td colspan="8" class="td-vacio">No se encontraron pacientes.</td></tr>
            <?php else: ?>
                <?php foreach($pacientes as $p): ?>
                <tr>
                    <td><?= $p['id_paciente'] ?></td>
                    <td><?= htmlspecialchars($p['dni']) ?></td>
                    <td><?= htmlspecialchars($p['apellido']) ?></td>
                    <td><?= htmlspecialchars($p['nombre']) ?></td>
                    <td><?= $p['fecha_nac'] ? date('d/m/Y', strtotime($p['fecha_nac'])) : '—' ?></td>
                    <td><?= htmlspecialchars($p['telefono'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($p['email'] ?? '—') ?></td>
                    <td class="nowrap">
                        <a href="<?= BASE_URL ?>sistema/controladores/ControladorPaciente.php?accion=editar&id=<?= $p['id_paciente'] ?>"
                        class="btn-icono" title="Editar">
                            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </a>
                        <a href="<?= BASE_URL ?>sistema/controladores/ControladorTurno.php?accion=nuevo&id_paciente=<?= $p['id_paciente'] ?>"
                        class="btn-icono" title="Nuevo turno para este paciente">
                            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M12 14v4M10 16h4"/></svg>
                        </a>
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

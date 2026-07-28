<?php
// sistema/vistas/medicos/index.php
// -----------------------------------------------------------------
// Las 3 vistas de médicos (index/nuevo/editar) siguen el mismo patrón
// que se repite en pacientes/, consultorios/ y horarios/: cada ABM tiene
// su propia carpeta bajo sistema/vistas/ con exactamente esos 3 archivos,
// porque así el nombre de archivo ya documenta qué caso de uso resuelve
// (no hay que adivinar leyendo el controlador). No se usa un sistema de
// plantillas genérico (un solo formulario.php reusado por todos los
// recursos) porque cada entidad tiene campos y validaciones demasiado
// distintas entre sí; el "costo" de la duplicación entre carpetas se
// paga a cambio de que cada vista sea simple de leer de punta a punta.
// Esta vista en particular usa tienePermiso() (no verificarRol()) en
// cada botón porque, a diferencia de pacientes/consultorios, acá el
// mismo rol 'recepcionista' necesita ver botones distintos según el
// permiso puntual que tenga (ver medicos.crear/editar/baja en
// ControladorMedico.php).
// -----------------------------------------------------------------
$paginaTitulo = 'Médicos';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / Médicos';
require __DIR__ . '/../layouts/navbar.php';

$msgs = ['creado'=>'Médico registrado.','actualizado'=>'Médico actualizado.',
         'baja'=>'Médico dado de baja.','alta'=>'Médico reactivado.'];
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
                <label>Especialidad</label>
                <select name="id_especialidad" class="form-control">
                    <option value="">Todas</option>
                    <?php foreach($especialidades as $e): ?>
                    <option value="<?= $e['id_especialidad'] ?>"
                        <?= ($filtros['id_especialidad'] ?? 0) == $e['id_especialidad'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($e['nombre']) ?>
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
        <span class="panel-titulo"><?= count($medicos) ?> médico(s)</span>
        <?php if (tienePermiso('medicos.crear')): ?>
        <a href="<?= BASE_URL ?>sistema/controladores/ControladorMedico.php?accion=nuevo" class="btn btn-primario btn-sm">
            + Nuevo médico
        </a>
        <?php endif; ?>
    </div>

    <div class="tabla-wrap">
        <table>
            <thead>
                <tr>
                    <th>Matrícula</th><th>Apellido</th><th>Nombre</th>
                    <th>Especialidades</th><th>Email</th><th>Estado</th><th>Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php if(empty($medicos)): ?>
                <tr><td colspan="7" class="td-vacio">No se encontraron médicos.</td></tr>
            <?php else: ?>
                <?php foreach($medicos as $m): ?>
                <?php $activo = ($m['estado'] ?? 'activo') === 'activo'; ?>
                <tr>
                    <td><?= $m['matricula'] ?></td>
                    <td><?= htmlspecialchars($m['apellido']) ?></td>
                    <td><?= htmlspecialchars($m['nombre']) ?></td>
                    <td class="texto-meta"><?= htmlspecialchars($m['especialidades'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($m['email'] ?? '—') ?></td>
                    <td>
                        <span class="badge badge-<?= $activo ? 'activo' : 'inactivo' ?>">
                            <?= $activo ? 'Activo' : 'Inactivo' ?>
                        </span>
                    </td>
                    <td class="nowrap">
                        <?php if (tienePermiso('medicos.editar')): ?>
                        <a href="<?= BASE_URL ?>sistema/controladores/ControladorMedico.php?accion=editar&id=<?= $m['matricula'] ?>"
                        class="btn-icono" title="Editar">
                            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </a>
                        <?php endif; ?>

                        <?php if (tienePermiso('medicos.baja')): ?>
                            <?php if ($activo): ?>
                            <form method="POST" action="<?= BASE_URL ?>sistema/controladores/ControladorMedico.php?accion=baja"
                                  class="d-inline"
                                  onsubmit="return confirm('¿Dar de baja a este médico? Dejará de ofrecerse para nuevos turnos.')">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="matricula" value="<?= $m['matricula'] ?>">
                                <button type="submit" class="btn-icono peligro" title="Dar de baja">
                                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="17" y1="8" x2="23" y2="14"/><line x1="23" y1="8" x2="17" y2="14"/></svg>
                                </button>
                            </form>
                            <?php else: ?>
                            <form method="POST" action="<?= BASE_URL ?>sistema/controladores/ControladorMedico.php?accion=reactivar"
                                  class="d-inline"
                                  onsubmit="return confirm('¿Reactivar a este médico?')">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="matricula" value="<?= $m['matricula'] ?>">
                                <button type="submit" class="btn-icono" title="Reactivar">
                                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/></svg>
                                </button>
                            </form>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if (!tienePermiso('medicos.editar') && !tienePermiso('medicos.baja')): ?>
                        <span class="texto-meta">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../layouts/footer.php'; ?>

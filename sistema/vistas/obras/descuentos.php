<?php
// sistema/vistas/obras/descuentos.php — Descuentos por obra social y médico
// -----------------------------------------------------------------
// Junta el formulario de alta y el listado en la MISMA pantalla (a
// diferencia de médico/paciente/consultorio, que separan nuevo.php de
// index.php) porque un descuento es un dato chico (3 campos) que se
// carga muy seguido y no necesita su propia URL; forzar una navegación
// aparte para algo tan simple sería más fricción que ayuda.
// El formulario no tiene "selected" en los <option> como los demás ABM
// del proyecto porque esto no es un editar: "guardarDescuento" hace un
// UPSERT (ON DUPLICATE KEY, ver ObraSocial::guardarDescuento) — cargar
// de nuevo la misma combinación actualiza el porcentaje en vez de
// duplicar la fila, así que no hace falta precargar valores existentes.
// -----------------------------------------------------------------
$paginaTitulo = 'Descuentos por médico';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / <a href="' . BASE_URL . 'sistema/controladores/ControladorObraSocial.php?accion=index">Obras sociales</a> / Descuentos';
require __DIR__ . '/../layouts/navbar.php';

$BASEC = BASE_URL . 'sistema/controladores/ControladorObraSocial.php';
$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';
$msgs = [
    'desc_guardado'  => 'Descuento guardado.',
    'desc_eliminado' => 'Descuento eliminado.',
];
?>

<?php if ($msg && isset($msgs[$msg])): ?>
<div class="alerta alerta-exito"><?= $msgs[$msg] ?></div>
<?php endif; ?>
<?php if ($err): ?>
<div class="alerta alerta-error"><?= htmlspecialchars(urldecode($err)) ?></div>
<?php endif; ?>

<!-- ── Alta / edición de un descuento ─────────────────── -->
<div class="panel panel-md">
    <div class="panel-header">
        <span class="panel-titulo">Cargar descuento</span>
    </div>
    <div class="panel-body">
        <p class="texto-ayuda mb-20">
            Definí el descuento (%) que una obra social le hace al paciente cuando el turno
            es con un médico determinado. Si ya existe esa combinación, se actualiza el porcentaje.
        </p>
        <form method="POST" action="<?= $BASEC ?>?accion=guardarDescuento">
            <?php csrf_field(); ?>
            <div class="form-grid mb-20">
                <div class="form-group">
                    <label for="id_obra_social">Obra social <span class="req">*</span></label>
                    <select name="id_obra_social" id="id_obra_social" class="form-control" required>
                        <option value="">— Seleccioná —</option>
                        <?php foreach ($obras as $o): ?>
                        <option value="<?= $o['id_obra_social'] ?>"><?= htmlspecialchars($o['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="matricula">Médico <span class="req">*</span></label>
                    <select name="matricula" id="matricula" class="form-control" required>
                        <option value="">— Seleccioná —</option>
                        <?php foreach ($medicos as $m): ?>
                        <option value="<?= $m['matricula'] ?>"><?= htmlspecialchars($m['nombre_completo']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="porcentaje_descuento">Descuento (%) <span class="req">*</span></label>
                    <input type="number" name="porcentaje_descuento" id="porcentaje_descuento"
                           class="form-control" min="0" max="100" step="0.01" value="0" required>
                    <span class="form-hint">0 = sin descuento · 100 = gratis</span>
                </div>
            </div>
            <div class="form-acciones">
                <button type="submit" class="btn btn-primario">Guardar descuento</button>
                <a href="<?= $BASEC ?>?accion=index" class="btn btn-secundario">Volver a obras sociales</a>
            </div>
        </form>
    </div>
</div>

<!-- ── Listado de descuentos ──────────────────────────── -->
<div class="panel">
    <div class="panel-header panel-header--sep">
        <span class="panel-titulo"><?= count($descuentos) ?> descuento(s) cargado(s)</span>
    </div>
    <div class="tabla-wrap">
        <table>
            <thead>
                <tr><th>Obra social</th><th>Médico</th><th>Descuento</th><th>Acciones</th></tr>
            </thead>
            <tbody>
            <?php if (empty($descuentos)): ?>
                <tr><td colspan="4" class="td-vacio">No hay descuentos cargados.</td></tr>
            <?php else: foreach ($descuentos as $d): ?>
                <tr>
                    <td><?= htmlspecialchars($d['obra_social']) ?></td>
                    <td><?= htmlspecialchars($d['medico']) ?></td>
                    <td><strong><?= (float)$d['porcentaje_descuento'] ?>%</strong></td>
                    <td class="nowrap">
                        <form method="POST" action="<?= $BASEC ?>?accion=eliminarDescuento"
                              onsubmit="return confirm('¿Eliminar este descuento?');" style="display:inline">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="id_descuento" value="<?= (int)$d['id_descuento'] ?>">
                            <button type="submit" class="btn-icono peligro" title="Eliminar">
                                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../layouts/footer.php'; ?>

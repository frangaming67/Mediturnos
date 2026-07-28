<?php
// sistema/vistas/consultorios/index.php — mismo patrón ABM que medicos/ (ver ahí el detalle)
// El texto de equipamiento se trunca a 45 caracteres en la celda y el
// completo se ve en un modal ("Ver más") en vez de ensanchar la columna:
// una descripción larga rompería el ancho de la tabla para todas las
// filas por una sola fila con texto largo.
$paginaTitulo = 'Consultorios';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / Consultorios';
require __DIR__ . '/../layouts/navbar.php';

$BASEC = BASE_URL . 'sistema/controladores/ControladorConsultorio.php';
$msgs = ['creado' => 'Consultorio creado.', 'actualizado' => 'Consultorio actualizado.'];
$msg  = $_GET['msg'] ?? '';
?>

<?php if ($msg && isset($msgs[$msg])): ?>
<div class="alerta alerta-exito"><?= $msgs[$msg] ?></div>
<?php endif; ?>

<div class="panel">
    <div class="panel-header panel-header--sep">
        <span class="panel-titulo"><?= count($consultorios) ?> consultorio(s)</span>
        <a href="<?= $BASEC ?>?accion=nuevo" class="btn btn-primario btn-sm">+ Nuevo consultorio</a>
    </div>

    <div class="tabla-wrap">
        <table>
            <thead>
                <tr><th>ID</th><th>Número</th><th>Piso</th><th>Equipamiento</th><th>Acciones</th></tr>
            </thead>
            <tbody>
            <?php if (empty($consultorios)): ?>
                <tr><td colspan="5" class="td-vacio">No hay consultorios cargados.</td></tr>
            <?php else: foreach ($consultorios as $c): ?>
                <tr>
                    <td><?= $c['id_consultorio'] ?></td>
                    <td><?= (int)$c['numero'] ?></td>
                    <td><?= (int)$c['piso'] ?></td>
                    <td>
                        <?php $desc = trim($c['descripcion_equipamiento'] ?? ''); ?>
                        <?php if ($desc === ''): ?>
                            —
                        <?php else: ?>
                            <span class="texto-gris"><?= htmlspecialchars(mb_strlen($desc) > 45 ? mb_substr($desc, 0, 45) . '…' : $desc) ?></span>
                            <button type="button" class="btn-ver-desc btn-link-ver"
                                    data-titulo="<?= htmlspecialchars('Cons. ' . $c['numero'] . ' - Piso ' . $c['piso']) ?>"
                                    data-desc="<?= htmlspecialchars($desc) ?>">Ver más</button>
                        <?php endif; ?>
                    </td>
                    <td class="nowrap">
                        <a href="<?= $BASEC ?>?accion=editar&id=<?= $c['id_consultorio'] ?>" class="btn-icono" title="Editar">
                            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal para ver la descripción completa en grande -->
<div class="modal-overlay" id="modal-desc">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-titulo" id="modal-desc-titulo">Equipamiento</span>
            <button class="btn-cerrar-modal" type="button">✕</button>
        </div>
        <div class="modal-body">
            <p id="modal-desc-body" style="white-space:pre-line; line-height:1.6; margin:0"></p>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.btn-ver-desc').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('modal-desc-titulo').textContent = btn.dataset.titulo;
        document.getElementById('modal-desc-body').textContent   = btn.dataset.desc;
        document.getElementById('modal-desc').classList.add('abierto');
    });
});
</script>

<?php require __DIR__ . '/../layouts/footer.php'; ?>

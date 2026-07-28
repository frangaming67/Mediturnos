<?php
// sistema/vistas/pagos/index.php — Listado de pagos
// -----------------------------------------------------------------
// Es una vista aparte de sistema/vistas/turnos/index.php aunque ambas
// muestran datos relacionados, porque responden preguntas distintas:
// turnos/index responde "¿qué turnos hay?" (con acciones de agenda:
// cancelar, cambiar estado), pagos/index responde "¿qué se cobró y qué
// falta cobrar?" (con acciones de caja: pagar, cobrar en recepción).
// Mezclarlas en una sola tabla obligaría a mostrar columnas irrelevantes
// para cada caso de uso.
// El modal de "Cobrar en recepción" se genera UNO POR CADA pago
// pendiente (foreach con id único modal-recepcion-<id>) en vez de un
// solo modal reusado con JS, para no depender de JavaScript para leer
// qué fila lo disparó — mismo criterio de "funciona sin JS" que en
// turnos/nuevo.php.
// -----------------------------------------------------------------
$paginaTitulo = 'Pagos';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / Pagos';
require __DIR__ . '/../layouts/navbar.php';

$URL     = BASE_URL . 'sistema/controladores/ControladorPago.php';
$esStaff = in_array($_SESSION['rol'], ['admin', 'recepcionista'], true);
$money   = fn($v) => '$' . number_format((float)$v, 2, ',', '.');

// Clase de badge según el estado del pago
$badgePago = [
    'Pagado'    => 'badge-realizado',
    'Pendiente' => 'badge-ausente',
    'Vencido'   => 'badge-cancelado',
    'Anulado'   => 'badge-inactivo',
];

$estadoActual = trim($_GET['estado'] ?? '');
$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';
$msgs = [
    'pagado' => ['exito', 'Pago registrado correctamente.'],
];
?>

<?php if ($msg && isset($msgs[$msg])): ?>
<div class="alerta alerta-<?= $msgs[$msg][0] ?>"><?= $msgs[$msg][1] ?></div>
<?php endif; ?>
<?php if ($err): ?>
<div class="alerta alerta-error"><?= htmlspecialchars(urldecode($err)) ?></div>
<?php endif; ?>

<div class="panel">
    <!-- Filtros (solo staff) -->
    <?php if ($esStaff): ?>
    <form method="GET" action="">
        <input type="hidden" name="accion" value="index">
        <div class="filtros-bar">
            <div class="form-group">
                <label>Estado</label>
                <select name="estado" class="form-control">
                    <option value="">Todos</option>
                    <?php foreach (['Pendiente','Pagado','Vencido','Anulado'] as $e): ?>
                    <option value="<?= $e ?>" <?= $estadoActual === $e ? 'selected' : '' ?>><?= $e ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>DNI paciente</label>
                <input type="text" name="dni_paciente" class="form-control" placeholder="Buscar DNI..."
                       value="<?= htmlspecialchars($_GET['dni_paciente'] ?? '') ?>">
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
    <?php endif; ?>

    <div class="panel-header panel-header--sep">
        <span class="panel-titulo"><?= count($pagos) ?> pago<?= count($pagos) !== 1 ? 's' : '' ?></span>
    </div>

    <div class="tabla-wrap">
        <table>
            <thead>
                <tr>
                    <th>Turno</th>
                    <th>Fecha / Hora</th>
                    <?php if ($esStaff): ?><th>Paciente</th><?php endif; ?>
                    <th>Médico</th>
                    <th>Obra Social</th>
                    <th>Monto</th>
                    <th>Estado</th>
                    <th>Método</th>
                    <th>Vencimiento</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($pagos)): ?>
                <tr><td colspan="<?= $esStaff ? 10 : 9 ?>" class="td-vacio">No hay pagos para mostrar.</td></tr>
            <?php else: foreach ($pagos as $pg): ?>
                <tr>
                    <td>#<?= $pg['id_turno'] ?></td>
                    <td class="nowrap">
                        <?= date('d/m/Y', strtotime($pg['fecha'])) ?><br>
                        <small class="texto-gris"><?= substr($pg['hora_inicio'], 0, 5) ?></small>
                    </td>
                    <?php if ($esStaff): ?>
                    <td>
                        <?= htmlspecialchars($pg['paciente']) ?><br>
                        <small class="texto-gris">DNI: <?= htmlspecialchars($pg['paciente_dni']) ?></small>
                    </td>
                    <?php endif; ?>
                    <td><?= htmlspecialchars($pg['medico']) ?></td>
                    <td><?= htmlspecialchars($pg['obra_social']) ?></td>
                    <td class="nowrap">
                        <?= $money($pg['monto_total']) ?>
                        <?php if ((float)$pg['porcentaje_descuento'] > 0): ?>
                        <br><small class="texto-verde">−<?= (float)$pg['porcentaje_descuento'] ?>%</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?= $badgePago[$pg['estado']] ?? 'badge-inactivo' ?>">
                            <?= htmlspecialchars($pg['estado']) ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($pg['metodo'] ?: '—') ?></td>
                    <td class="nowrap">
                        <?php if ($pg['estado'] === 'Pendiente'): ?>
                            <small><?= date('d/m/Y H:i', strtotime($pg['fecha_vencimiento'])) ?></small>
                        <?php elseif ($pg['estado'] === 'Pagado' && $pg['fecha_pago']): ?>
                            <small class="texto-gris">Pagado <?= date('d/m/Y', strtotime($pg['fecha_pago'])) ?></small>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td class="nowrap">
                        <?php if ($pg['estado'] === 'Pendiente'): ?>
                            <a href="<?= $URL ?>?accion=tarjeta&id_pago=<?= (int)$pg['id_pago'] ?>"
                               class="btn btn-primario btn-sm">Pagar</a>
                            <?php if ($esStaff): ?>
                            <button class="btn btn-secundario btn-sm"
                                    data-modal="modal-recepcion-<?= (int)$pg['id_pago'] ?>">Recepción</button>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="texto-gris">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($esStaff): ?>
<!-- Modales de confirmación de cobro en recepción -->
<?php foreach ($pagos as $pg): if ($pg['estado'] !== 'Pendiente') continue; ?>
<div class="modal-overlay" id="modal-recepcion-<?= (int)$pg['id_pago'] ?>">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-titulo">Cobrar en recepción</span>
            <button class="btn-cerrar-modal">✕</button>
        </div>
        <form method="POST" action="<?= $URL ?>?accion=recepcion">
            <?php csrf_field(); ?>
            <input type="hidden" name="id_pago" value="<?= (int)$pg['id_pago'] ?>">
            <input type="hidden" name="volver" value="<?= $URL ?>?accion=index">
            <div class="modal-body">
                <p>Confirmás el cobro en efectivo de
                   <strong><?= $money($pg['monto_total']) ?></strong> del turno
                   #<?= $pg['id_turno'] ?> de <strong><?= htmlspecialchars($pg['paciente']) ?></strong>?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secundario btn-cerrar-modal">Volver</button>
                <button type="submit" class="btn btn-primario">Confirmar cobro</button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/../layouts/footer.php'; ?>

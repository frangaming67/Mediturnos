<?php
// sistema/vistas/pagos/elegir.php
// pantalla que vel el paciente despues de reservar: le dice cuando debe pagar y ofrece opciones de pago
// -----------------------------------------------------------------
// Es una pantalla INTERMEDIA entre reservar y pagar (no se salta directo
// a tarjeta.php) porque pagar con tarjeta es opcional: el usuario puede
// preferir pagar en efectivo en recepción, y forzarlo a pasar por el
// formulario de tarjeta primero sería un paso de más innecesario.
// Vive en pagos/ (no en turnos/) porque conceptualmente ya dejó de ser
// "reservar un turno" y pasó a ser "resolver su cobro" — mismo criterio
// de separación que pagos/index.php vs turnos/index.php.
// -----------------------------------------------------------------
$paginaTitulo = 'Pago del turno';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / <a href="' . BASE_URL . 'sistema/controladores/ControladorTurno.php?accion=index">Turnos</a> / Pago';
require __DIR__ . '/../layouts/navbar.php';

$URL     = BASE_URL . 'sistema/controladores/ControladorPago.php';
$esStaff = in_array($_SESSION['rol'], ['admin', 'recepcionista'], true);
$money   = fn($v) => '$' . number_format((float)$v, 2, ',', '.');
$pagado  = $pago['estado'] === 'Pagado';
?>

<?php if (!empty($mensaje)): ?>
<div class="alerta alerta-error"><?= htmlspecialchars($mensaje) ?></div>
<?php endif; ?>

<div class="panel panel-md">
    <div class="panel-header">
        <span class="panel-titulo">Reserva confirmada — falta el pago</span>
    </div>
    <div class="panel-body">

        <div class="alerta alerta-info mb-20">
            <strong><?= htmlspecialchars($pago['especialidad']) ?></strong> con
            <strong><?= htmlspecialchars($pago['medico']) ?></strong><br>
            📅 <?= date('d/m/Y', strtotime($pago['fecha'])) ?>
            &nbsp; 🕐 <?= substr($pago['hora_inicio'], 0, 5) ?>
        </div>

        <!-- Detalle del importe -->
        <table class="tabla-importe">
            <tr>
                <td>Precio de consulta (<?= htmlspecialchars($pago['especialidad']) ?>)</td>
                <td class="ta-right"><?= $money($pago['monto_base']) ?></td>
            </tr>
            <tr>
                <td>
                    Descuento <?= htmlspecialchars($pago['obra_social']) ?>
                    (<?= rtrim(rtrim(number_format((float)$pago['porcentaje_descuento'], 2, ',', '.'), '0'), ',') ?>%)
                </td>
                <td class="ta-right texto-verde">
                    − <?= $money($pago['monto_base'] - $pago['monto_total']) ?>
                </td>
            </tr>
            <tr class="fila-total">
                <td><strong>Total a pagar</strong></td>
                <td class="ta-right"><strong><?= $money($pago['monto_total']) ?></strong></td>
            </tr>
        </table>

        <?php if ($pagado): ?>
            <div class="alerta alerta-exito mt-20">
                Este turno ya está pagado
                (<?= htmlspecialchars($pago['metodo'] ?: '—') ?><?= $pago['referencia'] ? ' · ' . htmlspecialchars($pago['referencia']) : '' ?>).
            </div>
            <div class="form-acciones mt-20">
                <a href="<?= BASE_URL ?>sistema/controladores/ControladorTurno.php?accion=index" class="btn btn-secundario">Volver a turnos</a>
            </div>

        <?php else: ?>
            <p class="texto-ayuda mt-20">
                Tenés tiempo de pagar hasta el
                <strong><?= date('d/m/Y H:i', strtotime($pago['fecha_vencimiento'])) ?> hs</strong>.
                Si no pagás antes, el turno se cancela automáticamente y se libera el horario.
            </p>

            <h3 class="pago-pregunta mt-20">¿Cómo querés pagar?</h3>

            <div class="pago-opciones mt-12">
                <!-- Pagar ahora con tarjeta (opción destacada) -->
                <a href="<?= $URL ?>?accion=tarjeta&id_pago=<?= (int)$pago['id_pago'] ?>"
                   class="pago-opcion pago-opcion--primaria">
                    <span class="pago-opcion-icono">💳</span>
                    <span class="pago-opcion-titulo">Pagar ahora con tarjeta</span>
                    <span class="pago-opcion-desc">Pago inmediato y seguro. El turno queda pagado al instante.</span>
                    <span class="pago-opcion-cta">Pagar con tarjeta →</span>
                </a>

                <!-- Pagar más tarde -->
                <a href="<?= BASE_URL ?>sistema/controladores/ControladorTurno.php?accion=index"
                   class="pago-opcion">
                    <span class="pago-opcion-icono">🕒</span>
                    <span class="pago-opcion-titulo">Pagar más tarde</span>
                    <span class="pago-opcion-desc">Pagá con tarjeta antes del vencimiento, o en efectivo en recepción.</span>
                    <span class="pago-opcion-cta">Dejar pendiente →</span>
                </a>
            </div>

            <?php if ($esStaff): ?>
            <!-- Cobro en mostrador (staff) -->
            <form method="POST" action="<?= $URL ?>?accion=recepcion" class="mt-20">
                <?php csrf_field(); ?>
                <input type="hidden" name="id_pago" value="<?= (int)$pago['id_pago'] ?>">
                <input type="hidden" name="volver" value="<?= BASE_URL ?>sistema/controladores/ControladorTurno.php?accion=index">
                <button type="submit" class="btn btn-secundario">Registrar pago en recepción (efectivo)</button>
            </form>
            <?php endif; ?>
        <?php endif; ?>

    </div>
</div>

<?php require __DIR__ . '/../layouts/footer.php'; ?>

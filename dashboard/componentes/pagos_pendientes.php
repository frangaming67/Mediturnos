<?php
// dashboard/componentes/pagos_pendientes.php
// Este componente confía en que dashboard_admin.php ya decidió si
// corresponde incluirlo (solo $esGestor) — por eso no repite el chequeo
// de rol acá adentro; es solo presentación de los datos que ya llegaron
// filtrados en $pagosPendientes.
//apartado de los pagos pendientes del dashboard
//primero se crea el panel de pagos pendientes
//despues se revisa si hay datos en la tabla $pagosPendientes, si no hay se muestra un mensaje de que no hay pagos pendientes
// si llegan a ver datos se recorre y se arma una fila con los datos
// se usan en strtotime para convertir una fecha en formato texto con un valor que pueda leer php
// el nowrap sirve para que el contenido no se rompa en varias lineas y se mantenga en una sola linea
?>
<div class="panel">
    <div class="panel-header">
        <span class="panel-titulo">Pagos pendientes</span>
    </div>
    <div class="tabla-wrap">
        <table>
            <thead>
                <tr><th>Vence</th><th>Turno</th><th>Paciente</th><th>Médico</th><th>Monto</th></tr>
            </thead>
            <tbody>
            <?php if (empty($pagosPendientes)): ?>
                <tr><td colspan="5" class="td-vacio">No hay pagos pendientes.</td></tr>
            <?php else: foreach ($pagosPendientes as $p): ?>
                <tr>
                    <td class="nowrap"><?= date('d/m/Y H:i', strtotime($p['fecha_vencimiento'])) ?></td>
                    <td class="nowrap"><?= date('d/m/Y', strtotime($p['fecha'])) ?> <?= substr($p['hora_inicio'],0,5) ?></td>
                    <td><?= htmlspecialchars($p['paciente']) ?></td>
                    <td><?= htmlspecialchars($p['medico']) ?></td>
                    <td class="nowrap"><?= $money($p['monto_total']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

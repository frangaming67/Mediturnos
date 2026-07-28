<?php
// dashboard/componentes/recaudacion.php
// A diferencia de los otros componentes, este SÍ repite el chequeo de
// rol acá adentro (defensa en profundidad): dashboard_admin.php ya lo
// protege con "if ($esAdmin)" antes del require, pero como es el único
// panel con datos económicos sensibles (recaudación real de la clínica),
// se prefirió que el propio archivo no confíe ciegamente en que quien lo
// incluya haya hecho el chequeo correctamente.
//apartado de recaudacion de cada medico del dashboard
// solo lo pueden ver los administradores, si no lo es se muestra un mensaje de error

if (!isset($esAdmin) || !$esAdmin) {
    echo '<div class="panel"><div class="panel-header"><span class="panel-titulo">Recaudación por médico</span></div><div class="panel-body"><p
    class="alerta alerta-error">No tiene permisos para ver esta sección.</p></div></div>';
    return;
}

?>
<div class="panel">
    <div class="panel-header">
        <span class="panel-titulo">Recaudación por médico</span>
    </div>
    <div class="tabla-wrap">
        <table>
            <thead>
                <tr><th>Médico</th><th>Turnos pagados</th><th>Total recaudado</th></tr>
            </thead>
            <tbody>
            <?php if (empty($recaudacion)): ?>
                <tr><td colspan="3" class="td-vacio">Todavía no hay pagos registrados.</td></tr>
            <?php else: foreach ($recaudacion as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['medico']) ?></td>
                    <td><?= (int)$r['turnos_pagados'] ?></td>
                    <td class="nowrap"><?= $money($r['total_recaudado']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

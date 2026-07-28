<?php
// dashboard/componentes/agenda_hoy.php
// Se muestra a TODOS los roles de gestión (y al médico, filtrada a lo
// suyo vía $agendaHoy ya armado en dashboard_admin.php), por eso no
// tiene ningún if de rol adentro: la decisión de qué filas trae ya se
// resolvió antes, al calcular $agendaHoy.
//  Agenda de hoy
// se cargan los turnos en $agendaHoy y si no hay se muestra un mensaje
// se usa el substr para mostrar solo la hora y minutos de la hora_inicio
// htmlspecialchars se usa para mostrat datos de formas segura y evita Cross-Site Scripting (XXS)
?>
<div class="panel">
    <div class="panel-header">
        <span class="panel-titulo">Agenda de hoy</span>
        <a href="<?= $urlTurnos ?>" class="btn btn-secundario btn-sm">Ver todos</a>
    </div>
    <div class="tabla-wrap">
        <table>
            <thead>
                <tr>
                    <th>Hora</th><th>Paciente</th><th>Médico</th>
                    <th>Especialidad</th><th>Consultorio</th><th>Estado</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($agendaHoy)): ?>
                <tr><td colspan="6" class="td-vacio">No hay turnos para hoy.</td></tr>
            <?php else: foreach ($agendaHoy as $t): ?>
                <tr>
                    <td class="nowrap"><?= substr($t['hora_inicio'], 0, 5) ?></td>
                    <td><?= htmlspecialchars($t['paciente']) ?></td>
                    <td><?= htmlspecialchars($t['medico']) ?></td>
                    <td><?= htmlspecialchars($t['especialidad']) ?></td>
                    <td><?= htmlspecialchars($t['consultorio']) ?></td>
                    <td><span class="badge badge-<?= strtolower($t['estado']) ?>"><?= $t['estado'] ?></span></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

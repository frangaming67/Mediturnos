<?php
// dashboard/componentes/kpis.php
// -----------------------------------------------------------------
// Vive en dashboard/componentes/ (no directo en dashboard_admin.php)
// porque es un bloque de HTML puro que dashboard_admin.php decide
// mostrar u ocultar según el rol (ver "if (!$esMedico)" en el archivo
// que lo incluye) — separarlo en su propio archivo hace que ese if en
// dashboard_admin.php se lea de un vistazo, en vez de tener 40 líneas
// de HTML de KPIs metidas ahí en el medio.
// este apartado sirve para mostrar los KPIs del dashboard,
// osea resumen del dashboard que muestran números importantes como:
//turnos reservados, realizados, cancelados y totales de hoy, 
// así como el total de pacientes y médicos activos.
// si el $esGestor es verdadero, se muestran los KPIs de pacientes y médicos como enlaces a sus listados,
// si no, se muestran como simples tarjetas sin enlace.
//ademas se usa <?= (int)($kpis[])??0) ? > para que se muestre un valor numerico y no un null.

?>
<div class="kpi-grid">
    <a href="<?= $urlTurnos ?>&estado=Reservado" class="kpi-card azul" title="Ver turnos reservados de hoy">
        <span class="kpi-label">Reservados hoy</span>
        <span class="kpi-valor"><?= (int)($kpis['reservados_hoy'] ?? 0) ?></span>
    </a>
    <a href="<?= $urlTurnos ?>&estado=Realizado" class="kpi-card verde" title="Ver turnos realizados de hoy">
        <span class="kpi-label">Realizados hoy</span>
        <span class="kpi-valor"><?= (int)($kpis['realizados_hoy'] ?? 0 ) ?></span>
    </a>
    <a href="<?= $urlTurnos ?>&estado=Cancelado" class="kpi-card rojo" title="Ver turnos cancelados de hoy">
        <span class="kpi-label">Cancelados hoy</span>
        <span class="kpi-valor"><?= (int)($kpis['cancelados_hoy'] ?? 0) ?></span>
    </a>
    <a href="<?= $urlTurnos ?>" class="kpi-card" title="Ver todos los turnos de hoy">
        <span class="kpi-label">Total turnos hoy</span>
        <span class="kpi-valor"><?= (int)($kpis['total_hoy'] ?? 0) ?></span>
    </a>
    <?php if ($esGestor): ?>
    <a href="<?= BASE_URL ?>sistema/controladores/ControladorPaciente.php?accion=index" class="kpi-card" title="Ver pacientes">
        <span class="kpi-label">Pacientes registrados</span>
        <span class="kpi-valor"><?= (int)($kpis['total_pacientes'] ?? 0) ?></span>
    </a>
    <a href="<?= BASE_URL ?>sistema/controladores/ControladorMedico.php?accion=index" class="kpi-card" title="Ver médicos">
        <span class="kpi-label">Médicos activos</span>
        <span class="kpi-valor"><?= (int)($kpis['total_medicos'] ?? 0) ?></span>
    </a>
    <?php else: ?>
    <div class="kpi-card">
        <span class="kpi-label">Pacientes registrados</span>
        <span class="kpi-valor"><?= (int)($kpis['total_pacientes'] ?? 0) ?></span>
    </div>
    <div class="kpi-card">
        <span class="kpi-label">Médicos activos</span>
        <span class="kpi-valor"><?= (int)($kpis['total_medicos'] ?? 0) ?></span>
    </div>
    <?php endif; ?>
</div>

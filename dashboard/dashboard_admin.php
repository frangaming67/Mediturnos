<?php
// dashboard/dashboard_admin.php
// -----------------------------------------------------------------
// Este archivo es el "controlador de vista" del dashboard de staff:
// prepara TODAS las variables que van a necesitar los componentes de
// más abajo ($kpis, $agendaHoy, $pagosPendientes, $recaudacion, $money,
// etc.) y después los incluye con require. Se decidió centralizar los
// datos acá (y no que cada componente pida los suyos) porque varios
// componentes comparten la misma variable (p. ej. $money la usan
// pagos_pendientes.php Y recaudacion.php): calcularla una sola vez evita
// duplicar lógica y consultas a la base.
// Por eso dashboard/componentes/*.php NO tienen "<?php $x = ...;" propio
// para estos datos: dependen del scope de este archivo, ya que PHP
// comparte variables entre un archivo y los que incluye con require.
// -----------------------------------------------------------------
require_once __DIR__ . '/../sistema/modelos/Pago.php';

// ── Datos de la vista 
$modelo->marcarRealizadosAutomaticamente();
$modeloPago = new Pago($pdo);              // modelo de pagos para obtener la recaudacion por medico y los pagos pendientes
$modeloPago->expirarVencidos();           // cancela pagos vencidos que sale del apartado de pago.php

$esAdmin   = ($rol === 'admin');           // si el rol es admin da true si no false
$esGestor  = in_array($rol, ['admin', 'recepcionista'], true);    // si el rol es admin o recepcionista da true si no false
$esMedico  = ($rol === 'medico');

// KPIs globales de la clínica: es "visión de negocio", solo para gestión.
// El médico no los ve (ver dashboard: se omite el panel de KPIs).
if (!$esMedico) {
    $kpis = $modelo->kpis();
}

// vista v_agenda_hoy y sale del apartado de turno.php; si es médico, sólo su propia agenda
$agendaHoy = $modelo->agendaHoy($esMedico ? (int)($_SESSION['matricula'] ?? 0) : null);
                                                            
// v_pagos_pendientes
if ($esGestor) {                                            // si es admin o recepcionista se muestran los pagos pendientes
    $pagosPendientes = $modeloPago->pendientes();         
}
// v_recaudacion_medico
if ($esAdmin) {
    $recaudacion  = $modeloPago->recaudacionPorMedico(); // si es admin se muestran los datos de recaudacion por medico
}

// Helpers de presentación que usan los componentes.
$hoy = date('Y-m-d');
$urlTurnos = BASE_URL . 'sistema/controladores/ControladorTurno.php?accion=index&fecha=' . $hoy;
// esta funcion sirve para convertir un valor numerico a un formato de dinero con dos decimales 
// y le agrega el simbolo de $ al principio, ademas de usar coma como separador decimal y punto 
$money = fn($v) => '$' . number_format((float)$v, 2, ',', '.');

?>

<!-- KPIs: solo gestión (admin/recepcionista); el médico no ve métricas globales -->
<?php if (!$esMedico): ?>
<?php require __DIR__ . '/componentes/kpis.php'; ?>
<?php endif; ?>

<!-- apartado para los accesos rápidos -->
<div class="panel">
    <div class="panel-header">
        <span class="panel-titulo">Accesos rápidos</span>
    </div>
    <div class="panel-body acciones-rapidas">
        <a href="<?= BASE_URL ?>sistema/controladores/ControladorTurno.php?accion=nuevo"
           class="btn btn-primario">+ Nuevo turno</a>
        <?php if ($esGestor): ?>
        <a href="<?= BASE_URL ?>sistema/controladores/ControladorPaciente.php?accion=nuevo"
           class="btn btn-secundario">+ Nuevo paciente</a>
        <a href="<?= BASE_URL ?>sistema/controladores/ControladorAusencia.php?accion=index"
           class="btn btn-secundario">Ausencia de médico</a>
        <?php endif; ?>
    </div>
</div>

<!-- Agenda de hoy -->
<?php require __DIR__ . '/componentes/agenda_hoy.php'; ?>
<!-- si el usuario es admin o recepcionista se muestran los pagos pendientes y la recaudacion por medico -->
<?php if ($esGestor): ?>
    <!-- Pagos pendientes -->
    <?php require __DIR__ . '/componentes/pagos_pendientes.php'; ?>
<?php endif; ?>
<!-- si el usuario es admin se muestra la recaudacion por medico -->
<?php if ($esAdmin): ?>
    <!-- Recaudación por médico -->
    <?php require __DIR__ . '/componentes/recaudacion.php'; ?>
<?php endif; ?>

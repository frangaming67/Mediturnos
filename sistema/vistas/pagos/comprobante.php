<?php
// sistema/vistas/pagos/comprobante.php — Constancia del turno pagado
// -----------------------------------------------------------------
// Es lo primero que ve la persona después de pagar, y lo que va a
// buscar de nuevo el día de la consulta.
//
// POR QUÉ ES UNA PÁGINA Y NO UN PDF
// Generar un PDF sin librerías —el proyecto no usa Composer— significa
// escribir un generador entero. Una página con estilos de impresión
// hace lo mismo: `Ctrl+P` la imprime o la guarda como PDF con el propio
// navegador, y además se puede mostrar desde el teléfono en la puerta
// de la clínica sin descargar nada.
//
// El controlador ya verificó que el pago sea de esta persona y que esté
// pagado; acá sólo se muestra.
// -----------------------------------------------------------------

$paginaTitulo = 'Comprobante';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / Comprobante';
$cssExtra     = ['paciente.css'];

require __DIR__ . '/../layouts/navbar.php';

$money = fn($v) => '$' . number_format((float) $v, 2, ',', '.');

$MESES = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
          'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$DIAS  = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
$ts    = strtotime($pago['fecha']);
$fechaTexto = ucfirst($DIAS[(int) date('w', $ts)]) . ' ' . (int) date('j', $ts)
            . ' de ' . $MESES[(int) date('n', $ts)] . ' de ' . date('Y', $ts);
?>

<?php if (!empty($_GET['msg'])): ?>
<div class="alerta alerta-exito no-imprimir" role="alert">
    ¡Listo! Tu pago se acreditó y el turno quedó confirmado. Te mandamos una copia por correo.
</div>
<?php endif; ?>

<div class="comprobante">

    <header class="comp-cab">
        <div class="comp-marca">
            <span class="comp-logo" aria-hidden="true">+</span>
            <span>MediTurnos</span>
        </div>
        <span class="badge badge-activo">Turno confirmado</span>
    </header>

    <?php // El código va grande y solo: es el dato que la persona busca
          // cuando abre esto en la puerta de la clínica. ?>
    <div class="comp-codigo">
        <span>Código de reserva</span>
        <strong><?= htmlspecialchars($pago['referencia'] ?: 'MT-' . str_pad((string) $pago['id_turno'], 6, '0', STR_PAD_LEFT)) ?></strong>
    </div>

    <div class="comp-cuerpo">
        <ul class="pac-datos-lista">
            <li><span class="etq">Paciente</span>
                <span class="val"><?= htmlspecialchars($pago['paciente']) ?>
                    · DNI <?= htmlspecialchars($pago['paciente_dni']) ?></span></li>
            <li><span class="etq">Profesional</span>
                <span class="val">Dr/a. <?= htmlspecialchars($pago['medico']) ?></span></li>
            <li><span class="etq">Especialidad</span>
                <span class="val"><?= htmlspecialchars($pago['especialidad']) ?></span></li>
            <li><span class="etq">Fecha</span>
                <span class="val"><?= htmlspecialchars($fechaTexto) ?></span></li>
            <li><span class="etq">Hora</span>
                <span class="val"><?= htmlspecialchars(substr($pago['hora_inicio'], 0, 5)) ?> hs
                    <?php if ($pago['duracion_turno_min']): ?>
                        · <?= (int) $pago['duracion_turno_min'] ?> min aprox.
                    <?php endif; ?></span></li>
            <li><span class="etq">Consultorio</span>
                <span class="val"><?= htmlspecialchars($pago['consultorio']) ?></span></li>
            <li><span class="etq">Cobertura</span>
                <span class="val"><?= htmlspecialchars($pago['obra_social'] . ' — ' . $pago['plan']) ?></span></li>
            <li><span class="etq">Turno n.º</span>
                <span class="val">#<?= (int) $pago['id_turno'] ?></span></li>
        </ul>

        <table class="tabla-importe comp-importe">
            <tr>
                <td>Consulta de <?= htmlspecialchars($pago['especialidad']) ?></td>
                <td class="ta-right"><?= $money($pago['monto_base']) ?></td>
            </tr>
            <?php if ((float) $pago['porcentaje_descuento'] > 0): ?>
            <tr>
                <td>Cobertura <?= htmlspecialchars($pago['obra_social']) ?>
                    (<?= rtrim(rtrim(number_format((float) $pago['porcentaje_descuento'], 2, ',', '.'), '0'), ',') ?>%)</td>
                <td class="ta-right texto-verde">− <?= $money($pago['monto_base'] - $pago['monto_total']) ?></td>
            </tr>
            <?php endif; ?>
            <tr class="fila-total">
                <td><strong>Total abonado</strong></td>
                <td class="ta-right"><strong><?= $money($pago['monto_total']) ?></strong></td>
            </tr>
        </table>

        <p class="comp-pago-info">
            Pagado el <?= htmlspecialchars(date('d/m/Y \a \l\a\s H:i', strtotime($pago['fecha_pago']))) ?>
            mediante <strong><?= htmlspecialchars($pago['metodo'] ?: '—') ?></strong><?php
            if (!empty($pago['tarjeta_ult4'])): ?>, tarjeta terminada en <?= htmlspecialchars($pago['tarjeta_ult4']) ?><?php
            endif; ?>.
        </p>

        <div class="comp-instrucciones">
            <strong>Antes de venir</strong>
            <ul>
                <li>Llegá <strong>10 minutos antes</strong> del horario.</li>
                <li>Traé tu <strong>DNI</strong> y la credencial de tu obra social.</li>
                <li>Presentá este código en recepción: <strong><?= htmlspecialchars($pago['referencia'] ?: '#' . (int) $pago['id_turno']) ?></strong></li>
                <li>Si no vas a poder venir, cancelá con al menos <strong>2 horas</strong> de anticipación
                    para que otra persona pueda usar el horario.</li>
            </ul>
        </div>
    </div>

    <footer class="comp-pie">
        MediTurnos · Comprobante emitido el <?= date('d/m/Y H:i') ?> ·
        Turno #<?= (int) $pago['id_turno'] ?>
    </footer>
</div>

<div class="form-acciones no-imprimir" style="margin-top:18px">
    <button type="button" class="btn btn-primario" onclick="window.print()">Imprimir o guardar en PDF</button>
    <a href="<?= BASE_URL ?>sistema/controladores/ControladorTurno.php?accion=detalle&id=<?= (int) $pago['id_turno'] ?>"
       class="btn btn-secundario">Ver el turno</a>
    <a href="<?= BASE_URL ?>dashboard.php" class="btn btn-secundario">Volver al inicio</a>
</div>

<?php require __DIR__ . '/../layouts/footer.php'; ?>

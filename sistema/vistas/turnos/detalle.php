<?php
// sistema/vistas/turnos/detalle.php — Ficha completa de un turno
// -----------------------------------------------------------------
// La usan el paciente (desde su panel) y el personal (desde el listado).
// El control de quién puede verla ya lo hizo turnoPropio() en el
// controlador: acá no se vuelve a decidir nada de permisos, sólo se
// muestra lo que llegó.
//
// Recibe: $turno (v_turnos_detalle), $historial, $motivoNoMover.
// -----------------------------------------------------------------

$esPaciente   = ($_SESSION['rol'] ?? '') === 'paciente';
$paginaTitulo = 'Detalle del turno';
$breadcrumb   = $esPaciente
    ? '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / Detalle del turno'
    : '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / <a href="' . BASE_URL
      . 'sistema/controladores/ControladorTurno.php?accion=index">Turnos</a> / Detalle';
$cssExtra     = ['paciente.css'];

require __DIR__ . '/../layouts/navbar.php';

$esp      = (new Turno($pdo))->datosEspecialidad((int) $turno['id_especialidad']);
$debePagar = ($turno['estado_pago'] ?? '') === 'Pendiente';

$meses = ['','enero','febrero','marzo','abril','mayo','junio','julio',
          'agosto','septiembre','octubre','noviembre','diciembre'];
$dias  = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
$ts    = strtotime($turno['fecha']);
$fechaTexto = ucfirst($dias[(int) date('w', $ts)]) . ' ' . (int) date('j', $ts)
            . ' de ' . $meses[(int) date('n', $ts)] . ' de ' . date('Y', $ts);
?>

<?php if (!empty($_GET['msg']) || !empty($_GET['err'])): ?>
<div class="alerta alerta-<?= !empty($_GET['err']) ? 'error' : 'exito' ?>" role="alert">
    <?php
    $avisos = [
        'reprogramado' => 'El turno quedó reprogramado.',
        'calificado'   => '¡Gracias! Tu calificación quedó registrada.',
    ];
    echo htmlspecialchars(!empty($_GET['err'])
        ? urldecode($_GET['err'])
        : ($avisos[$_GET['msg'] ?? ''] ?? 'Listo.'));
    ?>
</div>
<?php endif; ?>

<div class="pac-detalle-grid">

    <!-- ══════════ Datos del turno ══════════ -->
    <div class="panel">
        <div class="panel-header">
            <span class="panel-titulo">Turno #<?= (int) $turno['id_turno'] ?></span>
            <span class="badge badge-<?= mb_strtolower($turno['estado']) ?>">
                <?= htmlspecialchars($turno['estado']) ?>
            </span>
        </div>
        <div class="panel-body">
            <ul class="pac-datos-lista">
                <li><span class="etq">Profesional</span>
                    <span class="val">Dr/a. <?= htmlspecialchars($turno['medico']) ?></span></li>
                <li><span class="etq">Especialidad</span>
                    <span class="val"><?= htmlspecialchars($turno['especialidad']) ?></span></li>
                <li><span class="etq">Fecha</span>
                    <span class="val"><?= htmlspecialchars($fechaTexto) ?></span></li>
                <li><span class="etq">Hora</span>
                    <span class="val"><?= htmlspecialchars(substr($turno['hora_inicio'], 0, 5)) ?> hs</span></li>
                <?php if ($esp && $esp['duracion_turno_min']): ?>
                <li><span class="etq">Duración estimada</span>
                    <span class="val"><?= (int) $esp['duracion_turno_min'] ?> minutos</span></li>
                <?php endif; ?>
                <li><span class="etq">Consultorio</span>
                    <span class="val"><?= htmlspecialchars($turno['consultorio']) ?></span></li>
                <?php if (!$esPaciente): ?>
                <li><span class="etq">Paciente</span>
                    <span class="val"><?= htmlspecialchars($turno['paciente']) ?>
                        (DNI <?= htmlspecialchars($turno['paciente_dni']) ?>)</span></li>
                <?php endif; ?>
                <li><span class="etq">Cobertura</span>
                    <span class="val"><?= htmlspecialchars($turno['obra_social'] . ' — ' . $turno['plan']) ?></span></li>
                <?php if (!empty($turno['nro_afiliado'])): ?>
                <li><span class="etq">N.º de afiliado</span>
                    <span class="val"><?= htmlspecialchars($turno['nro_afiliado']) ?></span></li>
                <?php endif; ?>
                <?php if ($turno['id_pago']): ?>
                <li><span class="etq">Importe</span>
                    <span class="val">$<?= number_format((float) $turno['monto_total'], 2, ',', '.') ?></span></li>
                <li><span class="etq">Estado del pago</span>
                    <span class="val">
                        <span class="badge badge-<?= $turno['estado_pago'] === 'Pagado' ? 'activo' : ($debePagar ? 'ausente' : 'inactivo') ?>">
                            <?= htmlspecialchars($turno['estado_pago']) ?>
                        </span>
                        <?php if (!empty($turno['metodo_pago'])): ?>
                            · <?= htmlspecialchars($turno['metodo_pago']) ?>
                        <?php endif; ?>
                    </span></li>
                <?php endif; ?>
                <?php if (!empty($turno['observacion'])): ?>
                <li><span class="etq">Observación</span>
                    <span class="val"><?= htmlspecialchars($turno['observacion']) ?></span></li>
                <?php endif; ?>
            </ul>

            <?php if ($debePagar): ?>
            <div class="pac-pago-aviso" style="margin-top:18px">
                <div>
                    <strong>Falta abonar $<?= number_format((float) $turno['monto_total'], 2, ',', '.') ?></strong>
                    <span>Hasta el <?= htmlspecialchars(date('d/m/Y H:i', strtotime($turno['pago_vence']))) ?>,
                          o el turno se libera.</span>
                </div>
                <a href="<?= BASE_URL ?>sistema/controladores/ControladorPago.php?accion=elegir&id_pago=<?= (int) $turno['id_pago'] ?>"
                   class="btn btn-primario">Pagar ahora</a>
            </div>
            <?php endif; ?>

            <div class="form-acciones" style="margin-top:20px">
                <?php if ($motivoNoMover === null): ?>
                    <a href="<?= BASE_URL ?>sistema/controladores/ControladorTurno.php?accion=reprogramar&id=<?= (int) $turno['id_turno'] ?>"
                       class="btn btn-secundario">Reprogramar</a>
                    <button type="button" class="btn btn-peligro" data-modal="modal-cancelar-det">Cancelar turno</button>
                <?php else: ?>
                    <span class="pac-nota"><?= htmlspecialchars($motivoNoMover) ?></span>
                <?php endif; ?>
                <a href="<?= $esPaciente ? BASE_URL . 'dashboard.php'
                        : BASE_URL . 'sistema/controladores/ControladorTurno.php?accion=index' ?>"
                   class="btn btn-secundario">Volver</a>
            </div>
        </div>
    </div>

    <!-- ══════════ Calificación ══════════
         Sólo aparece cuando tiene sentido: una consulta ya realizada y
         todavía sin calificar. Mostrar el formulario antes de la
         consulta invitaría a puntuar algo que no pasó. -->
    <?php if ($calificacion): ?>
    <div class="panel">
        <div class="panel-header"><span class="panel-titulo">Tu calificación</span></div>
        <div class="panel-body">
            <div class="cal-dadas" aria-label="<?= (int) $calificacion['puntaje'] ?> de 5 estrellas">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <span class="<?= $i <= (int) $calificacion['puntaje'] ? 'llena' : '' ?>" aria-hidden="true">★</span>
                <?php endfor; ?>
                <span class="cal-dadas-num"><?= (int) $calificacion['puntaje'] ?>/5</span>
            </div>
            <?php if (!empty($calificacion['comentario'])): ?>
            <p class="cal-comentario"><?= htmlspecialchars($calificacion['comentario']) ?></p>
            <?php endif; ?>
            <p class="form-hint" style="margin-top:10px">
                Calificaste el <?= htmlspecialchars(date('d/m/Y', strtotime($calificacion['creada_en']))) ?>.
                No se puede modificar.
            </p>
        </div>
    </div>

    <?php elseif ($esPaciente && $motivoNoCalif === null): ?>
    <div class="panel">
        <div class="panel-header"><span class="panel-titulo">¿Cómo estuvo la consulta?</span></div>
        <div class="panel-body">
            <form method="POST" action="<?= BASE_URL ?>sistema/controladores/ControladorTurno.php?accion=calificar">
                <?php csrf_field(); ?>
                <input type="hidden" name="id_turno" value="<?= (int) $turno['id_turno'] ?>">

                <?php // Radios de verdad, no <div>s con onclick: se operan con el
                      // teclado, las lee un lector de pantalla y el formulario se
                      // puede enviar sin JavaScript. ?>
                <fieldset class="cal-estrellas">
                    <legend class="solo-lectores">Puntaje</legend>
                    <?php foreach ([5 => 'Excelente', 4 => 'Muy buena', 3 => 'Buena',
                                    2 => 'Regular', 1 => 'Mala'] as $n => $rotulo): ?>
                    <label title="<?= $rotulo ?>">
                        <input type="radio" name="puntaje" value="<?= $n ?>" required>
                        <span aria-hidden="true">★</span>
                        <span class="solo-lectores"><?= $n ?> — <?= $rotulo ?></span>
                    </label>
                    <?php endforeach; ?>
                </fieldset>

                <div class="form-group" style="margin-top:14px">
                    <label for="comentario">Comentario <span class="form-hint">(opcional)</span></label>
                    <textarea name="comentario" id="comentario" class="form-control" rows="3"
                              maxlength="400" placeholder="Contanos cómo te atendieron"></textarea>
                </div>

                <div class="form-acciones">
                    <button type="submit" class="btn btn-primario">Enviar calificación</button>
                </div>
                <p class="form-hint" style="margin-top:10px">
                    Se publica con tu inicial y apellido. Se puede enviar una sola vez.
                </p>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══════════ Qué le fue pasando ══════════ -->
    <div class="panel">
        <div class="panel-header"><span class="panel-titulo">Historial del turno</span></div>
        <div class="panel-body">
            <?php if (!$historial): ?>
                <p class="form-hint">Todavía no hubo movimientos.</p>
            <?php else: ?>
                <ul class="pac-linea">
                    <?php foreach (array_reverse($historial) as $h): ?>
                    <li>
                        <div class="hito">
                            <?php // Cuando los dos estados coinciden no hubo cambio de
                                  // situación: es una reprogramación. El texto lo aclara
                                  // en vez de mostrar "Reservado → Reservado", que no
                                  // le dice nada a nadie. ?>
                            <?= $h['estado_anterior'] === $h['estado_nuevo']
                                ? 'Cambio de horario'
                                : htmlspecialchars($h['estado_anterior'] . ' → ' . $h['estado_nuevo']) ?>
                        </div>
                        <div class="cuando"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($h['fecha_cambio']))) ?></div>
                        <?php if (!empty($h['observacion'])): ?>
                        <div class="detalle"><?= htmlspecialchars($h['observacion']) ?></div>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($motivoNoMover === null): ?>
<div class="modal-overlay" id="modal-cancelar-det">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-titulo">Cancelar turno</span>
            <button class="btn-cerrar-modal" aria-label="Cerrar">✕</button>
        </div>
        <form method="POST" action="<?= BASE_URL ?>sistema/controladores/ControladorTurno.php?accion=cancelar">
            <?php csrf_field(); ?>
            <input type="hidden" name="id_turno" value="<?= (int) $turno['id_turno'] ?>">
            <?php if ($esPaciente): ?>
            <input type="hidden" name="volver" value="dashboard">
            <?php endif; ?>
            <div class="modal-body">
                <p class="mb-14">¿Seguro que querés cancelar este turno?</p>
                <p class="pac-cancelar-resumen">
                    <?= htmlspecialchars($turno['especialidad'] . ' con Dr/a. ' . $turno['medico']
                        . ' — ' . $fechaTexto . ', ' . substr($turno['hora_inicio'], 0, 5) . ' hs') ?>
                </p>
                <div class="form-group">
                    <label for="obs-cancelar">Motivo <span class="form-hint">(opcional)</span></label>
                    <input type="text" name="observacion" id="obs-cancelar" class="form-control" maxlength="200">
                </div>
                <p class="form-hint" style="margin-top:12px">
                    El horario queda libre para otra persona.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secundario btn-cerrar-modal">Volver</button>
                <button type="submit" class="btn btn-peligro">Sí, cancelar</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../layouts/footer.php'; ?>

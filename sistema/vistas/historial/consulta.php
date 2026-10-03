<?php
// sistema/vistas/historial/consulta.php — Ficha clínica (lado médico)
// -----------------------------------------------------------------
// Lo que el profesional escribe después de atender: motivo, diagnóstico
// e indicaciones, más los estudios que pide y sus resultados.
//
// El controlador ya verificó que el turno sea de ESTE médico; acá sólo
// se muestra. Recibe: $turno, $consulta, $estudios, $mensaje.
// -----------------------------------------------------------------

$paginaTitulo = 'Ficha de la consulta';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / Ficha de la consulta';
$cssExtra     = ['paciente.css'];

require __DIR__ . '/../layouts/navbar.php';

$URL = BASE_URL . 'sistema/controladores/ControladorHistorial.php';

$MESES = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
          'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$ts = strtotime($turno['fecha']);
$fechaTexto = (int) date('j', $ts) . ' de ' . $MESES[(int) date('n', $ts)] . ' de ' . date('Y', $ts);

$yaRealizado = $turno['estado'] === 'Realizado';
?>

<?php if (!empty($mensaje)): ?>
<div class="alerta alerta-error" role="alert"><?= htmlspecialchars($mensaje) ?></div>
<?php endif; ?>

<?php if (!empty($_GET['msg'])): ?>
<div class="alerta alerta-exito" role="alert">
    <?php
    $avisos = [
        'guardada'       => 'La ficha quedó guardada. El paciente ya puede verla en su historial.',
        'estudio_pedido' => 'El estudio quedó registrado y el paciente fue notificado.',
        'resultado_ok'   => 'El resultado se cargó. El paciente ya puede verlo.',
    ];
    echo htmlspecialchars($avisos[$_GET['msg']] ?? 'Listo.');
    ?>
</div>
<?php endif; ?>

<!-- ══════════ Encabezado del turno ══════════ -->
<div class="hc-cabecera">
    <div>
        <h1><?= htmlspecialchars($turno['paciente']) ?></h1>
        <p>
            DNI <?= htmlspecialchars($turno['paciente_dni']) ?> ·
            <?= htmlspecialchars($turno['especialidad']) ?> ·
            <?= htmlspecialchars($fechaTexto) ?>,
            <?= htmlspecialchars(substr($turno['hora_inicio'], 0, 5)) ?> hs
        </p>
    </div>
    <span class="badge badge-<?= mb_strtolower($turno['estado']) ?>">
        <?= htmlspecialchars($turno['estado']) ?>
    </span>
</div>

<?php if (!$yaRealizado): ?>
<?php // No se puede escribir el diagnóstico de algo que todavía no pasó:
      // sería registrar una atención que no existió. El servidor lo
      // rechaza igual; esto se lo explica antes de que lo intente. ?>
<div class="alerta alerta-info">
    Este turno todavía figura como <strong><?= htmlspecialchars(mb_strtolower($turno['estado'])) ?></strong>.
    La ficha se puede completar una vez que lo marques como atendido desde tu agenda.
</div>
<?php endif; ?>

<div class="hc-grid">

    <!-- ══════════ Ficha clínica ══════════ -->
    <div class="panel">
        <div class="panel-header">
            <span class="panel-titulo">Ficha clínica</span>
            <?php if ($consulta): ?>
            <span class="form-hint">
                Registrada el <?= htmlspecialchars(date('d/m/Y H:i', strtotime($consulta['creada_en']))) ?>
            </span>
            <?php endif; ?>
        </div>
        <div class="panel-body">
            <form method="POST" action="<?= $URL ?>?accion=guardarConsulta">
                <?php csrf_field(); ?>
                <input type="hidden" name="id_turno" value="<?= (int) $turno['id_turno'] ?>">

                <div class="form-group mb-14">
                    <label for="motivo">Motivo de consulta <span class="req">*</span></label>
                    <input type="text" name="motivo_consulta" id="motivo" class="form-control"
                           maxlength="200" required <?= $yaRealizado ? '' : 'disabled' ?>
                           value="<?= htmlspecialchars($consulta['motivo_consulta'] ?? '') ?>"
                           placeholder="Ej: control de presión arterial">
                </div>

                <div class="form-group mb-14">
                    <label for="diagnostico">Diagnóstico</label>
                    <textarea name="diagnostico" id="diagnostico" class="form-control" rows="4"
                              <?= $yaRealizado ? '' : 'disabled' ?>
                              placeholder="Lo que observaste y tu evaluación"><?= htmlspecialchars($consulta['diagnostico'] ?? '') ?></textarea>
                </div>

                <div class="form-group mb-14">
                    <label for="indicaciones">Indicaciones</label>
                    <textarea name="indicaciones" id="indicaciones" class="form-control" rows="4"
                              <?= $yaRealizado ? '' : 'disabled' ?>
                              placeholder="Qué debe hacer el paciente"><?= htmlspecialchars($consulta['indicaciones'] ?? '') ?></textarea>
                    <span class="form-hint">
                        Esto lo lee el paciente tal cual desde su historial.
                    </span>
                </div>

                <?php if ($yaRealizado): ?>
                <div class="form-acciones">
                    <button type="submit" class="btn btn-primario">
                        <?= $consulta ? 'Guardar cambios' : 'Guardar ficha' ?>
                    </button>
                    <a href="<?= BASE_URL ?>dashboard.php" class="btn btn-secundario">Volver a mi agenda</a>
                </div>
                <?php if (!$consulta): ?>
                <p class="form-hint" style="margin-top:10px">
                    Al guardarla por primera vez le avisamos al paciente. Si después
                    la corregís, no se le vuelve a notificar.
                </p>
                <?php endif; ?>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- ══════════ Estudios ══════════ -->
    <div class="panel">
        <div class="panel-header"><span class="panel-titulo">Estudios</span></div>
        <div class="panel-body">

            <?php if ($yaRealizado): ?>
            <form method="POST" action="<?= $URL ?>?accion=pedirEstudio" class="hc-pedir">
                <?php csrf_field(); ?>
                <input type="hidden" name="id_turno" value="<?= (int) $turno['id_turno'] ?>">
                <div class="form-group">
                    <label for="tipo">Tipo</label>
                    <input type="text" name="tipo" id="tipo" class="form-control" maxlength="60"
                           list="tipos-estudio" required placeholder="Laboratorio">
                    <?php // Sugerencias, no una lista cerrada: el catálogo de
                          // estudios es infinito y encerrarlo en un <select>
                          // significaría una migración cada vez que aparece uno. ?>
                    <datalist id="tipos-estudio">
                        <option value="Laboratorio"></option>
                        <option value="Radiografía"></option>
                        <option value="Ecografía"></option>
                        <option value="Tomografía"></option>
                        <option value="Resonancia"></option>
                        <option value="Electrocardiograma"></option>
                    </datalist>
                </div>
                <div class="form-group">
                    <label for="nombre">Estudio</label>
                    <input type="text" name="nombre" id="nombre" class="form-control" maxlength="150"
                           required placeholder="Hemograma completo">
                </div>
                <button type="submit" class="btn btn-secundario">Solicitar</button>
            </form>
            <?php endif; ?>

            <?php if (!$estudios): ?>
                <p class="form-hint">Este paciente todavía no tiene estudios registrados.</p>
            <?php else: ?>
            <ul class="hc-estudios">
                <?php foreach ($estudios as $e): ?>
                <li>
                    <div class="hc-estudio-datos">
                        <strong><?= htmlspecialchars($e['nombre']) ?></strong>
                        <span>
                            <?= htmlspecialchars($e['tipo']) ?> ·
                            pedido el <?= htmlspecialchars(date('d/m/Y', strtotime($e['solicitado_en']))) ?>
                            por Dr/a. <?= htmlspecialchars($e['medico']) ?>
                        </span>
                    </div>

                    <div class="hc-estudio-accion">
                        <?php if ($e['estado'] === 'Disponible'): ?>
                            <span class="badge badge-activo">Con resultado</span>
                            <a href="<?= $URL ?>?accion=descargar&id=<?= (int) $e['id_estudio'] ?>"
                               class="btn btn-secundario btn-sm" target="_blank" rel="noopener">Ver</a>
                        <?php else: ?>
                            <span class="badge badge-ausente">Pendiente</span>
                        <?php endif; ?>
                    </div>

                    <?php // El formulario de carga aparece sólo para quien PIDIÓ el
                          // estudio. El resto ve el estudio y su resultado —eso es
                          // atención clínica— pero no puede reemplazarlo. El
                          // servidor lo revalida igual. ?>
                    <?php if ($yaRealizado && (int) $e['matricula'] === (int) $turno['matricula']): ?>
                    <form method="POST" action="<?= $URL ?>?accion=subirResultado"
                          enctype="multipart/form-data" class="hc-subir">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="id_turno"   value="<?= (int) $turno['id_turno'] ?>">
                        <input type="hidden" name="id_estudio" value="<?= (int) $e['id_estudio'] ?>">
                        <input type="file" name="resultado" accept="application/pdf,image/jpeg,image/png" required
                               aria-label="Archivo del resultado de <?= htmlspecialchars($e['nombre']) ?>">
                        <button type="submit" class="btn btn-secundario btn-sm">
                            <?= $e['estado'] === 'Disponible' ? 'Reemplazar' : 'Cargar resultado' ?>
                        </button>
                    </form>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <p class="form-hint" style="margin-top:12px">
                PDF, JPG o PNG, hasta 8 MB. Los resultados no se sirven por URL
                pública: se entregan sólo a quien tiene permiso de verlos.
            </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══════════ Recetas ══════════ -->
    <?php // Se muestran las que emitió ESTE profesional a este paciente.
          // La prescripción se carga en su propia pantalla y no en un
          // tercer formulario acá: una receta son varios medicamentos con
          // su dosis cada uno, y meterlo en esta columna haría la ficha
          // inmanejable. ?>
    <div class="panel">
        <div class="panel-header">
            <span class="panel-titulo">Recetas</span>
            <?php if ($yaRealizado): ?>
            <a href="<?= BASE_URL ?>sistema/controladores/ControladorReceta.php?accion=nueva&turno=<?= (int) $turno['id_turno'] ?>"
               class="btn btn-primario btn-sm">Emitir receta</a>
            <?php endif; ?>
        </div>
        <div class="panel-body">
            <?php if (!$yaRealizado): ?>
                <p class="form-hint">
                    Vas a poder emitir una receta cuando marques el turno como atendido.
                </p>
            <?php endif; ?>

            <?php if (!$recetas): ?>
                <p class="form-hint">Todavía no le emitiste ninguna receta a este paciente.</p>
            <?php else: ?>
            <ul class="hc-estudios">
                <?php foreach ($recetas as $rp): ?>
                <li>
                    <div class="hc-estudio-datos">
                        <strong><?= htmlspecialchars($rp['medicamentos'] ?? '—') ?></strong>
                        <span>
                            <?= htmlspecialchars(date('d/m/Y', strtotime($rp['emitida_el']))) ?>
                            · <?= (int) $rp['items'] ?> medicamento<?= (int) $rp['items'] === 1 ? '' : 's' ?>
                            <?php if ($rp['situacion'] === 'Vigente'): ?>
                                · vence el <?= htmlspecialchars(date('d/m/Y', strtotime($rp['vence_el']))) ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="hc-estudio-accion">
                        <span class="badge badge-<?= $rp['situacion'] === 'Vigente' ? 'activo'
                            : ($rp['situacion'] === 'Anulada' ? 'cancelado' : 'ausente') ?>">
                            <?= htmlspecialchars($rp['situacion']) ?>
                        </span>
                        <a href="<?= BASE_URL ?>sistema/controladores/ControladorReceta.php?accion=ver&id=<?= (int) $rp['id_receta'] ?>"
                           class="btn btn-secundario btn-sm">Ver</a>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../layouts/footer.php'; ?>

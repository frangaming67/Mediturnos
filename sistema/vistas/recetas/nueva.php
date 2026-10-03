<?php
// sistema/vistas/recetas/nueva.php — Emitir una receta (lado médico)
// -----------------------------------------------------------------
// El controlador ya verificó que el turno sea de ESTE médico; acá sólo
// se dibuja el formulario.
//
// ── POR QUÉ LOS RENGLONES SON CAMPOS REPETIDOS Y NO JAVASCRIPT ──
// Cada medicamento es una fila de `med_nombre[]`, `med_dosis[]`… El
// formulario trae tres renglones vacíos y funciona sin una línea de
// JavaScript: se completan los que se necesiten y los vacíos se
// descartan en el servidor. El botón "Agregar otro" clona un renglón y
// es un extra —si el JavaScript no corre, siguen estando los tres—.
//
// Es la misma decisión que en el asistente de reserva: la pantalla tiene
// que funcionar sin JavaScript, y lo que agrega JavaScript es comodidad,
// nunca la única forma de hacer la operación.
//
// Recibe: $turno, $recetas, $mensaje, $URL.
// -----------------------------------------------------------------

$paginaTitulo = 'Nueva receta';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / Nueva receta';
$cssExtra     = ['paciente.css'];

require __DIR__ . '/../layouts/navbar.php';

$MESES = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
          'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$ts         = strtotime($turno['fecha']);
$fechaTexto = (int) date('j', $ts) . ' de ' . $MESES[(int) date('n', $ts)] . ' de ' . date('Y', $ts);

$yaRealizado = $turno['estado'] === 'Realizado';
$RENGLONES   = 3;
?>

<?php if (!empty($mensaje)): ?>
<div class="alerta alerta-error" role="alert"><?= htmlspecialchars($mensaje) ?></div>
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
<?php // Prescribir es un acto de la atención: no se puede recetar sobre
      // una consulta que todavía no pasó. El servidor lo rechaza igual;
      // esto se lo explica antes de que lo intente. ?>
<div class="alerta alerta-info">
    Este turno todavía figura como <strong><?= htmlspecialchars(mb_strtolower($turno['estado'])) ?></strong>.
    Vas a poder emitir la receta una vez que lo marques como atendido desde tu agenda.
</div>
<?php endif; ?>

<div class="hc-grid">

    <!-- ══════════ El formulario ══════════ -->
    <div class="panel">
        <div class="panel-header"><span class="panel-titulo">Nueva receta</span></div>
        <div class="panel-body">
            <form method="POST" action="<?= $URL ?>?accion=emitir" id="formReceta">
                <?php csrf_field(); ?>
                <input type="hidden" name="id_turno" value="<?= (int) $turno['id_turno'] ?>">

                <div class="form-group mb-14">
                    <label for="diagnostico">Diagnóstico</label>
                    <input type="text" name="diagnostico" id="diagnostico" class="form-control"
                           maxlength="200" <?= $yaRealizado ? '' : 'disabled' ?>
                           placeholder="Ej: hipertensión arterial">
                </div>

                <span class="hist-rotulo">Medicamentos</span>
                <p class="form-hint" style="margin-bottom:10px">
                    Nombre, dosis y frecuencia son obligatorios en cada renglón.
                    Los que dejes vacíos no se guardan.
                </p>

                <div id="renglones">
                    <?php for ($i = 0; $i < $RENGLONES; $i++): ?>
                    <fieldset class="rec-renglon">
                        <legend class="solo-lectores">Medicamento <?= $i + 1 ?></legend>
                        <div class="form-group">
                            <label for="med_nombre_<?= $i ?>">Medicamento <?php if ($i === 0): ?><span class="req">*</span><?php endif; ?></label>
                            <input type="text" name="med_nombre[]" id="med_nombre_<?= $i ?>"
                                   class="form-control" maxlength="150"
                                   <?= $yaRealizado ? '' : 'disabled' ?>
                                   <?= $i === 0 ? 'required' : '' ?>
                                   placeholder="Enalapril">
                        </div>
                        <div class="form-group">
                            <label for="med_pres_<?= $i ?>">Presentación</label>
                            <input type="text" name="med_pres[]" id="med_pres_<?= $i ?>"
                                   class="form-control" maxlength="100"
                                   <?= $yaRealizado ? '' : 'disabled' ?>
                                   placeholder="comprimidos 10 mg">
                        </div>
                        <div class="form-group">
                            <label for="med_dosis_<?= $i ?>">Dosis <?php if ($i === 0): ?><span class="req">*</span><?php endif; ?></label>
                            <input type="text" name="med_dosis[]" id="med_dosis_<?= $i ?>"
                                   class="form-control" maxlength="100"
                                   <?= $yaRealizado ? '' : 'disabled' ?>
                                   <?= $i === 0 ? 'required' : '' ?>
                                   placeholder="1 comprimido">
                        </div>
                        <div class="form-group">
                            <label for="med_frec_<?= $i ?>">Frecuencia <?php if ($i === 0): ?><span class="req">*</span><?php endif; ?></label>
                            <input type="text" name="med_frec[]" id="med_frec_<?= $i ?>"
                                   class="form-control" maxlength="100"
                                   <?= $yaRealizado ? '' : 'disabled' ?>
                                   <?= $i === 0 ? 'required' : '' ?>
                                   placeholder="cada 12 horas">
                        </div>
                        <div class="form-group">
                            <label for="med_dur_<?= $i ?>">Duración</label>
                            <input type="text" name="med_dur[]" id="med_dur_<?= $i ?>"
                                   class="form-control" maxlength="100"
                                   <?= $yaRealizado ? '' : 'disabled' ?>
                                   placeholder="por 30 días">
                        </div>
                        <div class="form-group">
                            <label for="med_cant_<?= $i ?>">Envases</label>
                            <input type="number" name="med_cant[]" id="med_cant_<?= $i ?>"
                                   class="form-control" min="1" max="99" value="1"
                                   <?= $yaRealizado ? '' : 'disabled' ?>>
                        </div>
                    </fieldset>
                    <?php endfor; ?>
                </div>

                <?php if ($yaRealizado): ?>
                <?php // Nace oculto y lo muestra el propio script: si el
                      // JavaScript no corre, no queda un botón que no hace
                      // nada —el peor resultado posible—. ?>
                <button type="button" id="btnOtro" class="btn btn-secundario btn-sm"
                        style="display:none;margin-bottom:16px">+ Agregar otro medicamento</button>
                <?php endif; ?>

                <div class="form-group mb-14">
                    <label for="indicaciones">Indicaciones generales</label>
                    <textarea name="indicaciones" id="indicaciones" class="form-control" rows="3"
                              <?= $yaRealizado ? '' : 'disabled' ?>
                              placeholder="Lo que el paciente tiene que tener en cuenta"></textarea>
                    <span class="form-hint">Esto lo lee el paciente tal cual en su receta.</span>
                </div>

                <div class="form-group mb-14" style="max-width:220px">
                    <label for="dias">Vigencia (días)</label>
                    <input type="number" name="dias" id="dias" class="form-control"
                           min="1" max="365" value="<?= Receta::VIGENCIA_DIAS ?>"
                           <?= $yaRealizado ? '' : 'disabled' ?>>
                    <span class="form-hint">Por omisión, <?= Receta::VIGENCIA_DIAS ?> días.</span>
                </div>

                <?php if ($yaRealizado): ?>
                <div class="form-acciones">
                    <button type="submit" class="btn btn-primario">Emitir receta</button>
                    <a href="<?= BASE_URL ?>sistema/controladores/ControladorHistorial.php?accion=consulta&id=<?= (int) $turno['id_turno'] ?>"
                       class="btn btn-secundario">Volver a la ficha</a>
                </div>
                <p class="form-hint" style="margin-top:10px">
                    Al emitirla le avisamos al paciente por correo y queda en su cuenta.
                </p>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- ══════════ Lo que ya le receté ══════════ -->
    <div class="panel">
        <div class="panel-header">
            <span class="panel-titulo">Mis recetas a este paciente</span>
        </div>
        <div class="panel-body">
            <?php if (!$recetas): ?>
                <p class="form-hint">Todavía no le emitiste ninguna receta.</p>
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
                        <a href="<?= $URL ?>?accion=ver&id=<?= (int) $rp['id_receta'] ?>"
                           class="btn btn-secundario btn-sm">Ver</a>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <p class="form-hint" style="margin-top:14px">
                Las recetas no se borran: si una quedó mal, se anula desde su detalle
                y el paciente recibe el aviso.
            </p>
        </div>
    </div>
</div>

<?php if ($yaRealizado): ?>
<script>
// Agregar renglones: comodidad, no requisito. Clona el primer renglón y
// le limpia los valores. Los `id` se renumeran para que cada <label>
// siga apuntando a su campo — sin eso, hacer clic en el rótulo del
// renglón nuevo enfocaría el del primero.
(function () {
    var caja  = document.getElementById('renglones');
    var boton = document.getElementById('btnOtro');
    if (!caja || !boton) { return; }

    boton.style.display = '';   // recién acá existe de verdad

    boton.addEventListener('click', function () {
        var renglones = caja.querySelectorAll('.rec-renglon');
        if (renglones.length >= 20) { return; }   // el mismo techo que el servidor

        var nuevo = renglones[0].cloneNode(true);
        var n     = renglones.length;

        nuevo.querySelectorAll('input').forEach(function (campo) {
            campo.removeAttribute('required');
            campo.value = campo.type === 'number' ? '1' : '';
            if (campo.id) {
                var base = campo.id.replace(/_\d+$/, '');
                campo.id = base + '_' + n;
                var rotulo = nuevo.querySelector('label[for^="' + base + '_"]');
                if (rotulo) { rotulo.setAttribute('for', campo.id); }
            }
        });
        // El asterisco de obligatorio es sólo del primer renglón.
        nuevo.querySelectorAll('.req').forEach(function (r) { r.remove(); });

        var leyenda = nuevo.querySelector('legend');
        if (leyenda) { leyenda.textContent = 'Medicamento ' + (n + 1); }

        caja.appendChild(nuevo);
        var primero = nuevo.querySelector('input');
        if (primero) { primero.focus(); }
    });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../layouts/footer.php'; ?>

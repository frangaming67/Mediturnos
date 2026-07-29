<?php
// sistema/vistas/turnos/reprogramar.php — Mover un turno de día u hora
// -----------------------------------------------------------------
// Recibe: $turno, $fechaElegida, $slots, $mensaje.
//
// El selector de fecha recarga la página con ?fecha=... en vez de pedir
// los horarios por AJAX. Es a propósito: así la pantalla FUNCIONA sin
// JavaScript, se puede volver atrás con el botón del navegador y cada
// día tiene su propia dirección para compartir o recargar. El JS que
// hay abajo sólo evita el clic extra en "Ver horarios".
// -----------------------------------------------------------------

$esPaciente   = ($_SESSION['rol'] ?? '') === 'paciente';
$paginaTitulo = 'Reprogramar turno';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / '
              . '<a href="' . BASE_URL . 'sistema/controladores/ControladorTurno.php?accion=detalle&id='
              . (int) $turno['id_turno'] . '">Turno #' . (int) $turno['id_turno'] . '</a> / Reprogramar';
$cssExtra     = ['paciente.css'];

require __DIR__ . '/../layouts/navbar.php';

$URL = BASE_URL . 'sistema/controladores/ControladorTurno.php';
?>

<?php if (!empty($mensaje)): ?>
<div class="alerta alerta-error" role="alert"><?= htmlspecialchars($mensaje) ?></div>
<?php endif; ?>

<div class="panel panel-sm">
    <div class="panel-header"><span class="panel-titulo">Elegí el horario nuevo</span></div>
    <div class="panel-body">

        <p class="pac-repro-actual">
            Ahora tenés este turno el
            <strong><?= htmlspecialchars(date('d/m/Y', strtotime($turno['fecha']))) ?></strong>
            a las <strong><?= htmlspecialchars(substr($turno['hora_inicio'], 0, 5)) ?> hs</strong>,
            con Dr/a. <strong><?= htmlspecialchars($turno['medico']) ?></strong>
            (<?= htmlspecialchars($turno['especialidad']) ?>).
            <?php // Se aclara qué NO cambia: sin esto la persona puede temer
                  // que mover el turno le cambie el profesional o el precio. ?>
            <br>Se mantienen el profesional, la especialidad y el importe: sólo cambia el día y la hora.
        </p>

        <form method="GET" action="<?= $URL ?>" class="form-inline mb-18">
            <input type="hidden" name="accion" value="reprogramar">
            <input type="hidden" name="id" value="<?= (int) $turno['id_turno'] ?>">
            <div class="form-group" style="flex:1">
                <label for="fecha">Día</label>
                <input type="date" id="fecha" name="fecha" class="form-control"
                       value="<?= htmlspecialchars($fechaElegida) ?>"
                       min="<?= date('Y-m-d') ?>"
                       max="<?= date('Y-m-d', strtotime('+3 months')) ?>">
            </div>
            <div class="form-group" style="align-self:flex-end">
                <button type="submit" class="btn btn-secundario" id="btn-ver">Ver horarios</button>
            </div>
        </form>

        <form method="POST" action="<?= $URL ?>?accion=guardarReprogramacion" id="form-repro">
            <?php csrf_field(); ?>
            <input type="hidden" name="id_turno" value="<?= (int) $turno['id_turno'] ?>">
            <input type="hidden" name="fecha" value="<?= htmlspecialchars($fechaElegida) ?>">
            <input type="hidden" name="slot" id="slot-elegido">

            <div class="form-group">
                <label>Horarios libres el <?= htmlspecialchars(date('d/m/Y', strtotime($fechaElegida))) ?></label>

                <?php if (!$slots): ?>
                    <p class="pac-slot-vacio">
                        <?php // Se explica el porqué: "no hay horarios" a secas deja a la
                              // persona sin saber si el médico no atiende, está de licencia
                              // o simplemente ya se llenó ese día. ?>
                        No hay horarios libres ese día. Puede ser que el profesional no
                        atienda esa jornada, que esté ausente o que ya se hayan tomado
                        todos los turnos. Probá con otra fecha.
                    </p>
                <?php else: ?>
                    <div class="pac-slots" role="group" aria-label="Horarios disponibles">
                        <?php foreach ($slots as $s): ?>
                        <?php // hora_full ('HH:MM:SS'), no hora ('HH:MM'): la columna es
                              // TIME y el controlador compara el horario nuevo contra el
                              // actual. Con el formato corto nunca coincidirían y no se
                              // detectaría que la persona eligió el mismo horario.
                              $valor = $s['hora_full'] . '|' . (int) $s['id_consultorio'] . '|' . (int) $s['id_especialidad']; ?>
                        <button type="button" class="pac-slot"
                                data-slot="<?= htmlspecialchars($valor) ?>"
                                aria-pressed="false">
                            <?= htmlspecialchars(substr($s['hora'], 0, 5)) ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <span class="form-hint" style="margin-top:10px;display:block">
                        Consultorio y duración se mantienen según la agenda del profesional.
                    </span>
                <?php endif; ?>
            </div>

            <div class="form-acciones">
                <button type="submit" class="btn btn-primario" id="btn-confirmar" disabled>
                    Confirmar el cambio
                </button>
                <a href="<?= $URL ?>?accion=detalle&id=<?= (int) $turno['id_turno'] ?>"
                   class="btn btn-secundario">Volver sin cambiar nada</a>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    'use strict';

    // Con JS, cambiar la fecha recarga sola: el botón "Ver horarios" queda
    // como respaldo para quien no lo tenga.
    const fecha = document.getElementById('fecha');
    const btnVer = document.getElementById('btn-ver');
    if (fecha && btnVer) {
        btnVer.style.display = 'none';
        fecha.addEventListener('change', () => fecha.form.submit());
    }

    // Elegir un horario habilita el botón de confirmar. Nace deshabilitado
    // para que no se pueda enviar el formulario sin haber elegido nada.
    const oculto = document.getElementById('slot-elegido');
    const btnOk  = document.getElementById('btn-confirmar');
    document.querySelectorAll('.pac-slot').forEach(b => {
        b.addEventListener('click', () => {
            document.querySelectorAll('.pac-slot').forEach(o => {
                o.classList.remove('elegido');
                o.setAttribute('aria-pressed', 'false');
            });
            b.classList.add('elegido');
            b.setAttribute('aria-pressed', 'true');
            oculto.value = b.dataset.slot;
            btnOk.disabled = false;
        });
    });
})();
</script>

<?php require __DIR__ . '/../layouts/footer.php'; ?>

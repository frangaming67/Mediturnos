<?php
// agendar.php — Reserva de turno en cuatro pasos
// -----------------------------------------------------------------
// Especialidad → Profesional → Día → Horario → Confirmación.
//
// ── POR QUÉ CADA PASO ES UNA URL Y NO UN ASISTENTE DE JAVASCRIPT ──
// Podría resolverse escondiendo y mostrando <div>s con JS, como hace el
// registro. Acá no conviene, por tres motivos concretos:
//
//   1. Cada paso tiene su propia dirección. El botón "atrás" del
//      navegador hace lo que la persona espera, y un enlace a un
//      profesional se puede compartir o guardar.
//   2. Los datos de cada paso DEPENDEN del anterior: los médicos salen
//      de la especialidad, los días del médico, los horarios del día.
//      Con un asistente de JS habría que traerlo todo por adelantado
//      —los horarios de los seis médicos para el próximo mes— o pedirlo
//      por AJAX igual. La navegación no ahorra nada.
//   3. Funciona sin JavaScript. Cada paso es un enlace o un formulario.
//
// ── VALIDACIÓN EN CASCADA ────────────────────────────────────
// Ningún paso confía en el anterior porque los parámetros vienen de la
// URL y se pueden escribir a mano. Que el médico atienda esa
// especialidad, que el día tenga agenda y que el horario esté LIBRE se
// vuelve a comprobar en el servidor en cada carga.
// -----------------------------------------------------------------
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/includes/seguridad.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/subida_imagen.php';
require_once __DIR__ . '/sistema/modelos/Turno.php';
require_once __DIR__ . '/sistema/modelos/Medico.php';
require_once __DIR__ . '/sistema/modelos/Pago.php';

iniciarSesionSegura();
cabecerasSeguridad();
verificarSesion();
verificarRol(['paciente']);

$modelo       = new Turno($pdo);
$modeloMedico = new Medico($pdo);
$modeloPago   = new Pago($pdo);

$idPaciente = (int) ($_SESSION['id_paciente'] ?? 0);

$paginaTitulo = 'Agendar cita';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / Agendar cita';
$cssExtra     = ['paciente.css'];

if ($idPaciente <= 0) {
    require_once __DIR__ . '/sistema/vistas/layouts/navbar.php';
    echo '<div class="alerta alerta-error">Tu cuenta no tiene una ficha de paciente '
       . 'asociada, así que todavía no podés reservar. Comunicate con la clínica.</div>';
    require_once __DIR__ . '/sistema/vistas/layouts/footer.php';
    exit;
}

// ── Parámetros del recorrido ─────────────────────────────────
$idEsp     = (int) ($_GET['esp']   ?? 0);
$matricula = (int) ($_GET['mat']   ?? 0);
$fecha     = trim($_GET['fecha']   ?? '');
$hora      = trim($_GET['hora']    ?? '');
$idCons    = (int) ($_GET['cons']  ?? 0);
$error     = !empty($_GET['err']) ? urldecode($_GET['err']) : null;

/** Arma la URL de un paso conservando lo ya elegido. */
$paso_url = function (array $cambios = []) use ($idEsp, $matricula, $fecha) {
    $base = ['esp' => $idEsp ?: null, 'mat' => $matricula ?: null, 'fecha' => $fecha ?: null];
    $qs   = array_filter(array_merge($base, $cambios), fn($v) => $v !== null && $v !== '');
    return BASE_URL . 'agendar.php' . ($qs ? '?' . http_build_query($qs) : '');
};

// ── Validación en cascada: cada paso desarma el siguiente si no cierra ──
$especialidad = $idEsp ? $modelo->datosEspecialidad($idEsp) : false;
if (!$especialidad) { $idEsp = 0; $matricula = 0; $fecha = ''; $hora = ''; }

$medicos = $idEsp ? $modeloMedico->listarParaReserva($idEsp) : [];

$medico = false;
if ($matricula) {
    foreach ($medicos as $m) {
        if ((int) $m['matricula'] === $matricula) { $medico = $m; break; }
    }
    // El médico no atiende esta especialidad (URL escrita a mano o
    // agenda dada de baja mientras la persona elegía).
    if (!$medico) { $matricula = 0; $fecha = ''; $hora = ''; }
}

$slots = [];
if ($matricula && $fecha !== '') {
    if (strtotime($fecha) < strtotime('today')) {
        $fecha = ''; $hora = '';
    } else {
        $slots = array_values(array_filter(
            $modelo->obtenerSlots($matricula, $fecha),
            fn($s) => (int) $s['id_especialidad'] === $idEsp
        ));
    }
}

// El horario tiene que seguir LIBRE. Acá se cae quien tenía la pantalla
// abierta mientras otra persona tomaba ese turno.
$slotElegido = false;
if ($hora !== '') {
    foreach ($slots as $s) {
        if ($s['hora_full'] === $hora && (int) $s['id_consultorio'] === $idCons) {
            $slotElegido = $s; break;
        }
    }
    if (!$slotElegido) {
        $hora = '';
        $error ??= 'Ese horario ya no está disponible. Elegí otro.';
    }
}

// ── En qué paso estamos ──────────────────────────────────────
$paso = 1;
if ($idEsp)                        $paso = 2;
if ($idEsp && $matricula)          $paso = 3;
if ($idEsp && $matricula && $fecha !== '') $paso = 4;
if ($slotElegido)                  $paso = 5;

// ── Datos propios de cada paso ───────────────────────────────
$especialidades = $paso === 1 ? $modelo->especialidadesParaReserva() : [];
$dias           = $paso === 3 ? $modelo->diasDisponibles($matricula, $idEsp, date('Y-m-d'), 28) : [];

$planes = [];
$costos = [];
if ($paso === 5) {
    $planes = $modelo->planesDePaciente($idPaciente);
    // El precio de cada cobertura se calcula por adelantado para que
    // cambiar el desplegable actualice el total sin recargar. Es sólo
    // informativo: el importe real lo vuelve a calcular el servidor al
    // crear el pago, así que no hay nada que un usuario pueda falsear.
    foreach ($planes as $pl) {
        $costos[(int) $pl['id_plan']] = $modeloPago->calcular($idEsp, (int) $pl['id_plan'], $matricula);
    }
}

// ── Ayudas de presentación ───────────────────────────────────
$MESES = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
$DIAS  = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
$plata = fn($n) => '$' . number_format((float) $n, 2, ',', '.');

$fechaLegible = function (string $f) use ($MESES, $DIAS) {
    $ts = strtotime($f);
    return ucfirst($DIAS[(int) date('w', $ts)]) . ' ' . (int) date('j', $ts)
         . ' de ' . $MESES[(int) date('n', $ts)] . '.';
};

$inicialesMedico = fn(array $m) => strtoupper(mb_substr($m['nombre'], 0, 1) . mb_substr($m['apellido'], 0, 1));

require_once __DIR__ . '/sistema/vistas/layouts/navbar.php';
?>

<header class="pac-saludo">
    <h1>Agendar cita</h1>
    <p>Seguí los pasos para reservar tu turno</p>
</header>

<?php if ($error): ?>
<div class="alerta alerta-error" role="alert"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- ══════════ Barra de pasos ══════════
     Los pasos ya recorridos son ENLACES: permiten volver a cambiar una
     elección sin empezar de cero. Los que faltan no son clickeables
     porque todavía no se sabe qué hay adentro. -->
<ol class="agd-pasos">
    <?php
    $etapas = [
        1 => ['Especialidad', $paso_url(['mat' => null, 'fecha' => null, 'hora' => null, 'cons' => null])],
        2 => ['Profesional',  $paso_url(['fecha' => null, 'hora' => null, 'cons' => null])],
        3 => ['Día',          $paso_url(['hora' => null, 'cons' => null])],
        4 => ['Horario',      $paso_url(['hora' => null, 'cons' => null])],
        5 => ['Confirmar',    null],
    ];
    // Los pasos 1 y 2 vuelven al inicio de su propia selección.
    $etapas[1][1] = BASE_URL . 'agendar.php';
    $etapas[2][1] = BASE_URL . 'agendar.php?esp=' . $idEsp;

    foreach ($etapas as $n => [$rotulo, $url]):
        $estado = $n < $paso ? 'hecho' : ($n === $paso ? 'activo' : '');
        $clic   = ($n < $paso && $url);
    ?>
    <li class="agd-paso <?= $estado ?>">
        <?php if ($clic): ?><a href="<?= htmlspecialchars($url) ?>"><?php else: ?><span><?php endif; ?>
            <span class="agd-paso-num"><?= $n < $paso ? '✓' : $n ?></span>
            <span class="agd-paso-rot"><?= $rotulo ?></span>
        <?php if ($clic): ?></a><?php else: ?></span><?php endif; ?>
    </li>
    <?php endforeach; ?>
</ol>

<div class="panel">
<?php if ($paso === 1): ?>
    <!-- ══════════ 1. ESPECIALIDAD ══════════ -->
    <div class="panel-header"><span class="panel-titulo">¿Qué especialidad necesitás?</span></div>
    <div class="panel-body">
        <?php if (!$especialidades): ?>
            <p class="pac-slot-vacio">No hay especialidades con agenda cargada en este momento.</p>
        <?php else: ?>
        <div class="agd-grid">
            <?php foreach ($especialidades as $e): ?>
            <a class="agd-tarjeta" href="<?= BASE_URL ?>agendar.php?esp=<?= (int) $e['id_especialidad'] ?>">
                <strong><?= htmlspecialchars($e['nombre']) ?></strong>
                <span class="agd-precio">desde <?= $plata($e['precio_consulta']) ?></span>
                <span class="agd-meta">
                    <?= (int) $e['medicos'] ?> profesional<?= $e['medicos'] > 1 ? 'es' : '' ?>
                    · <?= (int) $e['duracion_turno_min'] ?> min
                </span>
            </a>
            <?php endforeach; ?>
        </div>
        <p class="form-hint" style="margin-top:16px">
            El precio es de lista. Si tenés obra social, el descuento se aplica
            en el último paso.
        </p>
        <?php endif; ?>
    </div>

<?php elseif ($paso === 2): ?>
    <!-- ══════════ 2. PROFESIONAL ══════════ -->
    <div class="panel-header">
        <span class="panel-titulo">Profesionales de <?= htmlspecialchars($especialidad['nombre']) ?></span>
    </div>
    <div class="panel-body">
        <?php if (!$medicos): ?>
            <p class="pac-slot-vacio">
                No hay profesionales con agenda para esta especialidad.
                <a href="<?= BASE_URL ?>agendar.php">Elegí otra</a>.
            </p>
        <?php else: ?>
        <div class="agd-medicos">
            <?php foreach ($medicos as $m): ?>
            <?php $foto = SubidaImagen::url($m['foto']); ?>
            <a class="agd-medico" href="<?= BASE_URL ?>agendar.php?esp=<?= $idEsp ?>&mat=<?= (int) $m['matricula'] ?>">
                <span class="agd-medico-foto">
                    <?php if ($foto): ?>
                        <img src="<?= htmlspecialchars($foto) ?>" alt="">
                    <?php else: ?>
                        <?= htmlspecialchars($inicialesMedico($m)) ?>
                    <?php endif; ?>
                </span>
                <span class="agd-medico-datos">
                    <strong>Dr/a. <?= htmlspecialchars($m['apellido'] . ', ' . $m['nombre']) ?></strong>
                    <span class="agd-medico-esp"><?= htmlspecialchars($especialidad['nombre']) ?></span>
                    <span class="agd-medico-linea">
                        <span>Matrícula <?= (int) $m['matricula'] ?></span>
                        <span><?= htmlspecialchars($m['consultorios']) ?></span>
                    </span>
                </span>
                <span class="agd-medico-calif">
                    <?php if ($m['calificacion'] !== null): ?>
                        <span class="agd-estrellas" aria-hidden="true">★</span>
                        <strong><?= htmlspecialchars(number_format((float) $m['calificacion'], 1, ',', '')) ?></strong>
                        <span class="agd-votos"><?= (int) $m['votos'] ?> opinion<?= $m['votos'] == 1 ? '' : 'es' ?></span>
                    <?php else: ?>
                        <?php // Sin calificaciones NO se muestra un 0 ni "0 estrellas":
                              // sería castigar a quien todavía nadie calificó. ?>
                        <span class="agd-sin-calif">Sin calificaciones aún</span>
                    <?php endif; ?>
                </span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

<?php elseif ($paso === 3): ?>
    <!-- ══════════ 3. DÍA ══════════ -->
    <div class="panel-header">
        <span class="panel-titulo">¿Qué día te queda cómodo?</span>
    </div>
    <div class="panel-body">
        <p class="agd-elegido">
            Dr/a. <strong><?= htmlspecialchars($medico['apellido'] . ', ' . $medico['nombre']) ?></strong>
            · <?= htmlspecialchars($especialidad['nombre']) ?>
        </p>

        <div class="agd-cal" role="group" aria-label="Días disponibles">
            <?php foreach ($DIAS as $i => $d): ?>
            <span class="agd-cal-cab"><?= $d ?></span>
            <?php endforeach; ?>

            <?php
            // Relleno para que el primer día caiga en su columna real.
            $primerDiaSemana = (int) date('w', strtotime($dias[0]['fecha']));
            for ($i = 0; $i < $primerDiaSemana; $i++) echo '<span></span>';

            foreach ($dias as $d):
                $ts = strtotime($d['fecha']);
                $rotulos = [
                    'pasado'     => 'Ya pasó',
                    'sin_agenda' => 'No atiende',
                    'ausente'    => 'Ausente',
                    'completo'   => 'Completo',
                ];
            ?>
                <?php if ($d['disponible']): ?>
                <a class="agd-dia libre"
                   href="<?= BASE_URL ?>agendar.php?esp=<?= $idEsp ?>&mat=<?= $matricula ?>&fecha=<?= $d['fecha'] ?>"
                   title="<?= (int) $d['libres'] ?> horarios libres">
                    <span class="agd-dia-num"><?= (int) date('j', $ts) ?></span>
                    <span class="agd-dia-libres"><?= (int) $d['libres'] ?></span>
                </a>
                <?php else: ?>
                <span class="agd-dia" title="<?= htmlspecialchars($rotulos[$d['motivo']] ?? '') ?>">
                    <span class="agd-dia-num"><?= (int) date('j', $ts) ?></span>
                </span>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <p class="form-hint" style="margin-top:14px">
            El número chico es la cantidad de horarios libres. Los días en gris
            son los que el profesional no atiende, está ausente o ya se completaron.
        </p>
    </div>

<?php elseif ($paso === 4): ?>
    <!-- ══════════ 4. HORARIO ══════════ -->
    <div class="panel-header">
        <span class="panel-titulo">Horarios del <?= htmlspecialchars($fechaLegible($fecha)) ?></span>
    </div>
    <div class="panel-body">
        <p class="agd-elegido">
            Dr/a. <strong><?= htmlspecialchars($medico['apellido'] . ', ' . $medico['nombre']) ?></strong>
            · <?= htmlspecialchars($especialidad['nombre']) ?>
        </p>

        <?php if (!$slots): ?>
            <p class="pac-slot-vacio">
                No quedan horarios libres ese día.
                <a href="<?= htmlspecialchars($paso_url(['fecha' => null])) ?>">Elegí otro</a>.
            </p>
        <?php else: ?>
        <div class="pac-slots">
            <?php foreach ($slots as $s): ?>
            <a class="pac-slot"
               href="<?= BASE_URL ?>agendar.php?esp=<?= $idEsp ?>&mat=<?= $matricula ?>&fecha=<?= urlencode($fecha) ?>&hora=<?= urlencode($s['hora_full']) ?>&cons=<?= (int) $s['id_consultorio'] ?>">
                <?= htmlspecialchars($s['hora']) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <p class="form-hint" style="margin-top:14px">
            Sólo se muestran los horarios libres en este momento.
        </p>
        <?php endif; ?>
    </div>

<?php else: ?>
    <!-- ══════════ 5. CONFIRMACIÓN ══════════ -->
    <div class="panel-header"><span class="panel-titulo">Revisá y confirmá</span></div>
    <div class="panel-body">

        <?php if (!$planes): ?>
            <div class="alerta alerta-error">
                No hay ninguna cobertura disponible para reservar. Cargá tu obra social
                desde <a href="<?= BASE_URL ?>perfil.php#cobertura">tu perfil</a>.
            </div>
        <?php else: ?>
        <?php
            $primerPlan = (int) $planes[0]['id_plan'];
            $costoIni   = $costos[$primerPlan];
        ?>
        <form method="POST" action="<?= BASE_URL ?>sistema/controladores/ControladorTurno.php?accion=reservar"
              id="form-confirmar" novalidate>
            <?php csrf_field(); ?>
            <?php // El id del paciente NO viaja: lo toma el controlador de la sesión. ?>
            <input type="hidden" name="matricula"       value="<?= $matricula ?>">
            <input type="hidden" name="fecha"           value="<?= htmlspecialchars($fecha) ?>">
            <input type="hidden" name="hora_inicio"     value="<?= htmlspecialchars($hora) ?>">
            <input type="hidden" name="id_especialidad" value="<?= $idEsp ?>">
            <input type="hidden" name="id_consultorio"  value="<?= $idCons ?>">

            <ul class="pac-datos-lista agd-resumen">
                <li><span class="etq">Especialidad</span>
                    <span class="val"><?= htmlspecialchars($especialidad['nombre']) ?></span></li>
                <li><span class="etq">Profesional</span>
                    <span class="val">Dr/a. <?= htmlspecialchars($medico['apellido'] . ', ' . $medico['nombre']) ?>
                        <span class="form-hint">Mat. <?= (int) $medico['matricula'] ?></span></span></li>
                <li><span class="etq">Consultorio</span>
                    <span class="val"><?= htmlspecialchars($slotElegido['consultorio']) ?></span></li>
                <li><span class="etq">Fecha</span>
                    <span class="val"><?= htmlspecialchars($fechaLegible($fecha)) ?></span></li>
                <li><span class="etq">Hora</span>
                    <span class="val"><?= htmlspecialchars($slotElegido['hora']) ?> hs</span></li>
                <li><span class="etq">Duración estimada</span>
                    <span class="val"><?= (int) $especialidad['duracion_turno_min'] ?> minutos</span></li>
                <li><span class="etq">Precio de lista</span>
                    <span class="val"><?= $plata($especialidad['precio_consulta']) ?></span></li>
            </ul>

            <div class="form-group mb-18" style="margin-top:18px">
                <label for="id_plan">Cobertura <span class="req">*</span></label>
                <select name="id_plan" id="id_plan" class="form-control" required>
                    <?php foreach ($planes as $pl): ?>
                    <option value="<?= (int) $pl['id_plan'] ?>">
                        <?= htmlspecialchars($pl['nombre']) ?>
                        <?= (int) $pl['es_propio'] === 1 ? '(tu cobertura)' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <span class="form-hint">Sólo tus coberturas cargadas y el pago particular.</span>
            </div>

            <div class="agd-total" id="agd-total">
                <div>
                    <span class="agd-total-etq">Total a pagar</span>
                    <span class="agd-total-desc" id="agd-desc">
                        <?= $costoIni['porcentaje_descuento'] > 0
                            ? htmlspecialchars((float) $costoIni['porcentaje_descuento'] . '% de descuento aplicado')
                            : 'Sin descuento aplicable' ?>
                    </span>
                </div>
                <strong id="agd-monto"><?= $plata($costoIni['monto_total']) ?></strong>
            </div>

            <fieldset class="form-group mb-18" style="margin-top:18px">
                <legend class="agd-legend">¿Cómo querés pagarlo?</legend>
                <label class="agd-opcion">
                    <input type="radio" name="pago" value="tarjeta" checked>
                    <span>
                        <strong>Ahora, con tarjeta</strong>
                        El turno queda confirmado al instante.
                    </span>
                </label>
                <label class="agd-opcion">
                    <input type="radio" name="pago" value="despues">
                    <span>
                        <strong>Más tarde o en recepción</strong>
                        Tenés 48 horas para abonar; después el horario se libera.
                    </span>
                </label>
            </fieldset>

            <label class="check" style="margin-bottom:6px">
                <input type="checkbox" name="acepto" id="acepto" value="1" required>
                <span>
                    Confirmo que los datos son correctos y acepto los términos de
                    atención: si no puedo asistir, aviso con al menos 2 horas de anticipación.
                </span>
            </label>
            <p class="campo-error" id="err-acepto">Necesitamos tu confirmación para reservar.</p>

            <div class="form-acciones" style="margin-top:18px">
                <button type="submit" class="btn btn-primario">Confirmar y reservar</button>
                <a href="<?= htmlspecialchars($paso_url(['hora' => null, 'cons' => null])) ?>"
                   class="btn btn-secundario">Elegir otro horario</a>
            </div>
        </form>
        <?php endif; ?>
    </div>
<?php endif; ?>
</div>

<?php if ($paso === 5 && $planes): ?>
<script>
(function () {
    'use strict';
    // Los importes de cada cobertura vienen calculados por el servidor.
    // Cambiar el desplegable sólo actualiza lo que se muestra: el importe
    // real lo vuelve a calcular el servidor al crear el pago.
    const COSTOS = <?= json_encode(array_map(fn($c) => [
        'total' => $c['monto_total'],
        'pct'   => (float) $c['porcentaje_descuento'],
    ], $costos), JSON_UNESCAPED_UNICODE) ?>;

    const sel   = document.getElementById('id_plan');
    const monto = document.getElementById('agd-monto');
    const desc  = document.getElementById('agd-desc');

    const plata = n => '$' + Number(n).toLocaleString('es-AR', {
        minimumFractionDigits: 2, maximumFractionDigits: 2
    });

    sel.addEventListener('change', () => {
        const c = COSTOS[sel.value];
        if (!c) return;
        monto.textContent = plata(c.total);
        desc.textContent  = c.pct > 0 ? c.pct + '% de descuento aplicado'
                                      : 'Sin descuento aplicable';
    });

    // El checkbox de términos se valida también en el servidor; esto sólo
    // evita el viaje de ida y vuelta.
    const form = document.getElementById('form-confirmar');
    form.addEventListener('submit', ev => {
        const acepto = document.getElementById('acepto');
        if (!acepto.checked) {
            ev.preventDefault();
            document.getElementById('err-acepto').style.display = 'flex';
            acepto.focus();
            return;
        }
        const btn = form.querySelector('button[type=submit]');
        btn.disabled = true;
        btn.textContent = 'Reservando…';
    });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/sistema/vistas/layouts/footer.php'; ?>

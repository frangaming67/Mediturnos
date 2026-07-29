<?php
// dashboard/dashboard_paciente.php — Resumen de la cuenta del paciente
// -----------------------------------------------------------------
// ANTES este archivo era el calendario de reserva: al paciente le
// aparecía la grilla de horarios apenas entraba, sin saber si tenía un
// turno la semana que viene ni si le quedaba algo por pagar.
//
// Está invertido respecto de cómo se usa el sistema: una persona entra
// muchas más veces a mirar qué tiene pendiente que a sacar un turno
// nuevo. Ahora el inicio responde "¿qué tengo?" y reservar vive en su
// propia pantalla (agendar.php).
//
// Las dos consultas SQL que este archivo ejecutaba a mano se fueron con
// el calendario y se reemplazaron por los modelos.
//
// Lo recibe todo de dashboard.php: $pdo, $modelo (Turno) y la sesión.
// -----------------------------------------------------------------

$idPaciente = (int) ($_SESSION['id_paciente'] ?? 0);

// Antes de mostrar nada se ponen los estados al día: los turnos impagos
// vencidos se cancelan y los que ya pasaron se marcan realizados. Sin
// esto, el panel podría anunciar como "próxima cita" un turno que en
// realidad ya venció sin pagarse.
require_once __DIR__ . '/../sistema/modelos/Pago.php';
(new Pago($pdo))->expirarVencidos();
$modelo->marcarRealizadosAutomaticamente();

$proximos = $idPaciente > 0 ? $modelo->proximosDePaciente($idPaciente, 4) : [];
$resumen  = $idPaciente > 0 ? $modelo->resumenPaciente($idPaciente)       : [];
$proxima  = $proximos[0] ?? null;
$otras    = array_slice($proximos, 1);

/** Fecha en castellano, sin depender de la configuración regional del servidor. */
function fechaLarga(string $fecha): string
{
    $dias   = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
    $meses  = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
    $ts     = strtotime($fecha);
    return $dias[(int) date('w', $ts)] . ' ' . (int) date('j', $ts)
         . ' ' . $meses[(int) date('n', $ts)];
}

/** "hoy" / "mañana" / "en 3 días": es lo que la persona quiere saber. */
function cuandoEs(string $fecha): string
{
    $dias = (int) floor((strtotime($fecha) - strtotime('today')) / 86400);
    return match (true) {
        $dias <= 0 => 'Hoy',
        $dias === 1 => 'Mañana',
        $dias < 7  => 'En ' . $dias . ' días',
        default    => 'En ' . (int) ceil($dias / 7) . ' semana' . ($dias >= 14 ? 's' : ''),
    };
}

$claseBadge = fn(string $estado) => 'badge-' . mb_strtolower($estado);
?>

<?php if (!empty($_GET['msg']) || !empty($_GET['err'])): ?>
<div class="alerta alerta-<?= !empty($_GET['err']) ? 'error' : 'exito' ?>" role="alert">
    <?php
    $avisos = [
        'cancelado'    => 'Tu turno fue cancelado.',
        'reprogramado' => 'Tu turno quedó reprogramado.',
        'reservado'    => 'Turno reservado.',
        'pagado'       => 'El pago se registró correctamente.',
    ];
    echo htmlspecialchars(
        !empty($_GET['err'])
            ? urldecode($_GET['err'])
            : ($avisos[$_GET['msg']] ?? 'Listo.')
    );
    ?>
</div>
<?php endif; ?>

<!-- ══════════════ Saludo ══════════════ -->
<header class="pac-saludo">
    <h1>Hola, <?= htmlspecialchars($_SESSION['nombre']) ?> <span aria-hidden="true">👋</span></h1>
    <p>Este es el resumen de tu cuenta</p>
</header>

<?php if ($idPaciente <= 0): ?>
    <div class="alerta alerta-error">
        Tu cuenta no tiene una ficha de paciente asociada, así que todavía no
        podés reservar turnos. Comunicate con la clínica para que la vinculen.
    </div>
<?php else: ?>

<!-- ══════════════ Próxima cita ══════════════ -->
<h2 class="pac-titulo-seccion">Próxima cita</h2>

<?php if (!$proxima): ?>

    <?php // Estado vacío: dice qué pasa, por qué no es un error, y ofrece
          // la acción que corresponde. Un panel en blanco deja a la persona
          // preguntándose si el sistema se rompió. ?>
    <div class="pac-vacio">
        <div class="pac-vacio-ico" aria-hidden="true">
            <svg width="42" height="42" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>
            </svg>
        </div>
        <h3>No tenés turnos programados</h3>
        <p>
            <?= $resumen['realizados'] > 0
                ? 'Cuando saques uno nuevo lo vas a ver acá con todos los detalles.'
                : 'Reservá tu primera consulta: elegís profesional, día y horario en dos minutos.' ?>
        </p>
        <a href="<?= BASE_URL ?>agendar.php" class="btn btn-primario">Agendar una cita</a>
    </div>

<?php else: ?>

    <?php
    $motivoNoMover = $modelo->motivoNoReprogramable($proxima);
    $debePagar     = ($proxima['estado_pago'] ?? '') === 'Pendiente';
    ?>
    <article class="pac-cita <?= $debePagar ? 'pac-cita--pendiente' : '' ?>">
        <div class="pac-cita-cab">
            <div>
                <h3>
                    Dr/a. <?= htmlspecialchars($proxima['medico']) ?>
                    <span class="pac-cita-esp">— <?= htmlspecialchars($proxima['especialidad']) ?></span>
                </h3>
            </div>
            <div class="pac-cita-estados">
                <span class="pac-cuando"><?= htmlspecialchars(cuandoEs($proxima['fecha'])) ?></span>
                <span class="badge <?= $claseBadge($proxima['estado']) ?>">
                    <?= htmlspecialchars($proxima['estado']) ?>
                </span>
            </div>
        </div>

        <ul class="pac-cita-datos">
            <li>
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                <?= htmlspecialchars(fechaLarga($proxima['fecha'])) ?>
            </li>
            <li>
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                <?= htmlspecialchars(substr($proxima['hora_inicio'], 0, 5)) ?> hs
            </li>
            <li>
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-5.6-7-11a7 7 0 0 1 14 0c0 5.4-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
                <?= htmlspecialchars($proxima['consultorio']) ?>
            </li>
        </ul>

        <?php if ($debePagar): ?>
        <?php // El pago pendiente se muestra ARRIBA de los botones y con su
              // vencimiento: si no se abona a tiempo el turno se cancela solo,
              // así que es la información más urgente de toda la tarjeta. ?>
        <div class="pac-pago-aviso">
            <div>
                <strong>Falta abonar $<?= number_format((float) $proxima['monto_total'], 2, ',', '.') ?></strong>
                <span>
                    Tenés tiempo hasta el
                    <?= htmlspecialchars(date('d/m/Y \a \l\a\s H:i', strtotime($proxima['pago_vence']))) ?>.
                    Después de esa hora el turno se libera.
                </span>
            </div>
            <a href="<?= BASE_URL ?>sistema/controladores/ControladorPago.php?accion=elegir&id_pago=<?= (int) $proxima['id_pago'] ?>"
               class="btn btn-primario">Pagar ahora</a>
        </div>
        <?php endif; ?>

        <div class="pac-cita-acciones">
            <a href="<?= BASE_URL ?>sistema/controladores/ControladorTurno.php?accion=detalle&id=<?= (int) $proxima['id_turno'] ?>"
               class="btn btn-primario">Ver detalles</a>

            <?php if ($motivoNoMover === null): ?>
                <a href="<?= BASE_URL ?>sistema/controladores/ControladorTurno.php?accion=reprogramar&id=<?= (int) $proxima['id_turno'] ?>"
                   class="btn btn-secundario">Reprogramar</a>
                <button type="button" class="btn btn-secundario btn-cancelar-turno"
                        data-modal="modal-cancelar"
                        data-id="<?= (int) $proxima['id_turno'] ?>"
                        data-resumen="<?= htmlspecialchars($proxima['especialidad'] . ' con ' . $proxima['medico']
                            . ' — ' . fechaLarga($proxima['fecha']) . ' ' . substr($proxima['hora_inicio'], 0, 5) . ' hs') ?>">
                    Cancelar
                </button>
            <?php else: ?>
                <span class="pac-nota"><?= htmlspecialchars($motivoNoMover) ?></span>
            <?php endif; ?>
        </div>
    </article>

    <?php if ($otras): ?>
    <h2 class="pac-titulo-seccion">Más adelante</h2>
    <div class="pac-otras">
        <?php foreach ($otras as $t): ?>
        <a class="pac-otra" href="<?= BASE_URL ?>sistema/controladores/ControladorTurno.php?accion=detalle&id=<?= (int) $t['id_turno'] ?>">
            <span class="pac-otra-fecha">
                <strong><?= (int) date('j', strtotime($t['fecha'])) ?></strong>
                <?= htmlspecialchars(['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'][(int) date('n', strtotime($t['fecha']))]) ?>
            </span>
            <span class="pac-otra-txt">
                <strong><?= htmlspecialchars($t['especialidad']) ?></strong>
                <span><?= htmlspecialchars($t['medico']) ?> · <?= htmlspecialchars(substr($t['hora_inicio'], 0, 5)) ?> hs</span>
            </span>
            <span class="badge <?= $claseBadge($t['estado']) ?>"><?= htmlspecialchars($t['estado']) ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

<?php endif; ?>

<!-- ══════════════ Accesos rápidos ══════════════ -->
<h2 class="pac-titulo-seccion">Accesos rápidos</h2>
<div class="pac-accesos">
    <a class="pac-acceso" href="<?= BASE_URL ?>agendar.php">
        <span class="pac-acceso-ico pac-ico-azul" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M12 14v4M10 16h4"/></svg>
        </span>
        <strong>Agendar cita</strong>
        <span>Elegí profesional y horario</span>
    </a>

    <a class="pac-acceso" href="<?= BASE_URL ?>sistema/controladores/ControladorTurno.php?accion=index">
        <span class="pac-acceso-ico pac-ico-verde" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M8 15h8"/></svg>
        </span>
        <strong>Mis turnos</strong>
        <span><?= (int) ($resumen['total'] ?? 0) ?> en total · <?= (int) ($resumen['realizados'] ?? 0) ?> ya realizados</span>
    </a>

    <a class="pac-acceso" href="<?= BASE_URL ?>sistema/controladores/ControladorPago.php?accion=index">
        <span class="pac-acceso-ico <?= ($resumen['pagos_pendientes'] ?? 0) > 0 ? 'pac-ico-amarillo' : 'pac-ico-gris' ?>" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
        </span>
        <strong>Mis pagos</strong>
        <span>
            <?= ($resumen['pagos_pendientes'] ?? 0) > 0
                ? (int) $resumen['pagos_pendientes'] . ' pendiente' . ($resumen['pagos_pendientes'] > 1 ? 's' : '')
                : 'Sin pagos pendientes' ?>
        </span>
    </a>

    <a class="pac-acceso" href="<?= BASE_URL ?>perfil.php">
        <span class="pac-acceso-ico pac-ico-gris" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        </span>
        <strong>Mi perfil</strong>
        <span>Datos, cobertura y contraseña</span>
    </a>
</div>

<!-- Modal de cancelación -->
<div class="modal-overlay" id="modal-cancelar">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-titulo">Cancelar turno</span>
            <button class="btn-cerrar-modal" aria-label="Cerrar">✕</button>
        </div>
        <form method="POST" action="<?= BASE_URL ?>sistema/controladores/ControladorTurno.php?accion=cancelar">
            <?php csrf_field(); ?>
            <input type="hidden" name="id_turno" id="cancelar-id">
            <input type="hidden" name="volver" value="dashboard">
            <div class="modal-body">
                <p class="mb-14">¿Seguro que querés cancelar este turno?</p>
                <p class="pac-cancelar-resumen" id="cancelar-resumen"></p>
                <div class="form-group">
                    <label for="cancelar-motivo">Motivo <span class="form-hint">(opcional)</span></label>
                    <input type="text" name="observacion" id="cancelar-motivo" class="form-control"
                           maxlength="200" placeholder="Ej: me surgió un imprevisto">
                </div>
                <p class="form-hint" style="margin-top:12px">
                    El horario queda libre para otra persona. Si ya abonaste, la clínica
                    se contacta para gestionar la devolución.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secundario btn-cerrar-modal">Volver</button>
                <button type="submit" class="btn btn-peligro">Sí, cancelar el turno</button>
            </div>
        </form>
    </div>
</div>

<script>
// El botón de cancelar carga el id y el resumen en el modal, para que la
// persona confirme viendo QUÉ está cancelando y no un "¿seguro?" a ciegas.
document.querySelectorAll('.btn-cancelar-turno').forEach(b => {
    b.addEventListener('click', () => {
        document.getElementById('cancelar-id').value = b.dataset.id;
        // textContent, nunca innerHTML: el resumen trae datos de la base.
        document.getElementById('cancelar-resumen').textContent = b.dataset.resumen;
    });
});
</script>

<?php endif; ?>

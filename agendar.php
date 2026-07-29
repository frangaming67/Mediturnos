<?php
// agendar.php — Reserva de turno del paciente
// -----------------------------------------------------------------
// Esta pantalla ESTABA dentro de dashboard_paciente.php: al paciente le
// aparecía el calendario de reserva apenas entraba, sin resumen de nada.
// Se separó por dos motivos:
//
//   1. El panel de inicio tiene que responder "¿qué tengo pendiente?",
//      no "reservá algo". Una persona entra muchas más veces a mirar su
//      próximo turno que a sacar uno nuevo.
//   2. Reservar es una tarea con su propio recorrido. Mezclada con el
//      resumen, ninguna de las dos cosas se lee bien.
//
// Las dos consultas SQL que la vista ejecutaba a mano se reemplazaron
// por los modelos que ya existían para eso — el propio archivo original
// dejaba anotado que había que hacerlo.
// -----------------------------------------------------------------
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/includes/seguridad.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/sistema/modelos/Turno.php';
require_once __DIR__ . '/sistema/modelos/Medico.php';

iniciarSesionSegura();
cabecerasSeguridad();
verificarSesion();
verificarRol(['paciente']);

$modeloTurno  = new Turno($pdo);
$modeloMedico = new Medico($pdo);

$idPaciente = (int) ($_SESSION['id_paciente'] ?? 0);

// Una cuenta de paciente sin ficha vinculada no puede reservar: no hay a
// nombre de quién. Es un caso de datos mal cargados, no de uso normal.
if ($idPaciente <= 0) {
    $paginaTitulo = 'Agendar cita';
    require_once __DIR__ . '/sistema/vistas/layouts/navbar.php';
    echo '<div class="alerta alerta-error">Tu cuenta no tiene una ficha de paciente '
       . 'asociada. Comunicate con la clínica para que la vinculen.</div>';
    require_once __DIR__ . '/sistema/vistas/layouts/footer.php';
    exit;
}

$medicosCalendario = $modeloMedico->listar(['estado' => 'activo']);

// Sólo las coberturas de ESTE paciente (más la opción particular).
// Antes se ofrecían las quince del sistema: ver Turno::planesDePaciente().
$planesCalendario = $modeloTurno->planesDePaciente($idPaciente);
$tieneCobertura   = false;
foreach ($planesCalendario as $pl) {
    if ((int) $pl['es_propio'] === 1) { $tieneCobertura = true; break; }
}

$mensaje = !empty($_GET['err']) ? urldecode($_GET['err']) : null;

$paginaTitulo = 'Agendar cita';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / Agendar cita';
$cssExtra     = ['paciente.css'];

require_once __DIR__ . '/sistema/vistas/layouts/navbar.php';
?>

<?php if ($mensaje): ?>
<div class="alerta alerta-error" role="alert"><?= htmlspecialchars($mensaje) ?></div>
<?php endif; ?>

<?php if (empty($planesCalendario)): ?>
    <div class="alerta alerta-error" role="alert">
        No hay ninguna cobertura disponible para reservar. Cargá tu obra social
        desde <a href="<?= BASE_URL ?>perfil.php#cobertura">tu perfil</a> o pedile
        a la clínica que dé de alta la opción de pago particular.
    </div>
<?php else: ?>

    <?php if (!$tieneCobertura): ?>
    <div class="alerta alerta-info">
        Todavía no cargaste tu obra social, así que vas a abonar como paciente
        particular. Podés cargarla en
        <a href="<?= BASE_URL ?>perfil.php#cobertura">tu perfil</a> y el descuento
        se aplica solo.
    </div>
    <?php endif; ?>

    <div class="cal-wrap">

        <!-- Columna médicos -->
        <div class="panel panel--sin-overflow">
            <div class="panel-header">
                <span class="panel-titulo">Elegí un médico</span>
            </div>
            <?php foreach ($medicosCalendario as $m): ?>
            <div class="medico-card"
                 data-matricula="<?= (int) $m['matricula'] ?>"
                 onclick="seleccionarMedico(this)">
                <div class="avatar">
                    <?= htmlspecialchars(strtoupper(mb_substr($m['apellido'], 0, 1))) ?>
                </div>
                <div>
                    <div class="medico-nombre">
                        Dr/a. <?= htmlspecialchars($m['apellido'] . ', ' . $m['nombre']) ?>
                    </div>
                    <div class="medico-esp">
                        <?= htmlspecialchars($m['especialidades'] ?? 'Sin especialidades') ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Columna calendario -->
        <div class="panel panel--sin-overflow">
            <div class="cal-header">
                <button class="cal-nav" onclick="cambiarMes(-1)">‹</button>
                <span id="cal-titulo" class="cal-titulo-mes"></span>
                <button class="cal-nav" onclick="cambiarMes(1)">›</button>
            </div>
            <div class="cal-grid" id="cal-grid">
                <div class="sin-medico col-full">
                    Seleccioná un médico para ver los días disponibles
                </div>
            </div>
            <div id="slots-container" style="display:none" class="slots-wrap">
                <div class="slots-titulo" id="slots-titulo"></div>
                <div class="slots-grid" id="slots-grid"></div>
            </div>
        </div>
    </div>

    <!-- Modal confirmar turno -->
    <div class="modal-overlay" id="modal-turno">
        <div class="modal">
            <div class="modal-header">
                <span class="modal-titulo">Confirmar turno</span>
                <button class="btn-cerrar-modal">✕</button>
            </div>
            <form method="POST"
                  action="<?= BASE_URL ?>sistema/controladores/ControladorTurno.php?accion=reservar">
                <?php csrf_field(); ?>
                <div class="modal-body">
                    <?php // El id del paciente NO viaja en el formulario: lo toma
                          // el controlador de la sesión. Si viniera acá, cualquiera
                          // podría reservarle un turno a otra persona. ?>
                    <input type="hidden" name="matricula"       id="inp-matricula">
                    <input type="hidden" name="fecha"           id="inp-fecha">
                    <input type="hidden" name="hora_inicio"     id="inp-hora">
                    <input type="hidden" name="id_especialidad" id="inp-especialidad">
                    <input type="hidden" name="id_consultorio"  id="inp-consultorio">

                    <div class="resumen-confirmacion">
                        <strong id="conf-medico"></strong><br>
                        <span id="conf-especialidad" class="texto-gris"></span><br>
                        📅 <span id="conf-fecha"></span> &nbsp; 🕐 <span id="conf-hora"></span><br>
                        🏥 <span id="conf-consultorio" class="texto-gris"></span>
                    </div>

                    <div class="form-group mb-14">
                        <label for="id_plan">Cobertura <span class="req">*</span></label>
                        <select name="id_plan" id="id_plan" class="form-control" required>
                            <option value="">— Seleccioná —</option>
                            <?php foreach ($planesCalendario as $pl): ?>
                            <option value="<?= (int) $pl['id_plan'] ?>">
                                <?= htmlspecialchars($pl['nombre']) ?>
                                <?= (int) $pl['es_propio'] === 1 ? '(tu cobertura)' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="form-hint">
                            Sólo aparecen tus coberturas cargadas y el pago particular.
                        </span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secundario btn-cerrar-modal">Cancelar</button>
                    <button type="submit" class="btn btn-primario">Confirmar reserva</button>
                </div>
            </form>
        </div>
    </div>

<?php endif; ?>

<?php
// JS externo con cache-busting, mismo patrón que el CSS del navbar.
$jsDir = __DIR__ . '/assets/js/';
$jsVer = fn($f) => BASE_URL . 'assets/js/' . $f
    . (is_file($jsDir . $f) ? '?v=' . filemtime($jsDir . $f) : '');
?>
<script>
// Único dato que el calendario necesita de PHP: la URL base del proyecto.
const BASE = '<?= BASE_URL ?>';
</script>
<script src="<?= $jsVer('calendario.js') ?>"></script>
<script src="<?= $jsVer('modal_turno.js') ?>"></script>

<?php require_once __DIR__ . '/sistema/vistas/layouts/footer.php'; ?>

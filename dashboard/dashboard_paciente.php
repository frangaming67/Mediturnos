<?php
// dashboard/dashboard_paciente.php
// -----------------------------------------------------------------
// Es el "otro lado" del if($rol==='paciente') de dashboard.php: mientras
// dashboard_admin.php muestra métricas y gestión, este archivo es
// puramente transaccional (reservar un turno). Por eso NO usa ninguno
// de los componentes de dashboard/componentes/ (kpis, agenda, pagos):
// esos están pensados para el staff, un paciente no necesita ver
// recaudación ni la agenda completa de la clínica, solo la suya (que ve
// aparte, en ControladorTurno?accion=index).
// Estas dos consultas SQL están en la vista y no en los modelos porque
// son de lectura simple para poblar selects/tarjetas (no hay lógica de
// negocio detrás); igual sería más prolijo moverlas a Medico::listar()
// y ObraSocial::listarPlanes(), que ya existen con ese mismo propósito
// (ver conversación previa sobre esto).
// Este apartado sirve para armar la pantalla de reserva de turnos para pacientes.
// Se muestran los médicos activos y un calendario para seleccionar un día y un horario disponible.
// sirve ademas para que el paciente pueda elegir un medico,
//ver su disponibilidad, reservar un turno y confirmar la reserva con su obra social.
//----------------------------------------------------------------
// sirve para obtener los médicos que se van a mostrar en la pantalla de reserva de turnos
// GROUP_CONCAT sirve para juntar varios valores de una columna en uno solo en mi caso sirve para juntar 
// las especialidades de un medico por si llegan a tener mas de una especialidad.
$medicosCalendario = $pdo->query(
    "SELECT m.matricula, m.nombre, m.apellido,GROUP_CONCAT(e.nombre ORDER BY e.nombre SEPARATOR ', ') AS especialidades
     FROM medico m
     LEFT JOIN medico_especialidad me ON me.matricula  = m.matricula
     LEFT JOIN especialidad e  ON e.id_especialidad = me.id_especialidad
     WHERE m.estado = 'activo'
     GROUP BY m.matricula ORDER BY m.apellido, m.nombre"
)->fetchAll();
//-----------------------------------------------------------------
//  sirve para obtener los planes de obra social que se van a mostrar en el modal de confirmación del turno
$planesCalendario = $pdo->query(
    "SELECT pl.id_plan,
            CONCAT(os.nombre, ' - ', pl.nombre_plan) AS nombre,
            pl.porcentaje_cobertura
     FROM plan_os pl
     JOIN obra_social os ON os.id_obra_social = pl.id_obra_social
     ORDER BY os.nombre, pl.nombre_plan"
)->fetchAll();
?>
<!-- ------------------------------------------------------------------ -->
<!-- Contenedor principal de la pantalla de reserva de turnos -->
<div class="cal-wrap">

    <!-- Columna médicos -->
    <div class="panel panel--sin-overflow">
        <div class="panel-header">
            <span class="panel-titulo">Elegí un médico</span>
        </div>
        <?php foreach($medicosCalendario as $m): ?>
        <div class="medico-card"
             data-matricula="<?= $m['matricula'] ?>"
             onclick="seleccionarMedico(this)">
            <div class="avatar">
                <?= strtoupper(substr($m['apellido'],0,1)) ?>
            </div>
            <div>
                <div class="medico-nombre">
                    Dr/a. <?= htmlspecialchars($m['apellido'].', '.$m['nombre']) ?>
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
                <input type="hidden" name="id_paciente"     id="inp-paciente"     value="<?= (int)($_SESSION['id_paciente'] ?? 0) ?>">
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
                    <label>Obra social / Plan <span class="req">*</span></label>
                    <select name="id_plan" class="form-control" required>
                        <option value="">— Seleccioná —</option>
                        <?php foreach($planesCalendario as $pl): ?>
                        <option value="<?= $pl['id_plan'] ?>">
                            <?= htmlspecialchars($pl['nombre']) ?>
                            (<?= $pl['porcentaje_cobertura'] ?>% cobertura)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Nro. de afiliado</label>
                    <input type="text" name="nro_afiliado" class="form-control" placeholder="Opcional">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secundario btn-cerrar-modal">Cancelar</button>
                <button type="submit" class="btn btn-primario">Confirmar reserva</button>
            </div>
        </form>
    </div>
</div>

<?php
// JS externo con cache-busting (mismo patrón que el CSS en navbar.php)
// sirve para hacer funcionar el calendario y el modal de reserva en la pantalla del paciente

$jsDir = __DIR__ . '/../assets/js/';
$jsVer = fn($f) => BASE_URL . 'assets/js/' . $f
    . (is_file($jsDir . $f) ? '?v=' . filemtime($jsDir . $f) : '');
?>
<script>
// Único dato que necesita PHP: la URL base del proyecto.
// const BASE = '< ?= BASE_URL ? >'; sirve para que pasare la URL base del proyecto a JavaScript, 
// para que el script pueda construir enlaces o peticiones correctas.
const BASE = '<?= BASE_URL ?>';
</script>
<script src="<?= $jsVer('calendario.js') ?>"></script>
<script src="<?= $jsVer('modal_turno.js') ?>"></script>

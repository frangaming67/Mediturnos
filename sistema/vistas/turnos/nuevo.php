<?php
// sistema/vistas/turnos/nuevo.php
// -----------------------------------------------------------------
// Está armado como un formulario de DOS PASOS con un solo POST a
// ?accion=nuevo (no dos rutas separadas) porque así funciona sin
// depender de JavaScript: el paso 2 necesita saber los slots libres,
// que dependen de médico+fecha elegidos en el paso 1, así que hace
// falta un roundtrip al servidor entre ambos sí o sí. El campo oculto
// "paso" es lo que le dice a ControladorTurno qué mitad del formulario
// renderizar (ver el $paso = (int)($_POST['paso'] ?? 1) del controlador).
// El datalist de pacientes (autocompletar por nombre/DNI) se resuelve
// con un <script> chico e inline en vez de con calendario.js porque es
// lógica exclusiva de ESTE formulario (buscar texto → id oculto), no
// algo que compartan otras pantallas.
// -----------------------------------------------------------------
$paginaTitulo = 'Nuevo turno';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / <a href="' . BASE_URL . 'sistema/controladores/ControladorTurno.php?accion=index">Turnos</a> / Nuevo';
require __DIR__ . '/../layouts/navbar.php';
?>

<?php if ($mensaje): ?>
<div class="alerta alerta-<?= $tipoMsg === 'error' ? 'error' : 'exito' ?>">
    <?= htmlspecialchars($mensaje) ?>
</div>
<?php endif; ?>

<div class="panel panel-md">
    <div class="panel-header">
        <span class="panel-titulo">
            <?= $paso === 1 ? 'Paso 1 — Elegí médico, fecha y plan' : 'Paso 2 — Elegí un horario disponible' ?>
        </span>
    </div>
    <div class="panel-body">

    <?php if ($paso === 1): ?>
    <!-- ── PASO 1 ─────────────────────────────────────────── -->
    <form method="POST" action="<?= BASE_URL ?>sistema/controladores/ControladorTurno.php?accion=nuevo">
        <input type="hidden" name="paso" value="2">

        <div class="form-grid mb-20">

            <!-- Médico -->
            <div class="form-group">
                <label for="matricula">Médico <span class="req">*</span></label>
                <select name="matricula" id="matricula" class="form-control" required>
                    <option value="">— Seleccioná —</option>
                    <?php foreach ($medicos as $m): ?>
                    <option value="<?= $m['matricula'] ?>"
                        <?= ($_POST['matricula'] ?? '') == $m['matricula'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($m['nombre_completo']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Paciente (solo admin y recepcionista lo eligen) -->
            <?php if ($_SESSION['rol'] !== 'paciente'): ?>
            <?php
                // Texto a mostrar en el buscador si el form vuelve con un paciente ya elegido
                $pacienteSel    = $_POST['id_paciente'] ?? '';
                $pacienteSelTxt = '';
                foreach ($pacientes as $p) {
                    if ($pacienteSel != '' && $pacienteSel == $p['id_paciente']) {
                        $pacienteSelTxt = $p['nombre_completo'] . ' — DNI ' . $p['dni'];
                        break;
                    }
                }
            ?>
            <div class="form-group">
                <label for="paciente_busqueda">Paciente <span class="req">*</span></label>
                <input type="text" id="paciente_busqueda" class="form-control"
                    list="lista_pacientes" autocomplete="off"
                    placeholder="Escribí nombre o DNI…"
                    value="<?= htmlspecialchars($pacienteSelTxt) ?>" required>
                <datalist id="lista_pacientes">
                    <?php foreach ($pacientes as $p): ?>
                    <option data-id="<?= $p['id_paciente'] ?>"
                        value="<?= htmlspecialchars($p['nombre_completo']) ?> — DNI <?= htmlspecialchars($p['dni']) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <!-- El id real viaja oculto; lo completa el buscador -->
                <input type="hidden" name="id_paciente" id="id_paciente"
                    value="<?= htmlspecialchars($pacienteSel) ?>">
                <span class="form-hint">Empezá a escribir y elegí de la lista</span>
            </div>
            <?php endif; ?>

            <!-- Fecha -->
            <div class="form-group">
                <label for="fecha">Fecha <span class="req">*</span></label>
                <input type="date" name="fecha" id="fecha" class="form-control"
                    value="<?= htmlspecialchars($_POST['fecha'] ?? date('Y-m-d')) ?>"
                    min="<?= date('Y-m-d') ?>" required>
                <span class="form-hint">Solo aparecerán horarios si el médico atiende ese día</span>
            </div>

            <!-- Obra social / Plan -->
            <div class="form-group">
                <label for="id_plan">Obra social / Plan <span class="req">*</span></label>
                <select name="id_plan" id="id_plan" class="form-control" required>
                    <option value="">— Seleccioná —</option>
                    <?php foreach ($planes as $pl): ?>
                    <option value="<?= $pl['id_plan'] ?>"
                        <?= ($_POST['id_plan'] ?? '') == $pl['id_plan'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($pl['nombre_completo']) ?> (<?= $pl['porcentaje_cobertura'] ?>% cobertura)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Nro de afiliado -->
            <div class="form-group">
                <label for="nro_afiliado">Nro. afiliado</label>
                <input type="text" name="nro_afiliado" id="nro_afiliado" class="form-control"
                    placeholder="Opcional"
                    value="<?= htmlspecialchars($_POST['nro_afiliado'] ?? '') ?>">
                <span class="form-hint">Dejá vacío si no aplica</span>
            </div>

        </div>

        <div class="form-acciones">
            <button type="submit" class="btn btn-primario">Ver turnos disponibles →</button>
            <a href="<?= BASE_URL ?>sistema/controladores/ControladorTurno.php?accion=index" class="btn btn-secundario">Cancelar</a>
        </div>
    </form>

    <?php if ($_SESSION['rol'] !== 'paciente'): ?>
    <script>
    // Buscador de paciente: resuelve el texto elegido al id real (campo oculto)
    (function () {
        var input  = document.getElementById('paciente_busqueda');
        var hidden = document.getElementById('id_paciente');
        var lista  = document.getElementById('lista_pacientes');
        if (!input || !hidden || !lista) return;

        function resolver() {
            var val = input.value.trim();
            var match = null;
            for (var i = 0; i < lista.options.length; i++) {
                if (lista.options[i].value === val) { match = lista.options[i]; break; }
            }
            hidden.value = match ? match.getAttribute('data-id') : '';
            // Si escribió algo que no está en la lista, marca el campo como inválido
            input.setCustomValidity((val !== '' && !match) ? 'Elegí un paciente de la lista' : '');
        }

        input.addEventListener('input', resolver);
        input.addEventListener('change', resolver);
        resolver();
    })();
    </script>
    <?php endif; ?>

    <?php else: ?>
    <!-- ── PASO 2 ─────────────────────────────────────────── -->
    <?php
        $diasES = ['Monday'=>'Lunes','Tuesday'=>'Martes','Wednesday'=>'Miércoles',
                   'Thursday'=>'Jueves','Friday'=>'Viernes','Saturday'=>'Sábado','Sunday'=>'Domingo'];
        $diaSemana    = $diasES[date('l', strtotime($fecha))] ?? '';
        $fechaDisplay = $diaSemana . ' ' . date('d/m/Y', strtotime($fecha));
    ?>

    <div class="alerta alerta-info mb-20">
        <strong>Médico:</strong> <?= htmlspecialchars($medicoNombre) ?> &nbsp;|&nbsp;
        <strong>Fecha:</strong> <?= htmlspecialchars($fechaDisplay) ?>
    </div>

    <p class="texto-ayuda">
        Hacé click en el horario que querés reservar y después confirmá.
    </p>

    <form method="POST" action="<?= BASE_URL ?>sistema/controladores/ControladorTurno.php?accion=reservar">
        <?php csrf_field(); ?>

        <!-- Datos del paso 1 que viajan ocultos -->
        <input type="hidden" name="matricula"    value="<?= (int)$matricula ?>">
        <input type="hidden" name="fecha"        value="<?= htmlspecialchars($fecha) ?>">
        <input type="hidden" name="id_plan"      value="<?= (int)$id_plan ?>">
        <input type="hidden" name="nro_afiliado" value="<?= htmlspecialchars($nro_afiliado) ?>">
        <input type="hidden" name="id_paciente"  value="<?= (int)$id_paciente ?>">

        <!-- Slots disponibles -->
        <div class="slots-grid">
            <?php foreach ($slots as $s): ?>
            <?php $valor = $s['hora_full'] . '|' . $s['id_consultorio'] . '|' . $s['id_especialidad']; ?>
            <label class="slot-opcion">
                <input type="radio" name="slot" value="<?= htmlspecialchars($valor) ?>" required>
                <span class="slot-hora"><?= htmlspecialchars($s['hora']) ?></span>
                <span class="slot-detalle">
                    <?= htmlspecialchars($s['especialidad']) ?><br>
                    <?= htmlspecialchars($s['consultorio']) ?>
                </span>
            </label>
            <?php endforeach; ?>
        </div>

        <div class="form-acciones mt-20">
            <button type="submit" class="btn btn-primario">Confirmar reserva</button>
            <a href="<?= BASE_URL ?>sistema/controladores/ControladorTurno.php?accion=nuevo" class="btn btn-secundario">← Volver</a>
        </div>
    </form>
    <?php endif; ?>

    </div>
</div>

<?php require __DIR__ . '/../layouts/footer.php'; ?>

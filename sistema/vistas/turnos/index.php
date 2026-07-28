<?php
// sistema/vistas/turnos/index.php
// -----------------------------------------------------------------
// Vive en sistema/vistas/turnos/ (no en dashboard/) porque ESTA es la
// pantalla de "todos los turnos con filtros", distinta del dashboard
// (que solo muestra un resumen del día). El controlador la elige con
// require en el case 'index'; esta vista no sabe ni le importa qué pasó
// antes, solo recibe $turnos/$medicos/$especialidades/$filtros ya
// armados. $esPaciente se recalcula acá (no viene del controlador)
// porque es un dato puramente de PRESENTACIÓN (qué columnas/botones
// mostrar), no algo que cambie qué turnos se traen de la base.
// El bloque final <script>verHistorial()</script> vive inline en la
// vista (no en assets/js/) porque es específico de ESTA tabla (necesita
// el id de cada fila) y no se reutiliza en ninguna otra pantalla.
// -----------------------------------------------------------------
$paginaTitulo = 'Turnos';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / Turnos';
require __DIR__ . '/../layouts/navbar.php';

$estadoActual = $filtros['estado'] ?? '';
$fechaActual  = $filtros['fecha']  ?? '';
$esPaciente = ($_SESSION['rol'] ==='paciente'); //$esPaciente va a valer true si es paciente y false si es admin/recepcionista/médico.

$URL_PAGO  = BASE_URL . 'sistema/controladores/ControladorPago.php';
$money     = fn($v) => '$' . number_format((float)$v, 2, ',', '.');
$badgePago = [
    'Pagado'    => 'badge-realizado',
    'Pendiente' => 'badge-ausente',
    'Vencido'   => 'badge-cancelado',
    'Anulado'   => 'badge-inactivo',
];

// Mensaje de operación
$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';
$mensajes = [
    'reservado'   => ['tipo'=>'exito',  'texto'=>'Turno reservado correctamente.'],
    'cancelado'   => ['tipo'=>'exito',  'texto'=>'Turno cancelado.'],
    'estado_ok'   => ['tipo'=>'exito',  'texto'=>'Estado actualizado.'],
];
?>

<?php if ($msg && isset($mensajes[$msg])): ?>
<div class="alerta alerta-<?= $mensajes[$msg]['tipo'] ?>">
    <?= $mensajes[$msg]['texto'] ?>
</div>
<?php endif; ?>
<?php if ($err): ?>
<div class="alerta alerta-error"><?= htmlspecialchars(urldecode($err)) ?></div>
<?php endif; ?>

<div class="panel">
    <!-- Filtros: solo para staff (admin / recepcionista / médico) -->
    <?php if (!$esPaciente): ?>
    <form method="GET" action="">
        <input type="hidden" name="accion" value="index">
        <div class="filtros-bar">
            <div class="form-group">
                <label>Fecha</label>
                <input type="date" name="fecha" class="form-control" value="<?= htmlspecialchars($fechaActual) ?>">
            </div>
            <div class="form-group">
                <label>Estado</label>
                <select name="estado" class="form-control">
                    <option value="">Todos</option>
                    <?php foreach(['Reservado','Confirmado','Realizado','Cancelado','Ausente'] as $e): ?>
                    <option value="<?= $e ?>" <?= $estadoActual===$e?'selected':'' ?>><?= $e ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>DNI paciente</label>
                <input type="text" name="dni_paciente" class="form-control" placeholder="Buscar DNI..."
                    value="<?= htmlspecialchars($filtros['dni_paciente'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Médico</label>
                <select name="matricula" class="form-control">
                    <option value="">Todos</option>
                    <?php foreach($medicos as $m): ?>
                    <option value="<?= $m['matricula'] ?>" <?= ($filtros['matricula']??'')==$m['matricula']?'selected':'' ?>>
                        <?= htmlspecialchars($m['nombre_completo']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Especialidad</label>
                <select name="id_especialidad" class="form-control">
                    <option value="">Todas</option>
                    <?php foreach($especialidades as $e): ?>
                    <option value="<?= $e['id_especialidad'] ?>" <?= ($filtros['id_especialidad']??'')==$e['id_especialidad']?'selected':'' ?>>
                        <?= htmlspecialchars($e['nombre']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group form-group--fin">
                <label>&nbsp;</label>
                <div class="btn-grupo">
                    <button type="submit" class="btn btn-primario btn-sm">Filtrar</button>
                    <a href="?accion=index" class="btn btn-secundario btn-sm">Limpiar</a>
                </div>
            </div>
        </div>
    </form>
    <?php endif; ?>

    <!-- Header del panel -->
    <div class="panel-header panel-header--sep">
        <span class="panel-titulo">
            <?= count($turnos) ?> turno<?= count($turnos)!==1?'s':'' ?> encontrado<?= count($turnos)!==1?'s':'' ?>
        </span>
        <a href="<?= BASE_URL ?>sistema/controladores/ControladorTurno.php?accion=nuevo" class="btn btn-primario btn-sm">
            + Nuevo turno
        </a>
    </div>

    <!-- Tabla -->
    <div class="tabla-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Fecha / Hora</th>
                    <th>Paciente</th>
                    <th>Médico</th>
                    <th>Especialidad</th>
                    <th>Consultorio</th>
                    <th>Obra Social / Plan</th>
                    <th>Estado</th>
                    <th>Pago</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($turnos)): ?>
                <tr><td colspan="10" class="td-vacio">No se encontraron turnos con esos filtros.</td></tr>
            <?php else: ?>
                <?php foreach($turnos as $t): ?>
                <tr>
                    <td><?= $t['id_turno'] ?></td>
                    <td class="nowrap">
                        <?= date('d/m/Y', strtotime($t['fecha'])) ?><br>
                        <small class="texto-gris"><?= substr($t['hora_inicio'],0,5) ?></small>
                    </td>
                    <td>
                        <?= htmlspecialchars($t['paciente']) ?><br>
                        <small class="texto-gris">DNI: <?= htmlspecialchars($t['paciente_dni']) ?></small>
                    </td>
                    <td><?= htmlspecialchars($t['medico']) ?></td>
                    <td><?= htmlspecialchars($t['especialidad']) ?></td>
                    <td><?= htmlspecialchars($t['consultorio']) ?></td>
                    <td>
                        <?= htmlspecialchars($t['obra_social']) ?><br>
                        <small class="texto-gris"><?= htmlspecialchars($t['plan']) ?></small>
                    </td>
                    <td>
                        <span class="badge badge-<?= strtolower($t['estado']) ?>">
                            <?= $t['estado'] ?>
                        </span>
                    </td>
                    <td class="nowrap">
                        <?php if (!empty($t['estado_pago'])): ?>
                            <span class="badge <?= $badgePago[$t['estado_pago']] ?? 'badge-inactivo' ?>">
                                <?= htmlspecialchars($t['estado_pago']) ?>
                            </span><br>
                            <small class="texto-gris"><?= $money($t['monto_total']) ?></small>
                        <?php else: ?>
                            <small class="texto-gris">—</small>
                        <?php endif; ?>
                    </td>
                    <td class="nowrap">
                    <?php if ($t['estado_pago'] === 'Pendiente' && !in_array($t['estado'], ['Cancelado','Realizado'])): ?>
                        <a href="<?= $URL_PAGO ?>?accion=elegir&id_pago=<?= (int)$t['id_pago'] ?>"
                           class="btn btn-primario btn-sm" title="Pagar turno">Pagar</a>
                    <?php endif; ?>
                    <?php if (!in_array($t['estado'], ['Cancelado','Realizado'])): ?>
                        <!-- Cancelar -->
                        <button class="btn-icono peligro"
                            data-modal="modal-cancelar"
                            onclick="document.getElementById('cancelarId').value='<?= $t['id_turno'] ?>';"
                            title="Cancelar turno">
                            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                        </button>
                        <!-- Cambiar estado -->
                        <button class="btn-icono"
                        <?php if (!$esPaciente): ?>
                            data-modal="modal-estado"
                            onclick="document.getElementById('estadoId').value='<?= $t['id_turno'] ?>';document.getElementById('estadoActual').textContent='<?= $t['estado'] ?>';"
                            title="Cambiar estado">
                            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>
                        <!-- Historial -->
                        <?php if (!$esPaciente): ?>
                            <button class="btn-icono"
                                onclick="verHistorial(<?= $t['id_turno'] ?>)"
                                title="Ver historial">
                                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            </button>
                        <?php endif; ?>
                    </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal cancelar -->
<div class="modal-overlay" id="modal-cancelar">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-titulo">Cancelar turno</span>
            <button class="btn-cerrar-modal">✕</button>
        </div>
        <form method="POST" action="<?= BASE_URL ?>sistema/controladores/ControladorTurno.php?accion=cancelar">
            <?php csrf_field(); ?>
            <div class="modal-body">
                <input type="hidden" name="id_turno" id="cancelarId">
                <div class="form-group">
                    <label>Motivo de cancelación</label>
                    <input type="text" name="observacion" class="form-control" placeholder="Ej: Paciente no puede asistir" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secundario btn-cerrar-modal">Volver</button>
                <button type="submit" class="btn btn-peligro">Confirmar cancelación</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal cambiar estado -->
<div class="modal-overlay" id="modal-estado">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-titulo">Cambiar estado del turno</span>
            <button class="btn-cerrar-modal">✕</button>
        </div>
        <form method="POST" action="<?= BASE_URL ?>sistema/controladores/ControladorTurno.php?accion=cambiarEstado">
            <?php csrf_field(); ?>
            <div class="modal-body">
                <input type="hidden" name="id_turno" id="estadoId">
                <p class="texto-ayuda">
                    Estado actual: <strong id="estadoActual"></strong>
                </p>
                <div class="form-group">
                    <label>Nuevo estado <span class="req">*</span></label>
                    <select name="estado" class="form-control" required>
                        <option value="">— Seleccioná —</option>
                        <option value="Confirmado">Confirmado</option>
                        <option value="Realizado">Realizado</option>
                        <option value="Ausente">Ausente</option>
                        <option value="Cancelado">Cancelado</option>
                    </select>
                </div>
                <div class="form-group mt-12">
                    <label>Observación</label>
                    <input type="text" name="observacion" class="form-control" placeholder="Opcional">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secundario btn-cerrar-modal">Volver</button>
                <button type="submit" class="btn btn-primario">Guardar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal historial -->
<div class="modal-overlay" id="modal-historial">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-titulo">Historial del turno</span>
            <button class="btn-cerrar-modal">✕</button>
        </div>
        <div class="modal-body" id="historial-contenido">
            <p style="color:var(--gris);font-style:italic">Cargando...</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secundario btn-cerrar-modal">Cerrar</button>
        </div>
    </div>
</div>

<script>
function verHistorial(id) {
    const overlay = document.getElementById('modal-historial');
    const contenido = document.getElementById('historial-contenido');
    overlay.classList.add('abierto');
    contenido.innerHTML = '<p class="texto-cargando">Cargando...</p>';

    fetch('<?= BASE_URL ?>sistema/controladores/ControladorTurno.php?accion=historial&id=' + encodeURIComponent(id))
        .then(r => {
            // El endpoint responde 403 (con JSON) si el turno no es del
            // paciente. Sin este chequeo, ese caso caía en el "sin
            // registros" de abajo y mostraba un mensaje engañoso.
            if (!r.ok) throw new Error('http ' + r.status);
            return r.json();
        })
        .then(data => {
            if (!data.historial || data.historial.length === 0) {
                mostrarMensaje('Sin registros en el historial.', 'texto-cargando');
                return;
            }

            // SEGURIDAD (XSS): el historial incluye `observacion`, que es
            // texto libre escrito por el usuario al cancelar un turno.
            // Antes se interpolaba en un template y se asignaba con
            // innerHTML, así que un motivo como
            //     <img src=x onerror="...">
            // se EJECUTABA en el navegador de quien abriera el historial
            // (típicamente un admin) → robo de sesión.
            // Ahora cada celda se llena con textContent, que escribe texto
            // plano y nunca interpreta HTML. Es la corrección de raíz:
            // no depende de acordarse de escapar en cada punto.
            const wrap  = document.createElement('div');
            wrap.className = 'tabla-wrap';
            const tabla = document.createElement('table');

            const thead = document.createElement('thead');
            const trh   = document.createElement('tr');
            ['Fecha', 'Desde', 'Hasta', 'Observación'].forEach(t => {
                const th = document.createElement('th');
                th.textContent = t;
                trh.appendChild(th);
            });
            thead.appendChild(trh);
            tabla.appendChild(thead);

            const tbody = document.createElement('tbody');
            data.historial.forEach(h => {
                const tr = document.createElement('tr');
                [h.fecha_cambio, h.estado_anterior ?? '—', h.estado_nuevo, h.observacion ?? '']
                    .forEach(valor => {
                        const td = document.createElement('td');
                        td.textContent = valor;   // ← nunca innerHTML
                        tr.appendChild(td);
                    });
                tbody.appendChild(tr);
            });
            tabla.appendChild(tbody);
            wrap.appendChild(tabla);

            contenido.replaceChildren(wrap);
        })
        .catch(() => {
            mostrarMensaje('No se pudo cargar el historial. Intentá de nuevo.', 'texto-error');
        });

    /** Reemplaza el cuerpo del modal por un mensaje simple. */
    function mostrarMensaje(texto, clase) {
        const p = document.createElement('p');
        p.className = clase;
        p.textContent = texto;
        contenido.replaceChildren(p);
    }
}
</script>

<?php require __DIR__ . '/../layouts/footer.php'; ?>

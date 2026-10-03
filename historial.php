<?php
// historial.php — Historial médico del paciente
// -----------------------------------------------------------------
// Punto de entrada del Área del Paciente, en la raíz junto a
// dashboard.php, agendar.php y perfil.php: es una pantalla de SU cuenta,
// no una acción sobre un recurso ajeno.
//
// Consultas y estudios llegan mezclados y ordenados por fecha desde
// Historial::timeline(), que los une en SQL. Acá sólo se dibujan.
//
// TODO lo que se muestra sale de la base. Si el paciente no tiene nada
// registrado, ve un estado vacío que lo explica — no una pantalla en
// blanco ni datos de ejemplo.
// -----------------------------------------------------------------
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/includes/seguridad.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/sistema/modelos/Historial.php';

iniciarSesionSegura();
cabecerasSeguridad();
verificarSesion();
verificarRol(['paciente']);

$modelo     = new Historial($pdo);
$idPaciente = (int) ($_SESSION['id_paciente'] ?? 0);

$paginaTitulo = 'Historial médico';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / Historial médico';
$cssExtra     = ['paciente.css'];

if ($idPaciente <= 0) {
    require_once __DIR__ . '/sistema/vistas/layouts/navbar.php';
    echo '<div class="alerta alerta-error">Tu cuenta no tiene una ficha de paciente '
       . 'asociada, así que todavía no hay historial que mostrar.</div>';
    require_once __DIR__ . '/sistema/vistas/layouts/footer.php';
    exit;
}

// ── Filtros ──────────────────────────────────────────────────
$filtros = [
    'clase' => is_string($_GET['clase'] ?? null) ? trim($_GET['clase']) : '',
    'q'     => is_string($_GET['q']     ?? null) ? trim($_GET['q'])     : '',
    'desde' => is_string($_GET['desde'] ?? null) ? trim($_GET['desde']) : '',
    'hasta' => is_string($_GET['hasta'] ?? null) ? trim($_GET['hasta']) : '',
];
// Una fecha con formato inválido se descarta en vez de llegar al SQL:
// no rompe nada (va como parámetro) pero devolvería cero filas sin que
// la persona entienda por qué.
foreach (['desde', 'hasta'] as $k) {
    if ($filtros[$k] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtros[$k])) {
        $filtros[$k] = '';
    }
}
$hayFiltros = $filtros['clase'] !== '' || $filtros['q'] !== ''
           || $filtros['desde'] !== '' || $filtros['hasta'] !== '';

$linea   = $modelo->timeline($idPaciente, $filtros);
$resumen = $modelo->resumen($idPaciente);

$URL_H = BASE_URL . 'sistema/controladores/ControladorHistorial.php';

$MESES = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun',
          'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

require_once __DIR__ . '/sistema/vistas/layouts/navbar.php';
?>

<header class="pac-saludo">
    <h1>Historial médico</h1>
    <p>Tus consultas, estudios y resultados</p>
</header>

<!-- ══════════ Resumen ══════════ -->
<div class="pac-accesos" style="margin-bottom:22px">
    <div class="pac-acceso" style="cursor:default">
        <span class="pac-acceso-ico pac-ico-azul" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M9 13h6M9 17h4"/></svg>
        </span>
        <strong><?= (int) ($resumen['consultas'] ?? 0) ?></strong>
        <span>Consulta<?= (int) ($resumen['consultas'] ?? 0) === 1 ? '' : 's' ?> registrada<?= (int) ($resumen['consultas'] ?? 0) === 1 ? '' : 's' ?></span>
    </div>
    <div class="pac-acceso" style="cursor:default">
        <span class="pac-acceso-ico pac-ico-verde" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M9 3h6l1 3h3a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h3z"/><circle cx="12" cy="13" r="3"/></svg>
        </span>
        <strong><?= (int) ($resumen['resultados'] ?? 0) ?> / <?= (int) ($resumen['estudios'] ?? 0) ?></strong>
        <span>Estudios con resultado</span>
    </div>
    <div class="pac-acceso" style="cursor:default">
        <span class="pac-acceso-ico pac-ico-gris" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
        </span>
        <strong><?= !empty($resumen['ultima_visita'])
            ? htmlspecialchars(date('d/m/Y', strtotime($resumen['ultima_visita'])))
            : '—' ?></strong>
        <span>Última visita</span>
    </div>
</div>

<!-- ══════════ Filtros ══════════
     Es un GET a propósito: cada búsqueda queda en la URL, así el botón
     "atrás" funciona y un filtro se puede guardar en favoritos. -->
<form method="GET" action="<?= BASE_URL ?>historial.php" class="hist-filtros">
    <div class="form-group">
        <label for="q">Buscar</label>
        <input type="search" name="q" id="q" class="form-control"
               value="<?= htmlspecialchars($filtros['q']) ?>"
               placeholder="Motivo, estudio, profesional…">
    </div>
    <div class="form-group">
        <label for="clase">Tipo</label>
        <select name="clase" id="clase" class="form-control">
            <option value="">Todo</option>
            <option value="consulta" <?= $filtros['clase'] === 'consulta' ? 'selected' : '' ?>>Consultas</option>
            <option value="estudio"  <?= $filtros['clase'] === 'estudio'  ? 'selected' : '' ?>>Estudios</option>
        </select>
    </div>
    <div class="form-group">
        <label for="desde">Desde</label>
        <input type="date" name="desde" id="desde" class="form-control"
               value="<?= htmlspecialchars($filtros['desde']) ?>">
    </div>
    <div class="form-group">
        <label for="hasta">Hasta</label>
        <input type="date" name="hasta" id="hasta" class="form-control"
               value="<?= htmlspecialchars($filtros['hasta']) ?>">
    </div>
    <div class="form-group form-group--fin">
        <div class="btn-grupo">
            <button type="submit" class="btn btn-primario">Filtrar</button>
            <?php if ($hayFiltros): ?>
            <a href="<?= BASE_URL ?>historial.php" class="btn btn-secundario">Limpiar</a>
            <?php endif; ?>
        </div>
    </div>
</form>

<!-- ══════════ Línea de tiempo ══════════ -->
<?php if (!$linea): ?>
    <div class="pac-vacio">
        <div class="pac-vacio-ico" aria-hidden="true">
            <svg width="42" height="42" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M9 13h6M9 17h4"/>
            </svg>
        </div>
        <?php if ($hayFiltros): ?>
            <h3>Sin resultados</h3>
            <p>No encontramos nada con esos filtros. Probá con otras fechas o limpiá la búsqueda.</p>
            <a href="<?= BASE_URL ?>historial.php" class="btn btn-secundario">Ver todo el historial</a>
        <?php else: ?>
            <h3>Tu historial está vacío</h3>
            <p>
                Acá vas a ver el detalle de cada consulta y los resultados de tus
                estudios, a medida que tus profesionales los vayan registrando.
            </p>
            <a href="<?= BASE_URL ?>agendar.php" class="btn btn-primario">Agendar una consulta</a>
        <?php endif; ?>
    </div>
<?php else: ?>

    <p class="form-hint" style="margin-bottom:12px">
        <?= count($linea) ?> registro<?= count($linea) === 1 ? '' : 's' ?>
        <?= $hayFiltros ? ' con los filtros aplicados' : '' ?>, del más reciente al más antiguo.
    </p>

    <ol class="hist-linea">
        <?php foreach ($linea as $h):
            $ts       = strtotime($h['fecha']);
            $esEstudio = $h['clase'] === 'estudio';
            $listo    = $esEstudio && $h['estado'] === 'Disponible';
        ?>
        <li class="hist-item hist-item--<?= $h['clase'] ?> <?= $listo ? 'hist-item--listo' : '' ?>">
            <span class="hist-fecha">
                <strong><?= (int) date('j', $ts) ?></strong>
                <?= $MESES[(int) date('n', $ts)] ?>
                <small><?= date('Y', $ts) ?></small>
            </span>

            <div class="hist-cuerpo">
                <div class="hist-cab">
                    <h3><?= htmlspecialchars($h['titulo']) ?></h3>
                    <span class="hist-etiqueta">
                        <?= $esEstudio ? 'Estudio' : 'Consulta' ?>
                    </span>
                </div>

                <p class="hist-meta">
                    <?= htmlspecialchars($h['subtitulo'] ?? '') ?>
                    · Dr/a. <?= htmlspecialchars($h['medico']) ?>
                </p>

                <?php if (!empty($h['detalle'])): ?>
                <div class="hist-bloque">
                    <span class="hist-rotulo">Diagnóstico</span>
                    <p><?= nl2br(htmlspecialchars($h['detalle'])) ?></p>
                </div>
                <?php endif; ?>

                <?php if (!empty($h['extra'])): ?>
                <div class="hist-bloque hist-bloque--indicaciones">
                    <span class="hist-rotulo">Indicaciones</span>
                    <p><?= nl2br(htmlspecialchars($h['extra'])) ?></p>
                </div>
                <?php endif; ?>

                <?php if ($esEstudio): ?>
                    <?php if ($listo): ?>
                    <div class="hist-acciones">
                        <?php // "Ver" abre el PDF en el visor del navegador;
                              // "Descargar" lo baja. Son cosas distintas y la
                              // persona sabe cuál quiere. ?>
                        <a href="<?= $URL_H ?>?accion=descargar&id=<?= (int) $h['id'] ?>"
                           class="btn btn-primario btn-sm" target="_blank" rel="noopener">Ver resultado</a>
                        <a href="<?= $URL_H ?>?accion=descargar&id=<?= (int) $h['id'] ?>&descargar=1"
                           class="btn btn-secundario btn-sm">Descargar</a>
                    </div>
                    <?php else: ?>
                    <p class="hist-pendiente">
                        Todavía no hay resultado cargado. Te avisamos apenas esté.
                    </p>
                    <?php endif; ?>
                <?php elseif (!empty($h['id_turno'])): ?>
                    <div class="hist-acciones">
                        <a href="<?= BASE_URL ?>sistema/controladores/ControladorTurno.php?accion=detalle&id=<?= (int) $h['id_turno'] ?>"
                           class="btn btn-secundario btn-sm">Ver el turno</a>
                    </div>
                <?php endif; ?>
            </div>
        </li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>

<?php require_once __DIR__ . '/sistema/vistas/layouts/footer.php'; ?>

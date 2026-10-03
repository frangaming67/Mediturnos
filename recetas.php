<?php
// recetas.php — Mis recetas (Área del Paciente)
// -----------------------------------------------------------------
// Punto de entrada en la raíz, junto a dashboard.php, agendar.php,
// historial.php y perfil.php: es una pantalla de SU cuenta, no una
// acción sobre un recurso ajeno. Las acciones (pedir la renovación, ver
// el detalle) viven en ControladorReceta.php.
//
// Todo lo que se muestra sale de la base. Si el paciente no tiene
// recetas, ve un estado vacío que lo explica — no una pantalla en blanco
// ni datos de ejemplo.
// -----------------------------------------------------------------
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/includes/seguridad.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/sistema/modelos/Receta.php';

iniciarSesionSegura();
cabecerasSeguridad();
verificarSesion();
verificarRol(['paciente']);

$modelo     = new Receta($pdo);
$idPaciente = (int) ($_SESSION['id_paciente'] ?? 0);

$paginaTitulo = 'Mis recetas';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / Mis recetas';
$cssExtra     = ['paciente.css'];

$URL_R = BASE_URL . 'sistema/controladores/ControladorReceta.php';

if ($idPaciente <= 0) {
    require_once __DIR__ . '/sistema/vistas/layouts/navbar.php';
    echo '<div class="alerta alerta-error">Tu cuenta no tiene una ficha de paciente '
       . 'asociada, así que todavía no hay recetas que mostrar.</div>';
    require_once __DIR__ . '/sistema/vistas/layouts/footer.php';
    exit;
}

// ── Filtros ──────────────────────────────────────────────────
// GET a propósito: cada búsqueda queda en la URL, así el botón "atrás"
// funciona y un filtro se puede guardar en favoritos.
$filtros = [
    'situacion' => is_string($_GET['situacion'] ?? null) ? trim($_GET['situacion']) : '',
    'q'         => is_string($_GET['q'] ?? null)         ? trim($_GET['q'])         : '',
];
$hayFiltros = $filtros['situacion'] !== '' || $filtros['q'] !== '';

$recetas      = $modelo->deDelPaciente($idPaciente, $filtros);
$resumen      = $modelo->resumenPaciente($idPaciente);
$renovaciones = $modelo->renovacionesDePaciente($idPaciente);

// Los pedidos que todavía esperan respuesta se muestran arriba: es lo
// que el paciente vino a mirar si ya pidió una renovación.
$pendientes = array_values(array_filter(
    $renovaciones,
    static fn(array $r): bool => $r['estado'] === 'Pendiente'
));

// Un aviso suelto para los errores que vuelven de una acción cuando el
// destino ya no existe o no corresponde.
$aviso = is_string($_GET['err'] ?? null) ? $_GET['err'] : null;
$exito = is_string($_GET['msg'] ?? null) ? $_GET['msg'] : null;

require_once __DIR__ . '/sistema/vistas/layouts/navbar.php';
?>

<header class="pac-saludo">
    <h1>Mis recetas</h1>
    <p>Lo que te prescribieron, hasta cuándo vale y cómo pedir una renovación</p>
</header>

<?php if ($aviso !== null): ?>
<div class="alerta alerta-error" role="alert"><?= htmlspecialchars($aviso) ?></div>
<?php endif; ?>

<?php if ($exito !== null): ?>
<div class="alerta alerta-exito" role="alert">
    <?php
    $textos = [
        'pedida'    => 'Tu pedido de renovación quedó registrado. Le avisamos al profesional.',
        'emitida'   => 'La receta quedó emitida.',
        'aprobada'  => 'La renovación fue aprobada.',
        'rechazada' => 'La renovación fue rechazada.',
    ];
    echo htmlspecialchars($textos[$exito] ?? 'Listo.');
    ?>
</div>
<?php endif; ?>

<!-- ══════════ Resumen ══════════ -->
<div class="pac-accesos" style="margin-bottom:22px">
    <div class="pac-acceso" style="cursor:default">
        <span class="pac-acceso-ico pac-ico-verde" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 15h6M12 12v6"/></svg>
        </span>
        <strong><?= $resumen['vigentes'] ?></strong>
        <span>Vigente<?= $resumen['vigentes'] === 1 ? '' : 's' ?></span>
    </div>
    <div class="pac-acceso" style="cursor:default">
        <span class="pac-acceso-ico <?= $resumen['por_vencer'] > 0 ? 'pac-ico-amarillo' : 'pac-ico-gris' ?>" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
        </span>
        <strong><?= $resumen['por_vencer'] ?></strong>
        <span>Vence<?= $resumen['por_vencer'] === 1 ? '' : 'n' ?> esta semana</span>
    </div>
    <div class="pac-acceso" style="cursor:default">
        <span class="pac-acceso-ico pac-ico-gris" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 3v6h-6"/></svg>
        </span>
        <strong><?= count($pendientes) ?></strong>
        <span>Pedido<?= count($pendientes) === 1 ? '' : 's' ?> esperando respuesta</span>
    </div>
</div>

<!-- ══════════ Pedidos pendientes ══════════ -->
<?php if ($pendientes): ?>
<div class="panel" style="margin-bottom:20px">
    <div class="panel-header"><span class="panel-titulo">Renovaciones pedidas</span></div>
    <div class="panel-body">
        <ul class="rec-pedidos">
            <?php foreach ($pendientes as $p): ?>
            <li>
                <div>
                    <strong>Pedido del <?= htmlspecialchars(date('d/m/Y', strtotime($p['solicitada_en']))) ?></strong>
                    <span>
                        A Dr/a. <?= htmlspecialchars($p['medico']) ?>
                        <?php if (!empty($p['motivo'])): ?>
                            · «<?= htmlspecialchars($p['motivo']) ?>»
                        <?php endif; ?>
                    </span>
                </div>
                <span class="badge badge-ausente">Esperando respuesta</span>
            </li>
            <?php endforeach; ?>
        </ul>
        <p class="form-hint" style="margin-top:12px">
            Te avisamos por correo y acá mismo en cuanto el profesional responda.
        </p>
    </div>
</div>
<?php endif; ?>

<!-- ══════════ Filtros ══════════ -->
<?php // No se dibujan si no hay nada que filtrar: un buscador sobre cero
      // recetas no ayuda, sólo ocupa la pantalla. ?>
<?php if ($resumen['total'] > 0): ?>
<form method="GET" action="<?= BASE_URL ?>recetas.php" class="hist-filtros" style="grid-template-columns:minmax(180px,2fr) minmax(140px,1fr) auto">
    <div class="form-group">
        <label for="q">Buscar</label>
        <input type="search" name="q" id="q" class="form-control"
               value="<?= htmlspecialchars($filtros['q']) ?>"
               placeholder="Medicamento, profesional, diagnóstico…">
    </div>
    <div class="form-group">
        <label for="situacion">Estado</label>
        <select name="situacion" id="situacion" class="form-control">
            <option value="">Todas</option>
            <?php foreach (['Vigente', 'Vencida', 'Anulada'] as $s): ?>
            <option value="<?= $s ?>" <?= $filtros['situacion'] === $s ? 'selected' : '' ?>><?= $s ?>s</option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group form-group--fin">
        <div class="btn-grupo">
            <button type="submit" class="btn btn-primario">Filtrar</button>
            <?php if ($hayFiltros): ?>
            <a href="<?= BASE_URL ?>recetas.php" class="btn btn-secundario">Limpiar</a>
            <?php endif; ?>
        </div>
    </div>
</form>
<?php endif; ?>

<!-- ══════════ Listado ══════════ -->
<?php if (!$recetas): ?>
    <div class="pac-vacio">
        <div class="pac-vacio-ico" aria-hidden="true">
            <svg width="42" height="42" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 15h6M12 12v6"/>
            </svg>
        </div>
        <?php if ($hayFiltros): ?>
            <h3>Sin resultados</h3>
            <p>No encontramos recetas con esos filtros. Probá con otro texto o mirá todas.</p>
            <a href="<?= BASE_URL ?>recetas.php" class="btn btn-secundario">Ver todas mis recetas</a>
        <?php else: ?>
            <h3>Todavía no tenés recetas</h3>
            <p>
                Acá vas a ver lo que te prescriban tus profesionales, con la dosis de
                cada medicamento y hasta cuándo vale cada receta.
            </p>
            <a href="<?= BASE_URL ?>agendar.php" class="btn btn-primario">Agendar una consulta</a>
        <?php endif; ?>
    </div>
<?php else: ?>

    <p class="form-hint" style="margin-bottom:12px">
        <?= count($recetas) ?> receta<?= count($recetas) === 1 ? '' : 's' ?><?= $hayFiltros ? ' con los filtros aplicados' : '' ?>,
        de la más reciente a la más antigua.
        <?php if (count($recetas) >= Receta::MAX_FILAS): ?>
            Se muestran las <?= Receta::MAX_FILAS ?> más nuevas.
        <?php endif; ?>
    </p>

    <ul class="rec-lista">
        <?php foreach ($recetas as $r):
            // La situación la calculó el modelo en SQL: la vista no
            // vuelve a deducir si está vencida, porque entonces esta
            // pantalla y el detalle podrían no coincidir.
            $sit     = $r['situacion'];
            $noRenov = $modelo->motivoNoRenovable($r);
            $dias    = (int) $r['dias_restantes'];
        ?>
        <li class="rec-item rec-item--<?= mb_strtolower($sit) ?>">
            <div class="rec-cab">
                <div>
                    <h3><?= htmlspecialchars($r['medicamentos'] ?? 'Receta sin medicamentos') ?></h3>
                    <p class="rec-meta">
                        Dr/a. <?= htmlspecialchars($r['medico']) ?>
                        <?php if (!empty($r['especialidad'])): ?>
                            · <?= htmlspecialchars($r['especialidad']) ?>
                        <?php endif; ?>
                        · emitida el <?= htmlspecialchars(date('d/m/Y', strtotime($r['emitida_el']))) ?>
                    </p>
                </div>
                <span class="badge badge-<?= $sit === 'Vigente' ? 'activo' : ($sit === 'Anulada' ? 'cancelado' : 'ausente') ?>">
                    <?= htmlspecialchars($sit) ?>
                </span>
            </div>

            <?php if (!empty($r['diagnostico'])): ?>
            <p class="rec-diag"><?= htmlspecialchars($r['diagnostico']) ?></p>
            <?php endif; ?>

            <p class="rec-vigencia">
                <?php if ($sit === 'Anulada'): ?>
                    Anulada el <?= htmlspecialchars(date('d/m/Y', strtotime($r['anulada_el'] ?? $r['emitida_el']))) ?>
                    <?php if (!empty($r['anulada_motivo'])): ?>
                        — <?= htmlspecialchars($r['anulada_motivo']) ?>
                    <?php endif; ?>
                <?php elseif ($sit === 'Vencida'): ?>
                    Venció el <?= htmlspecialchars(date('d/m/Y', strtotime($r['vence_el']))) ?>
                <?php elseif ($dias === 0): ?>
                    <strong>Vence hoy</strong>
                <?php elseif ($dias <= 7): ?>
                    <strong>Vence en <?= $dias ?> día<?= $dias === 1 ? '' : 's' ?></strong>,
                    el <?= htmlspecialchars(date('d/m/Y', strtotime($r['vence_el']))) ?>
                <?php else: ?>
                    Válida hasta el <?= htmlspecialchars(date('d/m/Y', strtotime($r['vence_el']))) ?>
                <?php endif; ?>
                · <?= (int) $r['items'] ?> medicamento<?= (int) $r['items'] === 1 ? '' : 's' ?>
            </p>

            <div class="rec-acciones">
                <a href="<?= $URL_R ?>?accion=ver&id=<?= (int) $r['id_receta'] ?>"
                   class="btn btn-secundario btn-sm">Ver el detalle</a>

                <?php // El botón de renovar aparece sólo si se puede, y cuando
                      // no se puede se explica por qué. El motivo lo decide
                      // Receta::motivoNoRenovable(), el MISMO método que usa el
                      // controlador para rechazar la petición: así nunca hay un
                      // botón que no hace nada, ni una URL que logra lo que el
                      // botón oculto no ofrecía. ?>
                <?php if ($noRenov === null): ?>
                <form method="POST" action="<?= $URL_R ?>?accion=solicitar" class="rec-renovar">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="id_receta" value="<?= (int) $r['id_receta'] ?>">
                    <input type="text" name="motivo" class="form-control" maxlength="300"
                           placeholder="Motivo (opcional)"
                           aria-label="Motivo del pedido de renovación">
                    <button type="submit" class="btn btn-primario btn-sm">Pedir renovación</button>
                </form>
                <?php else: ?>
                <span class="rec-nota"><?= htmlspecialchars($noRenov) ?></span>
                <?php endif; ?>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>

    <!-- ══════════ Historial de pedidos ya respondidos ══════════ -->
    <?php $resueltos = array_values(array_filter(
        $renovaciones,
        static fn(array $r): bool => $r['estado'] !== 'Pendiente'
    )); ?>
    <?php if ($resueltos): ?>
    <div class="panel" style="margin-top:22px">
        <div class="panel-header"><span class="panel-titulo">Pedidos de renovación anteriores</span></div>
        <div class="panel-body">
            <ul class="rec-pedidos">
                <?php foreach ($resueltos as $p): ?>
                <li>
                    <div>
                        <strong>
                            <?= htmlspecialchars(date('d/m/Y', strtotime($p['solicitada_en']))) ?>
                            — Dr/a. <?= htmlspecialchars($p['medico']) ?>
                        </strong>
                        <span>
                            <?php if (!empty($p['respuesta'])): ?>
                                «<?= htmlspecialchars($p['respuesta']) ?>»
                            <?php elseif ($p['estado'] === 'Aprobada'): ?>
                                Se emitió una receta nueva.
                            <?php else: ?>
                                Sin comentarios del profesional.
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="rec-pedido-fin">
                        <span class="badge badge-<?= $p['estado'] === 'Aprobada' ? 'activo' : 'cancelado' ?>">
                            <?= htmlspecialchars($p['estado']) ?>
                        </span>
                        <?php if (!empty($p['id_receta_nueva'])): ?>
                        <a href="<?= $URL_R ?>?accion=ver&id=<?= (int) $p['id_receta_nueva'] ?>"
                           class="btn btn-secundario btn-sm">Ver la receta</a>
                        <?php endif; ?>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/sistema/vistas/layouts/footer.php'; ?>

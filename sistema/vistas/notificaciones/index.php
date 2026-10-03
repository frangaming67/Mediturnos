<?php
// sistema/vistas/notificaciones/index.php — Centro de notificaciones
// -----------------------------------------------------------------
// La misma pantalla para los cuatro roles: lo que cambia es lo que cada
// uno recibe, no cómo se ve.
//
// Recibe: $avisos, $sinLeer, $tipos, $total, $pagina, $totalPaginas,
// $filtros, $mensaje, $URL.
// -----------------------------------------------------------------

$paginaTitulo = 'Notificaciones';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / Notificaciones';
// paciente.css además de la propia: de ahí salen `pac-saludo` y
// `pac-vacio`, que son el encabezado y el estado vacío de TODO el
// sistema y no sólo del Área del Paciente. Están en el archivo
// equivocado por su nombre, no por su contenido — es la misma deuda
// anotada para los widgets de formulario que viven en auth.css.
$cssExtra     = ['paciente.css', 'notificaciones.css'];

require __DIR__ . '/../layouts/navbar.php';
require_once __DIR__ . '/../componentes/icono_aviso.php';

$hayFiltros = $filtros['estado'] !== '' || $filtros['tipo'] !== '';
$leidas     = $total - $sinLeer;

/** Hace cuánto llegó, en palabras. Más útil que una fecha exacta. */
$hace = function (string $fecha): string {
    $seg = time() - strtotime($fecha);
    if ($seg < 60)     { return 'hace instantes'; }
    if ($seg < 3600)   { return 'hace ' . (int) ($seg / 60) . ' min'; }
    if ($seg < 86400)  { $h = (int) ($seg / 3600); return 'hace ' . $h . ' hora' . ($h === 1 ? '' : 's'); }
    if ($seg < 604800) { $d = (int) ($seg / 86400); return 'hace ' . $d . ' día' . ($d === 1 ? '' : 's'); }
    // A partir de una semana, la fecha dice más que "hace 23 días".
    return date('d/m/Y', strtotime($fecha));
};

/** Conserva los filtros al armar un enlace. */
$conFiltros = function (array $cambios) {
    $q = array_filter(array_merge([
        'accion' => 'index',
        'estado' => $_GET['estado'] ?? '',
        'tipo'   => $_GET['tipo'] ?? '',
    ], $cambios), fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($q);
};
?>

<?php if (!empty($mensaje)): ?>
<div class="alerta alerta-error" role="alert"><?= htmlspecialchars($mensaje) ?></div>
<?php endif; ?>

<?php if (!empty($_GET['msg'])): ?>
<div class="alerta alerta-exito" role="alert">
    <?php
    $n = (int) ($_GET['n'] ?? 0);
    $textos = [
        'leidas'   => $n === 1 ? 'Marcamos 1 aviso como leído.'
                               : 'Marcamos ' . $n . ' avisos como leídos.',
        'borrada'  => 'El aviso se eliminó.',
        'limpiada' => $n === 1 ? 'Eliminamos 1 aviso leído.'
                               : 'Eliminamos ' . $n . ' avisos leídos.',
    ];
    echo htmlspecialchars($textos[$_GET['msg']] ?? 'Listo.');
    ?>
</div>
<?php endif; ?>

<header class="pac-saludo">
    <h1>Notificaciones</h1>
    <p>
        <?php if ($sinLeer > 0): ?>
            Tenés <strong><?= $sinLeer ?></strong> sin leer
            <?= $total > $sinLeer ? ' de ' . $total . ' en total' : '' ?>
        <?php elseif ($total > 0): ?>
            Estás al día: leíste <?= $total === 1 ? 'el único aviso' : 'los ' . $total . ' avisos' ?>
        <?php else: ?>
            Acá vas a ver los avisos del sistema
        <?php endif; ?>
    </p>
</header>

<?php // Las acciones en bloque y los filtros sólo aparecen si hay algo
      // sobre lo que actuar. Una barra de herramientas encima de una
      // bandeja vacía es puro ruido. ?>
<?php if ($total > 0): ?>
<div class="notif-barra">
    <?php // Filtros por GET: cada combinación queda en la URL, así el
          // botón "atrás" funciona y se puede guardar en favoritos. ?>
    <div class="notif-pestanas" role="group" aria-label="Filtrar por estado">
        <a href="<?= htmlspecialchars($conFiltros(['estado' => ''])) ?>"
           class="notif-pestana <?= $filtros['estado'] === '' ? 'activa' : '' ?>">
            Todas <span><?= $total ?></span>
        </a>
        <a href="<?= htmlspecialchars($conFiltros(['estado' => 'no_leidas'])) ?>"
           class="notif-pestana <?= $filtros['estado'] === 'no_leidas' ? 'activa' : '' ?>">
            Sin leer <span><?= $sinLeer ?></span>
        </a>
        <a href="<?= htmlspecialchars($conFiltros(['estado' => 'leidas'])) ?>"
           class="notif-pestana <?= $filtros['estado'] === 'leidas' ? 'activa' : '' ?>">
            Leídas <span><?= $leidas ?></span>
        </a>
    </div>

    <?php if (count($tipos) > 1): ?>
    <?php // Un solo tipo no necesita desplegable: filtrar por el único
          // que hay no cambia nada. ?>
    <form method="GET" action="<?= $URL ?>" class="notif-tipo">
        <input type="hidden" name="accion" value="index">
        <?php if ($filtros['estado'] !== ''): ?>
        <input type="hidden" name="estado" value="<?= htmlspecialchars($filtros['estado']) ?>">
        <?php endif; ?>
        <label for="tipo" class="solo-lectores">Filtrar por tipo de aviso</label>
        <select name="tipo" id="tipo" class="form-control" onchange="this.form.submit()">
            <option value="">Todos los tipos</option>
            <?php foreach ($tipos as $t => $cuantas): ?>
            <option value="<?= htmlspecialchars($t) ?>" <?= $filtros['tipo'] === $t ? 'selected' : '' ?>>
                <?= htmlspecialchars(TipoAviso::etiqueta($t)) ?> (<?= $cuantas ?>)
            </option>
            <?php endforeach; ?>
        </select>
        <?php // Sin JavaScript el <select> no se envía solo: el botón es
              // el que hace que el filtro funcione igual. ?>
        <noscript><button type="submit" class="btn btn-secundario btn-sm">Filtrar</button></noscript>
    </form>
    <?php endif; ?>

    <div class="notif-acciones">
        <?php if ($sinLeer > 0): ?>
        <form method="POST" action="<?= $URL ?>?accion=leerTodas">
            <?php csrf_field(); ?>
            <button type="submit" class="btn btn-secundario btn-sm">Marcar todas leídas</button>
        </form>
        <?php endif; ?>

        <?php if ($leidas > 0): ?>
        <?php // Sólo borra las LEÍDAS. Un "borrar todo" se llevaría
              // avisos que la persona no vio, y perdería para siempre un
              // resultado disponible o un pago por vencer. ?>
        <form method="POST" action="<?= $URL ?>?accion=eliminarLeidas"
              onsubmit="return confirm('¿Eliminar los <?= $leidas ?> avisos ya leídos? Los que no leíste quedan.');">
            <?php csrf_field(); ?>
            <button type="submit" class="btn btn-secundario btn-sm">Vaciar leídas</button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ══════════ La lista ══════════ -->
<?php if (!$avisos): ?>
    <div class="pac-vacio">
        <div class="pac-vacio-ico" aria-hidden="true">
            <svg width="42" height="42" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <?= iconoAviso('campana') ?>
            </svg>
        </div>
        <?php if ($hayFiltros): ?>
            <h3>Sin resultados</h3>
            <p>No hay avisos con esos filtros.</p>
            <a href="<?= $URL ?>?accion=index" class="btn btn-secundario">Ver todas</a>
        <?php else: ?>
            <h3>No tenés notificaciones</h3>
            <p>
                Acá te vamos a avisar cuando pase algo que te importe: un turno
                confirmado, un pago registrado, un resultado disponible.
            </p>
            <a href="<?= BASE_URL ?>dashboard.php" class="btn btn-secundario">Volver al inicio</a>
        <?php endif; ?>
    </div>
<?php else: ?>

<ul class="notif-lista">
    <?php foreach ($avisos as $a):
        $cfg      = TipoAviso::config($a['tipo']);
        $noLeida  = $a['leida_en'] === null;
        $id       = (int) $a['id_notificacion'];
    ?>
    <li class="notif-item <?= $noLeida ? 'notif-item--nueva' : '' ?>">
        <span class="notif-ico notif-ico--<?= htmlspecialchars($cfg['color']) ?>" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                <?= iconoAviso($cfg['icono']) ?>
            </svg>
        </span>

        <div class="notif-cuerpo">
            <div class="notif-cab">
                <h3>
                    <?php // El título es el enlace: abrirlo marca el aviso
                          // como leído y lleva a lo que lo originó. ?>
                    <a href="<?= $URL ?>?accion=leer&id=<?= $id ?>">
                        <?= htmlspecialchars($a['titulo']) ?>
                    </a>
                </h3>
                <span class="notif-cuando" title="<?= htmlspecialchars(date('d/m/Y H:i', strtotime($a['creada_en']))) ?>">
                    <?= htmlspecialchars($hace($a['creada_en'])) ?>
                </span>
            </div>

            <p class="notif-mensaje"><?= htmlspecialchars($a['mensaje']) ?></p>

            <div class="notif-pie">
                <span class="notif-etiqueta"><?= htmlspecialchars(TipoAviso::etiqueta($a['tipo'])) ?></span>

                <?php if ($noLeida): ?>
                <span class="notif-punto" aria-label="Sin leer">Sin leer</span>
                <?php endif; ?>

                <?php // Que el correo salió se guarda en la fila, así que
                      // se puede responder "¿me llegó el mail?" sin abrir
                      // el log del servidor. ?>
                <?php if (!empty($a['email_enviado_en'])): ?>
                <span class="notif-mail" title="Correo enviado el <?= htmlspecialchars(date('d/m/Y H:i', strtotime($a['email_enviado_en']))) ?>">
                    también por correo
                </span>
                <?php endif; ?>
            </div>
        </div>

        <form method="POST" action="<?= $URL ?>?accion=eliminar" class="notif-borrar">
            <?php csrf_field(); ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <button type="submit" class="btn-icono peligro"
                    title="Eliminar este aviso"
                    aria-label="Eliminar el aviso: <?= htmlspecialchars($a['titulo']) ?>">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round">
                    <path d="M18 6 6 18M6 6l12 12"/>
                </svg>
            </button>
        </form>
    </li>
    <?php endforeach; ?>
</ul>

<?php require __DIR__ . '/../componentes/paginador.php'; ?>
<?php endif; ?>

<?php require __DIR__ . '/../layouts/footer.php'; ?>

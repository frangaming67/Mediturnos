<?php
// sistema/vistas/recetas/renovaciones.php — Bandeja de pedidos (médico)
// -----------------------------------------------------------------
// Los pedidos de renovación que esperan respuesta de ESTE profesional,
// el más viejo primero: es una bandeja de trabajo, y lo que lleva más
// tiempo esperando es lo que hay que resolver antes.
//
// Cada pedido se resuelve con dos botones en el mismo formulario
// (`decision=aprobar` / `decision=rechazar`) y un campo de respuesta
// compartido. Son dos submit de un único <form> y no dos formularios:
// así lo que el profesional escriba acompaña a cualquiera de las dos
// decisiones sin tener que escribirlo dos veces.
//
// Recibe: $pedidos, $mensaje, $URL.
// -----------------------------------------------------------------

$paginaTitulo = 'Pedidos de renovación';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / Pedidos de renovación';
$cssExtra     = ['paciente.css'];

require __DIR__ . '/../layouts/navbar.php';
?>

<?php if (!empty($mensaje)): ?>
<div class="alerta alerta-error" role="alert"><?= htmlspecialchars($mensaje) ?></div>
<?php endif; ?>

<?php if (!empty($_GET['msg'])): ?>
<div class="alerta alerta-exito" role="alert">
    <?php
    $textos = [
        'aprobada'  => 'Aprobaste el pedido: se emitió una receta nueva y el paciente ya fue notificado.',
        'rechazada' => 'Rechazaste el pedido. El paciente recibió tu respuesta.',
    ];
    echo htmlspecialchars($textos[$_GET['msg']] ?? 'Listo.');
    ?>
</div>
<?php endif; ?>

<header class="pac-saludo">
    <h1>Pedidos de renovación</h1>
    <p>
        <?php if ($pedidos): ?>
            <?= count($pedidos) ?> pedido<?= count($pedidos) === 1 ? '' : 's' ?>
            esperando tu respuesta
        <?php else: ?>
            Acá aparecen las renovaciones que te piden tus pacientes
        <?php endif; ?>
    </p>
</header>

<?php if (!$pedidos): ?>
    <div class="pac-vacio">
        <div class="pac-vacio-ico" aria-hidden="true">
            <svg width="42" height="42" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 6 9 17l-5-5"/>
            </svg>
        </div>
        <h3>No tenés pedidos pendientes</h3>
        <p>
            Cuando un paciente pida renovar una receta que firmaste, la vas a ver acá
            y te avisamos por correo.
        </p>
        <a href="<?= BASE_URL ?>dashboard.php" class="btn btn-secundario">Volver a mi agenda</a>
    </div>
<?php else: ?>

<ul class="rec-lista">
    <?php foreach ($pedidos as $p):
        // Cuánto lleva esperando. Es el dato que ordena la bandeja y el
        // que decide a cuál contestar primero, así que se muestra.
        $espera = (int) floor((time() - strtotime($p['solicitada_en'])) / 86400);
    ?>
    <li class="rec-item">
        <div class="rec-cab">
            <div>
                <h3><?= htmlspecialchars($p['paciente']) ?></h3>
                <p class="rec-meta">
                    DNI <?= htmlspecialchars($p['paciente_dni']) ?> ·
                    pidió el <?= htmlspecialchars(date('d/m/Y H:i', strtotime($p['solicitada_en']))) ?>
                </p>
            </div>
            <span class="badge badge-<?= $espera >= 3 ? 'cancelado' : 'ausente' ?>">
                <?= $espera === 0 ? 'Hoy' : 'Hace ' . $espera . ' día' . ($espera === 1 ? '' : 's') ?>
            </span>
        </div>

        <p class="rec-diag">
            <strong>Receta del <?= htmlspecialchars(date('d/m/Y', strtotime($p['emitida_el']))) ?></strong>
            <?php if (!empty($p['diagnostico'])): ?>
                — <?= htmlspecialchars($p['diagnostico']) ?>
            <?php endif; ?>
        </p>

        <p class="rec-vigencia">
            <?= htmlspecialchars($p['medicamentos'] ?? 'Sin medicamentos cargados') ?>
            <?php // Se puede pedir la renovación antes de que venza: el
                  // verbo cambia según el caso para que el profesional vea
                  // de un golpe si el paciente ya se quedó sin medicación. ?>
            · <?= strtotime($p['vence_el']) >= strtotime(date('Y-m-d')) ? 'vence' : 'venció' ?>
            el <?= htmlspecialchars(date('d/m/Y', strtotime($p['vence_el']))) ?>
        </p>

        <?php if (!empty($p['motivo'])): ?>
        <div class="hist-bloque">
            <span class="hist-rotulo">Lo que escribió el paciente</span>
            <p>«<?= htmlspecialchars($p['motivo']) ?>»</p>
        </div>
        <?php endif; ?>

        <form method="POST" action="<?= $URL ?>?accion=resolver" class="rec-resolver">
            <?php csrf_field(); ?>
            <input type="hidden" name="id_renovacion" value="<?= (int) $p['id_renovacion'] ?>">

            <div class="form-group">
                <label for="resp_<?= (int) $p['id_renovacion'] ?>">Respuesta para el paciente</label>
                <input type="text" name="respuesta" id="resp_<?= (int) $p['id_renovacion'] ?>"
                       class="form-control" maxlength="300"
                       placeholder="Opcional: el paciente lo recibe por correo">
            </div>

            <div class="btn-grupo">
                <?php // Aprobar emite una receta NUEVA con los mismos
                      // medicamentos y deja la anterior en el historial. Se
                      // dice en el propio botón para que nadie crea que
                      // extiende la vieja. ?>
                <button type="submit" name="decision" value="aprobar" class="btn btn-primario">
                    Aprobar y emitir receta
                </button>
                <button type="submit" name="decision" value="rechazar" class="btn btn-secundario"
                        onclick="return confirm('¿Rechazar el pedido? El paciente va a recibir tu respuesta.');">
                    Rechazar
                </button>
            </div>
        </form>

        <p class="rec-nota">
            Al aprobar se emite una receta nueva por <?= Receta::VIGENCIA_DIAS ?> días con los
            mismos medicamentos. La anterior no se modifica: queda en el historial como
            registro de lo que se prescribió entonces.
        </p>
    </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<?php require __DIR__ . '/../layouts/footer.php'; ?>

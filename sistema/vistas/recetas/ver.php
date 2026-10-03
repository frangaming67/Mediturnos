<?php
// sistema/vistas/recetas/ver.php — Detalle de una receta
// -----------------------------------------------------------------
// La MISMA vista para el paciente, para el médico y para el mostrador.
// El controlador ya verificó que esta persona puede verla; acá lo único
// que cambia según el rol son las acciones: el paciente puede pedir la
// renovación y el profesional que la firmó puede anularla.
//
// Es una sola vista y no tres porque el contenido —qué se prescribió, en
// qué dosis, hasta cuándo vale— es exactamente el mismo para los tres.
// Tres copias serían tres lugares donde corregir una dosis mal mostrada.
//
// Recibe: $r (la receta con su situación ya resuelta), $items, $noRenov,
// $mensaje, $rol, $miMat, $miPac, $URL.
// -----------------------------------------------------------------

$paginaTitulo = 'Receta del ' . date('d/m/Y', strtotime($r['emitida_el']));
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / '
              . ($rol === 'paciente'
                  ? '<a href="' . BASE_URL . 'recetas.php">Mis recetas</a> / Receta'
                  : 'Receta');
$cssExtra     = ['paciente.css'];

require __DIR__ . '/../layouts/navbar.php';

$sit     = $r['situacion'];
$esMia   = $rol === 'medico' && (int) $r['matricula'] === (int) $miMat;
$soyPac  = $rol === 'paciente';
$MESES   = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
            'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$ts      = strtotime($r['emitida_el']);
$fechaLg = (int) date('j', $ts) . ' de ' . $MESES[(int) date('n', $ts)] . ' de ' . date('Y', $ts);
?>

<?php if (!empty($mensaje)): ?>
<div class="alerta alerta-error no-imprimir" role="alert"><?= htmlspecialchars($mensaje) ?></div>
<?php endif; ?>

<?php if (param('msg') !== ''): ?>
<div class="alerta alerta-exito no-imprimir" role="alert">
    <?php
    $textos = [
        'emitida' => 'La receta quedó emitida y el paciente ya fue notificado.',
        'anulada' => 'La receta quedó anulada y le avisamos al paciente.',
        'pedida'  => 'Tu pedido de renovación quedó registrado. Le avisamos al profesional.',
    ];
    echo htmlspecialchars($textos[param('msg')] ?? 'Listo.');
    ?>
</div>
<?php endif; ?>

<?php // ══════════ El documento ══════════
      // `.comprobante` y las reglas de @media print de paciente.css ya
      // resuelven la impresión: se reutilizan en vez de escribir un
      // segundo juego de reglas que habría que mantener en paralelo. ?>
<div class="comprobante rec-doc">

    <div class="comp-cab">
        <div>
            <strong>MediTurnos</strong>
            <span>Receta médica</span>
        </div>
        <span class="rec-doc-estado rec-doc-estado--<?= mb_strtolower($sit) ?>">
            <?= htmlspecialchars($sit) ?>
        </span>
    </div>

    <div class="rec-doc-cuerpo">

        <!-- Quién y para quién -->
        <div class="rec-doc-partes">
            <div>
                <span class="hist-rotulo">Paciente</span>
                <strong><?= htmlspecialchars($r['paciente']) ?></strong>
                <span class="rec-doc-sub">DNI <?= htmlspecialchars($r['paciente_dni']) ?></span>
            </div>
            <div>
                <span class="hist-rotulo">Profesional</span>
                <strong>Dr/a. <?= htmlspecialchars($r['medico']) ?></strong>
                <?php if (!empty($r['especialidad'])): ?>
                <span class="rec-doc-sub"><?= htmlspecialchars($r['especialidad']) ?></span>
                <?php endif; ?>
                <span class="rec-doc-sub">Matrícula <?= (int) $r['matricula'] ?></span>
            </div>
            <div>
                <span class="hist-rotulo">Emitida</span>
                <strong><?= htmlspecialchars($fechaLg) ?></strong>
                <span class="rec-doc-sub">
                    <?php if ($sit === 'Anulada'): ?>
                        Anulada el <?= htmlspecialchars(date('d/m/Y', strtotime($r['anulada_el'] ?? $r['emitida_el']))) ?>
                    <?php else: ?>
                        Válida hasta el <?= htmlspecialchars(date('d/m/Y', strtotime($r['vence_el']))) ?>
                    <?php endif; ?>
                </span>
            </div>
        </div>

        <?php if (!empty($r['diagnostico'])): ?>
        <div class="rec-doc-bloque">
            <span class="hist-rotulo">Diagnóstico</span>
            <p><?= htmlspecialchars($r['diagnostico']) ?></p>
        </div>
        <?php endif; ?>

        <!-- ══════════ Los medicamentos ══════════ -->
        <span class="hist-rotulo" style="margin-top:18px">Medicamentos</span>
        <?php if (!$items): ?>
            <?php // No debería poder pasar: emitir() rechaza una receta sin
                  // medicamentos. Pero si pasara —una fila cargada a mano en
                  // la base— es mejor decirlo que dibujar una receta vacía
                  // que parece válida. ?>
            <p class="rec-nota">Esta receta no tiene medicamentos cargados.</p>
        <?php else: ?>
        <ol class="rec-items">
            <?php foreach ($items as $i): ?>
            <li>
                <div class="rec-item-nombre">
                    <strong><?= htmlspecialchars($i['nombre']) ?></strong>
                    <?php if (!empty($i['presentacion'])): ?>
                    <span><?= htmlspecialchars($i['presentacion']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="rec-item-pauta">
                    <span><strong><?= htmlspecialchars($i['dosis']) ?></strong>,
                          <?= htmlspecialchars($i['frecuencia']) ?><?php
                          if (!empty($i['duracion'])) {
                              echo ', ' . htmlspecialchars($i['duracion']);
                          } ?></span>
                    <span class="rec-item-cant">
                        <?= (int) $i['cantidad'] ?> envase<?= (int) $i['cantidad'] === 1 ? '' : 's' ?>
                    </span>
                </div>
            </li>
            <?php endforeach; ?>
        </ol>
        <?php endif; ?>

        <?php if (!empty($r['indicaciones'])): ?>
        <div class="hist-bloque hist-bloque--indicaciones" style="margin-top:18px">
            <span class="hist-rotulo">Indicaciones</span>
            <p><?= nl2br(htmlspecialchars($r['indicaciones'])) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($sit === 'Anulada' && !empty($r['anulada_motivo'])): ?>
        <div class="alerta alerta-error" style="margin-top:18px">
            <strong>Receta anulada.</strong>
            <?= htmlspecialchars($r['anulada_motivo']) ?>
        </div>
        <?php endif; ?>

        <?php // La aclaración legal va DENTRO del documento y no al pie de
              // la página: si alguien lo imprime, tiene que salir impresa.
              // Es la diferencia entre una advertencia y una advertencia
              // que sirve. ?>
        <p class="rec-legal">
            Documento interno de MediTurnos, generado el
            <?= htmlspecialchars(date('d/m/Y H:i')) ?>. <strong>No reemplaza a una
            receta con firma del profesional</strong> ni constituye una receta
            electrónica con validez legal: es el registro de lo prescripto en la
            consulta, para que el paciente lo tenga a mano y pueda pedir su
            renovación.
        </p>
    </div>
</div>

<!-- ══════════ Acciones ══════════ -->
<div class="rec-barra no-imprimir">
    <div class="btn-grupo">
        <?php if ($soyPac): ?>
        <a href="<?= BASE_URL ?>recetas.php" class="btn btn-secundario">Volver a mis recetas</a>
        <?php elseif (!empty($r['id_turno'])): ?>
        <a href="<?= BASE_URL ?>sistema/controladores/ControladorHistorial.php?accion=consulta&id=<?= (int) $r['id_turno'] ?>"
           class="btn btn-secundario">Volver a la ficha</a>
        <?php else: ?>
        <a href="<?= BASE_URL ?>dashboard.php" class="btn btn-secundario">Volver al inicio</a>
        <?php endif; ?>

        <?php // Imprimir es la única pieza de JavaScript de la pantalla y
              // no hace falta para nada más: sin él, Ctrl+P hace lo mismo.
              // Progressive enhancement: el botón es un extra, no el camino. ?>
        <button type="button" class="btn btn-secundario" onclick="window.print()">Imprimir</button>
    </div>

    <?php // ── El paciente: pedir la renovación ───────────────────
          // Las mismas reglas que en el listado, decididas por el MISMO
          // método del modelo que usa el controlador para rechazar la
          // petición. ?>
    <?php if ($soyPac && $noRenov === null): ?>
    <form method="POST" action="<?= $URL ?>?accion=solicitar" class="rec-renovar">
        <?php csrf_field(); ?>
        <input type="hidden" name="id_receta" value="<?= (int) $r['id_receta'] ?>">
        <input type="text" name="motivo" class="form-control" maxlength="300"
               placeholder="Motivo (opcional)" aria-label="Motivo del pedido de renovación">
        <button type="submit" class="btn btn-primario">Pedir renovación</button>
    </form>
    <?php elseif ($soyPac): ?>
    <span class="rec-nota"><?= htmlspecialchars($noRenov) ?></span>
    <?php endif; ?>

    <?php // ── El médico que la firmó: anularla ───────────────────
          // Sólo él. Otro profesional puede VER la receta de un paciente
          // que atendió —eso es atención clínica— pero no dar de baja lo
          // que prescribió un colega. El controlador lo revalida. ?>
    <?php if ($esMia && $sit === 'Vigente'): ?>
    <form method="POST" action="<?= $URL ?>?accion=anular" class="rec-renovar"
          onsubmit="return confirm('¿Anular esta receta? El paciente va a recibir un aviso.');">
        <?php csrf_field(); ?>
        <input type="hidden" name="id_receta" value="<?= (int) $r['id_receta'] ?>">
        <input type="text" name="motivo" class="form-control" maxlength="200"
               placeholder="Motivo de la anulación" aria-label="Motivo de la anulación">
        <button type="submit" class="btn btn-peligro">Anular</button>
    </form>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../layouts/footer.php'; ?>

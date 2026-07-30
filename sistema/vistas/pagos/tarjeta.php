<?php
// sistema/vistas/pagos/tarjeta.php — Formulario de pago con tarjeta (pasarela simulada)
//Permite ingresar datos de tarjeta en una pasarela simulada.
// -----------------------------------------------------------------
// El formateo del número de tarjeta (agrupar de a 4 dígitos) y la
// limpieza del CVV se hacen con JS inline, sólo cosmético del lado
// cliente — la validación que de verdad importa (Luhn, vencimiento,
// formato) se repite en el servidor dentro de Pago::pagarConTarjeta(),
// porque el JS del navegador se puede desactivar o esquivar y nunca
// alcanza como única defensa.
// -----------------------------------------------------------------
$paginaTitulo = 'Pagar con tarjeta';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / <a href="' . BASE_URL . 'sistema/controladores/ControladorTurno.php?accion=index">Turnos</a> / Pago con tarjeta';
$cssExtra     = ['paciente.css'];
require __DIR__ . '/../layouts/navbar.php';

$URL    = BASE_URL . 'sistema/controladores/ControladorPago.php';
$money  = fn($v) => '$' . number_format((float)$v, 2, ',', '.');
$anioAc = (int)date('Y');

// Segundos que quedan de retención del horario.
$restan = strtotime($pago['fecha_vencimiento']) - time();
?>

<?php if (!empty($mensaje)): ?>
<div class="alerta alerta-error"><?= htmlspecialchars($mensaje) ?></div>
<?php endif; ?>

<?php // La cuenta regresiva se muestra sólo cuando la retención es corta.
      // Con el plazo largo (48 h) un reloj sería alarmismo inútil; con 15
      // minutos es información que la persona necesita para decidir si
      // paga ahora o pide más tiempo.
      if ($restan > 0 && $restan <= 2 * Pago::MINUTOS_RESERVA * 60): ?>
<div class="pago-reloj" id="pago-reloj" role="status">
    <span>
        Te estamos guardando el horario. Si no completás el pago, se libera para otra persona.
    </span>
    <strong id="reloj-cuenta" data-restan="<?= (int) $restan ?>">
        <?= sprintf('%02d:%02d', intdiv($restan, 60), $restan % 60) ?>
    </strong>
</div>
<?php endif; ?>

<div class="panel panel-md">
    <div class="panel-header">
        <span class="panel-titulo">Pago con tarjeta</span>
    </div>
    <div class="panel-body">

        <div class="alerta alerta-info mb-20">
            <strong><?= htmlspecialchars($pago['especialidad']) ?></strong> con
            <strong><?= htmlspecialchars($pago['medico']) ?></strong>
            &nbsp;|&nbsp; 📅 <?= date('d/m/Y', strtotime($pago['fecha'])) ?>
            🕐 <?= substr($pago['hora_inicio'], 0, 5) ?><br>
            Total a pagar: <strong><?= $money($pago['monto_total']) ?></strong>
        </div>

        <form method="POST" action="<?= $URL ?>?accion=procesar" id="form-tarjeta" autocomplete="off">
            <?php csrf_field(); ?>
            <input type="hidden" name="id_pago" value="<?= (int)$pago['id_pago'] ?>">

            <div class="form-grid mb-20">
                <div class="form-group col-full">
                    <label for="titular">Nombre del titular <span class="req">*</span></label>
                    <input type="text" name="titular" id="titular" class="form-control"
                           placeholder="Como figura en la tarjeta" required>
                </div>

                <div class="form-group col-full">
                    <label for="numero">Número de tarjeta <span class="req">*</span></label>
                    <input type="text" name="numero" id="numero" class="form-control"
                           inputmode="numeric" maxlength="23"
                           placeholder="0000 0000 0000 0000" required>
                </div>

                <div class="form-group">
                    <label for="mes">Vencimiento <span class="req">*</span></label>
                    <div class="form-inline">
                        <select name="mes" id="mes" class="form-control" required>
                            <option value="">MM</option>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>"><?= str_pad($m, 2, '0', STR_PAD_LEFT) ?></option>
                            <?php endfor; ?>
                        </select>
                        <select name="anio" id="anio" class="form-control" required>
                            <option value="">AAAA</option>
                            <?php for ($a = $anioAc; $a <= $anioAc + 10; $a++): ?>
                            <option value="<?= $a ?>"><?= $a ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="cvv">Código de seguridad <span class="req">*</span></label>
                    <input type="text" name="cvv" id="cvv" class="form-control"
                           inputmode="numeric" maxlength="4" placeholder="CVV" required>
                </div>
            </div>

            <div class="form-acciones">
                <button type="submit" class="btn btn-primario">Pagar <?= $money($pago['monto_total']) ?></button>
                <a href="<?= $URL ?>?accion=elegir&id_pago=<?= (int)$pago['id_pago'] ?>" class="btn btn-secundario">← Volver</a>
            </div>
            <p class="texto-ayuda mt-20">
                🔒 Pago simulado para pruebas. No se almacena el número completo ni el CVV.<br>
                Tarjeta de prueba aprobada: <code>4509 9535 6623 3704</code> ·
                rechazada: cualquiera terminada en <code>0002</code>.
            </p>
        </form>

        <?php // "Necesito más tiempo" va FUERA del formulario de la tarjeta:
              // es otra acción y no debe arrastrar los datos de la tarjeta
              // ni disparar su validación de campos requeridos. ?>
        <?php if ($restan > 0 && $restan <= 2 * Pago::MINUTOS_RESERVA * 60): ?>
        <form method="POST" action="<?= $URL ?>?accion=diferir">
            <?php csrf_field(); ?>
            <input type="hidden" name="id_pago" value="<?= (int)$pago['id_pago'] ?>">
            <button type="submit" class="btn-link-ver">
                Necesito más tiempo — guardame el turno 48 horas
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<script>
// Formateo simple del número de tarjeta (grupos de 4) y solo dígitos.
const numero = document.getElementById('numero');
numero.addEventListener('input', () => {
    let v = numero.value.replace(/\D/g, '').slice(0, 19);
    numero.value = v.replace(/(.{4})/g, '$1 ').trim();
});
document.getElementById('cvv').addEventListener('input', e => {
    e.target.value = e.target.value.replace(/\D/g, '').slice(0, 4);
});

// Cuenta regresiva de la retención del horario.
// El servidor ya imprimió el tiempo restante, así que sin JavaScript se
// ve igual: sólo deja de descontar. La cuenta es cosmética — quien
// decide si el plazo venció es el servidor, no este reloj.
(function () {
    const cuenta = document.getElementById('reloj-cuenta');
    if (!cuenta) return;

    let restan = parseInt(cuenta.dataset.restan, 10);
    const caja = document.getElementById('pago-reloj');

    const tic = setInterval(() => {
        restan--;
        if (restan <= 0) {
            clearInterval(tic);
            cuenta.textContent = '00:00';
            caja.classList.add('vencido');
            caja.querySelector('span').textContent =
                'Se venció la reserva del horario. Al intentar pagar te vamos a avisar si todavía está libre.';
            return;
        }
        const m = String(Math.floor(restan / 60)).padStart(2, '0');
        const s = String(restan % 60).padStart(2, '0');
        cuenta.textContent = m + ':' + s;
        if (restan <= 60) caja.classList.add('vencido');
    }, 1000);
})();
</script>

<?php require __DIR__ . '/../layouts/footer.php'; ?>

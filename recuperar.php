<?php
// recuperar.php — Paso 1 del flujo: pedir el enlace de recuperación
// -----------------------------------------------------------------
// Vive en la raíz junto a login.php y registro.php por el mismo motivo:
// es un punto de entrada público y sin sesión.
//
// DECISIÓN DE SEGURIDAD IMPORTANTE — enumeración de usuarios:
// la pantalla responde SIEMPRE lo mismo, exista o no el email. Si
// dijera "ese correo no está registrado", cualquiera podría averiguar
// qué direcciones tienen cuenta en la clínica (dato sensible en salud)
// probando emails uno por uno. El usuario legítimo recibe el mail; el
// que no tiene cuenta, no; y desde afuera ambos casos se ven idénticos.
// -----------------------------------------------------------------
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/includes/seguridad.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/sistema/modelos/Usuario.php';

iniciarSesionSegura();
cabecerasSeguridad();

if (!empty($_SESSION['id_usuario'])) {
    header('Location: ' . BASE_URL . 'dashboard.php');
    exit;
}

// Token CSRF propio de esta pantalla (auth.php no se incluye acá porque
// arrastra verificarSesion y este flujo es sin sesión iniciada).
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$modelo   = new Usuario($pdo);
$enviado  = false;
$error    = null;
$email    = trim($_POST['email'] ?? '');
$rutaMail = null;   // sólo en modo simulado, para poder abrir el correo

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $error = 'La sesión del formulario expiró. Recargá la página e intentá de nuevo.';

    } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Ingresá un correo electrónico válido.';

    } elseif (loginBloqueado($pdo, 'reset:' . $email)) {
        // Mismo límite que el login: evita que se use esta pantalla para
        // mandar cientos de correos a una víctima (mail bombing).
        $error = 'Ya pediste el enlace varias veces. Esperá '
               . LOGIN_VENTANA_MIN . ' minutos antes de volver a intentarlo.';

    } else {
        registrarIntentoLogin($pdo, 'reset:' . $email, false);

        $usuario = $modelo->buscarPorEmail($email);

        if ($usuario) {
            try {
                $token  = $modelo->crearTokenReset((int) $usuario['id_usuario'], ipCliente());
                $enlace = (isset($_SERVER['HTTP_HOST']) ? 'http://' . $_SERVER['HTTP_HOST'] : '')
                        . BASE_URL . 'restablecer.php?token=' . urlencode($token);

                $nombre = e($usuario['nombre']);
                $cuerpo = <<<HTML
<div style="font-family:'Segoe UI',system-ui,sans-serif;max-width:520px;margin:0 auto;color:#0f172a">
  <div style="background:linear-gradient(135deg,#1e3a8a,#2563eb);padding:28px;border-radius:14px 14px 0 0;color:#fff">
    <h1 style="margin:0;font-size:20px">MediTurnos</h1>
  </div>
  <div style="border:1px solid #e2e8f0;border-top:none;border-radius:0 0 14px 14px;padding:28px;background:#fff">
    <h2 style="margin:0 0 12px;font-size:18px">Hola, {$nombre}</h2>
    <p style="color:#475569;line-height:1.6;font-size:14px">
      Recibimos un pedido para restablecer la contraseña de tu cuenta.
      Hacé clic en el botón para elegir una nueva:
    </p>
    <p style="text-align:center;margin:26px 0">
      <a href="{$enlace}" style="background:#2563eb;color:#fff;text-decoration:none;
         padding:13px 28px;border-radius:10px;font-weight:600;display:inline-block">
        Crear una contraseña nueva
      </a>
    </p>
    <p style="color:#64748b;font-size:13px;line-height:1.6">
      El enlace vence en una hora y sólo puede usarse una vez.<br>
      Si no pediste esto, ignorá el mensaje: tu contraseña actual sigue funcionando.
    </p>
    <p style="color:#94a3b8;font-size:12px;word-break:break-all;border-top:1px solid #e2e8f0;padding-top:14px;margin-top:20px">
      Si el botón no funciona, copiá y pegá esta dirección:<br>{$enlace}
    </p>
  </div>
</div>
HTML;

                $mailer = obtenerMailer();
                $mailer->enviar($usuario['email'], 'Restablecé tu contraseña — MediTurnos', $cuerpo);

                // En modo simulado se muestra dónde quedó el correo, para
                // poder seguir el flujo sin un servidor de mail.
                if ($mailer instanceof MailerArchivo) {
                    $rutaMail = $mailer->ultimoDestino();
                }
            } catch (Throwable $ex) {
                error_log('recuperar.php: ' . $ex->getMessage());
                // Se sigue mostrando el mensaje neutro: no se le revela al
                // visitante que hubo un fallo interno con ESE email.
            }
        }

        $enviado = true;   // respuesta idéntica exista o no la cuenta
    }
}

$cssDir = __DIR__ . '/publico/css/';
$cssVer = fn($f) => BASE_URL . 'publico/css/' . $f
    . (is_file($cssDir . $f) ? '?v=' . filemtime($cssDir . $f) : '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar contraseña — MediTurnos</title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#2563eb">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $cssVer('estilos.css') ?>">
    <link rel="stylesheet" href="<?= $cssVer('auth.css') ?>">
</head>
<body>
<div class="auth">

    <aside class="auth-marca">
        <a href="<?= BASE_URL ?>" class="auth-logo">
            <span class="marca" aria-hidden="true">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 6v12M6 12h12"/></svg>
            </span>
            MediTurnos
        </a>
        <div class="auth-marca-cuerpo">
            <h1>Recuperá el acceso a tu cuenta</h1>
            <p>Te enviamos un enlace seguro a tu correo para que puedas elegir una contraseña nueva.</p>
            <ul class="auth-puntos">
                <li>
                    <span class="tic" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
                    <span><strong>Enlace de un solo uso</strong>Deja de servir apenas lo usás.</span>
                </li>
                <li>
                    <span class="tic" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
                    <span><strong>Vence en 1 hora</strong>Pasado ese plazo hay que pedir uno nuevo.</span>
                </li>
                <li>
                    <span class="tic" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
                    <span><strong>Tu clave actual sigue activa</strong>Hasta que elijas una nueva no cambia nada.</span>
                </li>
            </ul>
        </div>
        <p class="auth-marca-pie">© <?= date('Y') ?> MediTurnos</p>
    </aside>

    <main class="auth-panel">
        <div class="auth-card">

            <a href="<?= BASE_URL ?>" class="auth-logo auth-logo-movil" style="color:var(--gris-osc)">
                <span class="marca" aria-hidden="true" style="background:var(--azul);border-color:transparent;color:#fff">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 6v12M6 12h12"/></svg>
                </span>
                MediTurnos
            </a>

            <?php if ($enviado): ?>
                <!-- ══ Confirmación (misma respuesta exista o no la cuenta) ══ -->
                <div class="auth-card-head">
                    <h2>Revisá tu correo</h2>
                    <p>Si <strong><?= e($email) ?></strong> tiene una cuenta en MediTurnos,
                       te enviamos un enlace para crear una contraseña nueva.</p>
                </div>

                <div class="auth-alerta auth-alerta--exito" role="status">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                    <span>El enlace vence en <strong>1 hora</strong> y sólo se puede usar una vez.</span>
                </div>

                <?php if ($rutaMail): ?>
                <!-- Sólo aparece en modo simulado (sin servidor SMTP configurado) -->
                <?php
                    // realpath() resuelve el "includes/.." que quedaba a la
                    // vista y deja una ruta limpia; además se arma el enlace
                    // web para poder abrir el correo con un clic en vez de
                    // tener que buscarlo en el explorador de archivos.
                    $rutaLimpia = realpath($rutaMail) ?: $rutaMail;
                    $urlMail    = BASE_URL . 'almacenamiento/mails/' . rawurlencode(basename($rutaLimpia));
                ?>
                <div class="auth-alerta auth-alerta--aviso">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>
                    <span>
                        <strong>Modo desarrollo:</strong> todavía no configuraste un servidor de correo,
                        así que el mensaje se guardó como archivo en vez de enviarse.
                        <a href="<?= e($urlMail) ?>" target="_blank" rel="noopener"
                           style="color:#92400e;font-weight:700;text-decoration:underline">
                            Abrir el correo
                        </a>
                        para continuar, o
                        <a href="<?= BASE_URL ?>probar_mail.php"
                           style="color:#92400e;font-weight:700;text-decoration:underline">
                            configurar el envío real
                        </a>.
                    </span>
                </div>
                <?php endif; ?>

                <p class="campo-ayuda" style="margin-bottom:22px">
                    ¿No te llegó? Revisá la carpeta de spam o
                    <a href="<?= BASE_URL ?>recuperar.php" class="enlace-sutil">probá con otro correo</a>.
                </p>

                <a href="<?= BASE_URL ?>login.php" class="btn-auth" style="text-decoration:none">
                    <span class="btn-texto">Volver a iniciar sesión</span>
                </a>

            <?php else: ?>
                <!-- ══ Formulario ══ -->
                <div class="auth-card-head">
                    <h2>¿Olvidaste tu contraseña?</h2>
                    <p>Ingresá el correo con el que te registraste y te mandamos un enlace para recuperarla.</p>
                </div>

                <?php if ($error): ?>
                <div class="auth-alerta auth-alerta--error" role="alert">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v5M12 16h.01"/></svg>
                    <span><?= e($error) ?></span>
                </div>
                <?php endif; ?>

                <form method="POST" id="form-recuperar" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

                    <div class="campo" id="campo-email">
                        <label for="email">Correo electrónico <span class="req" aria-hidden="true">*</span></label>
                        <div class="campo-input">
                            <span class="ico-izq" aria-hidden="true">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 6 10-6"/></svg>
                            </span>
                            <input type="email" id="email" name="email"
                                   value="<?= e($email) ?>"
                                   placeholder="tu@email.com"
                                   autocomplete="email" required autofocus
                                   aria-describedby="err-email">
                        </div>
                        <p class="campo-error" id="err-email">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v5M12 16h.01"/></svg>
                            Ingresá un correo electrónico válido.
                        </p>
                    </div>

                    <button type="submit" class="btn-auth" id="btn-enviar" style="margin-top:6px">
                        <span class="spinner" aria-hidden="true"></span>
                        <span class="btn-texto">Enviarme el enlace</span>
                    </button>
                </form>

                <div class="auth-pie" style="margin-top:24px">
                    ¿Te acordaste? <a href="<?= BASE_URL ?>login.php">Volver a iniciar sesión</a>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<script>
(function () {
    'use strict';
    const form = document.getElementById('form-recuperar');
    if (!form) return;

    const inp   = document.getElementById('email');
    const campo = document.getElementById('campo-email');
    const btn   = document.getElementById('btn-enviar');
    const re    = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

    const valido = () => re.test(inp.value.trim());

    inp.addEventListener('blur', () => {
        campo.classList.toggle('invalido', inp.value.trim() !== '' && !valido());
        campo.classList.toggle('valido', valido());
    });
    inp.addEventListener('input', () => {
        if (valido()) campo.classList.remove('invalido');
    });

    form.addEventListener('submit', (ev) => {
        if (!valido()) {
            ev.preventDefault();
            campo.classList.add('invalido');
            inp.focus();
            return;
        }
        btn.classList.add('cargando');
        btn.disabled = true;
        btn.querySelector('.btn-texto').textContent = 'Enviando…';
    });
})();
</script>
</body>
</html>

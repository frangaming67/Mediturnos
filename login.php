<?php
// login.php
// -----------------------------------------------------------------
// Vive en la RAÍZ del proyecto (no dentro de sistema/) porque, junto con
// registro.php y logout.php, es un punto de entrada público: la URL debe
// ser corta y memorizable (mediturnos/login.php), no
// mediturnos/sistema/controladores/.... Es el único lugar donde todavía
// NO hay sesión, así que no puede pasar por dashboard.php ni por
// verificarSesion() (eso generaría un loop de redirects).
// La lógica de validar usuario/contraseña NO está acá: se delega a
// ControladorAuth::login() para no mezclar HTML con reglas de negocio.
//
// SEGURIDAD (v2): antes de validar credenciales se consulta el registro
// de intentos. Tras LOGIN_MAX_INTENTOS fallos desde la misma IP para el
// mismo identificador, la cuenta queda bloqueada temporalmente: sin eso,
// un script podía probar contraseñas de forma ilimitada.
// -----------------------------------------------------------------
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/includes/seguridad.php';
require_once __DIR__ . '/sistema/controladores/ControladorAuth.php';

iniciarSesionSegura();   // cookie HttpOnly + SameSite (reemplaza session_start)
cabecerasSeguridad();    // nosniff, anti-clickjacking, CSP

// Si ya hay sesión activa se redirige al dashboard
// (evita que alguien logueado vuelva a ver el formulario de login)
if (!empty($_SESSION['id_usuario'])) {
    header('Location: ' . BASE_URL . 'dashboard.php');
    exit;
}

$ctrl        = new ControladorAuth($pdo);
$error       = null;
$aviso       = null;
$identificador = trim($_POST['usuario'] ?? '');

// El patrón "si es POST, procesar; siempre, dibujar el formulario" es el
// mismo que usan los controladores de ABM: permite mostrar el error en la
// MISMA página sin perder lo que el usuario ya escribió.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass = trim($_POST['contrasenia'] ?? '');

    if ($identificador === '' || $pass === '') {
        $error = 'Completá tu usuario y tu contraseña.';

    } elseif (loginBloqueado($pdo, $identificador)) {
        // Mensaje deliberadamente genérico: no confirma si la cuenta
        // existe, sólo que hay demasiados intentos desde acá.
        $error = 'Demasiados intentos fallidos. Esperá '
               . LOGIN_VENTANA_MIN . ' minutos antes de volver a probar, '
               . 'o restablecé tu contraseña.';

    } else {
        // login() redirige y corta si las credenciales son correctas;
        // si vuelve, devolvió un texto de error.
        $error = $ctrl->login($identificador, $pass);

        registrarIntentoLogin($pdo, $identificador, $error === null);

        if ($error !== null) {
            // Se avisa recién sobre el final para no darle pistas a un
            // atacante en cada fallo, pero sí evitarle la sorpresa del
            // bloqueo a alguien que simplemente olvidó su contraseña.
            $restantes = intentosRestantes($pdo, $identificador);
            if ($restantes > 0 && $restantes <= 2) {
                $aviso = $restantes === 1
                    ? 'Te queda 1 intento antes de que se bloquee el acceso temporalmente.'
                    : "Te quedan {$restantes} intentos antes de que se bloquee el acceso temporalmente.";
            }
        }
    }
}

// Mensajes que llegan por redirección desde otras pantallas
$msgLogout   = ($_GET['msg'] ?? '') === 'logout_ok';
$msgExpired  = ($_GET['exp'] ?? '') === '1';
$msgRegistro = ($_GET['msg'] ?? '') === 'registro_ok';
$msgReset    = ($_GET['msg'] ?? '') === 'reset_ok';

$cssDir = __DIR__ . '/publico/css/';
$cssVer = fn($f) => BASE_URL . 'publico/css/' . $f
    . (is_file($cssDir . $f) ? '?v=' . filemtime($cssDir . $f) : '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar sesión — MediTurnos</title>
    <meta name="description" content="Ingresá a tu cuenta de MediTurnos para gestionar tus turnos médicos.">
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

    <!-- ══════════ Columna de marca (oculta en móvil) ══════════ -->
    <aside class="auth-marca">
        <a href="<?= BASE_URL ?>" class="auth-logo">
            <span class="marca" aria-hidden="true">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 6v12M6 12h12"/></svg>
            </span>
            MediTurnos
        </a>

        <div class="auth-marca-cuerpo">
            <h1>Tu salud, organizada en un solo lugar</h1>
            <p>Gestioná tus turnos, consultá tus pagos y llevá el control de tu historial médico.</p>

            <ul class="auth-puntos">
                <li>
                    <span class="tic" aria-hidden="true">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                    </span>
                    <span><strong>Turnos en tiempo real</strong>Ves la disponibilidad real de cada profesional.</span>
                </li>
                <li>
                    <span class="tic" aria-hidden="true">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                    </span>
                    <span><strong>Tus datos protegidos</strong>Cada paciente accede únicamente a su información.</span>
                </li>
                <li>
                    <span class="tic" aria-hidden="true">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                    </span>
                    <span><strong>Cobertura aplicada</strong>El descuento de tu obra social se calcula solo.</span>
                </li>
            </ul>
        </div>

        <p class="auth-marca-pie">© <?= date('Y') ?> MediTurnos · Sistema de gestión de turnos médicos</p>
    </aside>

    <!-- ══════════ Columna del formulario ══════════ -->
    <main class="auth-panel">
        <div class="auth-card">

            <a href="<?= BASE_URL ?>" class="auth-logo auth-logo-movil" style="color:var(--gris-osc)">
                <span class="marca" aria-hidden="true" style="background:var(--azul);border-color:transparent;color:#fff">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 6v12M6 12h12"/></svg>
                </span>
                MediTurnos
            </a>

            <div class="auth-card-head">
                <h2>Iniciar sesión</h2>
                <p>Ingresá con tu usuario o tu correo electrónico.</p>
            </div>

            <?php if ($msgRegistro): ?>
            <div class="auth-alerta auth-alerta--exito" role="status">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                <span>Tu cuenta se creó correctamente. Ya podés iniciar sesión.</span>
            </div>
            <?php endif; ?>

            <?php if ($msgReset): ?>
            <div class="auth-alerta auth-alerta--exito" role="status">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                <span>Tu contraseña se actualizó. Ingresá con la nueva.</span>
            </div>
            <?php endif; ?>

            <?php if ($msgLogout): ?>
            <div class="auth-alerta auth-alerta--info" role="status">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
                <span>Cerraste sesión correctamente.</span>
            </div>
            <?php endif; ?>

            <?php if ($msgExpired): ?>
            <div class="auth-alerta auth-alerta--aviso" role="status">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                <span>Tu sesión expiró por inactividad. Volvé a ingresar.</span>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <!-- role="alert" hace que el lector de pantalla lo anuncie apenas aparece -->
            <div class="auth-alerta auth-alerta--error" role="alert">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v5M12 16h.01"/></svg>
                <span><?= e($error) ?></span>
            </div>
            <?php endif; ?>

            <?php if ($aviso): ?>
            <div class="auth-alerta auth-alerta--aviso" role="status">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>
                <span><?= e($aviso) ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" id="form-login" novalidate>

                <div class="campo" id="campo-usuario">
                    <label for="usuario">Usuario o correo electrónico <span class="req" aria-hidden="true">*</span></label>
                    <div class="campo-input">
                        <span class="ico-izq" aria-hidden="true">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        </span>
                        <input type="text" id="usuario" name="usuario"
                               value="<?= e($identificador) ?>"
                               placeholder="tu usuario o tu@email.com"
                               autocomplete="username"
                               required autofocus
                               aria-describedby="err-usuario">
                    </div>
                    <p class="campo-error" id="err-usuario">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v5M12 16h.01"/></svg>
                        Ingresá tu usuario o tu correo electrónico.
                    </p>
                </div>

                <div class="campo" id="campo-pass">
                    <label for="contrasenia">Contraseña <span class="req" aria-hidden="true">*</span></label>
                    <div class="campo-input con-boton">
                        <span class="ico-izq" aria-hidden="true">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        </span>
                        <input type="password" id="contrasenia" name="contrasenia"
                               placeholder="Tu contraseña"
                               autocomplete="current-password"
                               required
                               aria-describedby="err-pass caps-aviso">
                        <!-- Ver/ocultar: aria-pressed comunica el estado al lector de pantalla -->
                        <button type="button" class="btn-ojo" id="btn-ojo"
                                aria-label="Mostrar contraseña" aria-pressed="false">
                            <svg id="ojo-abierto" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>
                            </svg>
                            <svg id="ojo-cerrado" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none" aria-hidden="true">
                                <path d="M17.9 17.9A10.4 10.4 0 0 1 12 19c-6.5 0-10-7-10-7a18.4 18.4 0 0 1 5.1-5.9M9.9 4.2A9.1 9.1 0 0 1 12 4c6.5 0 10 7 10 7a18.5 18.5 0 0 1-2.2 3.2M1 1l22 22"/>
                            </svg>
                        </button>
                    </div>

                    <p class="campo-error" id="err-pass">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v5M12 16h.01"/></svg>
                        Ingresá tu contraseña.
                    </p>

                    <!-- Aviso de Bloq Mayús: la causa nº1 de "mi contraseña no anda" -->
                    <p class="caps" id="caps-aviso" role="status">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 4 8 8h-5v5H9v-5H4z"/><path d="M9 21h6"/></svg>
                        Bloq Mayús está activado
                    </p>
                </div>

                <div class="auth-fila">
                    <label class="check">
                        <!-- "Recordarme" precarga el usuario en este dispositivo.
                             NO extiende la sesión del servidor: eso debilitaría
                             el cierre por inactividad de 30 min. -->
                        <input type="checkbox" name="recordarme" id="recordarme" value="1">
                        Recordar mi usuario
                    </label>
                    <a href="<?= BASE_URL ?>recuperar.php" class="enlace-sutil">¿Olvidaste tu contraseña?</a>
                </div>

                <button type="submit" class="btn-auth" id="btn-entrar">
                    <span class="spinner" aria-hidden="true"></span>
                    <span class="btn-texto">Ingresar</span>
                </button>
            </form>

            <div class="auth-separador">¿Todavía no tenés cuenta?</div>

            <div class="auth-pie">
                <a href="<?= BASE_URL ?>registro.php">Creá tu cuenta de paciente</a>
                — es gratis y te lleva un minuto.
            </div>
        </div>
    </main>
</div>

<script>
(function () {
    'use strict';

    const form   = document.getElementById('form-login');
    const inpUsr = document.getElementById('usuario');
    const inpPwd = document.getElementById('contrasenia');
    const btnOjo = document.getElementById('btn-ojo');
    const caps   = document.getElementById('caps-aviso');
    const btn    = document.getElementById('btn-entrar');
    const check  = document.getElementById('recordarme');

    const CLAVE_RECORDADO = 'mediturnos_usuario';

    // ── Ver / ocultar contraseña ─────────────────────────────
    btnOjo.addEventListener('click', () => {
        const oculto = inpPwd.type === 'password';
        inpPwd.type = oculto ? 'text' : 'password';
        btnOjo.setAttribute('aria-pressed', String(oculto));
        btnOjo.setAttribute('aria-label', oculto ? 'Ocultar contraseña' : 'Mostrar contraseña');
        document.getElementById('ojo-abierto').style.display = oculto ? 'none' : '';
        document.getElementById('ojo-cerrado').style.display = oculto ? '' : 'none';
        inpPwd.focus();
    });

    // ── Bloq Mayús ───────────────────────────────────────────
    // getModifierState no existe en algunos navegadores viejos: se
    // consulta con guarda para no romper el login si falta.
    function chequearCaps(ev) {
        if (typeof ev.getModifierState !== 'function') return;
        caps.classList.toggle('visible', ev.getModifierState('CapsLock'));
    }
    inpPwd.addEventListener('keyup', chequearCaps);
    inpPwd.addEventListener('keydown', chequearCaps);
    inpPwd.addEventListener('blur', () => caps.classList.remove('visible'));

    // ── Validación visual en el cliente ──────────────────────
    // Es SÓLO ayuda visual: la validación real la hace el servidor.
    function validarCampo(input, contenedorId) {
        const campo = document.getElementById(contenedorId);
        const ok = input.value.trim() !== '';
        campo.classList.toggle('invalido', !ok);
        campo.classList.toggle('valido', ok);
        return ok;
    }
    inpUsr.addEventListener('blur', () => validarCampo(inpUsr, 'campo-usuario'));
    inpPwd.addEventListener('blur', () => validarCampo(inpPwd, 'campo-pass'));
    // Al corregir, el error desaparece al instante (feedback inmediato)
    inpUsr.addEventListener('input', () => {
        if (inpUsr.value.trim()) document.getElementById('campo-usuario').classList.remove('invalido');
    });
    inpPwd.addEventListener('input', () => {
        if (inpPwd.value.trim()) document.getElementById('campo-pass').classList.remove('invalido');
    });

    // ── Envío: validar, recordar usuario y mostrar el loader ──
    form.addEventListener('submit', (ev) => {
        const okUsr = validarCampo(inpUsr, 'campo-usuario');
        const okPwd = validarCampo(inpPwd, 'campo-pass');

        if (!okUsr || !okPwd) {
            ev.preventDefault();
            (!okUsr ? inpUsr : inpPwd).focus();
            return;
        }

        try {
            if (check.checked) localStorage.setItem(CLAVE_RECORDADO, inpUsr.value.trim());
            else               localStorage.removeItem(CLAVE_RECORDADO);
        } catch (e) { /* modo privado: no es crítico */ }

        // Loader + disabled: da feedback y evita el doble envío.
        btn.classList.add('cargando');
        btn.disabled = true;
        btn.querySelector('.btn-texto').textContent = 'Ingresando…';
    });

    // ── Precargar el usuario recordado ───────────────────────
    try {
        const guardado = localStorage.getItem(CLAVE_RECORDADO);
        if (guardado && !inpUsr.value) {
            inpUsr.value = guardado;
            check.checked = true;
            inpPwd.focus();          // el usuario ya está: foco en la contraseña
        }
    } catch (e) { /* localStorage no disponible */ }
})();
</script>
</body>
</html>

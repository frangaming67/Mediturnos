<?php
// restablecer.php — Paso 2 del flujo: elegir la contraseña nueva
// -----------------------------------------------------------------
// Se llega acá desde el enlace del correo, con ?token=...
//
// El token se valida DOS veces: al mostrar el formulario (para no
// pedirle la contraseña a alguien cuyo enlace ya venció) y otra vez al
// procesar el POST. La segunda es la que importa: entre que se dibuja
// la pantalla y se envía el formulario pueden pasar minutos, y el
// enlace podría haberse usado desde otro lado.
// -----------------------------------------------------------------
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/includes/seguridad.php';
require_once __DIR__ . '/includes/validacion.php';
require_once __DIR__ . '/sistema/modelos/Usuario.php';

iniciarSesionSegura();
cabecerasSeguridad();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$modelo = new Usuario($pdo);
$token  = trim($_GET['token'] ?? $_POST['token'] ?? '');
$error  = null;

// Las reglas de contraseña son las MISMAS que las del registro y las
// del cambio desde el perfil, así que viven en includes/validacion.php.
// Antes esta pantalla tenía su propia copia y su propia constante: si
// alguien hubiera subido el mínimo allá, acá habría seguido aceptando
// contraseñas de 8 sin que nada fallara.
// La constante se conserva con este nombre porque el HTML y el
// JavaScript de más abajo ya la imprimen así.
const PASS_MIN = Validacion::PASS_MIN;

// ── Validación inicial del token ─────────────────────────────
$reset = $token !== '' ? $modelo->validarTokenReset($token) : false;

// ── Procesar el cambio ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $error = 'La sesión del formulario expiró. Volvé a abrir el enlace del correo.';

    } elseif (!$reset) {
        $error = 'El enlace ya no es válido.';

    } else {
        $p1 = (string) ($_POST['password'] ?? '');
        $p2 = (string) ($_POST['password2'] ?? '');
        $error = Validacion::password($p1, $p2);

        if ($error === null) {
            $ok = $modelo->restablecerPassword(
                (int) $reset['id_reset'],
                (int) $reset['id_usuario'],
                $p1
            );
            if ($ok) {
                // Se limpia la sesión: si había una a medias, no debe
                // sobrevivir a un cambio de contraseña.
                session_regenerate_id(true);
                header('Location: ' . BASE_URL . 'login.php?msg=reset_ok');
                exit;
            }
            $error = 'No pudimos actualizar la contraseña. Intentá de nuevo.';
        }
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
    <title>Nueva contraseña — MediTurnos</title>
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
            <h1>Elegí tu nueva contraseña</h1>
            <p>Usá una combinación que no repitas en otros sitios. Es la llave de tu historial médico.</p>
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

            <?php if (!$reset): ?>
                <!-- ══ Token inválido / vencido / ya usado ══ -->
                <div class="auth-card-head">
                    <h2>Este enlace ya no sirve</h2>
                    <p>Puede que haya vencido (duran 1 hora), que ya lo hayas usado, o que el enlace esté incompleto.</p>
                </div>

                <div class="auth-alerta auth-alerta--error" role="alert">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v5M12 16h.01"/></svg>
                    <span>Por seguridad, cada enlace se puede usar una sola vez.</span>
                </div>

                <a href="<?= BASE_URL ?>recuperar.php" class="btn-auth" style="text-decoration:none">
                    <span class="btn-texto">Pedir un enlace nuevo</span>
                </a>

                <div class="auth-pie" style="margin-top:22px">
                    <a href="<?= BASE_URL ?>login.php">Volver a iniciar sesión</a>
                </div>

            <?php else: ?>
                <!-- ══ Formulario de nueva contraseña ══ -->
                <div class="auth-card-head">
                    <h2>Nueva contraseña</h2>
                    <p>Hola <strong><?= e($reset['nombre']) ?></strong>, elegí la contraseña con la que vas a entrar de ahora en más.</p>
                </div>

                <?php if ($error): ?>
                <div class="auth-alerta auth-alerta--error" role="alert">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v5M12 16h.01"/></svg>
                    <span><?= e($error) ?></span>
                </div>
                <?php endif; ?>

                <form method="POST" id="form-reset" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="token" value="<?= e($token) ?>">

                    <div class="campo" id="campo-p1">
                        <label for="password">Contraseña nueva <span class="req" aria-hidden="true">*</span></label>
                        <div class="campo-input con-boton">
                            <span class="ico-izq" aria-hidden="true">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            </span>
                            <input type="password" id="password" name="password"
                                   placeholder="Mínimo <?= PASS_MIN ?> caracteres"
                                   autocomplete="new-password" required
                                   aria-describedby="requisitos">
                            <button type="button" class="btn-ojo" id="btn-ojo" aria-label="Mostrar contraseña" aria-pressed="false">
                                <svg id="ojo-abierto" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg id="ojo-cerrado" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none" aria-hidden="true"><path d="M17.9 17.9A10.4 10.4 0 0 1 12 19c-6.5 0-10-7-10-7a18.4 18.4 0 0 1 5.1-5.9M9.9 4.2A9.1 9.1 0 0 1 12 4c6.5 0 10 7 10 7a18.5 18.5 0 0 1-2.2 3.2M1 1l22 22"/></svg>
                            </button>
                        </div>

                        <!-- Medidor de fortaleza -->
                        <div class="fuerza" id="fuerza" hidden>
                            <div class="fuerza-barra"><span id="fuerza-relleno"></span></div>
                            <span class="fuerza-texto" id="fuerza-texto"></span>
                        </div>

                        <!-- Requisitos: se marcan solos a medida que se cumplen -->
                        <ul class="requisitos" id="requisitos">
                            <li data-regla="largo"><span class="marca" aria-hidden="true">○</span> Al menos <?= PASS_MIN ?> caracteres</li>
                            <li data-regla="letra"><span class="marca" aria-hidden="true">○</span> Al menos una letra</li>
                            <li data-regla="numero"><span class="marca" aria-hidden="true">○</span> Al menos un número</li>
                        </ul>
                    </div>

                    <div class="campo" id="campo-p2">
                        <label for="password2">Repetir contraseña <span class="req" aria-hidden="true">*</span></label>
                        <div class="campo-input">
                            <span class="ico-izq" aria-hidden="true">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            </span>
                            <input type="password" id="password2" name="password2"
                                   placeholder="Repetí la contraseña"
                                   autocomplete="new-password" required
                                   aria-describedby="err-p2">
                        </div>
                        <p class="campo-error" id="err-p2">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v5M12 16h.01"/></svg>
                            Las contraseñas no coinciden.
                        </p>
                    </div>

                    <button type="submit" class="btn-auth" id="btn-guardar">
                        <span class="spinner" aria-hidden="true"></span>
                        <span class="btn-texto">Guardar contraseña</span>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </main>
</div>

<script>
(function () {
    'use strict';
    const form = document.getElementById('form-reset');
    if (!form) return;

    const p1 = document.getElementById('password');
    const p2 = document.getElementById('password2');
    const ojo = document.getElementById('btn-ojo');
    const btn = document.getElementById('btn-guardar');
    const MIN = <?= PASS_MIN ?>;

    // ── Ver / ocultar ────────────────────────────────────────
    ojo.addEventListener('click', () => {
        const oculto = p1.type === 'password';
        p1.type = oculto ? 'text' : 'password';
        ojo.setAttribute('aria-pressed', String(oculto));
        ojo.setAttribute('aria-label', oculto ? 'Ocultar contraseña' : 'Mostrar contraseña');
        document.getElementById('ojo-abierto').style.display = oculto ? 'none' : '';
        document.getElementById('ojo-cerrado').style.display = oculto ? '' : 'none';
        p1.focus();
    });

    // ── Requisitos + fortaleza ───────────────────────────────
    const reglas = {
        largo:  v => v.length >= MIN,
        letra:  v => /[A-Za-z]/.test(v),
        numero: v => /\d/.test(v)
    };

    function evaluar() {
        const v = p1.value;
        let cumplidas = 0;

        document.querySelectorAll('#requisitos li').forEach(li => {
            const ok = reglas[li.dataset.regla](v);
            li.classList.toggle('ok', ok);
            li.querySelector('.marca').textContent = ok ? '●' : '○';
            if (ok) cumplidas++;
        });

        // La fortaleza suma los requisitos y premia longitud y variedad:
        // es una guía para el usuario, no la validación (esa es del servidor).
        let puntos = cumplidas;
        if (v.length >= 12) puntos++;
        if (/[^A-Za-z0-9]/.test(v)) puntos++;

        const caja = document.getElementById('fuerza');
        caja.hidden = v.length === 0;

        const niveles = [
            { max: 2, txt: 'Débil',      clase: 'debil',   pct: 33 },
            { max: 4, txt: 'Aceptable',  clase: 'media',   pct: 66 },
            { max: 9, txt: 'Fuerte',     clase: 'fuerte',  pct: 100 }
        ];
        const n = niveles.find(x => puntos <= x.max) || niveles[2];
        const relleno = document.getElementById('fuerza-relleno');
        relleno.style.width = n.pct + '%';
        relleno.className = n.clase;
        document.getElementById('fuerza-texto').textContent = n.txt;

        return cumplidas === 3;
    }

    function coinciden() {
        const ok = p2.value !== '' && p1.value === p2.value;
        document.getElementById('campo-p2').classList.toggle('invalido', p2.value !== '' && !ok);
        document.getElementById('campo-p2').classList.toggle('valido', ok);
        return ok;
    }

    p1.addEventListener('input', () => { evaluar(); if (p2.value) coinciden(); });
    p2.addEventListener('input', coinciden);

    form.addEventListener('submit', (ev) => {
        const okPass = evaluar();
        const okRep  = coinciden();
        if (!okPass || !okRep) {
            ev.preventDefault();
            (!okPass ? p1 : p2).focus();
            if (!okRep) document.getElementById('campo-p2').classList.add('invalido');
            return;
        }
        btn.classList.add('cargando');
        btn.disabled = true;
        btn.querySelector('.btn-texto').textContent = 'Guardando…';
    });
})();
</script>
</body>
</html>

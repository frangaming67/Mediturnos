<?php
// registro.php — Alta pública de pacientes
// -----------------------------------------------------------------
// Punto de entrada público (raíz del proyecto, como login.php).
//
// DISEÑO: son 12 campos. Presentarlos todos juntos en una sola pantalla
// espanta a cualquiera, así que se agrupan en 3 pasos:
//     1. Datos personales · 2. Cuenta · 3. Cobertura y foto
// Es UN SOLO formulario: los pasos se muestran/ocultan con JS y se envía
// una única vez. Así, si el JavaScript falla, el formulario sigue siendo
// utilizable (todos los pasos quedan visibles) en vez de dejar al usuario
// atrapado en el paso 1. Mejora progresiva, igual que en la landing.
//
// La VALIDACIÓN real vive en ControladorAuth::registrar(): lo de acá es
// ayuda visual. La foto se procesa con SubidaImagen, que re-codifica el
// archivo (ver includes/subida_imagen.php).
// -----------------------------------------------------------------
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/includes/seguridad.php';
require_once __DIR__ . '/includes/subida_imagen.php';
require_once __DIR__ . '/sistema/controladores/ControladorAuth.php';

iniciarSesionSegura();
cabecerasSeguridad();

if (!empty($_SESSION['id_usuario'])) {
    header('Location: ' . BASE_URL . 'dashboard.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$ctrl   = new ControladorAuth($pdo);
$modelo = new Usuario($pdo);
$error  = null;

$planes = $modelo->listarPlanesObraSocial();

// Datos enviados, para no perderlos si hay un error de validación.
$d = fn(string $k) => trim($_POST[$k] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $error = 'La sesión del formulario expiró. Recargá la página e intentá de nuevo.';
    } else {
        // La foto se procesa ANTES de crear la cuenta: si el archivo es
        // inválido conviene avisar sin haber tocado la base todavía.
        $foto   = null;
        $subida = new SubidaImagen();
        if (!empty($_FILES['foto']['name'])) {
            $foto = $subida->procesar($_FILES['foto']);
            if ($foto === null && $subida->error()) {
                $error = $subida->error();
            }
        }

        if ($error === null) {
            $error = $ctrl->registrar([
                'nombre'       => $d('nombre'),
                'apellido'     => $d('apellido'),
                'dni'          => $d('dni'),
                'fecha_nac'    => $d('fecha_nac'),
                'sexo'         => $d('sexo'),
                'telefono'     => $d('telefono'),
                'direccion'    => $d('direccion'),
                'email'        => $d('email'),
                'usuario'      => $d('usuario'),
                'contrasenia'  => (string) ($_POST['contrasenia']  ?? ''),
                'contrasenia2' => (string) ($_POST['contrasenia2'] ?? ''),
                'id_plan'      => $d('id_plan'),
                'nro_afiliado' => $d('nro_afiliado'),
                'foto'         => $foto,
            ]);

            // Si el alta falló pero la foto ya se había guardado, se borra:
            // si no, quedaría un archivo huérfano ocupando lugar para siempre.
            if ($error !== null && $foto !== null) {
                $subida->eliminar($foto);
            }
        }
    }
}

$cssDir = __DIR__ . '/publico/css/';
$cssVer = fn($f) => BASE_URL . 'publico/css/' . $f
    . (is_file($cssDir . $f) ? '?v=' . filemtime($cssDir . $f) : '');

$PASS_MIN = ControladorAuth::PASS_MIN;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear cuenta — MediTurnos</title>
    <meta name="description" content="Creá tu cuenta de paciente en MediTurnos y reservá turnos médicos online.">
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

    <!-- ══════════ Columna de marca ══════════ -->
    <aside class="auth-marca">
        <a href="<?= BASE_URL ?>" class="auth-logo">
            <span class="marca" aria-hidden="true">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 6v12M6 12h12"/></svg>
            </span>
            MediTurnos
        </a>
        <div class="auth-marca-cuerpo">
            <h1>Creá tu cuenta y reservá en minutos</h1>
            <p>Una sola vez completás tus datos y después sacás turno con cualquier profesional en dos clics.</p>
            <ul class="auth-puntos">
                <li>
                    <span class="tic" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
                    <span><strong>Es gratis</strong>No se cobra nada por tener cuenta.</span>
                </li>
                <li>
                    <span class="tic" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
                    <span><strong>Tu cobertura aplicada</strong>Cargás la obra social una vez y el descuento sale solo.</span>
                </li>
                <li>
                    <span class="tic" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
                    <span><strong>Historial en un solo lugar</strong>Todos tus turnos y pagos, siempre a mano.</span>
                </li>
            </ul>
        </div>
        <p class="auth-marca-pie">© <?= date('Y') ?> MediTurnos</p>
    </aside>

    <!-- ══════════ Formulario ══════════ -->
    <main class="auth-panel">
        <div class="auth-card auth-card--ancha">

            <a href="<?= BASE_URL ?>" class="auth-logo auth-logo-movil" style="color:var(--gris-osc)">
                <span class="marca" aria-hidden="true" style="background:var(--azul);border-color:transparent;color:#fff">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 6v12M6 12h12"/></svg>
                </span>
                MediTurnos
            </a>

            <div class="auth-card-head">
                <h2>Crear cuenta</h2>
                <p>Completá tus datos para empezar a reservar turnos.</p>
            </div>

            <!-- Indicador de progreso -->
            <ol class="pasos-barra" id="pasos-barra">
                <li class="paso-item activo" data-paso="1">
                    <span class="paso-num">1</span><span class="paso-nombre">Tus datos</span>
                </li>
                <li class="paso-item" data-paso="2">
                    <span class="paso-num">2</span><span class="paso-nombre">Tu cuenta</span>
                </li>
                <li class="paso-item" data-paso="3">
                    <span class="paso-num">3</span><span class="paso-nombre">Cobertura</span>
                </li>
            </ol>

            <?php if ($error): ?>
            <div class="auth-alerta auth-alerta--error" role="alert">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v5M12 16h.01"/></svg>
                <span><?= e($error) ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" id="form-registro" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

                <!-- ════════ PASO 1 · DATOS PERSONALES ════════ -->
                <section class="paso" data-paso="1">
                    <div class="grid-2">
                        <div class="campo">
                            <label for="nombre">Nombre <span class="req" aria-hidden="true">*</span></label>
                            <div class="campo-input">
                                <span class="ico-izq" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
                                <input type="text" id="nombre" name="nombre" value="<?= e($d('nombre')) ?>"
                                       placeholder="Juan" autocomplete="given-name" maxlength="30" required data-reglas="requerido">
                            </div>
                            <p class="campo-error">Ingresá tu nombre.</p>
                        </div>

                        <div class="campo">
                            <label for="apellido">Apellido <span class="req" aria-hidden="true">*</span></label>
                            <div class="campo-input">
                                <span class="ico-izq" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
                                <input type="text" id="apellido" name="apellido" value="<?= e($d('apellido')) ?>"
                                       placeholder="Pérez" autocomplete="family-name" maxlength="30" required data-reglas="requerido">
                            </div>
                            <p class="campo-error">Ingresá tu apellido.</p>
                        </div>

                        <div class="campo">
                            <label for="dni">DNI <span class="req" aria-hidden="true">*</span></label>
                            <div class="campo-input">
                                <span class="ico-izq" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><circle cx="9" cy="12" r="2.5"/><path d="M14 10h4M14 14h4"/></svg></span>
                                <input type="text" id="dni" name="dni" value="<?= e($d('dni')) ?>"
                                       placeholder="30111222" inputmode="numeric" maxlength="9" required data-reglas="dni">
                            </div>
                            <p class="campo-error">Entre 7 y 9 dígitos, sin puntos.</p>
                        </div>

                        <div class="campo">
                            <label for="fecha_nac">Fecha de nacimiento</label>
                            <div class="campo-input">
                                <span class="ico-izq" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></span>
                                <input type="date" id="fecha_nac" name="fecha_nac" value="<?= e($d('fecha_nac')) ?>"
                                       max="<?= date('Y-m-d') ?>" min="1900-01-01" autocomplete="bday">
                            </div>
                            <p class="campo-error">Revisá la fecha.</p>
                        </div>

                        <div class="campo">
                            <label for="sexo">Sexo</label>
                            <div class="campo-input">
                                <span class="ico-izq" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="9" r="5"/><path d="M12 14v7M9 18h6"/></svg></span>
                                <select id="sexo" name="sexo" class="select-limpio">
                                    <option value="">Prefiero no indicarlo</option>
                                    <option value="F" <?= $d('sexo') === 'F' ? 'selected' : '' ?>>Femenino</option>
                                    <option value="M" <?= $d('sexo') === 'M' ? 'selected' : '' ?>>Masculino</option>
                                    <option value="X" <?= $d('sexo') === 'X' ? 'selected' : '' ?>>X / No binario</option>
                                </select>
                            </div>
                        </div>

                        <div class="campo">
                            <label for="telefono">Teléfono <span class="req" aria-hidden="true">*</span></label>
                            <div class="campo-input">
                                <span class="ico-izq" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2 4.2 2 2 0 0 1 4 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.1a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/></svg></span>
                                <input type="tel" id="telefono" name="telefono" value="<?= e($d('telefono')) ?>"
                                       placeholder="11 4444 1111" autocomplete="tel" maxlength="30" required data-reglas="telefono">
                            </div>
                            <p class="campo-error">Entre 8 y 15 dígitos.</p>
                        </div>

                        <div class="campo col-2">
                            <label for="email">Correo electrónico <span class="req" aria-hidden="true">*</span></label>
                            <div class="campo-input">
                                <span class="ico-izq" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 6 10-6"/></svg></span>
                                <input type="email" id="email" name="email" value="<?= e($d('email')) ?>"
                                       placeholder="juan@email.com" autocomplete="email" maxlength="120" required data-reglas="email">
                            </div>
                            <p class="campo-error">Ingresá un correo válido.</p>
                            <p class="campo-ayuda">Lo usamos para confirmarte los turnos y para recuperar tu cuenta.</p>
                        </div>

                        <div class="campo col-2">
                            <label for="direccion">Dirección</label>
                            <div class="campo-input">
                                <span class="ico-izq" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-5.6-7-11a7 7 0 0 1 14 0c0 5.4-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg></span>
                                <input type="text" id="direccion" name="direccion" value="<?= e($d('direccion')) ?>"
                                       placeholder="Av. Siempre Viva 1234, CABA" autocomplete="street-address" maxlength="150">
                            </div>
                        </div>
                    </div>

                    <div class="paso-acciones">
                        <span></span>
                        <button type="button" class="btn-auth btn-siguiente" data-ir="2" style="width:auto;padding:13px 28px">
                            Continuar →
                        </button>
                    </div>
                </section>

                <!-- ════════ PASO 2 · CUENTA ════════ -->
                <section class="paso" data-paso="2" hidden>
                    <div class="campo">
                        <label for="usuario">Nombre de usuario <span class="req" aria-hidden="true">*</span></label>
                        <div class="campo-input">
                            <span class="ico-izq" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
                            <input type="text" id="usuario" name="usuario" value="<?= e($d('usuario')) ?>"
                                   placeholder="juanperez" autocomplete="username" maxlength="40" required data-reglas="usuario">
                        </div>
                        <p class="campo-error">4 a 40 caracteres: letras, números, punto, guion o guion bajo.</p>
                        <p class="campo-ayuda">Vas a poder entrar con este usuario o con tu correo, como prefieras.</p>
                    </div>

                    <div class="campo">
                        <label for="contrasenia">Contraseña <span class="req" aria-hidden="true">*</span></label>
                        <div class="campo-input con-boton">
                            <span class="ico-izq" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span>
                            <input type="password" id="contrasenia" name="contrasenia"
                                   placeholder="Mínimo <?= $PASS_MIN ?> caracteres" autocomplete="new-password" required>
                            <button type="button" class="btn-ojo" id="btn-ojo" aria-label="Mostrar contraseña" aria-pressed="false">
                                <svg id="ojo-abierto" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg id="ojo-cerrado" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none" aria-hidden="true"><path d="M17.9 17.9A10.4 10.4 0 0 1 12 19c-6.5 0-10-7-10-7a18.4 18.4 0 0 1 5.1-5.9M9.9 4.2A9.1 9.1 0 0 1 12 4c6.5 0 10 7 10 7a18.5 18.5 0 0 1-2.2 3.2M1 1l22 22"/></svg>
                            </button>
                        </div>

                        <div class="fuerza" id="fuerza" hidden>
                            <div class="fuerza-barra"><span id="fuerza-relleno"></span></div>
                            <span class="fuerza-texto" id="fuerza-texto"></span>
                        </div>

                        <ul class="requisitos" id="requisitos">
                            <li data-regla="largo"><span class="marca" aria-hidden="true">○</span> Al menos <?= $PASS_MIN ?> caracteres</li>
                            <li data-regla="letra"><span class="marca" aria-hidden="true">○</span> Al menos una letra</li>
                            <li data-regla="numero"><span class="marca" aria-hidden="true">○</span> Al menos un número</li>
                        </ul>

                        <p class="caps" id="caps-aviso" role="status">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 4 8 8h-5v5H9v-5H4z"/><path d="M9 21h6"/></svg>
                            Bloq Mayús está activado
                        </p>
                    </div>

                    <div class="campo">
                        <label for="contrasenia2">Repetir contraseña <span class="req" aria-hidden="true">*</span></label>
                        <div class="campo-input">
                            <span class="ico-izq" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span>
                            <input type="password" id="contrasenia2" name="contrasenia2"
                                   placeholder="Repetí la contraseña" autocomplete="new-password" required>
                        </div>
                        <p class="campo-error">Las contraseñas no coinciden.</p>
                    </div>

                    <div class="paso-acciones">
                        <button type="button" class="btn-paso-atras" data-ir="1">← Volver</button>
                        <button type="button" class="btn-auth btn-siguiente" data-ir="3" style="width:auto;padding:13px 28px">
                            Continuar →
                        </button>
                    </div>
                </section>

                <!-- ════════ PASO 3 · COBERTURA Y FOTO ════════ -->
                <section class="paso" data-paso="3" hidden>

                    <!-- Foto de perfil -->
                    <div class="campo">
                        <label>Foto de perfil <span class="campo-opcional">(opcional)</span></label>

                        <div class="zona-foto" id="zona-foto" tabindex="0" role="button"
                             aria-label="Elegir una foto de perfil. También podés arrastrar la imagen hasta acá.">
                            <div class="foto-previa" id="foto-previa">
                                <!-- Avatar por defecto mientras no haya foto -->
                                <svg id="foto-icono" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                                </svg>
                                <img id="foto-img" alt="" hidden>
                            </div>
                            <div class="zona-foto-texto">
                                <strong>Arrastrá una imagen</strong>
                                <span>o hacé clic para elegirla · JPG, PNG o WebP · hasta 3 MB</span>
                            </div>
                            <input type="file" id="foto" name="foto" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
                        </div>

                        <div class="foto-acciones" id="foto-acciones" hidden>
                            <span class="foto-nombre" id="foto-nombre"></span>
                            <button type="button" class="btn-quitar-foto" id="btn-quitar-foto">Quitar</button>
                        </div>
                        <p class="campo-error" id="err-foto"></p>
                    </div>

                    <!-- Obra social -->
                    <div class="campo">
                        <label for="busca-os">Obra social <span class="campo-opcional">(opcional)</span></label>

                        <div class="campo-input">
                            <span class="ico-izq" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg></span>
                            <input type="text" id="busca-os" placeholder="Escribí para buscar tu obra social…"
                                   autocomplete="off" role="combobox" aria-expanded="false"
                                   aria-controls="lista-os" aria-autocomplete="list">
                        </div>

                        <!-- El valor real que viaja al servidor -->
                        <input type="hidden" name="id_plan" id="id_plan" value="<?= e($d('id_plan')) ?>">

                        <ul class="lista-os" id="lista-os" role="listbox" hidden>
                            <li class="os-opcion" role="option" data-id="" data-nombre="Sin obra social" tabindex="-1">
                                <strong>Sin obra social</strong>
                                <span>Pago particular</span>
                            </li>
                            <?php foreach ($planes as $pl): ?>
                            <li class="os-opcion" role="option"
                                data-id="<?= (int) $pl['id_plan'] ?>"
                                data-nombre="<?= e($pl['obra_social'] . ' — ' . $pl['nombre_plan']) ?>"
                                data-buscar="<?= e(mb_strtolower($pl['obra_social'] . ' ' . $pl['nombre_plan'])) ?>"
                                tabindex="-1">
                                <strong><?= e($pl['obra_social']) ?></strong>
                                <span><?= e($pl['nombre_plan']) ?>
                                    <?php if ($pl['porcentaje_cobertura'] !== null): ?>
                                        · <?= (float) $pl['porcentaje_cobertura'] ?>% de cobertura
                                    <?php endif; ?>
                                </span>
                            </li>
                            <?php endforeach; ?>
                            <li class="os-vacio" id="os-vacio" hidden>No encontramos esa obra social.</li>
                        </ul>

                        <p class="campo-ayuda" id="os-elegida">Si no la cargás ahora, podés hacerlo después desde tu perfil.</p>
                    </div>

                    <!-- Nro de afiliado: sólo aparece si eligió una obra social -->
                    <div class="campo" id="campo-afiliado" hidden>
                        <label for="nro_afiliado">Número de afiliado</label>
                        <div class="campo-input">
                            <span class="ico-izq" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg></span>
                            <input type="text" id="nro_afiliado" name="nro_afiliado" value="<?= e($d('nro_afiliado')) ?>"
                                   placeholder="Ej: 1234-56789/01" maxlength="50" data-reglas="afiliado">
                        </div>
                        <p class="campo-error">Sólo letras, números, guiones y barras (3 a 50 caracteres).</p>
                        <p class="campo-ayuda">Figura en tu credencial. Podés completarlo más adelante.</p>
                    </div>

                    <label class="check" style="margin:18px 0 4px">
                        <input type="checkbox" id="acepto" required>
                        <span>Confirmo que los datos son correctos y acepto los términos del servicio.</span>
                    </label>
                    <p class="campo-error" id="err-acepto">Necesitamos tu confirmación para crear la cuenta.</p>

                    <div class="paso-acciones">
                        <button type="button" class="btn-paso-atras" data-ir="2">← Volver</button>
                        <button type="submit" class="btn-auth" id="btn-crear" style="width:auto;padding:13px 30px">
                            <span class="spinner" aria-hidden="true"></span>
                            <span class="btn-texto">Crear mi cuenta</span>
                        </button>
                    </div>
                </section>
            </form>

            <div class="auth-pie" style="margin-top:24px">
                ¿Ya tenés cuenta? <a href="<?= BASE_URL ?>login.php">Iniciá sesión</a>
            </div>
        </div>
    </main>
</div>

<script>
(function () {
    'use strict';

    const form  = document.getElementById('form-registro');
    const pasos = [...document.querySelectorAll('.paso')];
    const items = [...document.querySelectorAll('.paso-item')];
    const MIN   = <?= $PASS_MIN ?>;

    // Con JS activo arranca el modo asistente. Si este script no corre,
    // los tres <section> quedan visibles y el formulario sigue usable.
    document.documentElement.classList.add('js-wizard');
    let actual = 1;

    function mostrar(n) {
        actual = n;
        pasos.forEach(p => { p.hidden = (+p.dataset.paso !== n); });
        items.forEach(i => {
            const v = +i.dataset.paso;
            i.classList.toggle('activo', v === n);
            i.classList.toggle('hecho', v < n);
        });
        document.querySelector('.auth-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // ── Validadores (espejo de los del servidor) ─────────────
    const reglas = {
        requerido: v => v.trim() !== '',
        dni:       v => /^\d{7,9}$/.test(v.trim()),
        telefono:  v => { const d = v.replace(/\D/g, ''); return d.length >= 8 && d.length <= 15; },
        email:     v => /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v.trim()),
        usuario:   v => /^[a-zA-Z0-9._-]{4,40}$/.test(v.trim()),
        afiliado:  v => v.trim() === '' || /^[A-Za-z0-9/-]{3,50}$/.test(v.trim())
    };

    function validar(input) {
        const nombre = input.dataset.reglas;
        if (!nombre) return true;
        const ok = reglas[nombre](input.value);
        const campo = input.closest('.campo');
        campo.classList.toggle('invalido', !ok);
        campo.classList.toggle('valido', ok && input.value.trim() !== '');
        return ok;
    }

    document.querySelectorAll('[data-reglas]').forEach(inp => {
        inp.addEventListener('blur', () => validar(inp));
        inp.addEventListener('input', () => {
            if (reglas[inp.dataset.reglas](inp.value)) inp.closest('.campo').classList.remove('invalido');
        });
    });

    // ── Contraseña: fortaleza, requisitos y coincidencia ─────
    const p1 = document.getElementById('contrasenia');
    const p2 = document.getElementById('contrasenia2');
    const reglasPass = { largo: v => v.length >= MIN, letra: v => /[A-Za-z]/.test(v), numero: v => /\d/.test(v) };

    function evaluarPass() {
        const v = p1.value;
        let cumplidas = 0;
        document.querySelectorAll('#requisitos li').forEach(li => {
            const ok = reglasPass[li.dataset.regla](v);
            li.classList.toggle('ok', ok);
            li.querySelector('.marca').textContent = ok ? '●' : '○';
            if (ok) cumplidas++;
        });

        let puntos = cumplidas;
        if (v.length >= 12) puntos++;
        if (/[^A-Za-z0-9]/.test(v)) puntos++;

        document.getElementById('fuerza').hidden = v.length === 0;
        const niveles = [
            { max: 2, txt: 'Débil',     clase: 'debil',  pct: 33 },
            { max: 4, txt: 'Aceptable', clase: 'media',  pct: 66 },
            { max: 9, txt: 'Fuerte',    clase: 'fuerte', pct: 100 }
        ];
        const n = niveles.find(x => puntos <= x.max) || niveles[2];
        const rel = document.getElementById('fuerza-relleno');
        rel.style.width = n.pct + '%';
        rel.className = n.clase;
        document.getElementById('fuerza-texto').textContent = n.txt;

        return cumplidas === 3;
    }

    function coinciden() {
        const ok = p2.value !== '' && p1.value === p2.value;
        p2.closest('.campo').classList.toggle('invalido', p2.value !== '' && !ok);
        p2.closest('.campo').classList.toggle('valido', ok);
        return ok;
    }

    p1.addEventListener('input', () => { evaluarPass(); if (p2.value) coinciden(); });
    p2.addEventListener('input', coinciden);

    // Bloq Mayús
    const caps = document.getElementById('caps-aviso');
    [p1, p2].forEach(inp => {
        ['keyup', 'keydown'].forEach(ev => inp.addEventListener(ev, e => {
            if (typeof e.getModifierState === 'function') {
                caps.classList.toggle('visible', e.getModifierState('CapsLock'));
            }
        }));
        inp.addEventListener('blur', () => caps.classList.remove('visible'));
    });

    // Ver / ocultar
    const ojo = document.getElementById('btn-ojo');
    ojo.addEventListener('click', () => {
        const oculto = p1.type === 'password';
        p1.type = oculto ? 'text' : 'password';
        ojo.setAttribute('aria-pressed', String(oculto));
        ojo.setAttribute('aria-label', oculto ? 'Ocultar contraseña' : 'Mostrar contraseña');
        document.getElementById('ojo-abierto').style.display = oculto ? 'none' : '';
        document.getElementById('ojo-cerrado').style.display = oculto ? '' : 'none';
        p1.focus();
    });

    // ── Foto: arrastrar y soltar + vista previa ──────────────
    const zona    = document.getElementById('zona-foto');
    const inpFoto = document.getElementById('foto');
    const img     = document.getElementById('foto-img');
    const icono   = document.getElementById('foto-icono');
    const acc     = document.getElementById('foto-acciones');
    const errFoto = document.getElementById('err-foto');
    const MAX_MB  = 3;

    function mostrarFoto(archivo) {
        errFoto.textContent = '';
        errFoto.style.display = 'none';

        // Validación en el cliente: es cortesía para no hacerle subir 5 MB
        // en vano. El servidor vuelve a validar todo (y además re-codifica).
        if (!/^image\/(jpeg|png|webp|gif)$/.test(archivo.type)) {
            errFoto.textContent = 'Ese archivo no es una imagen JPG, PNG o WebP.';
            errFoto.style.display = 'flex';
            inpFoto.value = '';
            return;
        }
        if (archivo.size > MAX_MB * 1024 * 1024) {
            errFoto.textContent = 'La imagen pesa más de ' + MAX_MB + ' MB.';
            errFoto.style.display = 'flex';
            inpFoto.value = '';
            return;
        }

        const lector = new FileReader();
        lector.onload = e => {
            img.src = e.target.result;
            img.hidden = false;
            icono.style.display = 'none';
            zona.classList.add('con-foto');
            acc.hidden = false;
            document.getElementById('foto-nombre').textContent = archivo.name;
        };
        lector.readAsDataURL(archivo);
    }

    zona.addEventListener('click', () => inpFoto.click());
    zona.addEventListener('keydown', e => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); inpFoto.click(); }
    });
    inpFoto.addEventListener('change', () => { if (inpFoto.files[0]) mostrarFoto(inpFoto.files[0]); });

    ['dragenter', 'dragover'].forEach(ev =>
        zona.addEventListener(ev, e => { e.preventDefault(); zona.classList.add('arrastrando'); }));
    ['dragleave', 'drop'].forEach(ev =>
        zona.addEventListener(ev, e => { e.preventDefault(); zona.classList.remove('arrastrando'); }));

    zona.addEventListener('drop', e => {
        const f = e.dataTransfer.files[0];
        if (!f) return;
        // DataTransfer permite asignar el archivo al input real, para que
        // viaje en el envío normal del formulario.
        const dt = new DataTransfer();
        dt.items.add(f);
        inpFoto.files = dt.files;
        mostrarFoto(f);
    });

    document.getElementById('btn-quitar-foto').addEventListener('click', () => {
        inpFoto.value = '';
        img.hidden = true;
        img.removeAttribute('src');
        icono.style.display = '';
        zona.classList.remove('con-foto');
        acc.hidden = true;
        errFoto.style.display = 'none';
    });

    // ── Obra social: buscador con lista filtrable ────────────
    const busca   = document.getElementById('busca-os');
    const lista   = document.getElementById('lista-os');
    const idPlan  = document.getElementById('id_plan');
    const elegida = document.getElementById('os-elegida');
    const campoAf = document.getElementById('campo-afiliado');
    const vacio   = document.getElementById('os-vacio');

    function abrir(v) {
        lista.hidden = !v;
        busca.setAttribute('aria-expanded', String(v));
    }

    busca.addEventListener('focus', () => abrir(true));
    busca.addEventListener('input', () => {
        const q = busca.value.trim().toLowerCase();
        let visibles = 0;
        lista.querySelectorAll('.os-opcion').forEach(li => {
            const txt = li.dataset.buscar || li.dataset.nombre.toLowerCase();
            const ok = q === '' || txt.includes(q);
            li.hidden = !ok;
            if (ok) visibles++;
        });
        vacio.hidden = visibles > 0;
        abrir(true);
    });

    lista.querySelectorAll('.os-opcion').forEach(li => {
        li.addEventListener('click', () => {
            idPlan.value = li.dataset.id;
            busca.value  = li.dataset.nombre;
            elegida.textContent = li.dataset.id
                ? 'Seleccionada: ' + li.dataset.nombre
                : 'Vas a abonar como paciente particular.';
            // El número de afiliado sólo tiene sentido con obra social
            campoAf.hidden = !li.dataset.id;
            if (!li.dataset.id) document.getElementById('nro_afiliado').value = '';
            abrir(false);
        });
    });

    document.addEventListener('click', e => {
        if (!lista.contains(e.target) && e.target !== busca) abrir(false);
    });

    // ── Navegación entre pasos ───────────────────────────────
    function validarPaso(n) {
        const sec = pasos.find(p => +p.dataset.paso === n);
        let ok = true, primero = null;

        sec.querySelectorAll('[data-reglas]').forEach(inp => {
            if (!validar(inp) && inp.required) { ok = false; primero = primero || inp; }
        });

        if (n === 2) {
            if (!evaluarPass()) { ok = false; primero = primero || p1; p1.closest('.campo').classList.add('invalido'); }
            if (!coinciden())   { ok = false; primero = primero || p2; }
        }
        if (primero) primero.focus();
        return ok;
    }

    document.querySelectorAll('.btn-siguiente').forEach(b =>
        b.addEventListener('click', () => { if (validarPaso(actual)) mostrar(+b.dataset.ir); }));
    document.querySelectorAll('.btn-paso-atras').forEach(b =>
        b.addEventListener('click', () => mostrar(+b.dataset.ir)));

    // Permitir volver a un paso ya completado desde la barra
    items.forEach(i => i.addEventListener('click', () => {
        const n = +i.dataset.paso;
        if (n < actual) mostrar(n);
    }));

    // ── Envío ────────────────────────────────────────────────
    form.addEventListener('submit', ev => {
        // Se revalidan TODOS los pasos: alguien pudo llegar al 3 y volver
        // a vaciar un campo del 1.
        for (const n of [1, 2]) {
            if (!validarPaso(n)) { ev.preventDefault(); mostrar(n); return; }
        }
        const acepto = document.getElementById('acepto');
        if (!acepto.checked) {
            ev.preventDefault();
            document.getElementById('err-acepto').style.display = 'flex';
            acepto.focus();
            return;
        }
        const btn = document.getElementById('btn-crear');
        btn.classList.add('cargando');
        btn.disabled = true;
        btn.querySelector('.btn-texto').textContent = 'Creando tu cuenta…';
    });

    mostrar(1);
})();
</script>
</body>
</html>

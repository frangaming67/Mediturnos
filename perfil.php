<?php
// perfil.php — Mi perfil
// -----------------------------------------------------------------
// Punto de entrada del sistema interno, como dashboard.php: arranca la
// sesión, valida el acceso, procesa el formulario que se haya enviado y
// dibuja la pantalla dentro del layout con barra lateral.
//
// SON CINCO FORMULARIOS, NO UNO
// Cada bloque (foto, cuenta, datos personales, cobertura, contraseña)
// se envía por separado, con su propio botón. Un formulario único
// obligaría a reescribir la contraseña para corregir un teléfono, y un
// error en cualquier campo haría fallar el guardado de todos los demás.
// Cada <form> lleva un campo oculto `seccion` que le dice a este
// archivo qué método del controlador invocar.
//
// PATRÓN POST-REDIRECT-GET
// Si el guardado sale bien se redirige a esta misma página. Sin eso, un
// F5 después de guardar reenviaría el POST y el navegador mostraría el
// cartel de "¿confirmás el reenvío?". Con el error se hace lo contrario
// —se dibuja la página sin redirigir— para no perder lo que la persona
// había escrito.
// -----------------------------------------------------------------
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/includes/seguridad.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/sistema/controladores/ControladorPerfil.php';

iniciarSesionSegura();
cabecerasSeguridad();
verificarSesion();

$ctrl   = new ControladorPerfil($pdo);
$modelo = new Perfil($pdo);

$perfil = $modelo->cargar((int) $_SESSION['id_usuario']);
if (!$perfil) {
    // Sesión viva de una cuenta que ya no existe (la dieron de baja
    // mientras estaba conectada). No hay perfil que mostrar: se cierra.
    header('Location: ' . BASE_URL . 'logout.php');
    exit;
}

$error        = null;   // mensaje de error a mostrar
$errorSeccion = null;   // en qué bloque mostrarlo

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Un POST más grande que post_max_size llega VACÍO: PHP descarta el
    // cuerpo antes de que este archivo se ejecute. Sin este chequeo, la
    // validación de CSRF fallaría y la persona vería "token inválido"
    // cuando el problema real es el peso de la imagen.
    if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $error        = 'El archivo es demasiado grande para el servidor. Probá con una imagen más chica.';
        $errorSeccion = 'foto';

    } else {
        csrf_verificar();
        // is_string, no un cast: con `seccion[]=x` el valor llega como array
        // y castearlo dispararía un warning. Si no es texto, no es una
        // sección válida y cae en el `default` del match.
        $seccion = is_string($_POST['seccion'] ?? null) ? $_POST['seccion'] : '';

        $r = match ($seccion) {
            'cuenta'    => $ctrl->guardarCuenta($perfil, $_POST),
            'datos'     => $ctrl->guardarDatos($perfil, $_POST),
            'cobertura' => $ctrl->guardarCobertura($perfil, $_POST),
            'foto'      => isset($_POST['quitar'])
                              ? $ctrl->quitarFoto($perfil)
                              : $ctrl->guardarFoto($perfil, $_FILES),
            'password'  => $ctrl->cambiarPassword($perfil, $_POST),
            default     => ['ok' => false, 'msg' => 'No reconocimos la acción.'],
        };

        if ($r['ok']) {
            $_SESSION['perfil_flash'] = ['msg' => $r['msg'], 'seccion' => $seccion];
            header('Location: ' . BASE_URL . 'perfil.php#' . $seccion);
            exit;
        }

        $error        = $r['msg'];
        $errorSeccion = $seccion;
        // Se recarga: algún bloque anterior pudo haberse guardado bien y
        // la pantalla tiene que reflejar lo que hay en la base, no lo que
        // había cuando se abrió.
        $perfil = $modelo->cargar((int) $_SESSION['id_usuario']);
    }
}

// Mensaje de éxito que dejó el redirect. Se consume una sola vez.
$flash = $_SESSION['perfil_flash'] ?? null;
unset($_SESSION['perfil_flash']);

$usuarios = new Usuario($pdo);
$planes   = !empty($perfil['id_paciente']) ? $usuarios->listarPlanesObraSocial() : [];

// Planes agrupados por obra social, para los <optgroup> del selector.
$planesPorOs = [];
foreach ($planes as $pl) {
    $planesPorOs[$pl['obra_social']][] = $pl;
}

/**
 * Valor de un campo: lo que se envió si el guardado falló, y si no el
 * de la base. Así un error no borra lo que la persona había escrito.
 */
$v = fn(string $campo, ?string $def = '') =>
    ($errorSeccion !== null && is_string($_POST[$campo] ?? null))
        ? $_POST[$campo]
        : (string) ($def ?? '');

$fotoUrl   = SubidaImagen::url($perfil['foto']);
$iniciales = strtoupper(mb_substr($perfil['nombre'], 0, 1) . mb_substr($perfil['apellido'], 0, 1));

// Edad, sólo si cargó la fecha de nacimiento.
$edad = null;
if (!empty($perfil['fecha_nac'])) {
    $edad = (new DateTime($perfil['fecha_nac']))->diff(new DateTime('today'))->y;
}

$tieneFicha   = !empty($perfil['id_paciente']) || !empty($perfil['matricula']);
$esPaciente   = !empty($perfil['id_paciente']);

// Qué bloques se dibujan para ESTE rol. Cada mensaje se muestra dentro de
// su bloque, así que hay que saber cuáles existen: si el error viene de un
// bloque que no se dibuja —un POST armado a mano pidiendo cobertura desde
// una cuenta de admin, o una sección inventada— el aviso no tendría dónde
// aparecer y la respuesta quedaría muda. En ese caso se muestra arriba.
$seccionesVisibles = ['foto', 'cuenta', 'password'];
if ($tieneFicha) $seccionesVisibles[] = 'datos';
if ($esPaciente) $seccionesVisibles[] = 'cobertura';

$avisoSuelto = null;
if ($error !== null && !in_array($errorSeccion, $seccionesVisibles, true)) {
    $avisoSuelto = ['clase' => 'error', 'msg' => $error];
} elseif ($flash && !in_array($flash['seccion'], $seccionesVisibles, true)) {
    $avisoSuelto = ['clase' => 'exito', 'msg' => $flash['msg']];
}

$paginaTitulo = 'Mi perfil';
$breadcrumb   = '<a href="' . BASE_URL . 'dashboard.php">Inicio</a> / Mi perfil';
// auth.css se carga acá aunque esta no sea una pantalla de autenticación:
// además del login y el registro, contiene los widgets de formulario
// reutilizables que el perfil vuelve a usar tal cual —la zona de arrastrar
// la foto, el medidor de fortaleza, la lista de requisitos y el aviso de
// Bloq Mayús—. Copiarlos en perfil.css sería duplicar CSS ya probado para
// que después los dos se separen. Queda anotado en la deuda técnica que
// esos widgets merecen su propio archivo, con un nombre que no diga "auth".
$cssExtra     = ['auth.css', 'perfil.css'];

require_once __DIR__ . '/sistema/vistas/layouts/navbar.php';
?>

<?php if ($avisoSuelto): ?>
<div class="alerta alerta-<?= e($avisoSuelto['clase']) ?>" role="alert"><?= e($avisoSuelto['msg']) ?></div>
<?php endif; ?>

<!-- ══════════════ Cabecera ══════════════ -->
<header class="perfil-cabecera">
    <div class="perfil-avatar">
        <?php if ($fotoUrl): ?>
            <img src="<?= e($fotoUrl) ?>" alt="Foto de perfil de <?= e($perfil['nombre'] . ' ' . $perfil['apellido']) ?>">
        <?php else: ?>
            <span aria-hidden="true"><?= e($iniciales) ?></span>
        <?php endif; ?>
    </div>

    <div class="perfil-id">
        <h1><?= e($perfil['nombre'] . ' ' . $perfil['apellido']) ?></h1>
        <p class="perfil-usuario">
            @<?= e($perfil['usuario']) ?>
            <span class="badge badge-activo"><?= e($perfil['rol']) ?></span>
        </p>
        <ul class="perfil-meta">
            <li>
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 6 10-6"/></svg>
                <?= e($perfil['email']) ?>
            </li>
            <li>
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                Miembro desde <?= e(date('d/m/Y', strtotime($perfil['fecha_alta']))) ?>
            </li>
            <?php if (!empty($perfil['ultimo_login'])): ?>
            <li>
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                Último acceso <?= e(date('d/m/Y \a \l\a\s H:i', strtotime($perfil['ultimo_login']))) ?>
            </li>
            <?php endif; ?>
        </ul>
    </div>
</header>

<div class="perfil-grid">

    <!-- Índice lateral: son anclas, no pestañas. Sin JavaScript siguen
         funcionando y el contenido nunca queda escondido. -->
    <nav class="perfil-nav" aria-label="Secciones del perfil">
        <a href="#foto">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="6" width="18" height="14" rx="2"/><circle cx="12" cy="13" r="3.5"/><path d="M8 6l1.5-2h5L16 6"/></svg>
            Foto
        </a>
        <a href="#cuenta">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            Cuenta
        </a>
        <?php if ($tieneFicha): ?>
        <a href="#datos">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2"/><circle cx="9" cy="12" r="2.5"/><path d="M14 10h4M14 14h4"/></svg>
            Datos personales
        </a>
        <?php endif; ?>
        <?php if ($esPaciente): ?>
        <a href="#cobertura">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-8-4.5-8-10V5l8-3 8 3v6c0 5.5-8 10-8 10z"/><path d="M12 8v6M9 11h6"/></svg>
            Cobertura
        </a>
        <?php endif; ?>
        <a href="#seguridad">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            Seguridad
        </a>
    </nav>

    <div class="perfil-secciones">

        <!-- ══════════════ FOTO ══════════════ -->
        <section class="panel" id="foto">
            <div class="panel-header"><span class="panel-titulo">Foto de perfil</span></div>
            <div class="panel-body">

                <?php if ($errorSeccion === 'foto'): ?>
                <div class="alerta alerta-error" role="alert"><?= e($error) ?></div>
                <?php elseif ($flash && $flash['seccion'] === 'foto'): ?>
                <div class="alerta alerta-exito" role="status"><?= e($flash['msg']) ?></div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" id="form-foto">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="seccion" value="foto">

                    <?php // <label for> en vez de un div con onclick: al hacer clic
                          // se abre el selector de archivos SIN JavaScript, y con el
                          // teclado el input sigue siendo alcanzable con Tab. ?>
                    <label class="zona-foto" for="archivo-foto" id="zona-foto">
                        <span class="foto-previa" id="foto-previa">
                            <?php if ($fotoUrl): ?>
                                <img id="foto-img" src="<?= e($fotoUrl) ?>" alt="">
                            <?php else: ?>
                                <svg id="foto-icono" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                <img id="foto-img" alt="" hidden>
                            <?php endif; ?>
                        </span>
                        <span class="zona-foto-texto">
                            <strong>Arrastrá una imagen o hacé clic acá</strong>
                            <span>JPG, PNG o WebP · hasta <?= (int) (SubidaImagen::MAX_BYTES / 1024 / 1024) ?> MB</span>
                        </span>
                        <input type="file" id="archivo-foto" name="foto" class="solo-lectores"
                               accept="image/jpeg,image/png,image/webp,image/gif">
                    </label>

                    <div class="foto-acciones" id="foto-acciones" hidden>
                        <span class="foto-nombre" id="foto-nombre"></span>
                        <button type="button" class="btn-quitar-foto" id="btn-descartar">Descartar</button>
                    </div>

                    <p class="campo-error" id="err-foto"></p>

                    <p class="form-hint" style="margin-top:12px">
                        La imagen se recorta en cuadrado y se reduce a
                        <?= SubidaImagen::MAX_LADO ?>&nbsp;px. El servidor la vuelve a generar
                        desde cero, así que no conserva metadatos: la ubicación donde fue
                        tomada no queda guardada.
                    </p>

                    <div class="form-acciones">
                        <button type="submit" class="btn btn-primario">Guardar foto</button>
                        <?php if ($perfil['foto']): ?>
                        <button type="submit" name="quitar" value="1" class="btn btn-secundario">
                            Quitar foto actual
                        </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </section>

        <!-- ══════════════ CUENTA ══════════════ -->
        <section class="panel" id="cuenta">
            <div class="panel-header"><span class="panel-titulo">Datos de la cuenta</span></div>
            <div class="panel-body">

                <?php if ($errorSeccion === 'cuenta'): ?>
                <div class="alerta alerta-error" role="alert"><?= e($error) ?></div>
                <?php elseif ($flash && $flash['seccion'] === 'cuenta'): ?>
                <div class="alerta alerta-exito" role="status"><?= e($flash['msg']) ?></div>
                <?php endif; ?>

                <form method="POST" novalidate>
                    <?php csrf_field(); ?>
                    <input type="hidden" name="seccion" value="cuenta">

                    <div class="form-grid mb-18">
                        <div class="form-group">
                            <label for="nombre">Nombre <span class="req">*</span></label>
                            <input type="text" id="nombre" name="nombre" class="form-control" required
                                   maxlength="<?= Validacion::NOMBRE_MAX ?>" autocomplete="given-name"
                                   value="<?= e($v('nombre', $perfil['nombre'])) ?>">
                        </div>
                        <div class="form-group">
                            <label for="apellido">Apellido <span class="req">*</span></label>
                            <input type="text" id="apellido" name="apellido" class="form-control" required
                                   maxlength="<?= Validacion::NOMBRE_MAX ?>" autocomplete="family-name"
                                   value="<?= e($v('apellido', $perfil['apellido'])) ?>">
                        </div>
                        <div class="form-group">
                            <label for="email">Correo electrónico <span class="req">*</span></label>
                            <input type="email" id="email" name="email" class="form-control" required
                                   maxlength="<?= Validacion::EMAIL_MAX ?>" autocomplete="email"
                                   value="<?= e($v('email', $perfil['email'])) ?>">
                            <span class="form-hint">También sirve para iniciar sesión y para recuperar la cuenta.</span>
                        </div>
                        <div class="form-group">
                            <label for="usuario-ro">Nombre de usuario</label>
                            <input type="text" id="usuario-ro" class="form-control" readonly
                                   value="<?= e($perfil['usuario']) ?>">
                            <span class="form-hint">
                                No se puede cambiar: es con lo que iniciás sesión y quedó
                                registrado en tus turnos.
                            </span>
                        </div>
                    </div>

                    <div class="form-acciones">
                        <button type="submit" class="btn btn-primario">Guardar cambios</button>
                    </div>
                </form>
            </div>
        </section>

        <?php if ($tieneFicha): ?>
        <!-- ══════════════ DATOS PERSONALES ══════════════ -->
        <section class="panel" id="datos">
            <div class="panel-header">
                <span class="panel-titulo">Datos personales</span>
            </div>
            <div class="panel-body">

                <?php if ($errorSeccion === 'datos'): ?>
                <div class="alerta alerta-error" role="alert"><?= e($error) ?></div>
                <?php elseif ($flash && $flash['seccion'] === 'datos'): ?>
                <div class="alerta alerta-exito" role="status"><?= e($flash['msg']) ?></div>
                <?php endif; ?>

                <form method="POST" novalidate>
                    <?php csrf_field(); ?>
                    <input type="hidden" name="seccion" value="datos">

                    <div class="form-grid mb-18">
                        <?php if ($esPaciente): ?>
                        <div class="form-group">
                            <label for="dni-ro">DNI</label>
                            <input type="text" id="dni-ro" class="form-control" readonly
                                   value="<?= e($perfil['dni']) ?>">
                            <span class="form-hint">
                                Si está mal, pedí la corrección en recepción: es el dato con el
                                que se identifica tu historia clínica.
                            </span>
                        </div>

                        <div class="form-group">
                            <label for="fecha_nac">Fecha de nacimiento</label>
                            <input type="date" id="fecha_nac" name="fecha_nac" class="form-control"
                                   max="<?= date('Y-m-d') ?>" min="1900-01-01" autocomplete="bday"
                                   value="<?= e($v('fecha_nac', $perfil['fecha_nac'])) ?>">
                            <?php if ($edad !== null): ?>
                            <span class="form-hint"><?= (int) $edad ?> años</span>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="sexo">Sexo</label>
                            <?php $sexoActual = $v('sexo', $perfil['sexo']); ?>
                            <select id="sexo" name="sexo" class="form-control">
                                <option value="">Prefiero no indicarlo</option>
                                <option value="F" <?= $sexoActual === 'F' ? 'selected' : '' ?>>Femenino</option>
                                <option value="M" <?= $sexoActual === 'M' ? 'selected' : '' ?>>Masculino</option>
                                <option value="X" <?= $sexoActual === 'X' ? 'selected' : '' ?>>X / No binario</option>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label for="telefono">Teléfono <span class="req">*</span></label>
                            <input type="tel" id="telefono" name="telefono" class="form-control" required
                                   maxlength="30" autocomplete="tel" placeholder="11 4444 1111"
                                   value="<?= e($v('telefono', $perfil['telefono'])) ?>">
                            <span class="form-hint">Es por donde te avisamos si hay que reprogramar un turno.</span>
                        </div>

                        <?php if ($esPaciente): ?>
                        <div class="form-group">
                            <label for="direccion">Dirección</label>
                            <input type="text" id="direccion" name="direccion" class="form-control"
                                   maxlength="<?= Validacion::DIRECCION_MAX ?>" autocomplete="street-address"
                                   placeholder="Av. Siempre Viva 1234, CABA"
                                   value="<?= e($v('direccion', $perfil['direccion'])) ?>">
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="form-acciones">
                        <button type="submit" class="btn btn-primario">Guardar cambios</button>
                    </div>
                </form>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($esPaciente): ?>
        <!-- ══════════════ COBERTURA ══════════════ -->
        <section class="panel" id="cobertura">
            <div class="panel-header"><span class="panel-titulo">Cobertura médica</span></div>
            <div class="panel-body">

                <?php if ($errorSeccion === 'cobertura'): ?>
                <div class="alerta alerta-error" role="alert"><?= e($error) ?></div>
                <?php elseif ($flash && $flash['seccion'] === 'cobertura'): ?>
                <div class="alerta alerta-exito" role="status"><?= e($flash['msg']) ?></div>
                <?php endif; ?>

                <?php if ($perfil['obra_social']): ?>
                <p class="cobertura-actual">
                    Hoy figurás en <strong><?= e($perfil['obra_social']) ?></strong>,
                    plan <strong><?= e($perfil['nombre_plan']) ?></strong>.
                </p>
                <?php else: ?>
                <p class="cobertura-actual">
                    No tenés cobertura cargada: los turnos se te cobran como
                    <strong>paciente particular</strong>.
                </p>
                <?php endif; ?>

                <form method="POST" novalidate id="form-cobertura">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="seccion" value="cobertura">

                    <?php $planActual = $v('id_plan', (string) ($perfil['id_plan'] ?? '')); ?>

                    <div class="form-group mb-18">
                        <label for="id_plan">Obra social y plan</label>
                        <?php // Un <select> con grupos, y no el buscador del registro: la
                              // lista es corta, el navegador ya deja escribir para saltar a
                              // una opción, y funciona sin JavaScript. ?>
                        <select id="id_plan" name="id_plan" class="form-control">
                            <option value="">Sin obra social — pago particular</option>
                            <?php foreach ($planesPorOs as $nombreOs => $lista): ?>
                            <optgroup label="<?= e($nombreOs) ?>">
                                <?php foreach ($lista as $pl): ?>
                                <option value="<?= (int) $pl['id_plan'] ?>"
                                    <?= $planActual === (string) $pl['id_plan'] ? 'selected' : '' ?>>
                                    <?= e($pl['nombre_plan']) ?><?php
                                        if ($pl['porcentaje_cobertura'] !== null) {
                                            echo ' — ' . (float) $pl['porcentaje_cobertura'] . '% de cobertura';
                                        } ?>
                                </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php // Visible por defecto: si el JS no corre, se ve igual y se puede
                          // completar. El script sólo lo esconde cuando no hay obra social. ?>
                    <div class="form-group mb-18" id="campo-afiliado">
                        <label for="nro_afiliado">Número de afiliado</label>
                        <input type="text" id="nro_afiliado" name="nro_afiliado" class="form-control"
                               maxlength="50" placeholder="Ej: 1234-56789/01"
                               value="<?= e($v('nro_afiliado', $perfil['nro_afiliado'])) ?>">
                        <span class="form-hint">Figura en tu credencial. Podés dejarlo vacío.</span>
                    </div>

                    <div class="form-acciones">
                        <button type="submit" class="btn btn-primario">Guardar cobertura</button>
                    </div>
                </form>
            </div>
        </section>
        <?php endif; ?>

        <!-- ══════════════ SEGURIDAD ══════════════ -->
        <section class="panel" id="seguridad">
            <div class="panel-header"><span class="panel-titulo">Seguridad</span></div>
            <div class="panel-body">

                <?php if ($errorSeccion === 'password'): ?>
                <div class="alerta alerta-error" role="alert"><?= e($error) ?></div>
                <?php elseif ($flash && $flash['seccion'] === 'password'): ?>
                <div class="alerta alerta-exito" role="status"><?= e($flash['msg']) ?></div>
                <?php endif; ?>

                <form method="POST" novalidate id="form-pass">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="seccion" value="password">

                    <div class="form-group mb-18">
                        <label for="password_actual">Contraseña actual <span class="req">*</span></label>
                        <div class="input-pass">
                            <input type="password" id="password_actual" name="password_actual"
                                   class="form-control" required autocomplete="current-password">
                        </div>
                        <span class="form-hint">
                            Te la pedimos aunque ya estés dentro: si alguien se sentara en tu
                            computadora con la sesión abierta, no podría cambiarte la clave.
                        </span>
                    </div>

                    <div class="form-group mb-18">
                        <label for="password_nueva">Contraseña nueva <span class="req">*</span></label>
                        <div class="input-pass">
                            <input type="password" id="password_nueva" name="password_nueva"
                                   class="form-control" required autocomplete="new-password"
                                   placeholder="Mínimo <?= Validacion::PASS_MIN ?> caracteres">
                            <button type="button" class="btn-ojo" id="btn-ojo"
                                    aria-label="Mostrar contraseña" aria-pressed="false">
                                <svg id="ojo-abierto" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg id="ojo-cerrado" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none" aria-hidden="true"><path d="M17.9 17.9A10.4 10.4 0 0 1 12 19c-6.5 0-10-7-10-7a18.4 18.4 0 0 1 5.1-5.9M9.9 4.2A9.1 9.1 0 0 1 12 4c6.5 0 10 7 10 7a18.5 18.5 0 0 1-2.2 3.2M1 1l22 22"/></svg>
                            </button>
                        </div>

                        <div class="fuerza" id="fuerza" hidden>
                            <div class="fuerza-barra"><span id="fuerza-relleno"></span></div>
                            <span class="fuerza-texto" id="fuerza-texto"></span>
                        </div>

                        <ul class="requisitos" id="requisitos">
                            <li data-regla="largo"><span class="marca" aria-hidden="true">○</span> Al menos <?= Validacion::PASS_MIN ?> caracteres</li>
                            <li data-regla="letra"><span class="marca" aria-hidden="true">○</span> Al menos una letra</li>
                            <li data-regla="numero"><span class="marca" aria-hidden="true">○</span> Al menos un número</li>
                        </ul>
                    </div>

                    <div class="form-group mb-18">
                        <label for="password_nueva2">Repetir contraseña nueva <span class="req">*</span></label>
                        <div class="input-pass">
                            <input type="password" id="password_nueva2" name="password_nueva2"
                                   class="form-control" required autocomplete="new-password">
                        </div>
                        <span class="form-hint" id="aviso-coinciden"></span>
                    </div>

                    <p class="caps" id="caps-aviso" role="status">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 4 8 8h-5v5H9v-5H4z"/><path d="M9 21h6"/></svg>
                        Bloq Mayús está activado
                    </p>

                    <div class="form-acciones">
                        <button type="submit" class="btn btn-primario">Cambiar contraseña</button>
                    </div>
                </form>
            </div>
        </section>

    </div><!-- /perfil-secciones -->
</div><!-- /perfil-grid -->

<script>
(function () {
    'use strict';

    // ── Foto: arrastrar y soltar + vista previa ──────────────────
    // Todo esto es OPCIONAL: el <label for> ya abre el selector de
    // archivos sin JavaScript y el formulario se envía igual.
    const zona    = document.getElementById('zona-foto');
    const entrada = document.getElementById('archivo-foto');

    if (zona && entrada) {
        const img     = document.getElementById('foto-img');
        const icono   = document.getElementById('foto-icono');
        const acc     = document.getElementById('foto-acciones');
        const errFoto = document.getElementById('err-foto');
        const MAX_MB  = <?= (int) (SubidaImagen::MAX_BYTES / 1024 / 1024) ?>;
        const previaOriginal = img && !img.hidden ? img.src : null;

        function fallar(msg) {
            errFoto.textContent = msg;
            errFoto.style.display = 'flex';
            entrada.value = '';
        }

        function mostrar(archivo) {
            errFoto.textContent = '';
            errFoto.style.display = 'none';

            // Cortesía: evita subir 5 MB en vano. El servidor revalida todo.
            if (!/^image\/(jpeg|png|webp|gif)$/.test(archivo.type)) {
                return fallar('Ese archivo no es una imagen JPG, PNG o WebP.');
            }
            if (archivo.size > MAX_MB * 1024 * 1024) {
                return fallar('La imagen pesa más de ' + MAX_MB + ' MB.');
            }

            const lector = new FileReader();
            lector.onload = e => {
                img.src = e.target.result;
                img.hidden = false;
                if (icono) icono.style.display = 'none';
                zona.classList.add('con-foto');
                acc.hidden = false;
                document.getElementById('foto-nombre').textContent = archivo.name;
            };
            lector.readAsDataURL(archivo);
        }

        entrada.addEventListener('change', () => {
            if (entrada.files[0]) mostrar(entrada.files[0]);
        });

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
            entrada.files = dt.files;
            mostrar(f);
        });

        document.getElementById('btn-descartar').addEventListener('click', () => {
            entrada.value = '';
            acc.hidden = true;
            errFoto.style.display = 'none';
            zona.classList.remove('con-foto');
            if (previaOriginal) {
                img.src = previaOriginal;          // vuelve a la foto guardada
            } else {
                img.hidden = true;
                img.removeAttribute('src');
                if (icono) icono.style.display = '';
            }
        });
    }

    // ── Cobertura: el número de afiliado sólo aplica con obra social ──
    const selPlan = document.getElementById('id_plan');
    if (selPlan) {
        const campoAf = document.getElementById('campo-afiliado');
        const inputAf = document.getElementById('nro_afiliado');
        const sincronizar = () => {
            const hayPlan = selPlan.value !== '';
            campoAf.hidden = !hayPlan;
            if (!hayPlan) inputAf.value = '';
        };
        selPlan.addEventListener('change', sincronizar);
        sincronizar();
    }

    // ── Contraseña: fortaleza, requisitos, coincidencia y Bloq Mayús ──
    const p1 = document.getElementById('password_nueva');
    const p2 = document.getElementById('password_nueva2');
    if (p1 && p2) {
        const MIN = <?= Validacion::PASS_MIN ?>;
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
        }

        const aviso = document.getElementById('aviso-coinciden');
        function coinciden() {
            if (p2.value === '') { aviso.textContent = ''; aviso.className = 'form-hint'; return; }
            const ok = p1.value === p2.value;
            aviso.textContent = ok ? 'Coinciden.' : 'No coinciden.';
            aviso.className = 'form-hint ' + (ok ? 'hint-ok' : 'hint-mal');
        }

        p1.addEventListener('input', () => { evaluar(); coinciden(); });
        p2.addEventListener('input', coinciden);

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

        const caps = document.getElementById('caps-aviso');
        [document.getElementById('password_actual'), p1, p2].forEach(inp => {
            ['keyup', 'keydown'].forEach(ev => inp.addEventListener(ev, e => {
                if (typeof e.getModifierState === 'function') {
                    caps.classList.toggle('visible', e.getModifierState('CapsLock'));
                }
            }));
            inp.addEventListener('blur', () => caps.classList.remove('visible'));
        });
    }
})();
</script>

<?php require_once __DIR__ . '/sistema/vistas/layouts/footer.php'; ?>

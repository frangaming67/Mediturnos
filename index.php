<?php
// =============================================================
// index.php — Landing pública de MediTurnos
// =============================================================
// Punto de entrada PÚBLICO del sitio (antes no existía: la raíz
// del proyecto no tenía index y quedaba expuesto el listado de
// directorios). Es la cara institucional del centro médico y la
// puerta de entrada al sistema de turnos.
//
// REGLA: nada de datos hardcodeados. Especialidades, médicos y
// estadísticas se leen de la base. Si una consulta falla, la
// sección se degrada con elegancia en lugar de romper la página.
// =============================================================

// La landing es pública: si la base estuviera caída debe seguir mostrándose
// (sin los datos dinámicos) en vez de responder un JSON de error al visitante.
define('CONEXION_TOLERANTE', true);
require_once __DIR__ . '/config/conexion.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ¿Hay alguien logueado? Cambia el CTA del navbar.
$logueado   = !empty($_SESSION['id_usuario']);
$nombreSesion = trim(($_SESSION['nombre'] ?? '') . ' ' . ($_SESSION['apellido'] ?? ''));

/**
 * Escape corto. Toda salida dinámica de esta página pasa por acá
 * (previene XSS: los nombres de médicos y especialidades vienen de
 * la base y podrían contener caracteres HTML).
 */
function e(?string $v): string
{
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Ícono SVG por especialidad. Se elige por palabra clave del nombre
 * para no depender de una columna nueva en la base.
 */
function iconoEspecialidad(string $nombre): string
{
    $n = strtolower($nombre);
    $svg = fn(string $p) => '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
        . 'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';

    return match (true) {
        str_contains($n, 'cardio')  => $svg('<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21.2l7.8-7.7 1-1.1a5.5 5.5 0 0 0 0-7.8z"/>'),
        str_contains($n, 'pediat')  => $svg('<circle cx="12" cy="9" r="5"/><path d="M8 8h.01M16 8h.01M9.5 11.5a3.5 3.5 0 0 0 5 0M5 21a7 7 0 0 1 14 0"/>'),
        str_contains($n, 'derma')   => $svg('<path d="M12 2a7 7 0 0 0-7 7c0 5 7 13 7 13s7-8 7-13a7 7 0 0 0-7-7z"/><circle cx="10" cy="8" r="1"/><circle cx="14" cy="11" r="1"/>'),
        str_contains($n, 'trauma')  => $svg('<path d="M7 3a2.5 2.5 0 0 0-2 4 2.5 2.5 0 0 0 0 4l6 6a2.5 2.5 0 0 0 4 0 2.5 2.5 0 0 0 4-2l-6-6a2.5 2.5 0 0 0 0-4 2.5 2.5 0 0 0-4 0z"/>'),
        str_contains($n, 'gineco')  => $svg('<circle cx="12" cy="8" r="5"/><path d="M12 13v8M9 18h6"/>'),
        str_contains($n, 'neuro')   => $svg('<path d="M12 4a4 4 0 0 0-4 4 3 3 0 0 0-1 5.8V16a3 3 0 0 0 5 2.2 3 3 0 0 0 5-2.2v-2.2A3 3 0 0 0 16 8a4 4 0 0 0-4-4z"/><path d="M12 4v14"/>'),
        str_contains($n, 'oftal')   => $svg('<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>'),
        default                     => $svg('<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>'),   // clínica / genérico
    };
}

// ── DATOS REALES DESDE LA BASE ───────────────────────────────
$especialidades = [];
$medicos        = [];
$stats          = ['pacientes' => 0, 'medicos' => 0, 'especialidades' => 0, 'atendidos' => 0];
$obrasSociales  = [];
$errorDatos     = null;

try {
    if (!$pdo) {
        throw new PDOException('Sin conexión a la base de datos.');
    }

    // Especialidades con su precio y duración de turno
    $especialidades = $pdo->query(
        "SELECT id_especialidad, nombre, duracion_turno_min, precio_consulta
         FROM   especialidad
         ORDER  BY nombre"
    )->fetchAll();

    // Médicos activos con sus especialidades agrupadas
    $medicos = $pdo->query(
        "SELECT m.matricula, m.nombre, m.apellido,
                GROUP_CONCAT(e.nombre ORDER BY e.nombre SEPARATOR ' · ') AS especialidades
         FROM   medico m
         LEFT   JOIN medico_especialidad me ON me.matricula = m.matricula
         LEFT   JOIN especialidad e         ON e.id_especialidad = me.id_especialidad
         WHERE  m.estado = 'activo'
         GROUP  BY m.matricula, m.nombre, m.apellido
         ORDER  BY m.apellido, m.nombre
         LIMIT  8"
    )->fetchAll();

    // Estadísticas institucionales (todas calculadas, ninguna inventada)
    $stats['pacientes']      = (int) $pdo->query("SELECT COUNT(*) FROM paciente")->fetchColumn();
    $stats['medicos']        = (int) $pdo->query("SELECT COUNT(*) FROM medico WHERE estado = 'activo'")->fetchColumn();
    $stats['especialidades'] = (int) $pdo->query("SELECT COUNT(*) FROM especialidad")->fetchColumn();
    $stats['atendidos']      = (int) $pdo->query(
        "SELECT COUNT(*) FROM turno t
         JOIN estado_turno et ON et.id_estado = t.id_estado
         WHERE et.descripcion = 'Realizado'"
    )->fetchColumn();

    $obrasSociales = $pdo->query("SELECT nombre FROM obra_social ORDER BY nombre")->fetchAll(PDO::FETCH_COLUMN);

} catch (PDOException $ex) {
    // La landing NO debe caerse si falla una consulta: se registra
    // el error y las secciones afectadas quedan vacías.
    error_log('index.php (landing): ' . $ex->getMessage());
    $errorDatos = true;
}

/** Ruta de la foto de un médico, si fue cargada en publico/img/medicos/. */
function fotoMedico(int $matricula): ?string
{
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
        $rel = 'publico/img/medicos/' . $matricula . '.' . $ext;
        if (is_file(__DIR__ . '/' . $rel)) {
            return BASE_URL . $rel;
        }
    }
    return null;
}

/** Foto institucional opcional (hero). */
function fotoInstitucional(string $slug): ?string
{
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
        $rel = 'publico/img/' . $slug . '.' . $ext;
        if (is_file(__DIR__ . '/' . $rel)) {
            return BASE_URL . $rel;
        }
    }
    return null;
}

$heroFoto = fotoInstitucional('hero');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MediTurnos — Turnos médicos online | Centro Médico</title>
<meta name="description" content="Reservá tu turno médico online en minutos. <?= (int)$stats['especialidades'] ?> especialidades, profesionales matriculados y confirmación inmediata. Sin llamadas, sin filas.">
<meta name="theme-color" content="#2563eb">
<meta property="og:title" content="MediTurnos — Turnos médicos online">
<meta property="og:description" content="Reservá tu turno médico online en minutos. Elegí especialidad, profesional y horario.">
<meta property="og:type" content="website">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>publico/css/landing.css?v=<?= @filemtime(__DIR__ . '/publico/css/landing.css') ?: 1 ?>">
<script>
// Habilita las animaciones de aparición SOLO si hay JS y el usuario no
// pidió reducir movimiento. Va en el <head> para que se aplique antes
// del primer pintado (evita el parpadeo del contenido).
// Si este script no corre, el CSS deja todo visible: la página nunca
// queda en blanco por depender de JavaScript.
(function () {
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  if ('IntersectionObserver' in window && !reduce) {
    document.documentElement.classList.add('js-anim');
  }
})();
</script>
</head>
<body>

<a class="skip" href="#contenido">Saltar al contenido principal</a>

<!-- ══════════════ NAVBAR ══════════════ -->
<header class="lp-nav" id="nav">
    <div class="contenedor">
        <a href="<?= BASE_URL ?>" class="logo" aria-label="MediTurnos, inicio">
            <span class="marca" aria-hidden="true">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <path d="M12 6v12M6 12h12"/>
                </svg>
            </span>
            MediTurnos
        </a>

        <nav class="nav-links" aria-label="Navegación principal">
            <a href="#especialidades">Especialidades</a>
            <a href="#medicos">Médicos</a>
            <a href="#como-funciona">Cómo funciona</a>
            <a href="#faq">Preguntas</a>
            <?php if (!$logueado): ?>
            <!-- En pantallas chicas el botón "Ingresar" se oculta de la barra
                 (no entra) y reaparece acá dentro del menú hamburguesa. -->
            <a href="<?= BASE_URL ?>login.php" class="solo-movil">Ingresar a mi cuenta</a>
            <?php endif; ?>
        </nav>

        <div class="nav-acciones">
            <?php if ($logueado): ?>
                <a href="<?= BASE_URL ?>dashboard.php" class="btn btn-primario">
                    Ir a mi panel
                </a>
            <?php else: ?>
                <a href="<?= BASE_URL ?>login.php" class="btn btn-fantasma">Ingresar</a>
                <a href="<?= BASE_URL ?>registro.php" class="btn btn-primario">Reservar turno</a>
            <?php endif; ?>
            <button class="nav-burger" id="burger" aria-label="Abrir menú" aria-expanded="false">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <path d="M3 6h18M3 12h18M3 18h18"/>
                </svg>
            </button>
        </div>
    </div>
</header>

<main id="contenido">

<!-- ══════════════ HERO ══════════════ -->
<?php
// Si se sube publico/img/hero.jpg, se usa como fondo del hero con un velo
// blanco encima para que el texto siga siendo legible (contraste WCAG).
$estiloHero = $heroFoto
    ? ' style="background-image:linear-gradient(rgba(255,255,255,.92),rgba(255,255,255,.97)),url(\'' . e($heroFoto) . '\');background-size:cover;background-position:center"'
    : '';
?>
<section class="hero"<?= $estiloHero ?>>

    <!-- Acceso directo a urgencias: es un <a href="tel:"> real, no un
         adorno. En el celular abre el marcador del teléfono. -->
    <a class="urgencias" href="tel:+541140000000">
        <span class="urgencias-punto" aria-hidden="true"></span>
        Urgencias 24 h
    </a>

    <div class="contenedor hero-centro">
        <?php if ($stats['pacientes'] > 0): ?>
        <span class="hero-badge">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
            <?= number_format($stats['pacientes'], 0, ',', '.') ?> pacientes ya confían en nosotros
        </span>
        <?php endif; ?>

        <h1>Tu salud, <span class="destaque">a un click</span> de distancia</h1>

        <p class="bajada">
            Encontrá tu especialista y reservá tu turno en segundos.
            Sin llamadas, sin filas.
        </p>

        <!-- Buscador: filtra especialidades y médicos en vivo -->
        <form class="buscador" role="search" onsubmit="return irABuscar(event)">
            <div class="campo">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>
                </svg>
                <label for="q" class="skip">Buscar especialidad o médico</label>
                <input type="search" id="q" placeholder="Buscá tu especialidad o médico…" autocomplete="off">
            </div>
            <button type="submit" class="btn btn-primario">Buscar turno</button>
        </form>

        <?php if ($especialidades): ?>
        <p class="hero-chips">
            <span>Populares:</span>
            <?php foreach (array_slice($especialidades, 0, 4) as $esp): ?>
                <a href="#especialidades" class="chip-link"
                   onclick="filtrar('<?= e($esp['nombre']) ?>')"><?= e($esp['nombre']) ?></a>
            <?php endforeach; ?>
        </p>
        <?php endif; ?>
    </div>
</section>

<!-- ══════════════ BARRA DE CONFIANZA (datos reales) ══════════════ -->
<section class="confianza" aria-label="Nuestros números">
    <div class="contenedor">
        <div class="confianza-grid">
            <div>
                <div class="v"><?= number_format($stats['pacientes'], 0, ',', '.') ?>+</div>
                <div class="l">Pacientes registrados</div>
            </div>
            <div>
                <div class="v"><?= (int) $stats['medicos'] ?></div>
                <div class="l">Profesionales activos</div>
            </div>
            <div>
                <div class="v"><?= (int) $stats['especialidades'] ?></div>
                <div class="l">Especialidades</div>
            </div>
            <div>
                <div class="v"><?= number_format($stats['atendidos'], 0, ',', '.') ?></div>
                <div class="l">Consultas realizadas</div>
            </div>
        </div>
    </div>
</section>

<!-- ══════════════ ESPECIALIDADES ══════════════ -->
<section class="seccion" id="especialidades">
    <div class="contenedor">
        <div class="sec-titulo reveal">
            <div class="eyebrow">Especialidades</div>
            <h2>Elegí por qué consultás</h2>
            <p>Todas nuestras especialidades con turnos online y precio informado desde el primer paso.</p>
        </div>

        <?php if ($especialidades): ?>
        <div class="esp-grid" id="grid-especialidades">
            <?php foreach ($especialidades as $esp): ?>
            <a href="<?= BASE_URL ?>login.php" class="esp-card reveal"
               data-nombre="<?= e(strtolower($esp['nombre'])) ?>">
                <span class="ico"><?= iconoEspecialidad($esp['nombre']) ?></span>
                <span class="n"><?= e($esp['nombre']) ?></span>
                <span class="m">Turnos de <?= (int) $esp['duracion_turno_min'] ?> min</span>
                <?php if (!empty($esp['precio_consulta'])): ?>
                <span class="precio">desde $<?= number_format((float) $esp['precio_consulta'], 0, ',', '.') ?></span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
        <p class="sin-resultados" id="sin-esp" hidden style="text-align:center;color:var(--gris);padding:30px">
            No encontramos especialidades con ese nombre.
        </p>
        <?php else: ?>
        <p style="text-align:center;color:var(--gris)">Estamos actualizando nuestras especialidades.</p>
        <?php endif; ?>
    </div>
</section>

<!-- ══════════════ MÉDICOS ══════════════ -->
<section class="seccion alt" id="medicos">
    <div class="contenedor">
        <div class="sec-titulo reveal">
            <div class="eyebrow">Nuestro equipo</div>
            <h2>Conocé a nuestros especialistas</h2>
            <p>Profesionales matriculados, con agenda abierta y disponibilidad real en el sistema.</p>
        </div>

        <?php if ($medicos): ?>
        <div class="med-grid" id="grid-medicos">
            <?php foreach ($medicos as $m):
                $foto      = fotoMedico((int) $m['matricula']);
                $iniciales = strtoupper(mb_substr($m['apellido'], 0, 1) . mb_substr($m['nombre'], 0, 1));
                $nombreCompleto = 'Dr/a. ' . $m['apellido'] . ', ' . $m['nombre'];
            ?>
            <article class="med-card reveal"
                     data-nombre="<?= e(strtolower($m['apellido'] . ' ' . $m['nombre'] . ' ' . ($m['especialidades'] ?? ''))) ?>">
                <div class="med-foto">
                    <?php if ($foto): ?>
                        <img src="<?= e($foto) ?>" alt="Retrato de <?= e($nombreCompleto) ?>" width="300" height="300" loading="lazy">
                    <?php else: ?>
                        <!-- Slot listo: subí publico/img/medicos/<?= (int) $m['matricula'] ?>.jpg -->
                        <span class="iniciales" aria-hidden="true"><?= e($iniciales) ?></span>
                    <?php endif; ?>
                </div>
                <div class="med-info">
                    <div class="n"><?= e($nombreCompleto) ?></div>
                    <div class="esp"><?= e($m['especialidades'] ?: 'Medicina general') ?></div>
                    <div class="mat">Matrícula N.° <?= (int) $m['matricula'] ?></div>
                    <a href="<?= BASE_URL ?>login.php" class="btn btn-fantasma">Ver disponibilidad</a>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <p class="sin-resultados" id="sin-med" hidden style="text-align:center;color:var(--gris);padding:30px">
            No encontramos profesionales con ese nombre.
        </p>
        <?php else: ?>
        <p style="text-align:center;color:var(--gris)">Estamos incorporando nuevos profesionales.</p>
        <?php endif; ?>
    </div>
</section>

<!-- ══════════════ CÓMO FUNCIONA ══════════════ -->
<section class="seccion" id="como-funciona">
    <div class="contenedor">
        <div class="sec-titulo reveal">
            <div class="eyebrow">Cómo funciona</div>
            <h2>Reservá en tres pasos</h2>
            <p>Todo el proceso es online y te lleva menos de dos minutos.</p>
        </div>

        <div class="pasos">
            <div class="paso reveal">
                <div class="num">1</div>
                <h3>Creá tu cuenta</h3>
                <p>Registrate con tu DNI y datos de contacto. Es gratis y queda asociada a tu historial.</p>
            </div>
            <div class="paso reveal">
                <div class="num">2</div>
                <h3>Elegí médico y horario</h3>
                <p>Mirá el calendario con la disponibilidad real de cada profesional y seleccioná el turno que te sirva.</p>
            </div>
            <div class="paso reveal">
                <div class="num">3</div>
                <h3>Confirmá y pagá</h3>
                <p>Aplicamos el descuento de tu obra social y podés abonar online o en recepción.</p>
            </div>
        </div>
    </div>
</section>

<!-- ══════════════ BENEFICIOS ══════════════ -->
<section class="seccion alt">
    <div class="contenedor">
        <div class="sec-titulo reveal">
            <div class="eyebrow">Por qué elegirnos</div>
            <h2>Una experiencia pensada para vos</h2>
        </div>

        <div class="ben-grid">
            <div class="ben reveal">
                <span class="ico" style="background:var(--azul-soft);color:var(--azul)" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                </span>
                <h3>Disponibilidad real</h3>
                <p>El calendario muestra únicamente los horarios libres de cada médico. Si aparece, está disponible.</p>
            </div>
            <div class="ben reveal">
                <span class="ico" style="background:var(--verde-cl);color:var(--verde)" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M12 2 3 6v6c0 5 3.8 9.4 9 10 5.2-.6 9-5 9-10V6z"/><path d="m9 12 2 2 4-4"/></svg>
                </span>
                <h3>Tus datos protegidos</h3>
                <p>Cada paciente accede solo a su propia información. Contraseñas cifradas y sesiones seguras.</p>
            </div>
            <div class="ben reveal">
                <span class="ico" style="background:var(--ambar-cl);color:var(--ambar)" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                </span>
                <h3>Cobertura aplicada</h3>
                <p>Calculamos automáticamente el descuento de tu obra social según el profesional elegido.</p>
            </div>
        </div>
    </div>
</section>

<!-- ══════════════ TESTIMONIOS ══════════════ -->
<section class="seccion">
    <div class="contenedor">
        <div class="sec-titulo reveal">
            <div class="eyebrow">Testimonios</div>
            <h2>Lo que dicen nuestros pacientes</h2>
        </div>

        <div class="test-grid">
            <div class="test reveal">
                <div class="estrellas" aria-label="5 de 5 estrellas">★★★★★</div>
                <p>"Saqué turno con el cardiólogo en dos minutos desde el celular. Llegué y me atendieron a horario."</p>
                <div class="quien">
                    <span class="av" style="background:linear-gradient(135deg,#3b82f6,#1e40af)" aria-hidden="true">LM</span>
                    <span><span class="nm">Lucía M.</span><br><span class="rl">Cardiología</span></span>
                </div>
            </div>
            <div class="test reveal">
                <div class="estrellas" aria-label="5 de 5 estrellas">★★★★★</div>
                <p>"Poder ver el precio con el descuento de mi obra social antes de confirmar me pareció clarísimo."</p>
                <div class="quien">
                    <span class="av" style="background:linear-gradient(135deg,#16a34a,#15803d)" aria-hidden="true">RS</span>
                    <span><span class="nm">Roberto S.</span><br><span class="rl">Clínica Médica</span></span>
                </div>
            </div>
            <div class="test reveal">
                <div class="estrellas" aria-label="5 de 5 estrellas">★★★★★</div>
                <p>"Reprogramé el turno de mi hija sin llamar por teléfono. Muy simple de usar."</p>
                <div class="quien">
                    <span class="av" style="background:linear-gradient(135deg,#d97706,#b45309)" aria-hidden="true">CG</span>
                    <span><span class="nm">Carla G.</span><br><span class="rl">Pediatría</span></span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ══════════════ FAQ ══════════════ -->
<section class="seccion alt" id="faq">
    <div class="contenedor">
        <div class="sec-titulo reveal">
            <div class="eyebrow">Preguntas frecuentes</div>
            <h2>Resolvemos tus dudas</h2>
        </div>

        <div class="faq">
            <?php
            $preguntas = [
                ['¿Necesito registrarme para sacar un turno?',
                 'Sí. Con tu cuenta podés ver tus turnos, cancelarlos y consultar tus pagos. El registro es gratuito y solo requiere DNI, teléfono y email.'],
                ['¿Cómo se calcula el precio de la consulta?',
                 'Cada especialidad tiene un precio de lista. Sobre ese valor se aplica el descuento que tu obra social tiene acordado con el profesional elegido. El total lo ves antes de confirmar.'],
                ['¿Puedo cancelar o reprogramar mi turno?',
                 'Sí, desde la sección Turnos de tu cuenta. Al cancelar, el horario se libera automáticamente para otro paciente y el pago pendiente queda anulado.'],
                ['¿Qué pasa si no pago dentro del plazo?',
                 'Al reservar tenés un plazo para abonar. Si vence sin registrarse el pago, el turno se cancela solo y el horario vuelve a estar disponible.'],
                ['¿Qué obras sociales aceptan?',
                 $obrasSociales
                    ? 'Trabajamos con ' . e(implode(', ', array_slice($obrasSociales, 0, 6))) . '. Consultá el descuento aplicable al elegir el profesional.'
                    : 'Trabajamos con las principales obras sociales. Consultá el descuento aplicable al elegir el profesional.'],
            ];
            foreach ($preguntas as $i => [$q, $a]):
            ?>
            <div class="faq-item reveal">
                <button class="faq-q" aria-expanded="false" aria-controls="faq-a-<?= $i ?>">
                    <span><?= e($q) ?></span>
                    <span class="mas" aria-hidden="true">+</span>
                </button>
                <div class="faq-a" id="faq-a-<?= $i ?>">
                    <p><?= $a ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ══════════════ CTA FINAL ══════════════ -->
<section class="cta">
    <div class="contenedor">
        <h2>¿Listo para tu próxima consulta?</h2>
        <p>Reservá tu turno ahora y recibí la confirmación al instante.</p>
        <div class="cta-botones">
            <a href="<?= BASE_URL ?>registro.php" class="btn btn-lg btn-blanco">Crear cuenta y reservar</a>
            <a href="<?= BASE_URL ?>login.php" class="btn btn-lg btn-borde">Ya tengo cuenta</a>
        </div>
    </div>
</section>

</main>

<!-- ══════════════ FOOTER ══════════════ -->
<footer class="pie">
    <div class="contenedor">
        <div class="pie-grid">
            <div>
                <div class="logo">
                    <span class="marca" aria-hidden="true">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 6v12M6 12h12"/></svg>
                    </span>
                    MediTurnos
                </div>
                <p class="desc">
                    Sistema de gestión de turnos médicos. Reservá online, consultá tu historial
                    y pagá con la cobertura de tu obra social.
                </p>
            </div>
            <div>
                <h4>Especialidades</h4>
                <ul>
                    <?php foreach (array_slice($especialidades, 0, 5) as $esp): ?>
                    <li><a href="#especialidades"><?= e($esp['nombre']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div>
                <h4>Sistema</h4>
                <ul>
                    <li><a href="<?= BASE_URL ?>login.php">Iniciar sesión</a></li>
                    <li><a href="<?= BASE_URL ?>registro.php">Crear cuenta</a></li>
                    <li><a href="#como-funciona">Cómo funciona</a></li>
                    <li><a href="#faq">Preguntas frecuentes</a></li>
                </ul>
            </div>
            <div>
                <h4>Contacto</h4>
                <ul>
                    <li>Av. Siempre Viva 1234</li>
                    <li>Tel. (011) 4000-0000</li>
                    <li>turnos@mediturnos.com</li>
                    <li>Lun a Vie · 8 a 20 hs</li>
                </ul>
            </div>
        </div>
        <div class="pie-bajo">
            <span>© <?= date('Y') ?> MediTurnos — Sistema de Gestión de Turnos Médicos</span>
            <span>Proyecto académico · Licenciatura en Sistemas</span>
        </div>
    </div>
</footer>

<script>
// ── Navbar con sombra al hacer scroll ──────────────────────
const nav = document.getElementById('nav');
addEventListener('scroll', () => nav.classList.toggle('scrolled', scrollY > 10), { passive: true });

// ── Menú móvil: lleva al bloque de navegación por anclas ───
document.getElementById('burger').addEventListener('click', function () {
    const abierto = this.getAttribute('aria-expanded') === 'true';
    this.setAttribute('aria-expanded', String(!abierto));
    document.querySelector('.nav-links').style.cssText = abierto
        ? ''
        : 'display:flex;position:absolute;top:var(--nav-h);left:0;right:0;background:#fff;flex-direction:column;padding:18px 24px;gap:14px;box-shadow:var(--md);border-bottom:1px solid var(--linea)';
});

// ── Buscador: filtra especialidades y médicos en vivo ──────
const input = document.getElementById('q');

function aplicarFiltro(texto) {
    const q = texto.trim().toLowerCase();
    let visEsp = 0, visMed = 0;

    document.querySelectorAll('#grid-especialidades .esp-card').forEach(c => {
        const ok = !q || c.dataset.nombre.includes(q);
        c.style.display = ok ? '' : 'none';
        if (ok) visEsp++;
    });
    document.querySelectorAll('#grid-medicos .med-card').forEach(c => {
        const ok = !q || c.dataset.nombre.includes(q);
        c.style.display = ok ? '' : 'none';
        if (ok) visMed++;
    });

    const sinEsp = document.getElementById('sin-esp');
    const sinMed = document.getElementById('sin-med');
    if (sinEsp) sinEsp.hidden = visEsp !== 0;
    if (sinMed) sinMed.hidden = visMed !== 0;
}

if (input) {
    input.addEventListener('input', () => aplicarFiltro(input.value));
}

function irABuscar(ev) {
    ev.preventDefault();
    aplicarFiltro(input.value);
    document.getElementById('especialidades').scrollIntoView({ behavior: 'smooth' });
    return false;
}

function filtrar(nombre) {
    if (!input) return;
    input.value = nombre;
    aplicarFiltro(nombre);
}

// ── FAQ accesible (acordeón con aria-expanded) ─────────────
document.querySelectorAll('.faq-q').forEach(btn => {
    btn.addEventListener('click', () => {
        const item     = btn.closest('.faq-item');
        const panel    = item.querySelector('.faq-a');
        const abierto  = item.classList.contains('abierto');

        // Cierra los demás (comportamiento acordeón)
        document.querySelectorAll('.faq-item.abierto').forEach(otro => {
            otro.classList.remove('abierto');
            otro.querySelector('.faq-a').style.maxHeight = null;
            otro.querySelector('.faq-q').setAttribute('aria-expanded', 'false');
        });

        if (!abierto) {
            item.classList.add('abierto');
            panel.style.maxHeight = panel.scrollHeight + 'px';
            btn.setAttribute('aria-expanded', 'true');
        }
    });
});

// ── Aparición progresiva al hacer scroll ───────────────────
const io = new IntersectionObserver((entradas) => {
    entradas.forEach(en => {
        if (en.isIntersecting) {
            en.target.classList.add('visible');
            io.unobserve(en.target);
        }
    });
}, { threshold: .12, rootMargin: '0px 0px -40px 0px' });

document.querySelectorAll('.reveal').forEach((el, i) => {
    el.style.transitionDelay = (i % 4 * 70) + 'ms';   // efecto escalonado por fila
    io.observe(el);
});

// RED DE SEGURIDAD: si por cualquier motivo el observer no llegara a
// disparar (pestaña en segundo plano, render pausado, navegador raro),
// a los 1,5 s se muestra todo igual. El contenido nunca queda oculto.
setTimeout(() => {
    document.querySelectorAll('.reveal:not(.visible)').forEach(el => el.classList.add('visible'));
}, 1500);
</script>
</body>
</html>

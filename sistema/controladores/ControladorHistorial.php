<?php
// sistema/controladores/ControladorHistorial.php
// -----------------------------------------------------------------
// Historial clínico: lo que el médico registra y lo que el paciente lee.
//
// Es un controlador-URL con switch($accion), como los otros diez. Las
// acciones se reparten en dos mundos:
//
//   Médico  → consulta, guardarConsulta, pedirEstudio, subirResultado
//   Ambos   → descargar (con el permiso verificado adentro)
//
// El historial del PACIENTE no vive acá sino en historial.php, en la
// raíz, junto a las otras pantallas del Área del Paciente: es un punto
// de entrada de su cuenta, no una acción sobre un recurso.
// -----------------------------------------------------------------

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/notificaciones.php';
require_once __DIR__ . '/../../includes/subida_estudio.php';
require_once __DIR__ . '/../modelos/Historial.php';
require_once __DIR__ . '/../modelos/Turno.php';

verificarSesion();

$modelo      = new Historial($pdo);
$modeloTurno = new Turno($pdo);
$accion      = $_GET['accion'] ?? 'index';

$URL     = BASE_URL . 'sistema/controladores/ControladorHistorial.php';
$URL_T   = BASE_URL . 'sistema/controladores/ControladorTurno.php';
$rol     = $_SESSION['rol'] ?? '';
$miMat   = (int) ($_SESSION['matricula'] ?? 0);

/**
 * Trae un turno que ESTE médico atendió, o corta.
 *
 * El mismo control se necesita en las cuatro acciones del médico, y
 * repetido en cada una sería fácil de olvidar actualizar en alguna — que
 * es exactamente cómo aparecen los agujeros de IDOR. La matrícula sale
 * siempre de la sesión, nunca de la petición.
 */
function turnoDelMedico(Turno $modeloTurno, int $idTurno, string $URL_T): array
{
    $t = $modeloTurno->detalleDeTurno($idTurno);

    if (!$t) {
        header('Location: ' . BASE_URL . 'dashboard.php?err=' . urlencode('No encontramos ese turno.'));
        exit;
    }
    if ((int) $t['matricula'] !== (int) ($_SESSION['matricula'] ?? 0)) {
        http_response_code(403);
        include __DIR__ . '/../vistas/layouts/403.php';
        exit;
    }
    return $t;
}

switch ($accion) {

    // ── Ficha clínica de un turno (la escribe el médico) ─────
    case 'consulta':
        verificarRol(['medico']);
        $turno      = turnoDelMedico($modeloTurno, (int) ($_GET['id'] ?? 0), $URL_T);
        $consulta   = $modelo->consultaDeTurno((int) $turno['id_turno']);
        $estudios   = $modelo->estudiosDePaciente((int) $turno['id_paciente']);
        $mensaje    = !empty($_GET['err']) ? urldecode($_GET['err']) : null;
        require __DIR__ . '/../vistas/historial/consulta.php';
        break;

    // ── Guardar la ficha ─────────────────────────────────────
    case 'guardarConsulta':
        verificarRol(['medico']);
        csrf_verificar();

        $turno = turnoDelMedico($modeloTurno, (int) ($_POST['id_turno'] ?? 0), $URL_T);
        $volver = $URL . '?accion=consulta&id=' . (int) $turno['id_turno'];

        // Sólo se registra sobre una consulta que YA ocurrió. Dejar
        // escribir el diagnóstico de un turno que todavía no pasó sería
        // permitir inventar una atención que no existió.
        if ($turno['estado'] !== 'Realizado') {
            header('Location: ' . $volver . '&err='
                . urlencode('Sólo se puede registrar la ficha de una consulta ya realizada.'));
            exit;
        }

        $motivo = is_string($_POST['motivo_consulta'] ?? null) ? trim($_POST['motivo_consulta']) : '';
        if ($motivo === '') {
            header('Location: ' . $volver . '&err=' . urlencode('El motivo de consulta es obligatorio.'));
            exit;
        }

        try {
            $eraNueva = $modelo->consultaDeTurno((int) $turno['id_turno']) === false;

            $modelo->guardarConsulta((int) $turno['id_turno'], $miMat, [
                'motivo_consulta' => $motivo,
                'diagnostico'     => is_string($_POST['diagnostico']  ?? null) ? $_POST['diagnostico']  : null,
                'indicaciones'    => is_string($_POST['indicaciones'] ?? null) ? $_POST['indicaciones'] : null,
            ]);

            // Se avisa sólo la PRIMERA vez. Si el médico vuelve a entrar
            // a corregir una coma, al paciente no le llega un correo por
            // cada retoque.
            if ($eraNueva) {
                obtenerNotificador($pdo)->notificarPaciente((int) $turno['id_paciente'], new Aviso(
                    TipoAviso::RESULTADOS_LISTOS,
                    'Ficha de tu consulta disponible',
                    'Dr/a. ' . $turno['medico'] . ' registró la ficha de tu consulta del '
                        . date('d/m/Y', strtotime($turno['fecha'])) . '.',
                    'historial.php',
                    (int) $turno['id_turno'],
                    [
                        'asunto'   => 'Ya podés ver la ficha de tu consulta',
                        'parrafos' => ['Tu profesional registró el detalle de la atención. '
                                     . 'Podés leerlo cuando quieras desde tu historial.'],
                    ],
                    null,
                    'Ver mi historial'
                ));
            }
        } catch (PDOException $e) {
            error_log('ControladorHistorial guardarConsulta: ' . $e->getMessage());
            header('Location: ' . $volver . '&err=' . urlencode('No se pudo guardar la ficha.'));
            exit;
        }

        header('Location: ' . $volver . '&msg=guardada');
        exit;

    // ── Pedir un estudio ─────────────────────────────────────
    case 'pedirEstudio':
        verificarRol(['medico']);
        csrf_verificar();

        $turno  = turnoDelMedico($modeloTurno, (int) ($_POST['id_turno'] ?? 0), $URL_T);
        $volver = $URL . '?accion=consulta&id=' . (int) $turno['id_turno'];

        $tipo   = is_string($_POST['tipo']   ?? null) ? trim($_POST['tipo'])   : '';
        $nombre = is_string($_POST['nombre'] ?? null) ? trim($_POST['nombre']) : '';

        if ($tipo === '' || $nombre === '') {
            header('Location: ' . $volver . '&err=' . urlencode('Indicá el tipo y el nombre del estudio.'));
            exit;
        }

        try {
            $idEstudio = $modelo->solicitarEstudio(
                (int) $turno['id_paciente'], $miMat,
                ['tipo' => $tipo, 'nombre' => $nombre],
                (int) $turno['id_turno']
            );

            obtenerNotificador($pdo)->notificarPaciente((int) $turno['id_paciente'], new Aviso(
                TipoAviso::RECETA_NUEVA,
                'Te pidieron un estudio',
                'Dr/a. ' . $turno['medico'] . ' te solicitó: ' . $nombre . '.',
                'historial.php',
                $idEstudio,
                [
                    'asunto'   => 'Tenés un estudio pendiente',
                    'parrafos' => ['Cuando el resultado esté cargado te vamos a avisar.'],
                    'datos'    => ['Estudio' => $nombre, 'Tipo' => $tipo,
                                   'Solicitado por' => 'Dr/a. ' . $turno['medico']],
                ],
                null,
                'Ver mi historial'
            ));
        } catch (PDOException $e) {
            error_log('ControladorHistorial pedirEstudio: ' . $e->getMessage());
            header('Location: ' . $volver . '&err=' . urlencode('No se pudo registrar el pedido.'));
            exit;
        }

        header('Location: ' . $volver . '&msg=estudio_pedido');
        exit;

    // ── Adjuntar el resultado de un estudio ──────────────────
    case 'subirResultado':
        verificarRol(['medico']);
        csrf_verificar();

        $turno  = turnoDelMedico($modeloTurno, (int) ($_POST['id_turno'] ?? 0), $URL_T);
        $volver = $URL . '?accion=consulta&id=' . (int) $turno['id_turno'];

        $estudio = $modelo->estudioConDueno((int) ($_POST['id_estudio'] ?? 0));
        // El estudio tiene que ser del MISMO paciente del turno: si no,
        // un médico podría adjuntarle un resultado a cualquiera armando
        // el POST a mano.
        if (!$estudio || (int) $estudio['id_paciente'] !== (int) $turno['id_paciente']) {
            header('Location: ' . $volver . '&err=' . urlencode('Ese estudio no es de este paciente.'));
            exit;
        }

        // Y además tiene que haberlo pedido ESTE profesional. Ver y
        // modificar son permisos distintos: un médico que atendió al
        // paciente puede leer todos sus estudios, pero sólo carga el
        // resultado de los que él mismo solicitó.
        //
        // Sin esto, cualquier médico con un turno del paciente podía
        // reemplazar el resultado que había subido otro —y el archivo
        // original se borraba del disco en el mismo paso—.
        if (!$modelo->estudioEsDe((int) $estudio['id_estudio'], (int) $turno['matricula'])) {
            header('Location: ' . $volver . '&err='
                . urlencode('Ese estudio lo pidió otro profesional: sólo él puede cargar el resultado.'));
            exit;
        }

        $subida  = new SubidaEstudio();
        $archivo = $subida->procesar($_FILES['resultado'] ?? null);
        if ($archivo === null) {
            header('Location: ' . $volver . '&err=' . urlencode($subida->error() ?? 'No se pudo subir el archivo.'));
            exit;
        }

        // ── EL try ENVUELVE SÓLO LA ESCRITURA ────────────────────
        // Antes abarcaba también la notificación, y eso era un error
        // sutil pero grave: el UPDATE va en autocommit, así que apenas
        // vuelve, la fila YA apunta al archivo nuevo y el viejo ya se
        // borró — no hay vuelta atrás. Si después fallaba la
        // notificación, el catch borraba el archivo NUEVO y dejaba la
        // fila apuntando a un archivo inexistente: el resultado del
        // paciente, perdido, por un problema de correo.
        //
        // El borrado compensatorio sólo tiene sentido mientras la base
        // todavía no confirmó nada.
        try {
            // El archivo anterior se borra DESPUÉS de que la base
            // confirmó el cambio: al revés, un fallo del UPDATE dejaría
            // la fila apuntando a un archivo que ya no existe.
            $anterior = $modelo->adjuntarResultado((int) $estudio['id_estudio'], $archivo);
        } catch (PDOException $e) {
            // La base falló: el archivo recién subido queda huérfano y
            // se borra para no dejar basura en el disco.
            $subida->eliminar($archivo);
            error_log('ControladorHistorial subirResultado: ' . $e->getMessage());
            header('Location: ' . $volver . '&err=' . urlencode('No se pudo guardar el resultado.'));
            exit;
        }

        if ($anterior !== null) {
            $subida->eliminar($anterior);
        }

        // Desde acá el resultado ya está guardado. Avisar es un efecto
        // colateral: si falla, se registra y se sigue — nunca se
        // deshace lo que ya quedó bien.
        try {
            obtenerNotificador($pdo)->notificarPaciente((int) $estudio['id_paciente'], new Aviso(
                TipoAviso::RESULTADOS_LISTOS,
                'Resultados disponibles',
                'Ya podés ver el resultado de: ' . $estudio['nombre'] . '.',
                'historial.php',
                (int) $estudio['id_estudio'],
                [
                    'asunto'   => 'Tus resultados ya están disponibles',
                    'parrafos' => ['El resultado quedó cargado en tu historial. '
                                 . 'Por seguridad no lo adjuntamos a este correo: '
                                 . 'entrá a tu cuenta para verlo o descargarlo.'],
                    'datos'    => ['Estudio' => $estudio['nombre'], 'Tipo' => $estudio['tipo']],
                ],
                null,
                'Ver mis resultados'
            ));
        } catch (Throwable $e) {
            error_log('ControladorHistorial avisarResultado: ' . $e->getMessage());
        }

        header('Location: ' . $volver . '&msg=resultado_ok');
        exit;

    // ── Descargar / ver un resultado ─────────────────────────
    // Es la razón por la que los archivos viven fuera de la carpeta
    // pública: acá se decide QUIÉN puede verlos antes de entregar un
    // solo byte.
    case 'descargar':
        $estudio = $modelo->estudioConDueno((int) ($_GET['id'] ?? 0));

        if (!$estudio || empty($estudio['archivo'])) {
            http_response_code(404);
            exit('No encontramos ese resultado.');
        }

        if (!$modelo->puedeVerEstudio($estudio, $rol,
                isset($_SESSION['id_paciente']) ? (int) $_SESSION['id_paciente'] : null,
                $miMat ?: null)) {
            http_response_code(403);
            include __DIR__ . '/../vistas/layouts/403.php';
            exit;
        }

        $subida = new SubidaEstudio();
        $ruta   = $subida->rutaDe($estudio['archivo']);
        if ($ruta === null) {
            http_response_code(404);
            exit('El archivo no está disponible.');
        }

        // 'inline' para que el navegador lo muestre (un PDF se abre en
        // el visor, una imagen se ve): descargar a la fuerza obligaría a
        // guardar en el disco algo que quizá sólo se quiere mirar.
        $disposicion = ($_GET['descargar'] ?? '') === '1' ? 'attachment' : 'inline';

        // Nombre legible para quien lo guarde, saneado: el nombre del
        // estudio lo escribió una persona y puede traer comillas o
        // saltos de línea que romperían la cabecera.
        $legible = preg_replace('/[^A-Za-z0-9 ._-]/', '', $estudio['nombre']);
        $legible = trim($legible) !== '' ? trim($legible) : 'estudio';
        $legible .= '.' . pathinfo($estudio['archivo'], PATHINFO_EXTENSION);

        header('Content-Type: ' . SubidaEstudio::tipoDe($estudio['archivo']));
        header('Content-Length: ' . filesize($ruta));
        header('Content-Disposition: ' . $disposicion . '; filename="' . $legible . '"');
        // Información de salud: que no quede cacheada en proxies.
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($ruta);
        exit;

    default:
        header('Location: ' . BASE_URL . 'dashboard.php');
        exit;
}

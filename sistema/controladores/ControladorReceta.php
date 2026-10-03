<?php
// sistema/controladores/ControladorReceta.php
// -----------------------------------------------------------------
// Recetas: lo que el médico prescribe y lo que el paciente pide de
// nuevo.
//
// Es un controlador-URL con switch($accion), como los otros once. Las
// acciones se reparten en tres mundos:
//
//   Médico   → nueva, emitir, anular, renovaciones, resolver
//   Paciente → solicitar
//   Ambos    → ver (con el permiso verificado adentro)
//
// El LISTADO del paciente no vive acá sino en recetas.php, en la raíz,
// junto a historial.php y las demás pantallas del Área del Paciente: es
// un punto de entrada de su cuenta, no una acción sobre un recurso.
//
// ── UNA ACLARACIÓN QUE CONVIENE DEJAR ESCRITA ────────────────
// Esto NO es una receta electrónica válida. Una receta con validez legal
// en Argentina necesita firma digital del profesional y estar asentada
// en un registro habilitado; acá no hay ni una cosa ni la otra. Lo que
// el sistema hace es llevar el registro interno de lo prescripto y
// resolver el circuito de renovación sin que el paciente tenga que
// sacar un turno para pedirla. Está dicho también en la vista, para que
// nadie la lleve a una farmacia creyendo que sirve.
// -----------------------------------------------------------------

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/notificaciones.php';
require_once __DIR__ . '/../../includes/guardias.php';
require_once __DIR__ . '/../modelos/Receta.php';
require_once __DIR__ . '/../modelos/Turno.php';
require_once __DIR__ . '/../modelos/Historial.php';

verificarSesion();

$modelo      = new Receta($pdo);
$modeloTurno = new Turno($pdo);
$modeloHist  = new Historial($pdo);
$accion      = $_GET['accion'] ?? 'index';

$URL   = BASE_URL . 'sistema/controladores/ControladorReceta.php';
$rol   = $_SESSION['rol'] ?? '';
$miMat = (int) ($_SESSION['matricula'] ?? 0);
$miPac = isset($_SESSION['id_paciente']) ? (int) $_SESSION['id_paciente'] : null;

/**
 * Arma la lista de medicamentos a partir del POST.
 *
 * El formulario manda un arreglo paralelo por campo (med_nombre[],
 * med_dosis[]…) y acá se transponen a una lista de medicamentos. Es la
 * forma natural de un formulario con renglones repetidos en HTML sin
 * JavaScript.
 *
 * Cada acceso comprueba que lo recibido sea un arreglo y que el valor
 * del renglón sea una cadena. No es paranoia: un POST armado a mano con
 * `med_nombre[0][]=x` hace que el renglón sea un arreglo, y `trim()`
 * sobre un arreglo es un 500. Es la falla que todavía tienen los
 * formularios viejos del proyecto y está anotada en la deuda técnica.
 */
function medicamentosDelPost(array $post): array
{
    $campos = ['nombre' => 'med_nombre', 'presentacion' => 'med_pres',
               'dosis'  => 'med_dosis',  'frecuencia'   => 'med_frec',
               'duracion' => 'med_dur',  'cantidad'     => 'med_cant'];

    $nombres = is_array($post['med_nombre'] ?? null) ? $post['med_nombre'] : [];

    // Un techo a los renglones. Sin él, un POST con diez mil renglones
    // son diez mil INSERT dentro de una transacción.
    $cuantos = min(count($nombres), 20);
    $items   = [];

    for ($i = 0; $i < $cuantos; $i++) {
        $item = [];
        foreach ($campos as $clave => $campo) {
            $arr = is_array($post[$campo] ?? null) ? $post[$campo] : [];
            $v   = $arr[$i] ?? null;
            $item[$clave] = is_string($v) ? $v : '';
        }
        $items[] = $item;
    }

    return $items;
}

switch ($accion) {

    // ── Formulario de nueva receta (lado médico) ─────────────
    case 'nueva':
        verificarRol(['medico']);
        $turno   = turnoDelMedico($modeloTurno, (int) ($_GET['turno'] ?? 0));
        $recetas = $modelo->deDelMedicoYPaciente($miMat, (int) $turno['id_paciente']);
        $mensaje = is_string($_GET['err'] ?? null) ? $_GET['err'] : null;
        require __DIR__ . '/../vistas/recetas/nueva.php';
        break;

    // ── Emitir la receta ────────────────────────────────────
    case 'emitir':
        verificarRol(['medico']);
        csrf_verificar();

        $turno  = turnoDelMedico($modeloTurno, (int) ($_POST['id_turno'] ?? 0));
        $volver = $URL . '?accion=nueva&turno=' . (int) $turno['id_turno'];

        // Prescribir es un acto de la atención: se puede sobre un turno
        // que YA ocurrió, no sobre uno agendado para la semana que
        // viene. Es el mismo criterio que la ficha clínica.
        if ($turno['estado'] !== 'Realizado') {
            header('Location: ' . $volver . '&err='
                . urlencode('Sólo se puede emitir una receta de una consulta ya realizada.'));
            exit;
        }

        try {
            $idReceta = $modelo->emitir(
                (int) $turno['id_paciente'],
                $miMat,
                [
                    'diagnostico'  => $_POST['diagnostico']  ?? null,
                    'indicaciones' => $_POST['indicaciones'] ?? null,
                    'dias'         => $_POST['dias']         ?? null,
                ],
                medicamentosDelPost($_POST),
                (int) $turno['id_turno']
            );
        } catch (InvalidArgumentException $e) {
            // Error de carga: se le dice qué falta y vuelve al formulario.
            header('Location: ' . $volver . '&err=' . urlencode($e->getMessage()));
            exit;
        } catch (PDOException $e) {
            error_log('ControladorReceta emitir: ' . $e->getMessage());
            header('Location: ' . $volver . '&err=' . urlencode('No se pudo emitir la receta.'));
            exit;
        }

        // Desde acá la receta ya está guardada. Avisar es un efecto
        // colateral: si el correo falla, se registra y se sigue — nunca
        // se deshace lo que ya quedó bien.
        try {
            $r     = $modelo->conDueno($idReceta);
            $items = $modelo->itemsDe($idReceta);

            $detalle = [];
            foreach ($items as $i) {
                $detalle[$i['nombre']] = trim($i['dosis'] . ', ' . $i['frecuencia']
                    . ($i['duracion'] ? ', ' . $i['duracion'] : ''));
            }

            obtenerNotificador($pdo)->notificarPaciente((int) $turno['id_paciente'], new Aviso(
                TipoAviso::RECETA_NUEVA,
                'Tenés una receta nueva',
                'Dr/a. ' . $turno['medico'] . ' te emitió una receta con '
                    . count($items) . ' medicamento' . (count($items) === 1 ? '' : 's') . '.',
                'recetas.php',
                $idReceta,
                [
                    'asunto'    => 'Tenés una receta nueva en MediTurnos',
                    'parrafos'  => ['Tu profesional emitió una receta a tu nombre. '
                                  . 'Podés verla y descargarla desde tu cuenta.'],
                    'datos'     => $detalle,
                    // El vencimiento va destacado: es el dato que define
                    // si todavía sirve, y el que el paciente va a mirar.
                    // 'destacado' es un par etiqueta/valor, no un texto
                    // suelto — ver emailPlantilla().
                    'destacado' => ['etiqueta' => 'Válida hasta',
                                    'valor'    => date('d/m/Y', strtotime($r['vence_el']))],
                    'nota'      => 'Este documento es el registro interno de la clínica. '
                                 . 'No reemplaza a una receta con firma del profesional.',
                ],
                null,
                'Ver mi receta'
            ));
        } catch (Throwable $e) {
            error_log('ControladorReceta avisarReceta: ' . $e->getMessage());
        }

        header('Location: ' . $URL . '?accion=ver&id=' . $idReceta . '&msg=emitida');
        exit;

    // ── Anular una receta ───────────────────────────────────
    case 'anular':
        verificarRol(['medico']);
        csrf_verificar();

        $idReceta = (int) ($_POST['id_receta'] ?? 0);
        $r        = $modelo->conDueno($idReceta);
        $volver   = $URL . '?accion=ver&id=' . $idReceta;

        if (!$r) {
            header('Location: ' . BASE_URL . 'dashboard.php?err='
                . urlencode('No encontramos esa receta.'));
            exit;
        }
        // Sólo el profesional que la firmó. El UPDATE del modelo lo
        // verifica igual en su WHERE; esto está para responder un 403 en
        // vez de un "no se pudo" que no explicaría nada.
        if ((int) $r['matricula'] !== $miMat) {
            cortar403();
        }

        if (!$modelo->anular($idReceta, $miMat, $_POST['motivo'] ?? null)) {
            header('Location: ' . $volver . '&err='
                . urlencode('Esa receta ya estaba anulada.'));
            exit;
        }

        try {
            obtenerNotificador($pdo)->notificarPaciente((int) $r['id_paciente'], new Aviso(
                TipoAviso::RECETA_ANULADA,
                'Una receta tuya fue anulada',
                'Dr/a. ' . $r['medico'] . ' anuló la receta del '
                    . date('d/m/Y', strtotime($r['emitida_el'])) . '.',
                'recetas.php',
                $idReceta,
                [
                    'asunto'   => 'Una de tus recetas fue anulada',
                    'parrafos' => ['Tu profesional dio de baja esta receta, así que ya no '
                                 . 'hay que usarla. Si necesitás una nueva, agendá una consulta.'],
                    'datos'    => array_filter([
                        'Emitida el' => date('d/m/Y', strtotime($r['emitida_el'])),
                        'Medicamentos' => $r['medicamentos'] ?? '',
                        'Motivo'     => $r['anulada_motivo'] ?? '',
                    ]),
                ],
                null,
                'Ver mis recetas'
            ));
        } catch (Throwable $e) {
            error_log('ControladorReceta avisarAnulada: ' . $e->getMessage());
        }

        header('Location: ' . $volver . '&msg=anulada');
        exit;

    // ── Ver una receta (paciente, su médico, o el mostrador) ─
    case 'ver':
        $r = $modelo->conDueno((int) ($_GET['id'] ?? 0));

        // Receta inexistente: se vuelve al panel con un aviso, no se
        // muestra la pantalla de 403. Esa pantalla dice "no tenés
        // permisos", que acá sería falso y mandaría a la persona a pedir
        // un permiso que ya tiene — lo que no tiene es la receta.
        if (!$r) {
            header('Location: ' . BASE_URL . 'dashboard.php?err='
                . urlencode('No encontramos esa receta.'));
            exit;
        }

        // "¿Este médico atendió a esta persona?" se consulta a
        // Historial, que es donde vive esa regla. Receta no la duplica:
        // la recibe ya resuelta. Ver el comentario de Receta::puedeVer().
        $atendio = $rol === 'medico' && $miMat > 0
            && $modeloHist->atendioAlPaciente($miMat, (int) $r['id_paciente']);

        if (!$modelo->puedeVer($r, $rol, $miPac, $miMat ?: null, $atendio)) {
            cortar403();
        }

        $items   = $modelo->itemsDe((int) $r['id_receta']);
        $noRenov = $modelo->motivoNoRenovable($r);
        $mensaje = is_string($_GET['err'] ?? null) ? $_GET['err'] : null;
        require __DIR__ . '/../vistas/recetas/ver.php';
        break;

    // ── El paciente pide la renovación ──────────────────────
    case 'solicitar':
        verificarRol(['paciente']);
        csrf_verificar();

        $idReceta = (int) ($_POST['id_receta'] ?? 0);
        $r        = $modelo->conDueno($idReceta);
        $volver   = $URL . '?accion=ver&id=' . $idReceta;

        // La receta tiene que ser SUYA. Sin esto, cambiar el id en el
        // POST pediría la renovación de la receta de otra persona.
        if (!$r || $miPac === null || (int) $r['id_paciente'] !== $miPac) {
            cortar403();
        }

        // Las mismas reglas que decidieron si se mostraba el botón. La
        // vista puede esconderlo; lo que impide la operación es esto.
        $motivoNo = $modelo->motivoNoRenovable($r);
        if ($motivoNo !== null) {
            header('Location: ' . $volver . '&err=' . urlencode($motivoNo));
            exit;
        }

        try {
            $idRenov = $modelo->solicitarRenovacion($idReceta, $miPac, $_POST['motivo'] ?? null);
        } catch (PDOException $e) {
            error_log('ControladorReceta solicitar: ' . $e->getMessage());
            header('Location: ' . $volver . '&err=' . urlencode('No se pudo registrar el pedido.'));
            exit;
        }

        // null = el UNIQUE del motor rechazó un segundo pedido pendiente.
        // Pasa con dos clics rápidos: la verificación de arriba leyó que
        // no había ninguno porque el otro pedido todavía no había
        // terminado de insertarse.
        if ($idRenov === null) {
            header('Location: ' . $volver . '&err='
                . urlencode('Ya tenías un pedido de renovación esperando respuesta.'));
            exit;
        }

        try {
            obtenerNotificador($pdo)->notificarMedico((int) $r['matricula'], new Aviso(
                TipoAviso::REFILL_SOLICITADO,
                'Pedido de renovación de receta',
                // La sesión guarda nombre y apellido por separado, no un
                // "nombre completo": se arma acá, con una salida por
                // omisión si la sesión viniera incompleta.
                (trim(($_SESSION['apellido'] ?? '') . ', ' . ($_SESSION['nombre'] ?? ''), " ,")
                    ?: 'Un paciente')
                    . ' pidió renovar la receta del ' . date('d/m/Y', strtotime($r['emitida_el'])) . '.',
                'sistema/controladores/ControladorReceta.php?accion=renovaciones',
                $idRenov,
                [
                    'asunto'   => 'Tenés un pedido de renovación para responder',
                    'parrafos' => ['Un paciente tuyo pidió renovar una receta. '
                                 . 'Podés aprobarla o rechazarla desde tu panel.'],
                    'datos'    => array_filter([
                        'Receta del'   => date('d/m/Y', strtotime($r['emitida_el'])),
                        'Medicamentos' => $r['medicamentos'] ?? '',
                        'Motivo'       => is_string($_POST['motivo'] ?? null)
                                          ? trim($_POST['motivo']) : '',
                    ]),
                ],
                null,
                'Ver los pedidos'
            ));
        } catch (Throwable $e) {
            error_log('ControladorReceta avisarPedido: ' . $e->getMessage());
        }

        header('Location: ' . $volver . '&msg=pedida');
        exit;

    // ── Bandeja de pedidos del médico ───────────────────────
    case 'renovaciones':
        verificarRol(['medico']);
        $pedidos = $modelo->renovacionesPendientes($miMat);
        $mensaje = is_string($_GET['err'] ?? null) ? $_GET['err'] : null;
        require __DIR__ . '/../vistas/recetas/renovaciones.php';
        break;

    // ── El médico aprueba o rechaza ─────────────────────────
    case 'resolver':
        verificarRol(['medico']);
        csrf_verificar();

        $idRenov  = (int) ($_POST['id_renovacion'] ?? 0);
        $aprobar  = ($_POST['decision'] ?? '') === 'aprobar';
        $volver   = $URL . '?accion=renovaciones';

        // Se lee ANTES de resolver porque después el pedido ya no está
        // pendiente, y hacen falta el paciente y la receta para avisar.
        $pedido = $modelo->renovacionConDatos($idRenov);
        if (!$pedido) {
            header('Location: ' . $volver . '&err=' . urlencode('No encontramos ese pedido.'));
            exit;
        }
        if ((int) $pedido['receta_matricula'] !== $miMat) {
            cortar403();
        }

        try {
            $idNueva = $modelo->resolverRenovacion(
                $idRenov, $miMat, $aprobar, $_POST['respuesta'] ?? null
            );
        } catch (RuntimeException $e) {
            // Ya resuelto, o de otro profesional: el modelo lo verifica
            // de nuevo con la fila bloqueada, que es lo que cubre el caso
            // de dos pestañas resolviendo el mismo pedido a la vez.
            header('Location: ' . $volver . '&err=' . urlencode($e->getMessage()));
            exit;
        } catch (PDOException $e) {
            error_log('ControladorReceta resolver: ' . $e->getMessage());
            header('Location: ' . $volver . '&err=' . urlencode('No se pudo responder el pedido.'));
            exit;
        }

        try {
            $notificador = obtenerNotificador($pdo);

            if ($aprobar && $idNueva !== null) {
                $nueva = $modelo->conDueno($idNueva);
                $notificador->notificarPaciente((int) $pedido['id_paciente'], new Aviso(
                    TipoAviso::REFILL_APROBADO,
                    'Tu renovación fue aprobada',
                    'Dr/a. ' . $pedido['medico'] . ' aprobó la renovación. Ya tenés la receta nueva.',
                    'sistema/controladores/ControladorReceta.php?accion=ver&id=' . $idNueva,
                    $idNueva,
                    [
                        'asunto'    => 'Tu renovación fue aprobada',
                        'parrafos'  => ['Emitimos una receta nueva con los mismos medicamentos. '
                                      . 'La anterior queda en tu historial como registro.'],
                        'datos'     => array_filter([
                            'Medicamentos' => $nueva['medicamentos'] ?? '',
                            'Profesional'  => 'Dr/a. ' . $pedido['medico'],
                        ]),
                        'destacado' => ['etiqueta' => 'Válida hasta',
                                        'valor'    => date('d/m/Y', strtotime($nueva['vence_el']))],
                    ],
                    null,
                    'Ver la receta nueva'
                ));
            } else {
                $notificador->notificarPaciente((int) $pedido['id_paciente'], new Aviso(
                    TipoAviso::REFILL_RECHAZADO,
                    'Tu renovación no fue aprobada',
                    'Dr/a. ' . $pedido['medico'] . ' no aprobó el pedido de renovación.',
                    'recetas.php',
                    $idRenov,
                    [
                        'asunto'   => 'Sobre tu pedido de renovación',
                        'parrafos' => ['Tu profesional prefiere verte antes de volver a '
                                     . 'prescribir. Podés agendar una consulta desde tu cuenta.'],
                        'datos'    => array_filter([
                            'Respuesta del profesional' => is_string($_POST['respuesta'] ?? null)
                                                           ? trim($_POST['respuesta']) : '',
                        ]),
                    ],
                    null,
                    'Agendar una consulta'
                ));
            }
        } catch (Throwable $e) {
            error_log('ControladorReceta avisarResolucion: ' . $e->getMessage());
        }

        header('Location: ' . $volver . '&msg=' . ($aprobar ? 'aprobada' : 'rechazada'));
        exit;

    // ── Sin acción: cada rol a donde le sirve ───────────────
    default:
        header('Location: ' . ($rol === 'paciente'
            ? BASE_URL . 'recetas.php'
            : $URL . '?accion=renovaciones'));
        exit;
}

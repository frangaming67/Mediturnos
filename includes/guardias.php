<?php
// includes/guardias.php
// -----------------------------------------------------------------
// Controles de acceso que necesita MÁS DE UN controlador.
//
// POR QUÉ EXISTE ESTE ARCHIVO
// `turnoDelMedico()` nació dentro de ControladorHistorial.php, donde
// era el mismo control repetido en sus cuatro acciones. Con la etapa 5
// pasó a necesitarlo también ControladorReceta.php, y copiarlo allá
// habría dejado dos versiones del mismo control de autorización en dos
// archivos.
//
// Eso es exactamente cómo se reabre un agujero ya cerrado: alguien
// endurece la regla en un archivo y la copia del otro se queda con la
// versión vieja. Ya pasó una vez en este proyecto con
// `Historial::atendioAlPaciente()`, que contaba cualquier turno en vez
// de sólo los realizados.
//
// Vive en includes/ y no en sistema/ por el mismo motivo que auth.php:
// no es un modelo, ni un controlador, ni una vista. Es infraestructura
// transversal.
// -----------------------------------------------------------------

/**
 * Trae un turno que ESTE médico atiende, o corta la ejecución.
 *
 * La matrícula sale SIEMPRE de la sesión, nunca de la petición: si
 * viniera por GET o POST, cualquiera podría leer la agenda de otro
 * profesional cambiando un número en la URL.
 *
 * Distingue dos respuestas a propósito:
 *   · turno inexistente → vuelve al panel con un aviso;
 *   · turno de otro médico → 403.
 *
 * Y no al revés. Responder 404 a un turno ajeno sería más discreto,
 * pero acá el 403 es correcto: el turno existe y el acceso está
 * denegado. La enumeración que habilita es inofensiva —se aprende que
 * un id de turno existe, sin un solo dato de él—, y en cambio un 404
 * confundiría a un profesional que entró a un enlace viejo.
 *
 * @return array La fila del turno (de v_turnos_detalle).
 */
function turnoDelMedico(Turno $modeloTurno, int $idTurno): array
{
    $t = $modeloTurno->detalleDeTurno($idTurno);

    if (!$t) {
        header('Location: ' . BASE_URL . 'dashboard.php?err='
            . urlencode('No encontramos ese turno.'));
        exit;
    }

    if ((int) $t['matricula'] !== (int) ($_SESSION['matricula'] ?? 0)) {
        cortar403();
    }

    return $t;
}

/**
 * Responde 403 con la pantalla del proyecto y termina.
 *
 * Está acá para que ningún controlador tenga que acordarse de las dos
 * líneas (código de estado + vista): una que mande la vista sin el
 * `http_response_code(403)` devuelve "acceso denegado" con un 200, y
 * cualquier cosa que lea códigos de estado —un monitor, un buscador— lo
 * toma por una página normal.
 */
function cortar403(): void
{
    http_response_code(403);
    include __DIR__ . '/../sistema/vistas/layouts/403.php';
    exit;
}

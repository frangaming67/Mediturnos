<?php
// sistema/modelos/Receta.php
// -----------------------------------------------------------------
// Recetas, sus medicamentos y el circuito de renovación.
//
// LA REGLA QUE ATRAVIESA TODO EL ARCHIVO
// Es la misma que en Historial.php: esto es información de salud, así
// que ningún método devuelve datos sin decir DE QUIÉN son. No existe un
// `buscarPorId($id)` suelto que devuelva la receta de cualquiera y deje
// el control en manos de quien llame — las lecturas son
// `deDelPaciente($id)` o `conDueno($id)`, y la segunda trae el dueño
// justamente para que se pueda verificar antes de mostrar nada.
//
// LO QUE EL MODELO DECIDE Y LO QUE NO
// Las reglas de negocio de "¿se puede renovar esto?" viven acá, en
// motivoNoRenovable(), y no repartidas por las vistas y el controlador.
// Una vista puede ocultar un botón; lo que impide la operación es el
// servidor, y tiene que ser el MISMO criterio en los dos lados o
// aparecen las dos fallas clásicas: el botón que no hace nada, o el
// botón que no está pero la URL sí funciona.
// -----------------------------------------------------------------

require_once __DIR__ . '/../../includes/busqueda.php';

class Receta
{
    /**
     * Días que vale una receta nueva.
     *
     * 30 es el plazo habitual de una receta ambulatoria en Argentina.
     * Es una constante y no un campo configurable porque hoy no hay
     * ninguna pantalla de parámetros del sistema donde ponerlo; el día
     * que la haya, esto se lee de ahí y nada más del código cambia.
     */
    public const VIGENCIA_DIAS = 30;

    /**
     * Techo de filas de un listado.
     *
     * El historial de recetas de una persona es corto por naturaleza,
     * pero "corto por naturaleza" no es una garantía: sin un techo, una
     * cuenta con años de tratamiento crónico devuelve todo en cada
     * visita. Se corta acá y la pantalla avisa que hay más.
     */
    public const MAX_FILAS = 200;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // =============================================================
    // EMITIR
    // =============================================================

    /**
     * Emite una receta con sus medicamentos.
     *
     * Va en una transacción porque una receta SIN medicamentos no es
     * media receta: es un papel firmado en blanco. Si falla el insert
     * del segundo renglón, no puede quedar la cabecera y el primero.
     *
     * @param array $items Cada uno: nombre, dosis, frecuencia
     *                     (obligatorios) + presentacion, duracion,
     *                     cantidad (opcionales).
     * @return int id de la receta emitida.
     * @throws InvalidArgumentException si no hay ningún medicamento válido.
     */
    public function emitir(int $idPaciente, int $matricula, array $d, array $items, ?int $idTurno = null): int
    {
        $limpios = $this->itemsValidos($items);
        if (!$limpios) {
            throw new InvalidArgumentException('Una receta necesita al menos un medicamento.');
        }

        $dias = (int) ($d['dias'] ?? self::VIGENCIA_DIAS);
        if ($dias < 1 || $dias > 365) {
            $dias = self::VIGENCIA_DIAS;
        }

        $this->pdo->beginTransaction();
        try {
            // La fecha de emisión y la de vencimiento las calcula la
            // BASE, no PHP. Si las calculara PHP y el reloj del servidor
            // web estuviera corrido respecto del de la base, una receta
            // emitida hoy podría quedar guardada con fecha de ayer — y
            // el CHECK de vigencia compara las dos columnas entre sí.
            $this->pdo->prepare(
                "INSERT INTO receta
                    (id_paciente, matricula, id_turno, diagnostico, indicaciones,
                     emitida_el, vence_el)
                 VALUES
                    (:p, :m, :t, :diag, :ind,
                     CURDATE(), DATE_ADD(CURDATE(), INTERVAL :dias DAY))"
            )->execute([
                ':p'    => $idPaciente,
                ':m'    => $matricula,
                ':t'    => $idTurno,
                ':diag' => $this->textoONulo($d['diagnostico']  ?? null, 200),
                ':ind'  => $this->textoONulo($d['indicaciones'] ?? null),
                ':dias' => $dias,
            ]);

            $idReceta = (int) $this->pdo->lastInsertId();
            $this->insertarItems($idReceta, $limpios);

            $this->pdo->commit();
            return $idReceta;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Inserta los renglones de una receta con una sentencia preparada reutilizada. */
    private function insertarItems(int $idReceta, array $items): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO receta_medicamento
                (id_receta, nombre, presentacion, dosis, frecuencia, duracion, cantidad)
             VALUES (:r, :nombre, :pres, :dosis, :frec, :dur, :cant)"
        );

        foreach ($items as $i) {
            $stmt->execute([
                ':r'      => $idReceta,
                ':nombre' => $i['nombre'],
                ':pres'   => $i['presentacion'],
                ':dosis'  => $i['dosis'],
                ':frec'   => $i['frecuencia'],
                ':dur'    => $i['duracion'],
                ':cant'   => $i['cantidad'],
            ]);
        }
    }

    /**
     * Deja sólo los medicamentos completos.
     *
     * El formulario manda tres renglones vacíos para que el médico los
     * complete si los necesita; los que vienen en blanco se descartan
     * acá en silencio, que es lo que la persona espera. Lo que NO se
     * descarta en silencio es un renglón a medias (nombre sin dosis):
     * eso se devuelve como error, porque una dosis que falta es una
     * dosis que el paciente no va a saber.
     */
    private function itemsValidos(array $items): array
    {
        $limpios = [];

        foreach ($items as $i) {
            if (!is_array($i)) {
                continue;
            }
            $nombre = $this->texto($i['nombre']     ?? null, 150);
            $dosis  = $this->texto($i['dosis']      ?? null, 100);
            $frec   = $this->texto($i['frecuencia'] ?? null, 100);
            $pres   = $this->texto($i['presentacion'] ?? null, 100);
            $dur    = $this->texto($i['duracion']     ?? null, 100);

            // Renglón entero en blanco: no lo quiso cargar.
            if ($nombre === '' && $dosis === '' && $frec === '' && $pres === '' && $dur === '') {
                continue;
            }
            if ($nombre === '' || $dosis === '' || $frec === '') {
                throw new InvalidArgumentException(
                    'Cada medicamento necesita nombre, dosis y frecuencia. '
                    . 'Completá los tres o dejá el renglón vacío.'
                );
            }

            $cant = (int) ($i['cantidad'] ?? 1);
            if ($cant < 1 || $cant > 99) {
                $cant = 1;
            }

            $limpios[] = [
                'nombre'       => $nombre,
                'dosis'        => $dosis,
                'frecuencia'   => $frec,
                'presentacion' => $pres !== '' ? $pres : null,
                'duracion'     => $dur  !== '' ? $dur  : null,
                'cantidad'     => $cant,
            ];
        }

        return $limpios;
    }

    // =============================================================
    // LECTURA
    // =============================================================

    /**
     * Las recetas de un paciente, con su situación ya resuelta.
     *
     * ── POR QUÉ `situacion` SE CALCULA EN LA CONSULTA ────────
     * La vigencia no está guardada (ver recetas.sql): se deduce de
     * `vence_el`. Si cada vista la dedujera por su cuenta, en poco
     * tiempo una pantalla diría "Vigente" y otra "Vencida" sobre la
     * misma receta, porque una compararía con CURDATE() y otra con la
     * fecha de PHP. Se resuelve UNA vez, acá, y todas las pantallas
     * leen la misma columna.
     *
     * Filtros: situacion ('Vigente'|'Vencida'|'Anulada'), q (texto).
     */
    public function deDelPaciente(int $idPaciente, array $filtros = []): array
    {
        $params = [':p' => $idPaciente];
        $where  = ['1=1'];

        if (!empty($filtros['situacion'])
            && in_array($filtros['situacion'], ['Vigente', 'Vencida', 'Anulada'], true)) {
            $where[]             = 'situacion = :sit';
            $params[':sit']      = $filtros['situacion'];
        }
        if (!empty($filtros['q'])) {
            // Tres marcadores distintos con el mismo valor: con
            // EMULATE_PREPARES en false no se puede repetir uno.
            $like = patronLike((string) $filtros['q']);
            $where[] = '(medicamentos LIKE :q1 OR medico LIKE :q2 OR diagnostico LIKE :q3)';
            $params[':q1'] = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
        }

        $sql = "SELECT * FROM ( " . $this->selectRecetas() . " WHERE r.id_paciente = :p ) AS q
                WHERE " . implode(' AND ', $where) . "
                ORDER BY emitida_el DESC, id_receta DESC
                LIMIT " . (int) self::MAX_FILAS;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Las que firmó este médico para este paciente (para la ficha clínica). */
    public function deDelMedicoYPaciente(int $matricula, int $idPaciente): array
    {
        $stmt = $this->pdo->prepare(
            $this->selectRecetas()
            . " WHERE r.id_paciente = :p AND r.matricula = :m
                ORDER BY r.emitida_el DESC, r.id_receta DESC
                LIMIT " . (int) self::MAX_FILAS
        );
        $stmt->execute([':p' => $idPaciente, ':m' => $matricula]);
        return $stmt->fetchAll();
    }

    /**
     * Una receta con su dueño, para poder verificar el permiso.
     *
     * Devuelve id_paciente y matricula a propósito: el que llama NECESITA
     * saber de quién es antes de mostrarla. Un método que devolviera la
     * receta sin el dueño invitaría a mostrarla sin preguntar.
     */
    public function conDueno(int $idReceta): array|false
    {
        $stmt = $this->pdo->prepare(
            $this->selectRecetas() . " WHERE r.id_receta = :id"
        );
        $stmt->execute([':id' => $idReceta]);
        return $stmt->fetch();
    }

    /** Los medicamentos de una receta, en el orden en que se cargaron. */
    public function itemsDe(int $idReceta): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM receta_medicamento WHERE id_receta = :r ORDER BY id_item"
        );
        $stmt->execute([':r' => $idReceta]);
        return $stmt->fetchAll();
    }

    /** Contadores para la cabecera de la pantalla del paciente. */
    public function resumenPaciente(int $idPaciente): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN estado = 'Vigente' AND vence_el >= CURDATE()
                     THEN 1 ELSE 0 END)                                AS vigentes,
                SUM(CASE WHEN estado = 'Vigente' AND vence_el <  CURDATE()
                     THEN 1 ELSE 0 END)                                AS vencidas,
                -- Las que vencen dentro de la semana: es el aviso que le
                -- sirve al paciente, el que le dice que pida la
                -- renovación ANTES de quedarse sin medicación.
                SUM(CASE WHEN estado = 'Vigente' AND vence_el >= CURDATE()
                          AND vence_el <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                     THEN 1 ELSE 0 END)                                AS por_vencer
             FROM receta WHERE id_paciente = :p"
        );
        $stmt->execute([':p' => $idPaciente]);
        $r = $stmt->fetch() ?: [];

        // SUM sobre cero filas devuelve NULL, no 0: se normaliza acá para
        // que la vista no tenga que hacerlo en cada número.
        foreach (['total', 'vigentes', 'vencidas', 'por_vencer'] as $k) {
            $r[$k] = (int) ($r[$k] ?? 0);
        }
        return $r;
    }

    /**
     * El SELECT que comparten todas las lecturas.
     *
     * Está en un método y no copiado en cada consulta porque el cálculo
     * de `situacion` es la definición misma de "vencida": duplicado en
     * cuatro consultas, alcanza con que una quede atrás para que dos
     * pantallas discrepen sobre la misma receta.
     *
     * Los medicamentos vienen con GROUP_CONCAT en una subconsulta y no
     * con una consulta por receta: un listado de 30 recetas serían 31
     * consultas, que es el mismo N+1 que hubo que arreglar en
     * Turno::obtenerSlots().
     */
    private function selectRecetas(): string
    {
        return "SELECT r.*,
                   CONCAT(m.apellido, ', ', m.nombre) AS medico,
                   m.estado                           AS medico_estado,
                   -- El paciente va en el SELECT y no se deja que lo
                   -- resuelva la vista: la ficha que ve el médico y la
                   -- receta impresa tienen que decir a nombre de quién
                   -- está, y leerlo de la sesión sólo funcionaría para
                   -- el propio paciente.
                   CONCAT(p.apellido, ', ', p.nombre) AS paciente,
                   p.dni                              AS paciente_dni,
                   e.nombre                           AS especialidad,
                   CASE WHEN r.estado = 'Anulada'      THEN 'Anulada'
                        WHEN r.vence_el < CURDATE()    THEN 'Vencida'
                        ELSE 'Vigente' END            AS situacion,
                   DATEDIFF(r.vence_el, CURDATE())    AS dias_restantes,
                   (SELECT COUNT(*) FROM receta_medicamento i
                     WHERE i.id_receta = r.id_receta)  AS items,
                   (SELECT GROUP_CONCAT(i2.nombre ORDER BY i2.id_item SEPARATOR ' · ')
                      FROM receta_medicamento i2
                     WHERE i2.id_receta = r.id_receta) AS medicamentos,
                   (SELECT COUNT(*) FROM renovacion_receta rp
                     WHERE rp.id_receta = r.id_receta
                       AND rp.estado = 'Pendiente')    AS renov_pendiente,
                   (SELECT COUNT(*) FROM renovacion_receta ra
                     WHERE ra.id_receta = r.id_receta
                       AND ra.estado = 'Aprobada')      AS renov_aprobada
                FROM   receta r
                JOIN   medico   m      ON m.matricula   = r.matricula
                JOIN   paciente p      ON p.id_paciente = r.id_paciente
                LEFT   JOIN turno t    ON t.id_turno  = r.id_turno
                LEFT   JOIN especialidad e ON e.id_especialidad = t.id_especialidad";
    }

    // =============================================================
    // PERMISOS
    // =============================================================

    /**
     * ¿Esta persona puede ver esta receta?
     *
     * ── POR QUÉ `$atendioAlPaciente` LLEGA COMO PARÁMETRO ────
     * Podría consultarse acá adentro, y a primera vista sería más
     * prolijo. Pero la regla "este médico atendió a este paciente" ya
     * vive en Historial::atendioAlPaciente(), y es una regla que YA
     * cambió una vez: contaba cualquier turno entre los dos, lo que
     * abría la historia clínica con sólo sacar un turno a futuro —y no
     * la cerraba nunca más si después se cancelaba—. Hoy cuenta sólo
     * los turnos realizados.
     *
     * Una segunda copia del mismo SQL en este archivo habría quedado con
     * la versión vieja. Duplicar una regla de autorización es
     * exactamente así como se reabre un agujero que ya se había cerrado:
     * la consulta vive en UN solo lugar y acá se recibe su resultado.
     */
    public function puedeVer(array $receta, string $rol, ?int $idPaciente, ?int $matricula, bool $atendioAlPaciente = false): bool
    {
        // El paciente, lo suyo.
        if ($rol === 'paciente') {
            return $idPaciente !== null && (int) $receta['id_paciente'] === $idPaciente;
        }

        // El médico que la firmó, siempre; cualquier otro, sólo si
        // atendió a esa persona.
        if ($rol === 'medico') {
            if ($matricula === null) {
                return false;
            }
            return (int) $receta['matricula'] === $matricula || $atendioAlPaciente;
        }

        // Administración y recepción: acceso administrativo al registro.
        // Son las mismas personas que ya cargan pacientes y cobran
        // turnos; negarles la receta no protegería nada y rompería el
        // mostrador.
        //
        // El rol se llama 'recepcionista' —así está en la tabla `rol` y
        // así lo compara Historial::puedeVerEstudio()—. Escribirlo
        // 'recepcion' no da error en ningún lado: simplemente devuelve
        // false para siempre, y el mostrador se queda afuera sin que
        // nada lo avise.
        return in_array($rol, ['admin', 'recepcionista'], true);
    }

    // =============================================================
    // ANULAR
    // =============================================================

    /**
     * Anula una receta. Sólo el médico que la firmó.
     *
     * No se borra la fila: una receta anulada es un hecho de la historia
     * clínica, y el paciente tiene que poder ver que existió y que se
     * dio de baja. Borrarla dejaría un hueco inexplicable entre dos
     * consultas.
     *
     * El WHERE lleva la matrícula y el estado: así la condición la
     * verifica el UPDATE en una sola operación y no hay ventana entre
     * "leo que es mía y está vigente" y "la anulo".
     */
    public function anular(int $idReceta, int $matricula, ?string $motivo): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE receta
             SET    estado = 'Anulada', anulada_motivo = :mot, anulada_el = NOW()
             WHERE  id_receta = :id AND matricula = :m AND estado = 'Vigente'"
        );
        $stmt->execute([
            ':mot' => $this->textoONulo($motivo, 200),
            ':id'  => $idReceta,
            ':m'   => $matricula,
        ]);
        return $stmt->rowCount() > 0;
    }

    // =============================================================
    // RENOVACIÓN
    // =============================================================

    /**
     * ¿Por qué NO se puede pedir la renovación de esta receta?
     *
     * Devuelve el motivo para mostrar, o null si se puede. Un solo
     * método con todas las reglas, que usan la vista (para explicar por
     * qué el botón no está) y el controlador (para rechazar la petición
     * aunque alguien arme la URL a mano). Es el mismo patrón que
     * Turno::motivoNoReprogramable(), y por el mismo motivo: dos listas
     * de reglas en dos lugares terminan diciendo cosas distintas.
     *
     * Recibe una fila de las que devuelve deDelPaciente()/conDueno().
     */
    public function motivoNoRenovable(array $r): ?string
    {
        if ($r['situacion'] === 'Anulada') {
            return 'Esta receta fue anulada por el profesional. Para una nueva hace falta una consulta.';
        }
        if ((int) $r['renov_pendiente'] > 0) {
            return 'Ya pediste la renovación de esta receta y está esperando respuesta.';
        }
        // Si ya se renovó, lo que hay que renovar es la receta NUEVA.
        // Dejar pedir sobre la vieja generaría una cadena de recetas
        // paralelas y el paciente no sabría cuál está vigente.
        if ((int) $r['renov_aprobada'] > 0) {
            return 'Esta receta ya fue renovada: pedí la renovación de la más reciente.';
        }
        // El pedido va al profesional que la firmó y nadie más lo
        // resuelve. Si ese profesional ya no atiende, el pedido no
        // tendría quién lo responda: quedaría pendiente para siempre, y
        // el paciente esperando. Mejor decírselo ahora.
        if (($r['medico_estado'] ?? 'activo') !== 'activo') {
            return 'El profesional que firmó esta receta ya no atiende en la clínica. '
                 . 'Agendá una consulta para que te la renueven.';
        }
        return null;
    }

    /**
     * El paciente pide la renovación.
     *
     * @return int|null id del pedido, o null si ya había uno pendiente.
     *
     * El "ya había uno pendiente" se detecta por el ERROR 1062 del
     * UNIQUE y no por un SELECT previo. El SELECT previo está igual, en
     * motivoNoRenovable(), porque sirve para explicarle a la persona lo
     * que pasa — pero entre ese SELECT y este INSERT hay una ventana en
     * la que entra el segundo clic. Lo que garantiza que no haya dos es
     * el índice; esto sólo traduce su error a un null.
     */
    public function solicitarRenovacion(int $idReceta, int $idPaciente, ?string $motivo): ?int
    {
        try {
            $this->pdo->prepare(
                "INSERT INTO renovacion_receta (id_receta, id_paciente, motivo)
                 VALUES (:r, :p, :mot)"
            )->execute([
                ':r'   => $idReceta,
                ':p'   => $idPaciente,
                ':mot' => $this->textoONulo($motivo, 300),
            ]);
            return (int) $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return null;
            }
            throw $e;
        }
    }

    /** La bandeja del médico: pedidos sin responder, el más viejo primero. */
    public function renovacionesPendientes(int $matricula): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT rr.*,
                    r.vence_el, r.diagnostico, r.emitida_el,
                    CONCAT(p.apellido, ', ', p.nombre) AS paciente,
                    p.dni                              AS paciente_dni,
                    (SELECT GROUP_CONCAT(i.nombre ORDER BY i.id_item SEPARATOR ' · ')
                       FROM receta_medicamento i
                      WHERE i.id_receta = r.id_receta) AS medicamentos
             FROM   renovacion_receta rr
             JOIN   receta   r ON r.id_receta   = rr.id_receta
             JOIN   paciente p ON p.id_paciente = rr.id_paciente
             WHERE  rr.estado = 'Pendiente' AND r.matricula = :m
             ORDER  BY rr.solicitada_en ASC
             LIMIT  " . (int) self::MAX_FILAS
        );
        $stmt->execute([':m' => $matricula]);
        return $stmt->fetchAll();
    }

    /** Cuántos pedidos esperando respuesta tiene este médico. */
    public function pendientesDeMedico(int $matricula): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM renovacion_receta rr
             JOIN   receta r ON r.id_receta = rr.id_receta
             WHERE  rr.estado = 'Pendiente' AND r.matricula = :m"
        );
        $stmt->execute([':m' => $matricula]);
        return (int) $stmt->fetchColumn();
    }

    /** Los pedidos de un paciente, con la respuesta si ya la hay. */
    public function renovacionesDePaciente(int $idPaciente): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT rr.*, CONCAT(m.apellido, ', ', m.nombre) AS medico
             FROM   renovacion_receta rr
             JOIN   receta r  ON r.id_receta  = rr.id_receta
             JOIN   medico m  ON m.matricula  = r.matricula
             WHERE  rr.id_paciente = :p
             ORDER  BY rr.solicitada_en DESC
             LIMIT  " . (int) self::MAX_FILAS
        );
        $stmt->execute([':p' => $idPaciente]);
        return $stmt->fetchAll();
    }

    /**
     * Un pedido con la receta y el médico que la firmó.
     *
     * Trae `receta_matricula` para que el controlador verifique que el
     * pedido es de una receta de ESTE profesional antes de dejarlo
     * resolver. Sin eso, un médico podría aprobar la renovación de una
     * receta de otro armando el POST a mano.
     */
    public function renovacionConDatos(int $idRenovacion): array|false
    {
        $stmt = $this->pdo->prepare(
            "SELECT rr.*,
                    r.matricula  AS receta_matricula,
                    r.diagnostico, r.indicaciones,
                    CONCAT(p.apellido, ', ', p.nombre) AS paciente,
                    CONCAT(m.apellido, ', ', m.nombre) AS medico
             FROM   renovacion_receta rr
             JOIN   receta   r ON r.id_receta   = rr.id_receta
             JOIN   paciente p ON p.id_paciente = rr.id_paciente
             JOIN   medico   m ON m.matricula   = r.matricula
             WHERE  rr.id_renovacion = :id"
        );
        $stmt->execute([':id' => $idRenovacion]);
        return $stmt->fetch();
    }

    /**
     * El médico aprueba o rechaza el pedido.
     *
     * Aprobar emite una receta NUEVA copiando los medicamentos de la
     * original, y deja las dos enlazadas. No se le toca la fecha de
     * vencimiento a la vieja: eso sería reescribir la historia clínica
     * —dejaría de existir el registro de qué valía y hasta cuándo—.
     *
     * Todo en una transacción: una renovación "Aprobada" cuyo
     * id_receta_nueva quedó en NULL porque falló el segundo INSERT es un
     * paciente al que el sistema le dice "ya tenés tu receta" y no tiene
     * ninguna.
     *
     * @return int|null id de la receta nueva (null si se rechazó).
     * @throws RuntimeException si el pedido ya estaba resuelto.
     */
    public function resolverRenovacion(
        int $idRenovacion,
        int $matricula,
        bool $aprobar,
        ?string $respuesta,
        int $dias = self::VIGENCIA_DIAS
    ): ?int {
        if ($dias < 1 || $dias > 365) {
            $dias = self::VIGENCIA_DIAS;
        }

        $this->pdo->beginTransaction();
        try {
            // FOR UPDATE: dos pestañas del médico apretando "Aprobar" a
            // la vez emitirían dos recetas nuevas para el mismo pedido.
            // El UNIQUE de `pendiente_unica` no cubre esto —no hay dos
            // inserts, hay dos updates sobre la misma fila—, así que acá
            // la garantía es el bloqueo de la fila hasta el commit.
            $stmt = $this->pdo->prepare(
                "SELECT rr.id_receta, rr.id_paciente, rr.estado,
                        r.matricula, r.diagnostico, r.indicaciones
                 FROM   renovacion_receta rr
                 JOIN   receta r ON r.id_receta = rr.id_receta
                 WHERE  rr.id_renovacion = :id
                 FOR UPDATE"
            );
            $stmt->execute([':id' => $idRenovacion]);
            $pedido = $stmt->fetch();

            if (!$pedido) {
                throw new RuntimeException('No encontramos ese pedido de renovación.');
            }
            if ((int) $pedido['matricula'] !== $matricula) {
                throw new RuntimeException('Ese pedido corresponde a una receta de otro profesional.');
            }
            if ($pedido['estado'] !== 'Pendiente') {
                throw new RuntimeException('Ese pedido ya fue respondido.');
            }

            $idNueva = null;

            if ($aprobar) {
                $this->pdo->prepare(
                    "INSERT INTO receta
                        (id_paciente, matricula, id_turno, diagnostico, indicaciones,
                         emitida_el, vence_el)
                     VALUES
                        (:p, :m, NULL, :diag, :ind,
                         CURDATE(), DATE_ADD(CURDATE(), INTERVAL :dias DAY))"
                )->execute([
                    ':p'    => (int) $pedido['id_paciente'],
                    ':m'    => $matricula,
                    ':diag' => $pedido['diagnostico'],
                    ':ind'  => $pedido['indicaciones'],
                    ':dias' => $dias,
                ]);
                $idNueva = (int) $this->pdo->lastInsertId();

                // Los medicamentos se copian con un INSERT ... SELECT y
                // no leyéndolos a PHP para volver a insertarlos: una
                // sola ida a la base, y es imposible que se pierda un
                // renglón en el camino.
                $this->pdo->prepare(
                    "INSERT INTO receta_medicamento
                        (id_receta, nombre, presentacion, dosis, frecuencia, duracion, cantidad)
                     SELECT :nueva, nombre, presentacion, dosis, frecuencia, duracion, cantidad
                     FROM   receta_medicamento
                     WHERE  id_receta = :vieja
                     ORDER  BY id_item"
                )->execute([
                    ':nueva' => $idNueva,
                    ':vieja' => (int) $pedido['id_receta'],
                ]);
            }

            $this->pdo->prepare(
                "UPDATE renovacion_receta
                 SET    estado = :est, respuesta = :resp, matricula = :m,
                        id_receta_nueva = :nueva, resuelta_en = NOW()
                 WHERE  id_renovacion = :id AND estado = 'Pendiente'"
            )->execute([
                ':est'   => $aprobar ? 'Aprobada' : 'Rechazada',
                ':resp'  => $this->textoONulo($respuesta, 300),
                ':m'     => $matricula,
                ':nueva' => $idNueva,
                ':id'    => $idRenovacion,
            ]);

            $this->pdo->commit();
            return $idNueva;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // =============================================================
    // AYUDANTES
    // =============================================================

    /** Texto recortado y sin espacios de más. Nunca null. */
    private function texto(mixed $v, int $max): string
    {
        // is_string: un POST con `nombre[]=a` hace que trim() reciba un
        // array y devuelva un 500. Es la falla que todavía tienen los
        // formularios viejos del proyecto; acá no.
        return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
    }

    /** Texto opcional: vacío se guarda como NULL, no como cadena vacía. */
    private function textoONulo(mixed $v, ?int $max = null): ?string
    {
        $v = is_string($v) ? trim($v) : '';
        if ($v === '') {
            return null;
        }
        return $max !== null ? mb_substr($v, 0, $max) : $v;
    }
}

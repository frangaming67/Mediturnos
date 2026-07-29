<?php
// sistema/modelos/Notificacion.php
// -----------------------------------------------------------------
// Acceso a datos del centro de notificaciones. Es el ÚNICO lugar que
// ejecuta SQL sobre la tabla `notificacion` — la misma regla que siguen
// los otros nueve modelos.
//
// LA DECISIÓN QUE ATRAVIESA TODO EL ARCHIVO
// Ningún método permite tocar una notificación sin decir de QUIÉN es.
// `marcarLeida($id, $idUsuario)` y `eliminar($id, $idUsuario)` llevan el
// dueño en el WHERE, no sólo el id.
//
// Se podría haber puesto ese control en el controlador —"buscá la
// notificación, fijate si es tuya, después borrala"—, pero eso deja la
// puerta abierta a que un controlador nuevo se olvide del chequeo. Acá
// el modelo directamente NO EXPONE una forma de borrar la notificación
// de otro: no hay nada que olvidarse.
//
// El costo es una consulta menos eficiente en teoría (dos columnas en el
// WHERE en vez de una); en la práctica el índice
// idx_notif_usuario la resuelve igual.
// -----------------------------------------------------------------

class Notificacion
{
    /** Notificaciones por página en el centro de avisos. */
    public const POR_PAGINA = 15;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // =============================================================
    // ESCRITURA
    // =============================================================

    /**
     * Registra un aviso y devuelve su id.
     *
     * `url_accion` se guarda RELATIVA (p. ej. "perfil.php#seguridad"):
     * si se guardara absoluta, mover el proyecto de carpeta o de dominio
     * dejaría todos los avisos viejos apuntando a una dirección muerta.
     */
    public function crear(array $d): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO notificacion
                (id_usuario, tipo, titulo, mensaje, url_accion, id_referencia)
             VALUES (:u, :tipo, :titulo, :mensaje, :url, :ref)"
        );
        $stmt->execute([
            ':u'       => $d['id_usuario'],
            ':tipo'    => $d['tipo'],
            ':titulo'  => mb_substr($d['titulo'], 0, 120),
            ':mensaje' => mb_substr($d['mensaje'], 0, 400),
            ':url'     => $d['url_accion']    ?? null,
            ':ref'     => $d['id_referencia'] ?? null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Deja constancia de que el correo de este aviso salió. */
    public function marcarEmailEnviado(int $id): void
    {
        $this->pdo->prepare(
            "UPDATE notificacion SET email_enviado_en = NOW() WHERE id_notificacion = :id"
        )->execute([':id' => $id]);
    }

    /** Marca una como leída. Devuelve false si no era de ese usuario. */
    public function marcarLeida(int $id, int $idUsuario): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE notificacion SET leida_en = NOW()
             WHERE id_notificacion = :id AND id_usuario = :u AND leida_en IS NULL"
        );
        $stmt->execute([':id' => $id, ':u' => $idUsuario]);
        return $stmt->rowCount() > 0;
    }

    /** Marca todas las pendientes del usuario. Devuelve cuántas. */
    public function marcarTodasLeidas(int $idUsuario): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE notificacion SET leida_en = NOW()
             WHERE id_usuario = :u AND leida_en IS NULL"
        );
        $stmt->execute([':u' => $idUsuario]);
        return $stmt->rowCount();
    }

    /**
     * Borra una notificación.
     *
     * Es un DELETE real, no una marca de "oculta": es correspondencia
     * propia del usuario y si la descarta no hay motivo para guardarla.
     * El hecho que la originó —el turno, el pago, la receta— sigue en su
     * tabla, así que no se pierde nada del historial del sistema.
     */
    public function eliminar(int $id, int $idUsuario): bool
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM notificacion WHERE id_notificacion = :id AND id_usuario = :u"
        );
        $stmt->execute([':id' => $id, ':u' => $idUsuario]);
        return $stmt->rowCount() > 0;
    }

    // =============================================================
    // LECTURA
    // =============================================================

    /**
     * WHERE compartido por listar() y contar(), para que el paginador
     * nunca muestre un total que no coincide con las filas.
     */
    private function construirFiltros(int $idUsuario, array $filtros): array
    {
        $where  = ['id_usuario = :u'];
        $params = [':u' => $idUsuario];

        if (($filtros['estado'] ?? '') === 'no_leidas') {
            $where[] = 'leida_en IS NULL';
        } elseif (($filtros['estado'] ?? '') === 'leidas') {
            $where[] = 'leida_en IS NOT NULL';
        }

        if (!empty($filtros['tipo'])) {
            $where[]          = 'tipo = :tipo';
            $params[':tipo']  = $filtros['tipo'];
        }

        return [implode(' AND ', $where), $params];
    }

    public function listar(int $idUsuario, array $filtros = [], int $pagina = 1): array
    {
        [$whereSql, $params] = $this->construirFiltros($idUsuario, $filtros);

        $porPagina = self::POR_PAGINA;
        $offset    = max(0, ($pagina - 1) * $porPagina);

        // LIMIT/OFFSET interpolados como enteros ya casteados: con
        // EMULATE_PREPARES en false MySQL los recibiría como string.
        $stmt = $this->pdo->prepare(
            "SELECT * FROM notificacion
             WHERE {$whereSql}
             ORDER BY creada_en DESC, id_notificacion DESC
             LIMIT {$porPagina} OFFSET {$offset}"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function contar(int $idUsuario, array $filtros = []): int
    {
        [$whereSql, $params] = $this->construirFiltros($idUsuario, $filtros);

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM notificacion WHERE {$whereSql}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** Cuántas sin leer (para el contador del menú). */
    public function sinLeer(int $idUsuario): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM notificacion WHERE id_usuario = :u AND leida_en IS NULL"
        );
        $stmt->execute([':u' => $idUsuario]);
        return (int) $stmt->fetchColumn();
    }

    /** Últimas N, para el desplegable del encabezado. */
    public function ultimas(int $idUsuario, int $cantidad = 5): array
    {
        $cantidad = max(1, min(20, $cantidad));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM notificacion WHERE id_usuario = :u
             ORDER BY creada_en DESC, id_notificacion DESC
             LIMIT {$cantidad}"
        );
        $stmt->execute([':u' => $idUsuario]);
        return $stmt->fetchAll();
    }

    /**
     * ¿Ya se le avisó a este usuario por este motivo?
     *
     * Evita el recordatorio duplicado: la tarea que avisa "tenés turno
     * mañana" corre en cada visita, y sin este control mandaría un correo
     * por cada vez que el paciente abre una página.
     */
    public function yaAvisado(int $idUsuario, string $tipo, int $idReferencia): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM notificacion
             WHERE id_usuario = :u AND tipo = :t AND id_referencia = :r"
        );
        $stmt->execute([':u' => $idUsuario, ':t' => $tipo, ':r' => $idReferencia]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** Datos mínimos del destinatario, para saludarlo y escribirle. */
    public function destinatario(int $idUsuario): array|false
    {
        $stmt = $this->pdo->prepare(
            "SELECT id_usuario, nombre, apellido, email, estado
             FROM usuario WHERE id_usuario = :id"
        );
        $stmt->execute([':id' => $idUsuario]);
        return $stmt->fetch();
    }

    /** Cuenta de usuario asociada a una ficha de paciente. */
    public function usuarioDePaciente(int $idPaciente): ?int
    {
        $stmt = $this->pdo->prepare(
            "SELECT id_usuario FROM usuario WHERE id_paciente = :p AND estado = 'activo' LIMIT 1"
        );
        $stmt->execute([':p' => $idPaciente]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** Cuenta de usuario de un médico (para avisarle de un refill). */
    public function usuarioDeMedico(int $matricula): ?int
    {
        $stmt = $this->pdo->prepare(
            "SELECT id_usuario FROM usuario WHERE matricula = :m AND estado = 'activo' LIMIT 1"
        );
        $stmt->execute([':m' => $matricula]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }
}

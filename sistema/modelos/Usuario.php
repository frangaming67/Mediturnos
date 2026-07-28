<?php
// sistema/modelos/Usuario.php
// -----------------------------------------------------------------
// Vive en sistema/modelos/ (y no en includes/, junto a auth.php) porque
// SÍ es de negocio: sabe el esquema de las tablas `usuario`, `rol` y
// `rol_permiso` y hace INSERT/UPDATE/SELECT reales. auth.php, en cambio,
// solo lee/escribe $_SESSION (nunca toca la base) — por eso quedan
// separados aunque ambos hablen de "quién puede entrar".
// registrarPaciente() es el único método que escribe en DOS tablas
// (paciente + usuario) y por eso es también el único que usa una
// transacción explícita: sin ella, un fallo a mitad de camino podría
// dejar un paciente sin cuenta de acceso o una cuenta sin ficha asociada.
// -----------------------------------------------------------------

class Usuario
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Busca un usuario para el login y carga sus permisos.
     *
     * Acepta NOMBRE DE USUARIO **o** EMAIL en el mismo campo.
     * Por qué las dos cosas: una plataforma moderna se loguea con email
     * (nadie recuerda un alias), pero las cuentas que ya existen —admin,
     * cfernandez, recepcion…— entran con nombre de usuario. Aceptar
     * ambos moderniza el acceso SIN dejar afuera a nadie.
     *
     * Se usan dos placeholders distintos con el mismo valor porque con
     * PDO::ATTR_EMULATE_PREPARES = false no se puede repetir uno.
     */
    public function buscarParaLogin(string $identificador): array|false
    {
        $stmt = $this->pdo->prepare(
            "SELECT u.id_usuario, u.nombre, u.apellido, u.usuario, u.email,
                    u.contrasenia, u.estado, u.id_paciente, u.matricula, u.foto,
                    r.nombre AS rol
             FROM   usuario u
             JOIN   rol     r ON r.id_rol = u.id_rol
             WHERE  (u.usuario = :ident1 OR u.email = :ident2)
               AND  u.estado  = 'activo'
             LIMIT  1"
        );
        $stmt->execute([':ident1' => $identificador, ':ident2' => $identificador]);
        $user = $stmt->fetch();
        if (!$user) return false;

        // Cargar permisos reales desde rol_permiso → permiso
        $stmt2 = $this->pdo->prepare(
            "SELECT p.nombre
             FROM   rol_permiso rp
             JOIN   permiso     p ON p.id_permiso = rp.id_permiso
             JOIN   usuario     u ON u.id_rol     = rp.id_rol
             WHERE  u.id_usuario = :id"
        );
        $stmt2->execute([':id' => $user['id_usuario']]);
        $user['permisos'] = $stmt2->fetchAll(PDO::FETCH_COLUMN);
        return $user;
    }

    /**
     * Actualiza último login y marca online.
     */
    public function registrarLogin(int $id): void
    {
        $this->pdo->prepare(
            "UPDATE usuario SET ultimo_login = NOW(), online = 1 WHERE id_usuario = :id"
        )->execute([':id' => $id]);
    }

    /**
     * Marca usuario como offline al hacer logout.
     */
    public function registrarLogout(int $id): void
    {
        $this->pdo->prepare(
            "UPDATE usuario SET online = 0 WHERE id_usuario = :id"
        )->execute([':id' => $id]);
    }

    /**
     * Lista usuarios con su rol. Filtros opcionales:
     *   - id_rol   (int)    → filtra por rol
     *   - estado   (string) → 'activo' / 'inactivo'
     *   - busqueda (string) → texto en usuario, nombre, apellido o email
     */
    /** Cantidad de usuarios por página en el listado. */
    public const POR_PAGINA = 25;

    /**
     * WHERE + parámetros compartidos por listar() y contar(), para que
     * ambos filtren idéntico y el total del paginador siempre coincida
     * con lo que se muestra.
     */
    private function construirFiltros(array $filtros): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filtros['id_rol'])) {
            $where[]           = 'u.id_rol = :id_rol';
            $params[':id_rol'] = $filtros['id_rol'];
        }
        if (!empty($filtros['estado'])) {
            $where[]           = 'u.estado = :estado';
            $params[':estado'] = $filtros['estado'];
        }
        if (!empty($filtros['busqueda'])) {
            // Con PDO::ATTR_EMULATE_PREPARES = false no se puede repetir un
            // mismo placeholder; se usa uno distinto por columna con igual valor.
            $where[] = '(u.usuario LIKE :q1 OR u.nombre LIKE :q2 OR u.apellido LIKE :q3 OR u.email LIKE :q4)';
            $like = '%' . $filtros['busqueda'] . '%';
            $params[':q1'] = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
            $params[':q4'] = $like;
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * Lista usuarios con su rol, PAGINADO.
     *
     * Sin LIMIT, con los 1000+ usuarios de la base real esta pantalla
     * devolvía casi 2 MB de HTML en una sola respuesta.
     */
    public function listar(array $filtros = [], int $pagina = 1): array
    {
        [$whereSql, $params] = $this->construirFiltros($filtros);

        $porPagina = self::POR_PAGINA;
        $offset    = max(0, ($pagina - 1) * $porPagina);

        // LIMIT/OFFSET van interpolados como enteros ya casteados: con
        // EMULATE_PREPARES en false, MySQL los recibiría como string si
        // fueran parámetros y rechazaría la consulta. El casteo a int
        // impide cualquier inyección.
        $sql = "SELECT u.id_usuario, u.nombre, u.apellido, u.usuario, u.email,
                       u.estado, u.fecha_alta, u.ultimo_login,
                       r.nombre AS rol
                FROM   usuario u
                JOIN   rol     r ON r.id_rol = u.id_rol
                WHERE  " . $whereSql . "
                ORDER  BY u.apellido, u.nombre
                LIMIT  {$porPagina} OFFSET {$offset}";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Total de usuarios que cumplen los filtros (para el paginador). */
    public function contar(array $filtros = []): int
    {
        [$whereSql, $params] = $this->construirFiltros($filtros);

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM usuario u JOIN rol r ON r.id_rol = u.id_rol WHERE " . $whereSql
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Busca usuario por ID.
     */
    public function buscarPorId(int $id): array|false
    {
        $stmt = $this->pdo->prepare(
            "SELECT u.*, r.nombre AS rol_nombre
             FROM   usuario u JOIN rol r ON r.id_rol = u.id_rol
             WHERE  u.id_usuario = :id"
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    /**
     * Crea un nuevo usuario con hash bcrypt.
     */
    public function crear(array $datos): int
    {
        $hash = password_hash($datos['contrasenia'], PASSWORD_DEFAULT);
        $stmt = $this->pdo->prepare(
            "INSERT INTO usuario (nombre, apellido, usuario, email, contrasenia,id_rol, id_paciente, matricula)
             VALUES (:nombre, :apellido, :usuario, :email, :hash,:id_rol, :id_paciente, :matricula)"
        );
        $stmt->execute([
            ':nombre' => $datos['nombre'],
            ':apellido' => $datos['apellido'],
            ':usuario' => $datos['usuario'],
            ':email' => $datos['email'],
            ':hash' => $hash,
            ':id_rol' => $datos['id_rol'],
            ':id_paciente' => !empty($datos['id_paciente']) ? $datos['id_paciente'] : null,
            ':matricula' => !empty($datos['matricula'])   ? $datos['matricula']   : null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Verifica si un DNI ya está registrado como paciente.
     */
    public function existeDni(string $dni): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM paciente WHERE dni = :dni");
        $stmt->execute([':dni' => $dni]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Registro público de un paciente: crea la ficha en `paciente`
     * y la cuenta en `usuario` (rol paciente) enlazadas, en una sola
     * transacción. Devuelve el id_usuario creado.
     */
    public function registrarPaciente(array $datos): int
    {
        // id_rol del rol "paciente" (sin hardcodear)
        $idRolPaciente = (int) $this->pdo
            ->query("SELECT id_rol FROM rol WHERE nombre = 'paciente' LIMIT 1")
            ->fetchColumn();

        $this->pdo->beginTransaction();
        try {
            // 1) Ficha de paciente
            //    sexo y direccion son columnas nuevas (auth_v2.sql) y
            //    admiten NULL: las cuentas creadas antes no las tienen.
            $stmtP = $this->pdo->prepare(
                "INSERT INTO paciente (dni, nombre, apellido, fecha_nac, sexo, telefono, direccion, email)
                 VALUES (:dni, :nombre, :apellido, :fecha_nac, :sexo, :telefono, :direccion, :email)"
            );
            $stmtP->execute([
                ':dni' => $datos['dni'],
                ':nombre' => $datos['nombre'],
                ':apellido' => $datos['apellido'],
                ':fecha_nac' => !empty($datos['fecha_nac']) ? $datos['fecha_nac'] : null,
                ':sexo' => !empty($datos['sexo']) ? $datos['sexo'] : null,
                ':direccion' => !empty($datos['direccion']) ? $datos['direccion'] : null,
                // El teléfono va como TEXTO, no como int: paciente.telefono es
                // VARCHAR(20). Con el cast a int, un celular con característica
                // (ej. 91123456789) supera el rango de INT y se guardaba
                // truncado, y además se perdían los ceros a la izquierda.
                // Paciente::crear() ya lo guardaba como string: esto alinea
                // los dos caminos de alta para que el dato quede igual venga
                // del registro público o del panel.
                ':telefono' => $datos['telefono'],
                ':email' => $datos['email'] ?: null,
            ]);
            $idPaciente = (int) $this->pdo->lastInsertId();

            // 2) Cuenta de usuario enlazada
            $hash  = password_hash($datos['contrasenia'], PASSWORD_DEFAULT);
            $stmtU = $this->pdo->prepare(
                "INSERT INTO usuario (nombre, apellido, usuario, email, contrasenia,
                                      id_rol, id_paciente, matricula, foto)
                 VALUES (:nombre, :apellido, :usuario, :email, :hash,
                         :id_rol, :id_paciente, NULL, :foto)"
            );
            $stmtU->execute([
                ':nombre' => $datos['nombre'],
                ':apellido' => $datos['apellido'],
                ':usuario' => $datos['usuario'],
                ':email' => $datos['email'] ?: null,
                ':hash' => $hash,
                ':id_rol' => $idRolPaciente,
                ':id_paciente' => $idPaciente,
                ':foto' => $datos['foto'] ?? null,
            ]);
            $idUsuario = (int) $this->pdo->lastInsertId();

            // 3) Obra social (opcional).
            //    Va DENTRO de la misma transacción a propósito: si fallara,
            //    no puede quedar un paciente creado con una cobertura a
            //    medias. O se guarda todo, o no se guarda nada.
            if (!empty($datos['id_plan'])) {
                $this->pdo->prepare(
                    "INSERT INTO paciente_plan (id_paciente, id_plan, nro_afiliado, fecha_alta)
                     VALUES (:p, :pl, :nro, CURDATE())"
                )->execute([
                    ':p'   => $idPaciente,
                    ':pl'  => $datos['id_plan'],
                    ':nro' => !empty($datos['nro_afiliado']) ? $datos['nro_afiliado'] : null,
                ]);
            }

            $this->pdo->commit();
            return $idUsuario;
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Planes de obra social para el selector del registro, con el nombre
     * de la obra social ya resuelto. Se usa tanto para dibujar la lista
     * como para VALIDAR en el servidor que el id enviado existe de verdad.
     */
    public function listarPlanesObraSocial(): array
    {
        return $this->pdo->query(
            "SELECT pl.id_plan, pl.nombre_plan, pl.porcentaje_cobertura,
                    os.id_obra_social, os.nombre AS obra_social
             FROM   plan_os pl
             JOIN   obra_social os ON os.id_obra_social = pl.id_obra_social
             ORDER  BY os.nombre, pl.nombre_plan"
        )->fetchAll();
    }

    /** ¿Existe ese plan? (validación server-side del selector). */
    public function existePlan(int $idPlan): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM plan_os WHERE id_plan = :id");
        $stmt->execute([':id' => $idPlan]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Actualiza datos de usuario (sin cambiar contraseña).
     */
    public function actualizar(int $id, array $datos): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE usuario
             SET nombre = :nombre, apellido = :apellido, email = :email,
                 id_rol = :id_rol, estado = :estado
             WHERE id_usuario = :id"
        );
        return $stmt->execute([
            ':nombre' => $datos['nombre'],
            ':apellido' => $datos['apellido'],
            ':email' => $datos['email'],
            ':id_rol' => $datos['id_rol'],
            ':estado' => $datos['estado'],
            ':id' => $id,
        ]);
    }

    /**
     * Baja lógica: cambia estado a 'inactivo'.
     */
    public function darDeBaja(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE usuario SET estado = 'inactivo', fecha_baja = CURDATE() WHERE id_usuario = :id"
        );
        return $stmt->execute([':id' => $id]);
    }
    /**
     * Lista roles disponibles para el formulario.
     */
    public function listarRoles(): array
    {
        return $this->pdo->query("SELECT id_rol, nombre FROM rol ORDER BY nombre")->fetchAll();
    }

    /**
     * Verifica si un nombre de usuario ya existe.
     */
    public function existeUsuario(string $usuario, int $excludeId = 0): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM usuario WHERE usuario = :u AND id_usuario <> :id"
        );
        $stmt->execute([':u' => $usuario, ':id' => $excludeId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Verifica si un email ya está registrado (la columna es UNIQUE).
     */
    public function existeEmail(string $email, int $excludeId = 0): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM usuario WHERE email = :e AND id_usuario <> :id"
        );
        $stmt->execute([':e' => $email, ':id' => $excludeId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    // =============================================================
    // RECUPERACIÓN DE CONTRASEÑA
    // =============================================================

    /** Minutos que dura un enlace de recuperación. */
    public const RESET_MINUTOS = 60;

    /** Busca una cuenta activa por email (para el flujo de recuperación). */
    public function buscarPorEmail(string $email): array|false
    {
        $stmt = $this->pdo->prepare(
            "SELECT id_usuario, nombre, apellido, email
             FROM   usuario
             WHERE  email = :e AND estado = 'activo'
             LIMIT  1"
        );
        $stmt->execute([':e' => $email]);
        return $stmt->fetch();
    }

    /**
     * Genera un enlace de recuperación y devuelve el token EN CLARO
     * (es el único momento en que existe: en la base sólo queda su hash).
     *
     * Por qué se guarda hasheado: si alguien lograra leer la tabla, con
     * los tokens en claro podría tomar el control de cualquier cuenta que
     * tuviera un pedido pendiente. Hasheado, lo que ve no le sirve.
     * Se usa SHA-256 y no bcrypt porque el token ya es aleatorio de 32
     * bytes: no hace falta el costo de un hash lento contra fuerza bruta.
     *
     * Además invalida los pedidos anteriores del mismo usuario, para que
     * no queden varios enlaces vivos a la vez.
     */
    public function crearTokenReset(int $idUsuario, ?string $ip = null): string
    {
        $this->pdo->prepare(
            "UPDATE password_reset SET usado_en = NOW()
             WHERE id_usuario = :id AND usado_en IS NULL"
        )->execute([':id' => $idUsuario]);

        $token = bin2hex(random_bytes(32));   // 64 caracteres, imposible de adivinar

        $this->pdo->prepare(
            "INSERT INTO password_reset (id_usuario, token_hash, expira_en, ip_solicito)
             VALUES (:id, :hash, DATE_ADD(NOW(), INTERVAL :min MINUTE), :ip)"
        )->execute([
            ':id'   => $idUsuario,
            ':hash' => hash('sha256', $token),
            ':min'  => self::RESET_MINUTOS,
            ':ip'   => $ip,
        ]);

        return $token;
    }

    /**
     * Valida un token: debe existir, no estar usado y no haber vencido.
     * Devuelve los datos del pedido + del usuario, o false.
     */
    public function validarTokenReset(string $token): array|false
    {
        $stmt = $this->pdo->prepare(
            "SELECT pr.id_reset, pr.id_usuario, pr.expira_en,
                    u.nombre, u.apellido, u.email, u.usuario
             FROM   password_reset pr
             JOIN   usuario u ON u.id_usuario = pr.id_usuario
             WHERE  pr.token_hash = :hash
               AND  pr.usado_en IS NULL
               AND  pr.expira_en > NOW()
               AND  u.estado = 'activo'
             LIMIT  1"
        );
        $stmt->execute([':hash' => hash('sha256', $token)]);
        return $stmt->fetch();
    }

    /**
     * Cambia la contraseña y quema el token, todo o nada.
     *
     * Va en una transacción porque son dos escrituras que deben ocurrir
     * juntas: si se cambiara la clave y fallara el marcado del token, el
     * enlace quedaría vivo y podría reutilizarse — justamente lo que hay
     * que evitar.
     */
    public function restablecerPassword(int $idReset, int $idUsuario, string $nuevaPass): bool
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                "UPDATE usuario SET contrasenia = :hash WHERE id_usuario = :id"
            )->execute([
                ':hash' => password_hash($nuevaPass, PASSWORD_DEFAULT),
                ':id'   => $idUsuario,
            ]);

            $this->pdo->prepare(
                "UPDATE password_reset SET usado_en = NOW() WHERE id_reset = :id"
            )->execute([':id' => $idReset]);

            $this->pdo->commit();
            return true;
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            error_log('Usuario restablecerPassword: ' . $e->getMessage());
            return false;
        }
    }
}

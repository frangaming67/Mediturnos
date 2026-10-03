<?php
// =============================================================
// includes/notificaciones.php — Emisión de avisos
// =============================================================
// Cuando pasa algo que le importa a una persona —le confirmaron un
// turno, le rechazaron el pago, le aprobaron una renovación— hay que
// avisarle. Y por más de una vía: dentro de la aplicación y por correo,
// y mañana quizá por push.
//
// EL PROBLEMA QUE RESUELVE
// Sin esto, cada controlador tendría que acordarse de tres cosas a la
// vez: grabar el aviso, redactar el correo y mandarlo. Trece tipos de
// aviso por catorce lugares que los disparan es la receta exacta para
// que algunos avisen por los dos canales, otros por uno, y unos cuantos
// se olviden de avisar.
//
// Acá el controlador dice UNA sola cosa:
//
//     $notificador->notificar($idUsuario, new Aviso(
//         TipoAviso::PAGO_APROBADO,
//         'Pago aprobado',
//         'Tu turno con el Dr. Pérez quedó confirmado.'
//     ));
//
// y el servicio se ocupa de repartirlo por todos los canales que
// correspondan a ese tipo.
//
// CÓMO SE AGREGA PUSH EL DÍA DE MAÑANA
// Se escribe una clase que implemente CanalNotificacion y se enchufa:
//
//     $notificador->agregarCanal(new CanalPush($claves));
//
// Ni un controlador se entera. Ese es todo el punto de partir esto en
// canales en vez de escribir "grabar + mandar mail" a mano en cada lado.
//
// DÓNDE VIVE CADA COSA
//   · Este archivo   → decide QUÉ se manda y POR DÓNDE
//   · Notificacion   → el SQL (es un modelo, como manda el proyecto)
//   · email_plantilla→ cómo se ve el correo
//   · mailer.php     → cómo sale el correo del servidor
// =============================================================

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/email_plantilla.php';
require_once __DIR__ . '/../sistema/modelos/Notificacion.php';

// urlAbsoluta() vive en email_plantilla.php, que es de donde la necesitan
// los enlaces de los correos.

// =============================================================
// CATÁLOGO DE TIPOS
// =============================================================

/**
 * Los avisos que el sistema sabe emitir.
 *
 * El tipo decide dos cosas: con qué icono y color se dibuja en el centro
 * de notificaciones, y si además sale por correo.
 *
 * NO todo va por mail a propósito. Un aviso de "turno reprogramado"
 * merece un correo; uno de "marcaste tu perfil como actualizado", no.
 * Mandar todo por correo es la forma más rápida de que la gente empiece
 * a filtrar los correos del sistema a la papelera, y entonces tampoco
 * lea los que sí importan.
 */
final class TipoAviso
{
    // Turnos
    public const TURNO_RESERVADO     = 'turno_reservado';
    public const TURNO_CONFIRMADO    = 'turno_confirmado';
    public const TURNO_CANCELADO     = 'turno_cancelado';
    public const TURNO_REPROGRAMADO  = 'turno_reprogramado';
    public const TURNO_RECORDATORIO  = 'turno_recordatorio';

    // Pagos
    public const PAGO_APROBADO       = 'pago_aprobado';
    public const PAGO_RECHAZADO      = 'pago_rechazado';
    public const PAGO_POR_VENCER     = 'pago_por_vencer';

    // Clínico
    public const ESTUDIO_PEDIDO      = 'estudio_pedido';
    public const RESULTADOS_LISTOS   = 'resultados_listos';
    public const RECETA_NUEVA        = 'receta_nueva';
    public const RECETA_ANULADA      = 'receta_anulada';
    public const REFILL_SOLICITADO   = 'refill_solicitado';
    public const REFILL_APROBADO     = 'refill_aprobado';
    public const REFILL_RECHAZADO    = 'refill_rechazado';
    public const MENSAJE_MEDICO      = 'mensaje_medico';

    // Cuenta
    public const CUENTA_BIENVENIDA   = 'cuenta_bienvenida';
    public const CUENTA_PASSWORD     = 'cuenta_password';
    public const CUENTA_EMAIL        = 'cuenta_email';
    public const CUENTA_DATOS        = 'cuenta_datos';

    /**
     * Configuración de cada tipo.
     *   icono → clave que la vista traduce a un SVG
     *   color → 'azul' | 'verde' | 'rojo' | 'amarillo'
     *   email → si además se manda por correo
     */
    private const CONFIG = [
        self::TURNO_RESERVADO    => ['icono' => 'calendario', 'color' => 'azul',     'email' => true],
        self::TURNO_CONFIRMADO   => ['icono' => 'tilde',      'color' => 'verde',    'email' => true],
        self::TURNO_CANCELADO    => ['icono' => 'cruz',       'color' => 'rojo',     'email' => true],
        self::TURNO_REPROGRAMADO => ['icono' => 'calendario', 'color' => 'amarillo', 'email' => true],
        self::TURNO_RECORDATORIO => ['icono' => 'reloj',      'color' => 'azul',     'email' => true],

        self::PAGO_APROBADO      => ['icono' => 'tarjeta',    'color' => 'verde',    'email' => true],
        self::PAGO_RECHAZADO     => ['icono' => 'tarjeta',    'color' => 'rojo',     'email' => true],
        self::PAGO_POR_VENCER    => ['icono' => 'reloj',      'color' => 'amarillo', 'email' => true],

        // Pedir un estudio tiene su propio tipo. Hasta la etapa 5 reusaba
        // RECETA_NUEVA, que funcionaba sólo porque todavía no existían
        // las recetas: en cuanto existieron, el paciente veía "te
        // pidieron un estudio" con el icono de una receta, y no había
        // forma de distinguir los dos avisos en el listado.
        self::ESTUDIO_PEDIDO     => ['icono' => 'documento',  'color' => 'azul',     'email' => true],
        self::RESULTADOS_LISTOS  => ['icono' => 'documento',  'color' => 'verde',    'email' => true],
        self::RECETA_NUEVA       => ['icono' => 'receta',     'color' => 'azul',     'email' => true],
        // Que te anulen una receta es de las pocas cosas que hay que
        // saber ANTES de ir a la farmacia, así que va por correo.
        self::RECETA_ANULADA     => ['icono' => 'receta',     'color' => 'rojo',     'email' => true],
        self::REFILL_SOLICITADO  => ['icono' => 'receta',     'color' => 'amarillo', 'email' => true],
        self::REFILL_APROBADO    => ['icono' => 'receta',     'color' => 'verde',    'email' => true],
        self::REFILL_RECHAZADO   => ['icono' => 'receta',     'color' => 'rojo',     'email' => true],
        self::MENSAJE_MEDICO     => ['icono' => 'mensaje',    'color' => 'azul',     'email' => false],

        self::CUENTA_BIENVENIDA  => ['icono' => 'usuario',    'color' => 'azul',     'email' => true],
        // Un cambio de contraseña o de correo SÍ va por mail aunque sea
        // "de cuenta": si no fue la persona quien lo hizo, ese correo es
        // el único modo de que se entere a tiempo.
        self::CUENTA_PASSWORD    => ['icono' => 'candado',    'color' => 'amarillo', 'email' => true],
        self::CUENTA_EMAIL       => ['icono' => 'sobre',      'color' => 'amarillo', 'email' => true],
        // Cambiar el teléfono no amerita un correo.
        self::CUENTA_DATOS       => ['icono' => 'usuario',    'color' => 'azul',     'email' => false],
    ];

    /** Configuración de un tipo, con valores neutros si no se conoce. */
    public static function config(string $tipo): array
    {
        return self::CONFIG[$tipo]
            ?? ['icono' => 'campana', 'color' => 'azul', 'email' => false];
    }

    public static function existe(string $tipo): bool
    {
        return isset(self::CONFIG[$tipo]);
    }

    /** Todos los tipos conocidos (para el filtro del centro de avisos). */
    public static function todos(): array
    {
        return array_keys(self::CONFIG);
    }

    /**
     * Nombre legible de un tipo, para el filtro del centro de avisos.
     *
     * Está separado de CONFIG porque son dos cosas distintas: CONFIG dice
     * cómo se ENTREGA el aviso (icono, color, correo) y esto dice cómo se
     * LLAMA. Mezclarlos haría que agregar una traducción obligue a tocar
     * la tabla de configuración de entrega.
     *
     * El valor por omisión convierte `turno_reservado` en "Turno
     * reservado": si alguien agrega un tipo y se olvida de poner su
     * nombre, el filtro muestra algo legible en vez de la clave cruda.
     */
    public static function etiqueta(string $tipo): string
    {
        $nombres = [
            self::TURNO_RESERVADO    => 'Turno reservado',
            self::TURNO_CONFIRMADO   => 'Turno confirmado',
            self::TURNO_CANCELADO    => 'Turno cancelado',
            self::TURNO_REPROGRAMADO => 'Turno reprogramado',
            self::TURNO_RECORDATORIO => 'Recordatorio de turno',
            self::PAGO_APROBADO      => 'Pago aprobado',
            self::PAGO_RECHAZADO     => 'Pago rechazado',
            self::PAGO_POR_VENCER    => 'Pago por vencer',
            self::ESTUDIO_PEDIDO     => 'Estudio solicitado',
            self::RESULTADOS_LISTOS  => 'Resultados disponibles',
            self::RECETA_NUEVA       => 'Receta nueva',
            self::RECETA_ANULADA     => 'Receta anulada',
            self::REFILL_SOLICITADO  => 'Renovación solicitada',
            self::REFILL_APROBADO    => 'Renovación aprobada',
            self::REFILL_RECHAZADO   => 'Renovación rechazada',
            self::MENSAJE_MEDICO     => 'Mensaje del profesional',
            self::CUENTA_BIENVENIDA  => 'Bienvenida',
            self::CUENTA_PASSWORD    => 'Cambio de contraseña',
            self::CUENTA_EMAIL       => 'Cambio de correo',
            self::CUENTA_DATOS       => 'Datos actualizados',
        ];

        return $nombres[$tipo] ?? ucfirst(str_replace('_', ' ', $tipo));
    }
}

// =============================================================
// EL AVISO
// =============================================================

/**
 * Un hecho que hay que comunicar.
 *
 * Es un objeto de datos, no una entidad: se arma, se despacha y se
 * descarta. Lo que queda guardado es la fila en `notificacion`.
 *
 * Los campos de correo son OPCIONALES y sólo enriquecen el mail (una
 * tabla de detalle, un código destacado, un botón). Si no se completan,
 * el correo se arma igual con el título y el mensaje: nunca hay que
 * escribir el aviso dos veces.
 */
class Aviso
{
    public function __construct(
        public string  $tipo,
        public string  $titulo,
        public string  $mensaje,
        /** Ruta interna a la que lleva el aviso (relativa a BASE_URL). */
        public ?string $url = null,
        /** Id del turno / pago / receta que lo originó. */
        public ?int    $referencia = null,
        /** Opciones extra para emailPlantilla(): datos, destacado, boton, aviso, nota, parrafos. */
        public array   $email = [],
        /** true/false fuerza el envío por correo; null respeta el tipo. */
        public ?bool   $forzarEmail = null,
        /** Texto del botón del correo. */
        public string  $textoBoton = 'Ver en MediTurnos',
    ) {
    }

    /** ¿Este aviso sale por correo? */
    public function vaPorEmail(): bool
    {
        return $this->forzarEmail ?? TipoAviso::config($this->tipo)['email'];
    }
}

// =============================================================
// CANALES
// =============================================================

/**
 * Una vía de entrega. Agregar push, SMS o WhatsApp es implementar esto
 * y registrarlo en el Notificador; nada más del sistema se entera.
 */
interface CanalNotificacion
{
    public function nombre(): string;

    /** ¿Se puede usar ahora? (config cargada, servicio disponible…) */
    public function disponible(array $destinatario, Aviso $aviso): bool;

    /** @return bool true si se entregó */
    public function entregar(array $destinatario, Aviso $aviso): bool;
}

/**
 * Canal DENTRO de la aplicación: la fila en `notificacion` que alimenta
 * el centro de avisos. Es el único canal que nunca se saltea: aunque el
 * correo falle o la persona no tenga email cargado, el aviso queda.
 */
class CanalApp implements CanalNotificacion
{
    private Notificacion $modelo;
    private ?int $ultimoId = null;

    public function __construct(Notificacion $modelo)
    {
        $this->modelo = $modelo;
    }

    public function nombre(): string
    {
        return 'app';
    }

    public function disponible(array $destinatario, Aviso $aviso): bool
    {
        return true;
    }

    public function entregar(array $destinatario, Aviso $aviso): bool
    {
        try {
            $this->ultimoId = $this->modelo->crear([
                'id_usuario'    => (int) $destinatario['id_usuario'],
                'tipo'          => $aviso->tipo,
                'titulo'        => $aviso->titulo,
                'mensaje'       => $aviso->mensaje,
                'url_accion'    => $aviso->url,
                'id_referencia' => $aviso->referencia,
            ]);
            return true;
        } catch (PDOException $e) {
            error_log('CanalApp: ' . $e->getMessage());
            return false;
        }
    }

    /** Id de la fila recién creada (para marcarle el envío de correo). */
    public function ultimoId(): ?int
    {
        return $this->ultimoId;
    }
}

/**
 * Canal CORREO: arma el HTML con la plantilla y lo despacha con el
 * Mailer configurado (SMTP real si hay credenciales, archivo si no).
 */
class CanalEmail implements CanalNotificacion
{
    private Mailer $mailer;

    public function __construct(Mailer $mailer)
    {
        $this->mailer = $mailer;
    }

    public function nombre(): string
    {
        return 'email';
    }

    public function disponible(array $destinatario, Aviso $aviso): bool
    {
        // Sin dirección no hay nada que hacer; y si el tipo de aviso no
        // lleva correo, tampoco.
        return $aviso->vaPorEmail()
            && !empty($destinatario['email'])
            && filter_var($destinatario['email'], FILTER_VALIDATE_EMAIL) !== false;
    }

    public function entregar(array $destinatario, Aviso $aviso): bool
    {
        $opciones = $aviso->email;

        // Valores por defecto tomados del propio aviso: quien dispara la
        // notificación no tiene que redactar dos veces lo mismo.
        $opciones['titulo']    ??= $aviso->titulo;
        $opciones['preheader'] ??= $aviso->mensaje;
        $opciones['saludo']    ??= 'Hola, ' . trim($destinatario['nombre'] ?? '');
        $opciones['parrafos']  ??= [$aviso->mensaje];

        if ($aviso->url && empty($opciones['boton'])) {
            $opciones['boton'] = [
                'texto' => $aviso->textoBoton,
                'url'   => urlAbsoluta($aviso->url),
            ];
        }

        $asunto = ($opciones['asunto'] ?? $aviso->titulo) . ' — MediTurnos';

        return $this->mailer->enviar(
            $destinatario['email'],
            $asunto,
            emailPlantilla($opciones)
        );
    }
}

// =============================================================
// EL SERVICIO
// =============================================================

class Notificador
{
    /** @var CanalNotificacion[] */
    private array $canales = [];

    private Notificacion $modelo;
    private CanalApp $canalApp;

    public function __construct(PDO $pdo, ?Mailer $mailer = null)
    {
        $this->modelo   = new Notificacion($pdo);
        $this->canalApp = new CanalApp($this->modelo);

        $this->canales[] = $this->canalApp;
        $this->canales[] = new CanalEmail($mailer ?? obtenerMailer());
    }

    /** Enchufa un canal nuevo (push, SMS…) sin tocar nada más. */
    public function agregarCanal(CanalNotificacion $canal): void
    {
        $this->canales[] = $canal;
    }

    /**
     * Despacha un aviso por todos los canales que correspondan.
     *
     * @return array<string,bool> Resultado por canal: ['app'=>true, 'email'=>false]
     *
     * NUNCA lanza excepción. Una notificación es un efecto colateral de
     * una operación que ya salió bien: si el servidor de correo está
     * caído, lo último que debe pasar es que se revierta el pago que el
     * paciente acaba de hacer. Los fallos se registran y se siguen.
     */
    public function notificar(int $idUsuario, Aviso $aviso): array
    {
        $resultado = [];

        $destinatario = $this->modelo->destinatario($idUsuario);
        if (!$destinatario || $destinatario['estado'] !== 'activo') {
            error_log('Notificador: usuario ' . $idUsuario . ' inexistente o inactivo');
            return $resultado;
        }

        foreach ($this->canales as $canal) {
            $nombre = $canal->nombre();
            try {
                if (!$canal->disponible($destinatario, $aviso)) {
                    continue;
                }
                $resultado[$nombre] = $canal->entregar($destinatario, $aviso);
            } catch (Throwable $e) {
                error_log('Notificador[' . $nombre . ']: ' . $e->getMessage());
                $resultado[$nombre] = false;
            }
        }

        // Deja registrado que el correo salió, para poder responder
        // "¿me llegó el mail?" sin abrir el log del servidor.
        if (!empty($resultado['email']) && $this->canalApp->ultimoId()) {
            try {
                $this->modelo->marcarEmailEnviado($this->canalApp->ultimoId());
            } catch (PDOException $e) {
                error_log('Notificador marcarEmailEnviado: ' . $e->getMessage());
            }
        }

        return $resultado;
    }

    /**
     * Avisa una sola vez por el mismo motivo.
     *
     * Para recordatorios: la tarea que los dispara corre en cada visita,
     * y sin este control el paciente recibiría un correo por cada página
     * que abriera.
     */
    public function notificarUnaVez(int $idUsuario, Aviso $aviso): array
    {
        if ($aviso->referencia !== null
            && $this->modelo->yaAvisado($idUsuario, $aviso->tipo, $aviso->referencia)) {
            return [];
        }
        return $this->notificar($idUsuario, $aviso);
    }

    /**
     * Avisa a un paciente por su id de ficha (no de cuenta).
     *
     * `$unaVez` existe para las tareas por tiempo (recordatorios, avisos
     * de vencimiento): esas corren una y otra vez sobre las mismas filas,
     * y sin el control mandarían un correo por cada corrida. El resto de
     * los avisos nacen de una acción concreta que ocurre una sola vez, y
     * ahí el control sobraría.
     */
    public function notificarPaciente(int $idPaciente, Aviso $aviso, bool $unaVez = false): array
    {
        $idUsuario = $this->modelo->usuarioDePaciente($idPaciente);
        // Una ficha sin cuenta es normal: la recepción carga pacientes
        // que nunca se registraron. No es un error, simplemente no hay
        // a quién avisarle.
        if (!$idUsuario) {
            return [];
        }
        return $unaVez
            ? $this->notificarUnaVez($idUsuario, $aviso)
            : $this->notificar($idUsuario, $aviso);
    }

    /** Avisa a un médico por su matrícula. */
    public function notificarMedico(int $matricula, Aviso $aviso, bool $unaVez = false): array
    {
        $idUsuario = $this->modelo->usuarioDeMedico($matricula);
        if (!$idUsuario) {
            return [];
        }
        return $unaVez
            ? $this->notificarUnaVez($idUsuario, $aviso)
            : $this->notificar($idUsuario, $aviso);
    }

    public function modelo(): Notificacion
    {
        return $this->modelo;
    }
}

/** Instancia lista para usar, con los canales por defecto. */
function obtenerNotificador(PDO $pdo): Notificador
{
    static $instancia = null;
    if ($instancia === null) {
        $instancia = new Notificador($pdo);
    }
    return $instancia;
}

# Arquitectura

## Panorama general

MediTurnos es una aplicación **monolítica renderizada en el servidor**, con un
patrón MVC implementado a mano sobre PHP puro. No usa framework ni gestor de
dependencias: el motivo está registrado en [ADR-0001](adr/0001-sin-framework.md).

```
┌─────────────────────────────────────────────────────────────┐
│  NAVEGADOR                                                  │
│  HTML renderizado en el servidor + JS puntual (calendario,  │
│  modales, validación visual)                                │
└───────────────────────────┬─────────────────────────────────┘
                            │ HTTP
┌───────────────────────────▼─────────────────────────────────┐
│  APACHE + PHP                                               │
│                                                             │
│  Puntos de entrada          index / login / registro /      │
│                             dashboard / Controlador*.php    │
│         │                                                   │
│         ▼                                                   │
│  Seguridad                  seguridad.php  → sesión, CSP    │
│                             auth.php       → rol, permisos  │
│         │                                                   │
│         ▼                                                   │
│  Controladores              validan, orquestan, redirigen   │
│         │                                                   │
│         ▼                                                   │
│  Modelos                    ÚNICO lugar que habla con la DB │
│         │                                                   │
│         ▼                                                   │
│  Vistas                     sólo presentación               │
└───────────────────────────┬─────────────────────────────────┘
                            │ PDO
┌───────────────────────────▼─────────────────────────────────┐
│  MariaDB / InnoDB                                           │
│  Tablas · Vistas · Triggers · Procedimientos ·              │
│  Índices únicos que garantizan la consistencia              │
└─────────────────────────────────────────────────────────────┘
```

## Múltiples puntos de entrada

A diferencia de un framework con *front controller* único, acá **cada
`Controlador*.php` es una URL real** que Apache ejecuta directamente:

```
http://localhost/mediturnos/sistema/controladores/ControladorTurno.php?accion=index
                                                  └── archivo real en disco ──┘
```

Apache entrega el archivo a PHP, que lo ejecuta de arriba a abajo. El `switch
($accion)` decide qué hacer y termina incluyendo la vista correspondiente.

**Ventaja:** simplicidad, sin capa de enrutado que entender.
**Desventaja:** no hay un punto único donde aplicar middleware; cada controlador
repite las llamadas a `verificarSesion()` y `verificarRol()`.

## Cómo viajan los datos del controlador a la vista

No hay motor de plantillas ni paso de parámetros. Se aprovecha que **`require`
ejecuta el archivo incluido en el mismo ámbito de variables**:

```php
// ControladorMedico.php
$especialidades = $modelo->obtenerEspecialidades();   // se define acá
require __DIR__ . '/../vistas/medicos/nuevo.php';     // se usa allá
```

Dentro de `nuevo.php`, `$especialidades` simplemente existe. Es comportamiento
nativo de PHP, no del proyecto.

**A tener en cuenta:** el acoplamiento es implícito. Ninguna firma declara qué
variables espera una vista; hay que leer el controlador. Al agregar una vista,
verificar siempre qué variables necesita.

## Las tres capas

### Modelos (`sistema/modelos/`)

Nueve clases, una por entidad. Son el **único lugar del proyecto que ejecuta
SQL**. Reciben el `PDO` por constructor (inyección de dependencias):

```php
class Turno {
    public function __construct(private PDO $pdo) {}
    public function listar(array $filtros = []): array { /* ... */ }
}
```

Ningún modelo imprime HTML ni redirige. Devuelven arrays o lanzan excepciones.

### Controladores (`sistema/controladores/`)

Diez archivos con la misma estructura:

```php
require conexion + auth + modelo
verificarSesion();
verificarRol([...]);
csrf_verificar();

switch ($accion) {
    case 'index':  /* consultar */ require vista; break;
    case 'guardar': /* validar, guardar, redirigir */ exit;
}
```

Tras una operación exitosa **siempre redirigen** (patrón POST/Redirect/GET), para
que al recargar no se repita el envío.

`ControladorAuth` es la excepción: es una clase con métodos, porque no se visita
como URL sino que lo incluyen `login.php`, `registro.php` y `logout.php`, cada uno
con su propio flujo.

### Vistas (`sistema/vistas/`)

Sólo presentación. Cada una abre con `navbar.php` y cierra con `footer.php`:

```php
$paginaTitulo = 'Turnos';
require __DIR__ . '/../layouts/navbar.php';   // abre <html>, sidebar, topbar
/* ... HTML propio ... */
require __DIR__ . '/../layouts/footer.php';   // cierra todo + JS común
```

Se partió en dos archivos —en vez de una función `renderLayout($contenido)`—
para que cada vista escriba su HTML con sintaxis PHP normal en el medio, sin
tener que armarlo como string.

## Lógica en la base de datos

Parte de las reglas de negocio vive en el motor, no en PHP. **Es deliberado**:
son reglas que deben cumplirse aunque el código falle o haya concurrencia.

| Mecanismo | Qué garantiza |
|---|---|
| Índice único `uq_turno_slot` | Un solo turno activo por médico/fecha/hora |
| Índice único `uq_turno_consultorio` | Un solo turno activo por consultorio/fecha/hora |
| SP `ReservarTurno` | Valida y crea el turno de forma atómica |
| SP `CancelarTurno` | Rechaza cancelar un turno ya finalizado |
| Trigger `trg_turno_after_update` | Registra cada cambio de estado en el historial |
| Columna generada `pago.monto_total` | El total no puede quedar inconsistente |

### Por qué el índice único y no una validación en PHP

Validar en PHP («¿está libre este horario?» y después insertar) deja una ventana
entre las dos operaciones. Con dos usuarios simultáneos:

```
Usuario A: ¿libre? → sí
Usuario B: ¿libre? → sí          ← ambos pasaron el chequeo
Usuario A: INSERT  → ok
Usuario B: INSERT  → ok          ← turno duplicado
```

Con el índice único, el segundo `INSERT` es rechazado por el motor (error 1062) y
el PHP lo traduce a un mensaje claro. **La condición de carrera desaparece.**

## Patrones utilizados

| Patrón | Dónde |
|---|---|
| MVC | Estructura general |
| POST/Redirect/GET | Todos los formularios |
| Inyección de dependencias | `PDO` inyectado en cada modelo |
| Front Controller (parcial) | `dashboard.php` rutea por rol |
| Strategy | `Mailer` con implementación SMTP o archivo |
| Template Method | `navbar` + contenido + `footer` |
| Repository (informal) | Los modelos abstraen el acceso a datos |
| Mejora progresiva | Landing y registro funcionan sin JavaScript |

## Decisiones registradas (ADR)

- [ADR-0001](adr/0001-sin-framework.md) — PHP puro sin framework
- [ADR-0002](adr/0002-concurrencia-en-la-base.md) — Concurrencia resuelta en el motor
- [ADR-0003](adr/0003-mailer-intercambiable.md) — Correo con implementaciones intercambiables
- [ADR-0004](adr/0004-login-usuario-o-email.md) — Login por usuario o email

## Deuda técnica conocida

| Tema | Situación |
|---|---|
| Dos sistemas de autorización | Conviven `verificarRol()` y `verificarPermiso()`. Ver [authorization.md](authorization.md) |
| CSP con `unsafe-inline` | Hay `<script>` y `style=""` en las vistas; quitarlos exige reescribirlas |
| Tareas de mantenimiento por petición | `expirarVencidos()` corre en cada visita en lugar de por cron |
| Sin pruebas automatizadas | La verificación es manual |

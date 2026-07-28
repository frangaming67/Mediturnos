# Comunicacion entre archivos

> Apunte original del proyecto, conservado tal cual se escribió durante
> el desarrollo. La documentación técnica vigente está en la carpeta
> `docs/` (un nivel arriba); estos textos se mantienen como referencia
> histórica y material de estudio para la defensa.

```text
================================================================
MEDITURNOS — CÓMO SE COMUNICAN LOS ARCHIVOS ENTRE SÍ
================================================================

MECANISMOS DE COMUNICACIÓN
---------------------------
El proyecto usa 4 formas de pasar información entre archivos:

  1. require / require_once  → incluir un archivo PHP dentro de otro
  2. Variables PHP            → pasar datos de controlador a vista
  3. $_GET / $_POST          → datos que viajan desde el navegador al servidor
  4. $_SESSION               → datos que persisten entre peticiones

================================================================
1. require / require_once
================================================================
Es la forma más directa: un archivo incluye el código de otro.

config/conexion.php
  → Es incluido por TODOS los controladores con require_once.
  → Crea la variable $pdo que es la conexión a MySQL.
  → Se usa require_once (no require) para evitar que se incluya dos veces
    si varios archivos lo piden.

includes/auth.php
  → Es incluido por todos los controladores.
  → Define las funciones verificarSesion(), verificarRol(), tienePermiso().
  → Estas funciones leen $_SESSION directamente.

sistema/modelos/Turno.php
  → Es incluido por ControladorTurno.php con require_once.
  → Define la clase Turno. El controlador la instancia pasándole $pdo:
      $modelo = new Turno($pdo)

sistema/vistas/layouts/navbar.php
  → Es incluido por CADA vista al principio con require.
  → Genera el DOCTYPE, el <head> con el CSS, la barra lateral y el <main>.
  → Lee $_SESSION para mostrar el nombre del usuario y su rol.
  → Lee $paginaTitulo y $breadcrumb que el controlador define antes.

sistema/vistas/layouts/footer.php
  → Es incluido por cada vista al final con require.
  → Cierra las etiquetas <main>, <div>, <footer>, <body>, <html>.
  → Incluye el JavaScript global (sidebar, modales).

================================================================
2. Variables PHP — del controlador a la vista
================================================================
El controlador define variables antes de hacer require de la vista.
La vista las usa directamente porque PHP comparte el scope.

Ejemplo en ControladorTurno.php:
  $turnos  = $modelo->listar($filtros);   // array de turnos
  $medicos = $modelo->listarMedicos();    // array de médicos
  require __DIR__ . '/../vistas/turnos/index.php';

La vista index.php puede usar $turnos y $medicos directamente:
  foreach ($turnos as $t) { ... }
  foreach ($medicos as $m) { ... }

También se usan $mensaje y $tipoMsg para mostrar alertas:
  // En el controlador:
  $mensaje = 'Turno reservado correctamente.';
  $tipoMsg = 'exito';

  // En la vista:
  <?php if ($mensaje): ?>
    <div class="alerta alerta-<?= $tipoMsg ?>">...</div>
  <?php endif; ?>

================================================================
3. $_GET y $_POST — datos del navegador al servidor
================================================================

$_GET → se usa para:
  - Indicar qué acción ejecutar: ?accion=index, ?accion=nuevo, ?accion=reservar
  - Filtros de búsqueda: ?fecha=2026-06-01&estado=Reservado
  - Mensajes de confirmación: ?msg=reservado
  - Mensajes de error en redirecciones: ?err=Turno+no+encontrado

$_POST → se usa para:
  - Formularios que envían datos a guardar (login, nuevo turno, nuevo paciente)
  - El campo 'paso' que indica en qué etapa está el formulario de dos pasos
  - Cualquier dato sensible que no debe aparecer en la URL

¿POR QUÉ ALGUNOS ERRORES VAN POR GET Y OTROS POR POST?
-------------------------------------------------------
Cuando una acción que escribe en la base (accion=reservar) falla,
no puede mostrar la vista directamente porque no tiene los datos necesarios
para rellenar los selects de la vista (lista de médicos, planes, etc.).

La solución es redirigir con header('Location: ...?err=mensaje')
a la acción que SÍ carga esos datos (?accion=nuevo).

Esa acción lee $_GET['err'] y lo muestra como alerta.

================================================================
4. $_SESSION — datos que persisten entre peticiones
================================================================
La sesión se inicia en login.php con session_start() y se mantiene
hasta que el usuario cierra sesión o pasan 30 minutos de inactividad.

Datos que viajan en sesión:
  $_SESSION['id_usuario']   → para saber quién está logueado
  $_SESSION['rol']          → para verificarRol() en cada controlador
  $_SESSION['permisos']     → array con permisos del rol
  $_SESSION['id_paciente']  → para que el paciente reserva en su nombre
  $_SESSION['matricula']    → para que el médico vea sus propios turnos
  $_SESSION['ultimo_acceso']→ para el timeout de 30 minutos

================================================================
DIAGRAMA DE COMUNICACIÓN PARA UNA RESERVA
================================================================

NAVEGADOR                    SERVIDOR (PHP)                    MYSQL
────────                    ──────────────                    ─────

GET ?accion=nuevo
──────────────────>
                            ControladorTurno.php
                              verificarSesion()    ← $_SESSION
                              $modelo->listarMedicos()
                                                   ─────────> SELECT medico
                                                   <───────── array médicos
                              $modelo->listarPlanes()
                                                   ─────────> SELECT plan_os JOIN obra_social
                                                   <───────── array planes
                              require 'nuevo.php'
                              (vista genera HTML con $medicos, $planes)
<──────────────────
HTML con formulario paso 1

POST paso=2, matricula=10001, fecha=2026-06-02, id_plan=1
──────────────────>
                            ControladorTurno.php
                              $modelo->obtenerSlots(10001, '2026-06-02')
                                                   ─────────> SELECT horario_atencion
                                                   <───────── horarios del médico
                                                   ─────────> SELECT COUNT(*) FROM turno (por cada slot)
                                                   <───────── 0 = libre, >0 = ocupado
                              require 'nuevo.php'
                              (vista genera HTML con $slots como radio buttons)
<──────────────────
HTML con paso 2 (botones de horario)

POST slot=08:20:00|1|1, matricula=10001, fecha=..., id_plan=1
──────────────────>
                            ControladorTurno.php (accion=reservar)
                              explode('|', $_POST['slot'])
                              $modelo->reservar($datos)
                                                   ─────────> INSERT INTO turno
                                                   (trigger automático)
                                                   ─────────> INSERT INTO historial_turno
                                                   <───────── OK
                              header('Location: ?accion=index&msg=reservado')
<──────────────────
Redirección a lista de turnos

GET ?accion=index&msg=reservado
──────────────────>
                            ControladorTurno.php
                              $modelo->listar()    ─────────> SELECT turno JOIN ...
                                                   <───────── array turnos
                              require 'index.php'
                              (vista lee $_GET['msg'] y muestra alerta verde)
<──────────────────
HTML lista de turnos con "Turno reservado correctamente."
```

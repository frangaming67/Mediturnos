# Arquitectura MVC

> Apunte original del proyecto, conservado tal cual se escribió durante
> el desarrollo. La documentación técnica vigente está en la carpeta
> `docs/` (un nivel arriba); estos textos se mantienen como referencia
> histórica y material de estudio para la defensa.

```text
================================================================
MEDITURNOS — ARQUITECTURA GENERAL (PATRÓN MVC)
================================================================

¿QUÉ ES MVC?
------------
MVC significa Modelo - Vista - Controlador.
Es una forma de organizar el código separando tres responsabilidades:

  MODELO      → todo lo que tiene que ver con la base de datos
  VISTA       → todo lo que ve el usuario (el HTML)
  CONTROLADOR → el intermediario: recibe la petición, llama al modelo,
                y le pasa los datos a la vista

La regla principal es que cada capa solo hace su trabajo:
- El modelo no sabe cómo se muestra la información
- La vista no sabe de dónde vienen los datos
- El controlador no guarda datos ni genera HTML

¿POR QUÉ USAR MVC?
------------------
Si en el futuro necesitás cambiar cómo se muestran los turnos,
solo tocás la vista. Si cambia la base de datos, solo tocás el modelo.
No tenés que buscar lógica mezclada con HTML o SQL mezclado con HTML.

ESTRUCTURA DE CARPETAS
----------------------
mediturnos/
│
├── config/
│   └── conexion.php          → Configura la conexión a MySQL (PDO)
│
├── includes/
│   └── auth.php              → Funciones de sesión y permisos
│
├── sistema/
│   ├── modelos/              → CAPA MODELO
│   │   ├── Turno.php
│   │   ├── Medico.php
│   │   ├── Paciente.php
│   │   └── Usuario.php
│   │
│   ├── controladores/        → CAPA CONTROLADOR
│   │   ├── ControladorTurno.php
│   │   ├── ControladorMedico.php
│   │   ├── ControladorPaciente.php
│   │   ├── ControladorUsuario.php
│   │   └── ControladorAuth.php
│   │
│   └── vistas/               → CAPA VISTA
│       ├── layouts/
│       │   ├── navbar.php    → Menú lateral (se incluye en todas las páginas)
│       │   ├── footer.php    → Pie de página con JS global
│       │   └── 403.php       → Página de acceso denegado
│       ├── turnos/
│       ├── medicos/
│       ├── pacientes/
│       └── usuarios/
│
├── publico/
│   └── css/
│       └── estilos.css       → Estilos globales de toda la app
│
├── login.php                 → Página de inicio de sesión
├── logout.php                → Cierra la sesión
└── dashboard.php             → Página principal después del login

FLUJO GENERAL DE UNA PETICIÓN
------------------------------
El usuario hace click o envía un formulario →
  1. El navegador envía una petición HTTP al servidor
  2. El CONTROLADOR la recibe (ej: ControladorTurno.php)
  3. El controlador llama al MODELO para obtener o guardar datos
  4. El modelo ejecuta la consulta SQL y devuelve los datos
  5. El controlador pasa los datos a la VISTA (require 'vista.php')
  6. La vista genera el HTML con esos datos
  7. El usuario ve la página actualizada

Ejemplo concreto:
  Usuario hace click en "Turnos" en el menú
  → ControladorTurno.php?accion=index
  → Controlador llama a $modelo->listar()
  → Modelo hace SELECT en la tabla turno con JOINs
  → Controlador recibe el array $turnos
  → Controlador hace require 'vistas/turnos/index.php'
  → Vista itera $turnos y genera la tabla HTML
```

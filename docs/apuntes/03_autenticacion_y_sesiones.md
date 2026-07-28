# Autenticacion y sesiones

> Apunte original del proyecto, conservado tal cual se escribió durante
> el desarrollo. La documentación técnica vigente está en la carpeta
> `docs/` (un nivel arriba); estos textos se mantienen como referencia
> histórica y material de estudio para la defensa.

```text
================================================================
MEDITURNOS — AUTENTICACIÓN Y SESIONES
================================================================

¿QUÉ ES UNA SESIÓN EN PHP?
---------------------------
HTTP es un protocolo sin estado: el servidor no recuerda quién sos
entre una petición y la siguiente.

Las sesiones solucionan esto. PHP guarda datos en el servidor
y le da al navegador una cookie con un ID único.
En cada petición siguiente, el navegador manda ese ID
y el servidor recupera los datos guardados.

ARCHIVOS INVOLUCRADOS
---------------------
  login.php              → Formulario de ingreso + llamada al controlador
  ControladorAuth.php    → Lógica de login y logout
  Usuario.php (modelo)   → Consulta la base para verificar credenciales
  includes/auth.php      → Funciones de protección que usan los controladores
  logout.php             → Cierra la sesión

FLUJO DE LOGIN PASO A PASO
---------------------------
1. El usuario escribe usuario y contraseña en login.php y hace click en "Ingresar"

2. login.php verifica que el método sea POST y llama a:
     $ctrl->login($_POST['usuario'], $_POST['contrasenia'])

3. ControladorAuth->login() llama al modelo:
     $user = $modelo->buscarParaLogin($usuarioInput)
   Esto ejecuta un SELECT que une usuario y rol, solo trae usuarios activos.

4. Si el usuario existe, se verifica la contraseña con:
     password_verify($passInput, $user['contrasenia'])
   La contraseña en la base está guardada como hash bcrypt (no en texto plano).
   password_verify() compara el texto ingresado contra ese hash.

5. Si la verificación es correcta:
   - session_regenerate_id(true) → crea un nuevo ID de sesión
     (previene Session Fixation: que alguien robe el ID antes del login)
   - Se guardan datos en $_SESSION:
       $_SESSION['id_usuario']
       $_SESSION['nombre']
       $_SESSION['apellido']
       $_SESSION['rol']          ← muy importante para los permisos
       $_SESSION['permisos']     ← array con los permisos del rol
       $_SESSION['id_paciente']  ← si el usuario es paciente, su id_paciente
       $_SESSION['ultimo_acceso']

6. Se redirige al dashboard.php

¿POR QUÉ SE GUARDA id_paciente EN LA SESIÓN?
--------------------------------------------
Cuando un usuario con rol 'paciente' reserva un turno,
el sistema necesita saber qué registro de la tabla paciente le corresponde.
En lugar de buscarlo cada vez, se guarda directo en la sesión al hacer login.

PROTECCIÓN DE PÁGINAS (includes/auth.php)
-----------------------------------------
Todos los controladores llaman a verificarSesion() al inicio.

  function verificarSesion()
    → Verifica que $_SESSION['id_usuario'] exista.
    → Controla el timeout: si pasaron más de 30 minutos sin actividad,
      destruye la sesión y redirige al login.
    → Actualiza $_SESSION['ultimo_acceso'] en cada petición.

  function verificarRol(array $rolesPermitidos)
    → Verifica que el rol del usuario esté en la lista permitida.
    → Si no tiene permiso, devuelve error 403 (Acceso denegado).
    → Se usa así:
        verificarRol(['admin', 'recepcionista'])
        → solo admin y recepcionista pueden continuar

  function tienePermiso(string $permiso)
    → Verifica si el usuario tiene un permiso específico.
    → Los permisos vienen de la tabla rol_permiso y se guardan en sesión.

PERMISOS POR ROL
----------------
  admin          → todo: ver, crear, editar, cancelar turnos, pacientes, médicos, usuarios
  recepcionista  → ver/crear/cancelar turnos, ver/crear/editar pacientes, ver médicos
  medico         → ver turnos, cambiar estado, ver pacientes
  paciente       → ver sus turnos, crear turnos, cancelar sus propios turnos

FLUJO DE LOGOUT
---------------
logout.php llama a $ctrl->logout() que hace tres pasos:
  1. $_SESSION = []              → vacía el array de sesión
  2. setcookie(...)              → elimina la cookie del navegador
  3. session_destroy()           → elimina los datos del servidor
  4. Redirige a login.php?msg=logout_ok

Hacer solo uno de los tres pasos no sería suficiente.
Si solo se hace session_destroy() sin borrar la cookie,
el navegador seguiría mandando el ID viejo.
```

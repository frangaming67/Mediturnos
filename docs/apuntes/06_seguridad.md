# Seguridad

> Apunte original del proyecto, conservado tal cual se escribió durante
> el desarrollo. La documentación técnica vigente está en la carpeta
> `docs/` (un nivel arriba); estos textos se mantienen como referencia
> histórica y material de estudio para la defensa.

```text
================================================================
MEDITURNOS — DECISIONES DE SEGURIDAD
================================================================

1. CONTRASEÑAS CON BCRYPT
--------------------------
Las contraseñas no se guardan en texto plano en la base de datos.
Se usa el algoritmo bcrypt a través de las funciones nativas de PHP:

  Al crear el usuario:
    $hash = password_hash($contrasenia, PASSWORD_DEFAULT)
    Se guarda $hash en la columna contrasenia de la tabla usuario.

  Al hacer login:
    password_verify($input, $hashDeLaBase)
    Devuelve true si el input coincide con el hash.

¿Por qué bcrypt y no MD5 o SHA1?
Bcrypt es lento por diseño: tarda unos milisegundos en calcular el hash.
Para un usuario legítimo eso no importa, pero para alguien que intenta
adivinar millones de contraseñas, ese costo se multiplica.
MD5 y SHA1 son tan rápidos que se pueden probar miles de millones por segundo.

2. PREPARED STATEMENTS (prevención de SQL Injection)
------------------------------------------------------
SQL Injection ocurre cuando un atacante escribe código SQL en un campo
de texto y ese código se ejecuta en la base de datos.

Ejemplo de código VULNERABLE:
  $sql = "SELECT * FROM usuario WHERE usuario = '$_POST[usuario]'";
  Si alguien escribe: admin' OR '1'='1
  La consulta se convierte en:
    SELECT * FROM usuario WHERE usuario = 'admin' OR '1'='1'
  Y devuelve todos los usuarios.

El proyecto usa prepared statements con PDO en todos lados:
  $stmt = $pdo->prepare("SELECT * FROM usuario WHERE usuario = :u");
  $stmt->execute([':u' => $_POST['usuario']]);
  PDO escapa automáticamente el valor. No importa lo que escriba el usuario.

3. htmlspecialchars() EN LAS VISTAS (prevención de XSS)
---------------------------------------------------------
XSS (Cross-Site Scripting) ocurre cuando datos del usuario se muestran
en el HTML sin escapar, permitiendo inyectar código JavaScript.

Ejemplo vulnerable:
  echo $turno['observacion'];  // si alguien guardó <script>alert('hack')</script>
                                // ese script se ejecutaría en el navegador

El proyecto usa htmlspecialchars() en cada dato que se muestra:
  echo htmlspecialchars($turno['observacion']);
  Convierte < en &lt; y > en &gt;, por lo que el navegador lo muestra
  como texto, no como código.

4. VERIFICACIÓN DE ROL EN CADA ACCIÓN
--------------------------------------
No alcanza con verificar el rol una sola vez al entrar al sistema.
Cada acción que modifica datos verifica el rol explícitamente:

  case 'reservar':
    verificarRol(['admin', 'recepcionista', 'paciente']);

  case 'cambiarEstado':
    verificarRol(['admin', 'recepcionista', 'medico']);

Si un paciente intenta acceder a ?accion=cambiarEstado directamente
escribiendo la URL, recibe un error 403 (Acceso denegado).

5. EL PACIENTE SOLO PUEDE RESERVAR PARA SÍ MISMO
--------------------------------------------------
Cuando el usuario tiene rol 'paciente', el sistema ignora
cualquier id_paciente que venga del formulario:

  $id_paciente = ($_SESSION['rol'] === 'paciente')
      ? (int)($_SESSION['id_paciente'] ?? 0)
      : (int)($_POST['id_paciente'] ?? 0);

Así, aunque alguien manipule el HTML para cambiar el id_paciente,
el servidor usa el de la sesión. Un paciente no puede reservar
un turno que aparezca a nombre de otra persona.

6. SESSION FIXATION
--------------------
Al hacer login exitoso, el código llama a:
  session_regenerate_id(true)

Esto crea un nuevo ID de sesión diferente al que tenía antes del login.
Si un atacante hubiera conseguido el ID de sesión anterior (antes del login),
ese ID ya no sirve. El usuario legítimo tiene uno nuevo.

7. TIMEOUT DE SESIÓN
---------------------
En auth.php, verificarSesion() controla el tiempo de inactividad:

  $timeout = 30 * 60;  // 30 minutos en segundos
  if (time() - $_SESSION['ultimo_acceso'] > $timeout) {
      session_destroy();
      header('Location: login.php?exp=1');
  }

Si el usuario no hace nada en 30 minutos, su sesión se destruye.
Esto reduce el riesgo de que alguien use una computadora que dejaron abierta.

8. LOGOUT COMPLETO EN TRES PASOS
----------------------------------
Para cerrar la sesión correctamente, no alcanza con session_destroy().
El logout hace tres cosas:
  1. $_SESSION = []          → vacía los datos de sesión
  2. setcookie(...)          → elimina la cookie del navegador (borra el ID)
  3. session_destroy()       → elimina el archivo de sesión del servidor

Si solo se destruye la sesión sin borrar la cookie,
el navegador sigue mandando el ID viejo en la próxima petición.
```

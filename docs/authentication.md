# Autenticación

## Recorrido de una sesión

```
1. login.php
   iniciarSesionSegura()  → cookie HttpOnly + SameSite=Lax
   cabecerasSeguridad()   → nosniff, anti-clickjacking, CSP
        │
2. POST usuario + contraseña
        │
3. ¿Bloqueado por intentos fallidos?  ── sí ──> mensaje y corta
        │ no
4. ControladorAuth::login()
   buscarParaLogin()  → acepta NOMBRE DE USUARIO O EMAIL
   password_verify()  → compara contra el hash bcrypt
        │
   ✗ falla ──> registrarIntentoLogin(false) ──> "usuario o contraseña incorrectos"
        │ ✓
5. session_regenerate_id(true)   ← previene Session Fixation
        │
6. Se guardan en $_SESSION: id_usuario, nombre, apellido, rol,
   permisos, id_paciente, matricula, ultimo_acceso
        │
7. registrarLogin() → marca online = 1 en la base
        │
8. Redirección a dashboard.php   (patrón POST/Redirect/GET)
        │
9. En CADA página protegida: verificarSesion()
      · ¿hay id_usuario?              si no → login
      · ¿pasaron 30 min sin actividad? si sí → destruye y avisa
      · actualiza ultimo_acceso
        │
10. logout.php
    registrarLogout() → online = 0
    $_SESSION = []  ·  cookie expirada  ·  session_destroy()
```

## Datos guardados en la sesión

| Clave | Para qué |
|---|---|
| `id_usuario` | Identidad. Su ausencia = no autenticado |
| `nombre` / `apellido` | Saludo e iniciales del avatar |
| `rol` | Control de acceso y elección de dashboard |
| `permisos` | Array para el control fino |
| `id_paciente` | Aísla los datos de un paciente a los suyos |
| `matricula` | Aísla la agenda de un médico a la suya |
| `foto` | Avatar de la barra lateral, sin consultar la base en cada página |
| `ultimo_acceso` | Vencimiento por inactividad (30 min) |
| `csrf_token` | Secreto compartido con los formularios |

Se cachean para **no consultar la base en cada petición**. Se pierden al cerrar
sesión o al vencer por inactividad.

## Login por usuario o email

`buscarParaLogin()` acepta ambos en el mismo campo:

```sql
WHERE (u.usuario = :ident1 OR u.email = :ident2) AND u.estado = 'activo'
```

Una plataforma moderna se loguea con el correo, pero las cuentas existentes usan
alias. Aceptar los dos moderniza el acceso sin dejar afuera a nadie. Ver
[ADR-0004](adr/0004-login-usuario-o-email.md).

> Se usan dos marcadores distintos con el mismo valor porque con
> `PDO::ATTR_EMULATE_PREPARES = false` no se puede repetir uno.

## Registro

Tres pasos en un solo formulario (`registro.php`). Con JavaScript desactivado los
tres quedan visibles y el formulario sigue siendo utilizable.

**Todo se crea en una transacción**: ficha de paciente, cuenta de usuario y
cobertura. Si algo falla no puede quedar una ficha sin cuenta ni una cobertura
huérfana. Si el alta falla después de guardar la foto, la foto también se elimina.

### Validaciones del servidor

Las reglas viven en **`includes/validacion.php`** (clase `Validacion`), un solo
lugar para las tres pantallas que piden los mismos datos: el registro, el
restablecimiento por correo y el perfil.

| Campo | Regla | Método |
|---|---|---|
| Nombre / Apellido | Obligatorios, ≤30 (ancho real de la columna) | `nombre()` |
| DNI | 7 a 9 dígitos, sólo números, único | `dni()` |
| Teléfono | 8 a 15 dígitos (admite separadores) | `telefono()` |
| Email | Formato válido, ≤120, único | `email()` |
| Usuario | 4-40 caracteres `[a-zA-Z0-9._-]`, único | `usuario()` |
| Contraseña | ≥8, al menos una letra y un número, coincidente | `password()` |
| Fecha nac. | Fecha real, no futura, ≥1900 | `fechaNac()` |
| Sexo | Dentro del ENUM | `sexo()` |
| Dirección | ≤150 | `direccion()` |
| Nº afiliado | 3-50 caracteres `[A-Za-z0-9/-]` | `nroAfiliado()` |
| Plan | Debe existir en `plan_os` | consulta a la base |

Cada método devuelve el **mensaje de error** o `null` si el valor es válido:

```php
if ($err = Validacion::dni($dni)) return $err;
```

Todas se repiten en el servidor aunque el formulario ya valide: el JavaScript se
desactiva y el POST se arma a mano.

> **Por qué se unificaron.** Estaban escritas tres veces, y `restablecer.php`
> declaraba su propia constante `PASS_MIN = 8` sin relación con la de
> `ControladorAuth`. Subir el mínimo en un archivo y olvidarse del otro no habría
> dado ningún error: el sistema simplemente pediría contraseñas distintas según
> por dónde entrara la persona.

## Cambio de contraseña desde el perfil

`perfil.php` permite cambiarla con la sesión abierta, y aun así **exige la
contraseña actual**. No es burocracia: si alguien se levanta sin cerrar sesión,
cualquiera que pase podría cambiarle la clave y dejarlo afuera de su cuenta.
Pedir la actual convierte "tener la sesión abierta" en "además saber la
contraseña".

| Control | Motivo |
|---|---|
| Verifica la contraseña actual | Que la sesión abierta no alcance |
| Mismo bloqueo que el login, con identificador `perfil:usuario` | Pedir la actual abre la puerta a probarlas de a una. El prefijo evita que alguien con la sesión secuestrada deje al dueño sin poder iniciar sesión |
| La nueva debe ser distinta de la actual | Cambiarla por la misma no cambia nada y da falsa sensación de renovación |
| `session_regenerate_id(true)` al terminar | Si alguien hubiera robado la cookie, el id viejo deja de servirle en el momento en que el dueño cambia la clave |

**Verificado:** tras el cambio, la contraseña vieja deja de servir, la nueva
funciona, y la sesión de quien la cambió sigue abierta.

## Recuperación de contraseña

```
recuperar.php → email → (¿existe?) → token → correo
                                        │
restablecer.php?token=... → validar → nueva contraseña → quemar token
```

| Decisión | Motivo |
|---|---|
| Se guarda el **hash** del token | Si alguien leyera la tabla, no podría usar los enlaces pendientes |
| SHA-256, no bcrypt | El token ya es aleatorio de 32 bytes; no hace falta un hash lento |
| Un solo uso | Al usarse se marca; queda inservible aunque no haya vencido |
| Vence en 60 minutos | Reduce la ventana si el correo se filtra |
| Cambio + quemado en transacción | Si fallara el marcado, el enlace quedaría reutilizable |
| **Respuesta siempre idéntica** | Decir "ese email no existe" permitiría averiguar quién tiene cuenta en la clínica |
| Mismo límite que el login | Evita usarlo para enviar correo masivo a una casilla |

## Protección contra fuerza bruta

Tras **5 intentos fallidos en 15 minutos** desde la misma IP para el mismo
identificador, el acceso se bloquea temporalmente.

Se combinan identificador **e** IP a propósito: si el bloqueo dependiera sólo del
usuario, cualquiera podría dejar afuera a otra persona fallando su login adrede.

Al entrar correctamente se limpian los fallos previos, para que quien se equivocó
cuatro veces y acertó a la quinta no quede a un error del bloqueo.

**Verificado:** con la contraseña correcta, estando bloqueado, el acceso sigue
denegado.

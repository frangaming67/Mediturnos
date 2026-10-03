# Seguridad

## Amenazas cubiertas

| Amenaza | Estado | Cómo |
|---|---|---|
| SQL Injection | ✅ | Sentencias preparadas en el 100% de las consultas |
| XSS reflejado | ✅ | `htmlspecialchars` en toda salida |
| XSS almacenado | ✅ | `textContent` en JS; nunca `innerHTML` con datos de la base |
| CSRF | ✅ | Token por sesión comparado con `hash_equals`, **más POST obligatorio** en toda acción que modifica datos — ver abajo |
| Session Fixation | ✅ | `session_regenerate_id(true)` al autenticar |
| Session Hijacking | ⚠️ Parcial | Cookie `HttpOnly` + `SameSite`, timeout 30 min. `Secure` requiere HTTPS |
| Fuerza bruta | ✅ | 5 intentos / 15 min por identificador + IP. También al cambiar la contraseña desde el perfil |
| IDOR | ✅ | Filtro por propiedad en el SQL y verificación por recurso. En el perfil, el id **nunca** viaja en el formulario |
| Enumeración de usuarios | ✅ | La recuperación responde igual exista o no la cuenta |
| Subida de archivos | ✅ | Tipo real + re-codificación + nombre aleatorio + `.htaccess` |
| Clickjacking | ✅ | `X-Frame-Options: DENY` |
| Credenciales expuestas | ✅ | `config/mail.php` en `.gitignore` |
| Contraseñas | ✅ | bcrypt vía `password_hash` |
| Campos enviados como array | ⚠️ Parcial | Cubierto en el perfil, el historial, las recetas y las notificaciones; y `param()` cubre los parámetros de la URL en las vistas. Los formularios de los ABM todavía asumen texto |
| Manipulación de precio | ✅ | La cobertura del turno se valida contra las del paciente — ver abajo |
| Acceso a datos de salud | ✅ | Los resultados viven fuera de la carpeta pública y se entregan sólo tras verificar quién los pide |
| Redirección abierta | ✅ | La `url_accion` de un aviso se valida antes de ir a la cabecera `Location` — ver abajo |
| Inyección de cabeceras | ✅ | Mismo control: un salto de línea en un destino lo descarta |
| Buscadores con comodines | ✅ | `patronLike()` escapa `%` y `_`. No era inyección, era un buscador que mentía |

## Consultas preparadas

Todas las consultas usan marcadores. `PDO::ATTR_EMULATE_PREPARES = false` obliga a
que la preparación la haga el servidor MySQL, sin interpolación del lado de PHP.

Los filtros dinámicos arman el `WHERE` con marcadores, nunca concatenando valores:

```php
if (!empty($filtros['dni'])) {
    $where[]        = "paciente_dni LIKE :dni";
    $params[':dni'] = '%' . $filtros['dni'] . '%';
}
$sql = "SELECT * FROM v_turnos_detalle WHERE " . implode(' AND ', $where);
```

Sólo `LIMIT`/`OFFSET` se interpolan, después de castear a entero: MySQL los rechaza
como marcadores con la emulación desactivada, y el casteo los vuelve seguros.

## XSS: el caso del historial

Se encontró y corrigió un XSS almacenado real. El modal del historial construía la
tabla así:

```javascript
html += `<td>${h.observacion}</td>`;   // VULNERABLE
contenido.innerHTML = html;
```

`observacion` es texto libre que escribe el paciente al cancelar un turno. Un
motivo como `<img src=x onerror="...">` **se ejecutaba** en el navegador de quien
abriera el historial — normalmente un administrador. Escalada de paciente a admin.

La corrección va a la raíz: se construye el DOM y se escribe con `textContent`,
que nunca interpreta HTML.

```javascript
const td = document.createElement('td');
td.textContent = valor;
```

**Verificado con un ataque real:** el payload no se ejecutó, no se creó ningún
elemento `img` y el texto apareció literal en la celda.

## Subida de archivos

Nunca se confía en el nombre, la extensión ni en el tipo declarado por el
navegador: los tres los controla quien sube el archivo.

1. `is_uploaded_file()` — que sea una subida real y no una ruta inyectada
2. `finfo` — tipo **real** leído del contenido
3. `getimagesize()` — que se pueda decodificar de verdad
4. Techo de píxeles — frena imágenes que pesan poco comprimidas pero agotan la
   memoria al descomprimirse
5. **Re-codificación con GD** — el archivo final lo genera el servidor desde los
   píxeles, así que cualquier contenido escondido desaparece, igual que los
   metadatos EXIF con ubicación
6. Nombre aleatorio y extensión controlada
7. `.htaccess` en la carpeta destino que impide ejecutar PHP

**Verificado:** un archivo `.jpg` que contenía código PHP fue rechazado, y pedir un
`.php` dentro de esa carpeta devuelve `403`.

## El turno gratis

No todo agujero es técnico. Este era de negocio y el formulario lo servía en
bandeja: el desplegable de reserva ofrecía **las quince obras sociales del
sistema** y el controlador tomaba el `id_plan` del POST sin verificar de quién
era.

El descuento sale de `descuento_os_medico`, y con los datos reales **IOMA tiene
100 % con una de las médicas**. Cualquier paciente podía elegir esa cobertura y
sacar un turno gratis: con el monto en cero, `Pago::crearParaTurno()` marca el
pago como saldado y el turno se confirma solo.

Corregido en dos capas —la lista que se ofrece y la validación al guardar—,
porque un `<select>` se edita desde las herramientas del navegador.

**Verificado con el ataque real:** el POST con una cobertura ajena es rechazado
y no crea ningún turno. Detalle completo en [area-paciente.md](area-paciente.md).

> **Lección:** al auditar, mirar también qué opciones ofrece un formulario. Un
> desplegable con más opciones de las que corresponden es una autorización que
> nadie escribió.

## Archivos que no tienen URL

Las fotos de perfil se sirven por URL pública y están bien así: el módulo de
subida **re-codifica** cada imagen con GD, de modo que el archivo final lo genera
el servidor y no puede contener nada escondido.

Con un resultado de laboratorio eso no se puede hacer — es un PDF, y
re-generarlo pierde justamente lo que importa. Como no se puede neutralizar el
contenido, la defensa se mueve a **dónde vive el archivo**:

```
publico/img/perfiles/     → dentro del sitio, con URL           (fotos)
almacenamiento/estudios/  → FUERA del sitio, sin URL alguna     (estudios)
```

Se entregan desde `ControladorHistorial.php?accion=descargar`, que verifica quién
pide **antes de emitir un solo byte**, y sólo a tres perfiles: el paciente dueño,
un médico que lo atendió alguna vez, y el staff.

**Verificado:** la URL directa al archivo devuelve `403`; otro paciente recibe
`403` y no recibe el PDF; un médico que nunca lo atendió, tampoco.

Detalle completo en [historial-clinico.md](historial-clinico.md).

## IDOR: el id que nunca sale del servidor

Todas las pantallas de "mi perfil" tienen el mismo riesgo: si el id del usuario
viaja en un campo oculto del formulario, cualquiera lo edita y escribe sobre la
cuenta de otro.

Acá ningún método del controlador recibe un id por parámetro desde el navegador.
Todos trabajan sobre el perfil que `perfil.php` cargó a partir de
`$_SESSION['id_usuario']`:

```php
$perfil = $modelo->cargar((int) $_SESSION['id_usuario']);
$ctrl->guardarCuenta($perfil, $_POST);   // el id sale de $perfil, no de $_POST
```

**Verificado con un ataque real:** un POST que incluía `id_usuario`, `id` e
`id_paciente` de otra cuenta no modificó ni un campo de esa cuenta; el cambio
cayó, como corresponde, sobre la del atacante.

## Cabeceras HTTP

```
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: same-origin
Permissions-Policy: camera=(), microphone=(), geolocation=()
Content-Security-Policy: default-src 'self'; ...
```

> La CSP incluye `unsafe-inline` porque hay scripts y estilos en línea en las
> vistas. Quitarlo obliga a reescribirlas; está registrado como deuda técnica.

## Manejo de errores

El detalle técnico va al log del servidor y el usuario ve un mensaje neutro:

```php
catch (PDOException $e) {
    error_log('ControladorX: ' . $e->getMessage());
    $mensaje = 'No se pudo guardar. Intentá de nuevo.';
}
```

Un mensaje de PDO puede incluir fragmentos de la consulta o de la cadena de
conexión, así que nunca se muestra al visitante.

## 🚨 CSRF: el token no alcanzaba

`csrf_verificar()` empieza así:

```php
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    return;
}
```

Deja pasar todo lo que no sea POST, y **está bien** donde se la pensó: al
principio de un controlador que sirve también listados, que no llevan token.

Pero seis controladores la llamaban **dentro** de una acción que modifica datos,
confiando en que ahí protegía. Si la petición llega por GET, no verifica nada y
la acción se ejecuta igual.

Y una acción que funciona por GET no necesita ningún formulario para dispararse.
Basta que la persona, con su sesión abierta, abra una página cualquiera —un foro,
un correo en HTML, un comentario— que contenga:

```html
<img src="https://elsitio/.../ControladorNotificacion.php?accion=eliminarLeidas">
```

El navegador pide esa "imagen" con las cookies de la sesión y la acción corre. No
hay nada que la persona pueda notar: es una imagen que no carga.

**Comprobado contra el servidor local antes del arreglo:** un GET sin token a
`?accion=eliminarLeidas` devolvía `302` y borraba tres notificaciones.

### El arreglo

`csrf_post()` exige las dos cosas, y ninguna sobra:

| Sólo esto | Qué sigue siendo posible |
|---|---|
| POST sin token | Un formulario preparado en otro sitio lo dispara |
| Token, cualquier método | Una etiqueta `<img>` lo dispara |

Las dieciocho llamadas que estaban dentro de un `case` pasaron a `csrf_post()`.
Las siete que están al principio de un controlador se dejaron como estaban: esos
controladores sirven listados por GET y exigir POST los rompería enteros.

Las acciones que leían su id de `$_POST` ya eran inofensivas por GET —el id
llegaba en cero y no encontraban nada que tocar— pero quedaban a merced del
próximo cambio. Las expuestas de verdad eran las que no necesitan ningún dato de
entrada: `leerTodas` y `eliminarLeidas`.

### Lo que esto enseña

El agujero no estuvo en una función mal escrita: `csrf_verificar()` hace
exactamente lo que dice. Estuvo en **usarla para algo que no cubría**, con un
nombre que invitaba a creer que sí. Una función de seguridad que falla en
silencio cuando se la usa fuera de su contexto es una función que va a fallar.

Por eso `csrf_post()` responde `405 Method Not Allowed` y no un `403`: deja claro
que el problema es el método, no el permiso, y el próximo que la use mal se
entera al primer intento.

---

## 🚨 Redirección abierta: el agujero que no llegó a existir

Cada notificación guarda su `url_accion`, y al abrirla el controlador redirige
ahí. Ese valor lo escribe el propio sistema, así que hoy no puede traer nada
raro.

Se valida igual, porque termina en una cabecera `Location:`:

- un aviso con `//otrositio.com` llevaría a otro dominio **desde una dirección
  nuestra**, que es la base de un engaño de phishing: el enlace que la persona
  recibe y revisa es del sitio en el que confía;
- un salto de línea dentro del valor permite agregar cabeceras a la respuesta.

`destinoSeguro()` rechaza cualquier esquema (`http:`, `javascript:`, `data:`), los
`//host` y `\\host`, y los saltos de línea. Ante la duda, el panel.

Son cinco líneas que no arreglan ningún problema de hoy: cubren al código que
todavía no se escribió. Hay seis comprobaciones que lo verifican, una por cada
forma de intentarlo.

---
## Pendientes conocidos

| Tema | Situación |
|---|---|
| HTTPS | Sin él no se puede activar el flag `Secure` de la cookie |
| CSP estricta | Requiere sacar el JavaScript y los estilos en línea |
| `registro.php` sin CSRF | Es alta pública; convendría token más CAPTCHA |
| Rotación de sesión | Sólo se regenera al entrar, no periódicamente |

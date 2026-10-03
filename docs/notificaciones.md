# Notificaciones y correos

Cuando pasa algo que le importa a una persona —le confirmaron un turno, le
rechazaron el pago, le aprobaron una renovación— hay que avisarle. Y por más de
una vía.

## El problema

Trece tipos de aviso disparados desde catorce lugares distintos. Si cada
controlador tuviera que acordarse de grabar el aviso, redactar el correo y
mandarlo, el resultado inevitable sería que algunos avisen por los dos canales,
otros por uno, y unos cuantos se olviden de avisar.

## La solución: un servicio con canales

El controlador dice **una sola cosa**:

```php
$notificador->notificar($idUsuario, new Aviso(
    TipoAviso::PAGO_APROBADO,
    'Pago aprobado',
    'Tu turno con el Dr. Pérez quedó confirmado.',
    'perfil.php',           // a dónde lleva
    $idTurno                // qué lo originó
));
```

Y el servicio lo reparte por todos los canales que correspondan a ese tipo.

```
                    ┌──────────────┐
   Controlador ───> │ Notificador  │
                    └──────┬───────┘
                           │
          ┌────────────────┼────────────────┐
          ▼                ▼                ▼
     ┌─────────┐     ┌──────────┐     ┌──────────┐
     │ CanalApp│     │CanalEmail│     │ CanalPush│
     │         │     │          │     │ (futuro) │
     └────┬────┘     └────┬─────┘     └──────────┘
          │               │
   tabla `notificacion`   │
                    emailPlantilla()
                          │
                     mailer.php  ──> SMTP real / archivo
```

### Cómo se agrega push mañana

```php
$notificador->agregarCanal(new CanalPush($claves));
```

Se escribe una clase que implemente `CanalNotificacion` y se registra. **Ni un
controlador se entera.** Ese es todo el motivo de partir esto en canales en vez
de escribir "grabar + mandar mail" a mano en cada lugar.

## Reparto de responsabilidades

| Archivo | De qué se ocupa |
|---|---|
| `includes/notificaciones.php` | **Qué** se manda y **por dónde** |
| `sistema/modelos/Notificacion.php` | El SQL (es un modelo, como los otros diez) |
| `includes/email_plantilla.php` | Cómo **se ve** el correo |
| `includes/mailer.php` | Cómo **sale** el correo del servidor |

Están separados a propósito: el diseño del correo no debería cambiar si mañana
se reemplaza SMTP por una API, y la plantilla tiene que poder probarse sin
enviar nada.

## El catálogo de tipos

El tipo decide dos cosas: con qué icono y color se dibuja, y **si además sale por
correo**.

| Tipo | Correo | Por qué |
|---|---|---|
| `turno_reservado` · `turno_confirmado` · `turno_cancelado` · `turno_reprogramado` | ✅ | Afectan un compromiso con fecha y hora |
| `turno_recordatorio` · `pago_por_vencer` | ✅ | Sirven justamente para llegar fuera de la app |
| `pago_aprobado` · `pago_rechazado` | ✅ | Involucran dinero |
| `estudio_pedido` · `resultados_listos` | ✅ | Información clínica que se espera |
| `receta_nueva` · `receta_anulada` · `refill_*` | ✅ | Una receta anulada hay que saberla **antes** de ir a la farmacia |
| `cuenta_password` · `cuenta_email` | ✅ | **Si no fue la persona, ese correo es el único modo de que se entere a tiempo** |
| `mensaje_medico` · `cuenta_datos` | ❌ | No justifican interrumpir a nadie en su bandeja |

> **No todo va por correo a propósito.** Mandar todo es la forma más rápida de
> que la gente empiece a filtrar los correos del sistema a la papelera, y
> entonces tampoco lea los que sí importan.

Los tipos viven en PHP y la columna `tipo` es `VARCHAR`, no `ENUM`: agregar un
aviso nuevo va a pasar seguido, y con `ENUM` cada uno obligaría a un `ALTER TABLE`.
La etapa 5 agregó dos sin tocar el esquema, que es exactamente para lo que estaba
pensado.

### Un tipo reusado que no se podía seguir reusando

Pedir un estudio avisaba con `receta_nueva`. Funcionaba —y nadie lo notó—
**sólo porque las recetas todavía no existían**. En cuanto existieron, el paciente
veía "te pidieron un estudio" con el icono de una receta y los dos avisos eran
indistinguibles en el listado.

Es el costo típico de reusar un tipo "parecido" para salir del paso: no falla
hasta que aparece el caso de verdad, y entonces falla en la pantalla del
usuario.

## Decisiones de la tabla

| Decisión | Motivo |
|---|---|
| Apunta a `usuario`, no a `paciente` | Un médico también recibe avisos (una solicitud de renovación). La notificación es de la **cuenta** |
| `leida_en DATETIME` en vez de `leida BOOLEAN` | Guardar *cuándo* es estrictamente más información que guardar *que sí*, no ocupa más, y `NULL` sigue siendo "no leída" |
| `url_accion` **relativa** | Guardarla absoluta dejaría todos los avisos viejos apuntando a una dirección muerta si el proyecto cambia de carpeta o de dominio |
| `id_referencia` | Permite no duplicar recordatorios del mismo turno y rastrear qué produjo cada aviso |
| `email_enviado_en` | Responde "¿me llegó el mail?" sin abrir el log del servidor |
| Borrado real (`DELETE`) | Es correspondencia propia: si la persona la descarta, no hay razón para conservarla. El hecho que la originó sigue en su tabla |
| `ON DELETE CASCADE` | Los avisos de una cuenta eliminada no tienen a quién pertenecer |

## Dos garantías del servicio

**1. Notificar nunca rompe la operación.** Un aviso es un efecto colateral de algo
que ya salió bien. Si el servidor de correo está caído, lo último que debe pasar
es que se revierta el pago que el paciente acaba de hacer. Los fallos se
registran en el log y la ejecución sigue.

```php
} catch (Throwable $e) {
    error_log('Notificador[' . $nombre . ']: ' . $e->getMessage());
    $resultado[$nombre] = false;
}
```

**Verificado:** con un canal que lanza excepción, los otros dos siguen
entregando.

**2. El canal dentro de la app nunca se saltea.** Aunque el correo falle o la
persona no tenga dirección cargada, el aviso queda registrado y aparece en su
centro de notificaciones.

**Verificado:** con un correo de formato inválido, el aviso igual se guarda y
no se intenta enviar nada.

## Recordatorios sin duplicados

```php
$notificador->notificarUnaVez($idUsuario, $aviso);   // requiere id_referencia
```

La tarea que dispara los recordatorios corre en cada visita. Sin este control, el
paciente recibiría un correo por cada página que abriera.

**Verificado:** tres llamadas seguidas con la misma referencia dejan una sola
notificación.

## Direcciones a las que no se escribe

Los datos de prueba usan `@example.com`. Cada aviso a una de esas direcciones
viajaba al servidor SMTP, Gmail lo aceptaba, y volvía horas después como un
**rebote a la casilla del dueño del sistema**.

No es un error de configuración que se pueda corregir: `example.com` y compañía
están reservados por la [RFC 2606](https://www.rfc-editor.org/info/rfc2606) y
declaran Null MX ([RFC 7505](https://www.rfc-editor.org/info/rfc7505));
`.test`, `.invalid` y `.localhost` los reserva la RFC 6761. Mandarles un mensaje
es una garantía de rebote.

`MailerSmtp` los descarta **antes de abrir el socket** y deja el motivo en el
log. Se comprueba también el sufijo (`algo.example.com`, `mi-pc.local`), pero no
los dominios que sólo se parecen: `x@example.company.com` sí se intenta.

El modo archivo los sigue guardando: ahí el punto es justamente poder leer el
correo que se habría mandado.

> Esto **no** cubre una dirección con formato válido que simplemente no existe
> —`laila@gmail.com`—: eso sólo se sabe intentando. Si un aviso no llega, lo
> primero a revisar es qué correo tiene cargada esa cuenta.

## El buzón de desarrollo perdía correos

`MailerArchivo` nombraba los archivos con la fecha **al segundo** más el
destinatario. Dos correos a la misma persona dentro del mismo segundo generaban
el mismo nombre y el segundo pisaba al primero, sin decir nada.

Pasa de verdad: al reservar un turno y que falle el pago salen dos avisos casi
juntos. Y el síntoma es el peor posible — el sistema da el correo por enviado y
no está en ninguna parte. Se agregó un sufijo aleatorio al nombre.

## Por qué los correos se ven "anticuados"

No es descuido. El correo **no es la web**:

- Se maquetan **tablas**, no flexbox ni grid. Outlook renderiza con el motor de
  Word y no entiende layout moderno.
- El CSS va **en línea**. Gmail descarta casi todo lo que esté en un `<style>`.
- Ancho fijo de **600 px**, el máximo seguro en clientes de escritorio.
- **Sin imágenes externas**: la mayoría de los clientes las bloquea, así que un
  logo en `<img>` se vería como un cuadro roto. El logotipo se dibuja con texto
  y color de fondo.
- **Preheader oculto**: el texto que el cliente muestra al lado del asunto. Sin
  él, ahí aparece lo primero que encuentre —normalmente el logotipo—, que no le
  dice nada a nadie.

## Aislamiento entre usuarios

Ningún método del modelo permite tocar una notificación sin decir de quién es:

```php
public function eliminar(int $id, int $idUsuario): bool
{
    // el dueño va en el WHERE, no sólo el id
    "DELETE FROM notificacion WHERE id_notificacion = :id AND id_usuario = :u"
}
```

Se podría haber puesto el control en el controlador, pero eso deja la puerta
abierta a que un controlador nuevo se olvide del chequeo. Así el modelo
directamente **no expone** una forma de borrar la notificación de otro.

**Verificado:** un usuario ajeno no puede marcarla como leída ni eliminarla, y
la fila sigue existiendo después del intento.

---

## El centro de notificaciones

La tabla y el servicio de emisión existen desde la primera etapa, y todos los
módulos vienen escribiendo ahí. Lo que faltaba era **dónde verlas**.

`ControladorNotificacion.php` y su vista: pestañas por estado (todas / sin leer /
leídas), filtro por tipo, paginado, marcar una o todas como leídas, borrar una y
vaciar las leídas.

### Es un controlador y no un archivo en la raíz

`historial.php` y `recetas.php` están en la raíz porque son pantallas del Área
del Paciente. Las notificaciones no: un médico recibe los pedidos de renovación,
y el día que haya avisos para administración también los va a recibir. Es una
pantalla de cualquier cuenta.

### El filtro ofrece sólo los tipos que esa persona tiene

`Notificacion::porTipo()` agrupa los avisos del usuario. Un desplegable con los
veinte tipos del sistema le ofrecería a un paciente filtrar por "pedido de
renovación", que es un aviso de médico y nunca va a tener.

### "Vaciar leídas" no borra las que no se leyeron

Un "borrar todo" se llevaría avisos que la persona no vio, y le haría perder un
resultado disponible o un pago por vencer sin que sepa que existió. Para
deshacerse de una sin leer está el botón de su fila.

### El campanita es un enlace, no un desplegable

Un desplegable necesita JavaScript para abrirse, y sin JavaScript el campanita no
haría nada: el peor resultado posible, porque la persona cree que la aplicación
está rota. Así funciona siempre y el panel completo está a un clic.

El contador es **la única consulta que se permite en el layout**, o sea en cada
página del sistema. Se justifica porque tiene que estar al día en todas las
pantallas —es su razón de existir— y es un `COUNT` sobre el índice
`idx_notif_sin_leer`, hecho exactamente para eso. Va envuelta en `try/catch`: el
campanita es decoración, y nadie debería quedarse sin ver su turno porque no se
pudo contar un aviso.

El contador de renovaciones pendientes del médico, en cambio, vive en su panel
justamente porque sólo importa ahí.

---

## 🚨 La redirección abierta que no llegó a existir

Cada aviso guarda su `url_accion`, y al abrirlo el controlador redirige ahí. Ese
valor lo escribe el propio sistema, así que hoy no puede traer nada raro.

Igual se valida, porque termina en una cabecera `Location:` y eso convierte
cualquier descuido futuro en dos agujeros concretos:

- **Redirección abierta.** Un aviso con `//otrositio.com` llevaría a otro dominio
  *desde una dirección nuestra*. Es la base de un engaño de phishing: el enlace
  que la persona recibe y revisa es del sitio en el que confía.
- **Inyección de cabeceras.** Un salto de línea dentro del valor permite agregar
  cabeceras propias a la respuesta.

`destinoSeguro()` rechaza cualquier esquema (`http:`, `javascript:`, `data:`), los
`//host` y `\host`, y los saltos de línea. Ante la duda, el panel. Son cinco
líneas que cubren al código que todavía no se escribió, y hay seis comprobaciones
que lo verifican.

---

## Las tareas por tiempo

Todos los demás avisos salen de algo que alguien hizo: se reservó un turno, se
aprobó un pago. El controlador que atiende esa acción avisa y listo.

Dos no tienen disparador, porque nadie hace nada el día antes de un turno —de eso
se trata el recordatorio—:

| Tarea | Cuándo | Por qué ese momento |
|---|---|---|
| Recordatorio de turno | entre 24 y 36 h antes | Un turno de las 9 avisado a las 23:50 del día anterior llega diez minutos antes de que la persona se vaya a dormir. Con una ventana de horas el aviso sale cuando todavía se puede reorganizar el día o cancelar |
| Pago por vencer | 6 h antes | Antes sería ruido (el plazo normal es de 48 h) y después ya no sirve |

### Dos caminos para ejecutarlas

El **recomendado** es un evento programado corriendo `tareas/ejecutar.php` una vez
por hora — ver [deployment.md](deployment.md).

El de **reserva**, para un hosting sin cron, es `dashboard.php` con un freno de
diez minutos por sesión. Las tareas son globales, así que la visita de cualquiera
dispara los avisos de todos. Su defecto es claro: **si nadie entra al sitio, nadie
recibe su recordatorio.**

Es la misma solución de compromiso que ya tenía `expirarVencidos()`, y está
anotada en la [deuda técnica](roadmap.md).

### Lo que hace que repetirlas sea seguro

Todo pasa por `notificarUnaVez()`, que mira si ya existe un aviso de ese tipo para
esa referencia y ese usuario. **Correr las tareas mil veces produce exactamente
los mismos avisos que correrlas una.** Sin eso, el paciente recibiría un correo
por cada página que abriera alguien.

### La tarea no se sirve por la web

Un archivo que dispara correos y se puede pedir por URL es un archivo que
cualquiera puede hacer correr mil veces. Los avisos no se duplicarían, pero el
servidor haría el trabajo igual: una denegación de servicio regalada.

Hay **dos barreras independientes** y a propósito: la comprobación de `PHP_SAPI`,
que no depende del servidor web, y el `.htaccess` de la carpeta, que no depende de
PHP. Cualquiera de las dos sola alcanzaría; las dos juntas siguen valiendo si una
falla —un `.htaccess` que no se lee porque falta `AllowOverride`, por ejemplo—.

---

## Cómo se verifica

```bash
php  pruebas/tareas.php               # 20 comprobaciones
bash pruebas/notificaciones_http.sh   # 62 comprobaciones
```

Las segundas incluyen los seis intentos de redirección abierta, los intentos
cruzados entre dos cuentas (ver, marcar y borrar el aviso de otra persona), y la
separación entre exigir POST y exigir token, que son dos defensas distintas y
fallan distinto.

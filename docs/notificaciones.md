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
| `resultados_listos` · `receta_nueva` · `refill_*` | ✅ | Información clínica que se espera |
| `cuenta_password` · `cuenta_email` | ✅ | **Si no fue la persona, ese correo es el único modo de que se entere a tiempo** |
| `mensaje_medico` · `cuenta_datos` | ❌ | No justifican interrumpir a nadie en su bandeja |

> **No todo va por correo a propósito.** Mandar todo es la forma más rápida de
> que la gente empiece a filtrar los correos del sistema a la papelera, y
> entonces tampoco lea los que sí importan.

Los tipos viven en PHP y la columna `tipo` es `VARCHAR`, no `ENUM`: agregar un
aviso nuevo va a pasar seguido, y con `ENUM` cada uno obligaría a un `ALTER TABLE`.

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

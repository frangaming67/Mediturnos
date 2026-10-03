# Historial clínico

Es el módulo que más cuidado pide del proyecto: acá no hay turnos ni pagos, hay
**información de salud de personas reales**.

## De dónde salen los datos

Nada es de ejemplo. Cada línea del historial la escribió un profesional:

```
El médico atiende  →  marca el turno como Realizado
                   →  registra la ficha clínica (motivo, diagnóstico, indicaciones)
                   →  pide estudios si hace falta
                   →  carga el resultado cuando llega
                                    ↓
El paciente ve su historial con todo eso, ordenado por fecha
```

Por eso esta etapa construyó **las dos puntas**. Sólo el lado del paciente habría
dado una pantalla eternamente vacía.

## Dos tablas

| Tabla | Qué guarda | Regla en el esquema |
|---|---|---|
| `consulta` | Lo que el médico escribe sobre un turno atendido | `id_turno` es **UNIQUE** |
| `estudio` | Un pedido de análisis o imagen, y su resultado | `estado` = `Pendiente` \| `Disponible` |

**`consulta.id_turno` es UNIQUE a propósito.** Una consulta es el registro de *un*
turno atendido. Si el médico vuelve a entrar para agregar algo que se olvidó,
corrige la misma ficha — no se acumulan varias "consultas" sueltas del mismo
turno, porque entonces el historial del paciente mostraría la misma cita
duplicada. El `INSERT ... ON DUPLICATE KEY UPDATE` hace exactamente eso.

## Los archivos no son públicos

Esta es la decisión central del módulo, y es distinta a la de las fotos de perfil.

`SubidaImagen` se apoya en una defensa muy fuerte: **re-codifica** la imagen con
GD, así que el archivo final lo genera el servidor y no conserva nada del
original. Acá eso no se puede hacer — un resultado de laboratorio suele ser un
PDF, y un PDF no se "re-genera" sin perder justamente lo que importa.

Como no se puede neutralizar el contenido, la defensa se mueve a **dónde vive**:

```
publico/img/perfiles/     → dentro del sitio, con URL pública      (fotos)
almacenamiento/estudios/  → FUERA del sitio, sin URL alguna        (estudios)
```

| Barrera | Qué hace |
|---|---|
| Carpeta fuera de `publico/` | No existe ninguna URL que alcance el archivo |
| `ControladorHistorial?accion=descargar` | Verifica **quién pide** antes de emitir un solo byte |
| Nombre aleatorio de 32 hex | Adivinar el nombre no sirve de nada |
| `.htaccess` con `Require all denied` | Segunda barrera si alguien mueve la carpeta adentro del sitio |
| `Cache-Control: private, no-store` | No queda cacheado en proxies |
| `X-Content-Type-Options: nosniff` | El navegador no reinterpreta el tipo |

**Verificado:** pedir el archivo por URL directa devuelve `403`; un PHP disfrazado
de `.pdf` es rechazado por el tipo real leído del contenido.

## Quién puede ver un estudio

Tres casos y ninguno más:

```php
if ($rol === 'admin' || $rol === 'recepcionista') return true;
if ($rol === 'paciente') return $estudio['id_paciente'] === $idPaciente;
if ($rol === 'medico')   return $this->atendioAlPaciente($matricula, $estudio['id_paciente']);
```

El caso del médico es el interesante: **no alcanza con ser médico**. Tiene que
haber atendido a esa persona alguna vez. Un profesional que nunca la vio no tiene
por qué acceder a sus análisis.

### 🚨 "Atendido" no es "tiene un turno"

La primera versión de `atendioAlPaciente()` contaba **cualquier** turno entre los
dos, y eso abría la historia clínica de par en par:

- Alguien saca turno con un profesional para el mes que viene y, **desde ese
  instante**, ese profesional puede leer todos sus estudios anteriores —
  incluidos los que pidió otro médico.
- Peor: si después lo cancela, la fila queda con estado `Cancelado` y **el acceso
  no se pierde nunca más**.

Entrar a la historia clínica de alguien se justifica por haberlo **atendido**, no
por tener una cita agendada. Un turno futuro todavía no pasó y uno cancelado no
pasó nunca. Ahora sólo cuentan los `Realizado`.

**Verificado con la secuencia completa:** sin turno → `403`; con turno futuro →
`403`; cancelado → `403`; recién al marcarlo atendido, `200`.

### Ver y modificar son permisos distintos

Un médico que atendió al paciente puede **leer** todos sus estudios — eso es
atención clínica. Pero sólo puede **cargar el resultado** de los que él mismo
pidió.

Sin esa distinción, cualquier médico con un turno del paciente podía reemplazar
el resultado que había subido otro profesional, y el archivo original se borraba
del disco en el mismo paso. El formulario de carga ahora aparece sólo para quien
pidió el estudio, y el servidor lo revalida.

**Verificado con los cuatro casos:** el dueño sí, su médico tratante sí, otro
paciente `403`, y sin sesión no se entrega nada.

> El control vive en el **modelo**, no en el controlador. Ningún método devuelve
> datos sin decir de quién son: no hay un `buscarPorId($id)` suelto que devuelva
> la consulta de cualquiera y deje el control en manos de quien llame. Es la misma
> decisión que en `Notificacion.php` y por el mismo motivo — si el control vive en
> el controlador, alcanza con que un controlador nuevo se olvide de hacerlo.

## La línea de tiempo

Consultas y estudios llegan **mezclados y ordenados** desde un `UNION` en SQL, no
desde dos consultas que PHP junta después.

El motivo es la paginación: ordenar en PHP obligaría a traer *todo* para poder
cortar la página correcta, y un paciente con años de atención haría eso en cada
visita.

Los filtros se aplican **afuera** del `UNION`, sobre el resultado combinado.
Escribirlos dos veces —uno por rama— sería la forma más rápida de que un día
filtren distinto.

## 🚨 El choque de collations

La primera versión de las tablas usaba `utf8mb4_unicode_ci`. La pantalla del
historial moría entera:

```
ERROR 1271: Illegal mix of collations for operation 'UNION'
```

Las 21 tablas anteriores y la base misma usan `utf8mb4_general_ci`. El `UNION`
pone en la misma columna `especialidad.nombre` (general) y `estudio.tipo`
(unicode), y MySQL se planta.

Mientras las tablas nuevas se consultaron solas, la diferencia no se notó —
`notificacion` (migración 13) y `calificacion` (migración 15) arrastraban el mismo
problema sin saberlo. Apareció recién al unir tablas nuevas con viejas.

**No alcanzaba con arreglar la consulta:** el mismo choque puede salir en
cualquier `JOIN`, `ORDER BY` o comparación futura. `collation_unificada.sql`
alinea las tres tablas nuevas al collation que ya tenía todo lo demás.

> `utf8mb4_unicode_ci` es el **mejor** de los dos: ordena los acentos como
> corresponde en castellano. Lo correcto a futuro sería llevar todo el esquema
> hacia él — pero hoy son 28 tablas con claves foráneas entre sí, y eso se hace con la
> base fuera de servicio y con respaldo. Queda en la deuda técnica. Lo que no se
> puede es tener las dos cosas conviviendo.

## Reglas de la ficha clínica

| Regla | Por qué |
|---|---|
| Sólo sobre un turno `Realizado` | Escribir el diagnóstico de algo que no pasó sería registrar una atención inexistente |
| Sólo el médico del turno | La matrícula sale de la sesión, nunca de la petición |
| Se avisa la **primera** vez, no en cada corrección | Si no, el paciente recibe un correo por cada coma que el médico retoca |
| El motivo de consulta es obligatorio | Es lo mínimo que identifica de qué se trató la atención |

La pantalla muestra los campos **deshabilitados** cuando el turno todavía no se
atendió, y el servidor lo rechaza igual: el `disabled` del HTML se quita desde las
herramientas del navegador.

## El borrado compensatorio tiene que ir antes del commit

Al reemplazar un resultado, el `try` envolvía la escritura **y** la notificación.
Parece prolijo y es un error sutil pero grave.

El `UPDATE` va en autocommit: apenas vuelve, la fila ya apunta al archivo nuevo y
el viejo ya se borró — **no hay vuelta atrás**. Si después fallaba la
notificación, el `catch` borraba el archivo *nuevo* y dejaba la fila apuntando a
uno inexistente: el resultado del paciente, perdido, por un problema de correo.

```php
try   { $anterior = $modelo->adjuntarResultado(...); }   // sólo la escritura
catch { $subida->eliminar($archivo); ... }               // compensar tiene sentido

if ($anterior) $subida->eliminar($anterior);             // punto de no retorno

try   { $notificador->notificarPaciente(...); }          // efecto colateral
catch { error_log(...); }                                // se registra y se sigue
```

> **La regla:** un bloque compensatorio sólo puede cubrir lo que todavía no se
> confirmó. Una vez que la base dijo que sí, deshacer es destruir.

## Los correos no llevan resultados

El aviso de "resultados disponibles" **no adjunta el archivo**. Dice que ya está
cargado y que entre a su cuenta.

Un correo viaja por servidores que no controlamos y queda en la bandeja para
siempre. Un análisis clínico no debería vivir ahí: el enlace obliga a
autenticarse, el adjunto no.

**Verificado:** el correo generado no trae adjuntos y sí trae la indicación de
entrar a la cuenta.

## Notificaciones que dispara esta etapa

| Cuándo | Tipo | Correo |
|---|---|---|
| El médico registra la ficha (primera vez) | `resultados_listos` | ✅ |
| El médico pide un estudio | `receta_nueva` | ✅ |
| El médico carga un resultado | `resultados_listos` | ✅ |

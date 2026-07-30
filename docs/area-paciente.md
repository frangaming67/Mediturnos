# Área del Paciente

El paciente no usa una versión recortada del sistema del personal: usa **otro
producto**. Le importan su próxima cita, su salud y su cuenta. Palabras como
"Agenda", "Gestión" o "Dashboard" no significan nada para alguien que viene a
atenderse.

## Menú propio

| Personal | Paciente |
|---|---|
| Dashboard · Turnos · Pagos · Pacientes · Médicos · Horarios · Ausencias · Obras sociales · Consultorios · Usuarios | Inicio · Agendar cita · Mis turnos · Mis pagos · Mi perfil |

Se resuelve con una bifurcación dentro de `navbar.php` y no con un layout
aparte, para no duplicar el `<head>`, la barra superior y el pie: lo único que
cambia entre los dos mundos son los enlaces.

> El menú lista **sólo lo que funciona**. Historial, Recetas y Notificaciones se
> agregan cuando su pantalla exista de verdad: un menú con enlaces que no llevan
> a ninguna parte es peor que un menú corto.

## El panel de inicio estaba invertido

`dashboard_paciente.php` **era** el calendario de reserva: al paciente le
aparecía la grilla de horarios apenas entraba, sin saber si tenía un turno la
semana que viene ni si le quedaba algo por pagar.

Está al revés de cómo se usa el sistema: una persona entra muchas más veces a
mirar qué tiene pendiente que a sacar un turno nuevo.

- **`dashboard.php`** → responde *"¿qué tengo?"*
- **`agendar.php`** → reservar, con su propio recorrido

De paso, las dos consultas SQL que la vista ejecutaba a mano pasaron a los
modelos — el propio archivo original dejaba anotado que había que hacerlo.

### Qué muestra el inicio

```
Hola, Lucía 👋
Este es el resumen de tu cuenta

PRÓXIMA CITA
┌────────────────────────────────────────────────┐
│ Dr/a. González — Cardiología    EN 3 DÍAS  ●Reservado │
│ 📅 Viernes 25 Jul  🕐 10:00 hs  📍 Cons. 4     │
│ ┌──────────────────────────────────────────┐   │
│ │ Falta abonar $9.000        [Pagar ahora] │   │
│ │ Hasta el 27/07 10:00, o el turno se libera│  │
│ └──────────────────────────────────────────┘   │
│ [Ver detalles] [Reprogramar] [Cancelar]        │
└────────────────────────────────────────────────┘
```

El aviso de pago va **arriba** de los botones: si no se abona a tiempo el turno
se cancela solo, así que es lo más urgente de la tarjeta.

### Estado vacío

Dice qué pasa, aclara que no es un error y ofrece la acción que corresponde. Un
panel en blanco deja a la persona preguntándose si el sistema se rompió.

El texto cambia según el caso: a alguien que ya se atendió antes se le dice
"cuando saques uno nuevo lo vas a ver acá"; a alguien que nunca vino, "reservá
tu primera consulta".

## 🚨 El agujero que cerró esta etapa

El formulario de reserva ofrecía **los quince planes del sistema** y nadie
verificaba que el elegido fuera del paciente:

```php
// dashboard_paciente.php — antes
$planesCalendario = $pdo->query("SELECT ... FROM plan_os ...")->fetchAll();  // TODOS
```

```php
// ControladorTurno.php — antes
'id_plan' => (int)($_POST['id_plan'] ?? 0),   // sin comprobar de quién es
```

El descuento sale de `descuento_os_medico`. Con los datos reales:

| Obra social | Médico | Descuento |
|---|---|---|
| **IOMA** | **Dra. González** | **100 %** |
| Swiss Medical | Dr. López | 90 % |
| OSDE | Dr. Fernández | 80 % |

Cualquier paciente podía elegir IOMA y sacar un turno **gratis**. Al quedar el
monto en cero, `Pago::crearParaTurno()` da el pago por saldado automáticamente y
el turno se confirma solo. Sin tocar una línea de HTML: el desplegable ya lo
ofrecía.

**La corrección va en dos capas:**

1. `Turno::planesDePaciente()` ofrece únicamente las coberturas cargadas a esa
   persona, más el pago particular.
2. `Turno::planPermitido()` lo revalida en el servidor al reservar, porque el
   `<select>` se edita desde las herramientas del navegador.

Se aplica **sólo al rol paciente**: la recepción sí puede elegir otra cobertura,
porque atiende casos reales (alguien que llega con una credencial distinta a la
que tiene cargada).

**Verificado con el ataque real:** un POST con el plan de IOMA desde una cuenta
que no lo tiene es rechazado y no crea ningún turno.

## Agendar en cuatro pasos

```
Especialidad → Profesional → Día → Horario → Confirmación
   ?esp=2        &mat=10002    &fecha=…      &hora=…&cons=…
```

### Por qué cada paso es una URL y no un asistente de JavaScript

Podría resolverse escondiendo y mostrando `<div>`s, como hace el registro. Acá no
conviene, por tres motivos concretos:

1. **Cada paso tiene su propia dirección.** El botón "atrás" del navegador hace
   lo que la persona espera, y un enlace a un profesional se puede compartir.
2. **Los datos de cada paso dependen del anterior.** Los médicos salen de la
   especialidad, los días del médico, los horarios del día. Un asistente de JS
   tendría que traerlo todo por adelantado —los horarios de los seis médicos para
   el próximo mes— o pedirlo por AJAX igual. La navegación no ahorra nada.
3. **Funciona sin JavaScript.** Cada paso es un enlace o un formulario.

### Validación en cascada

Ningún paso confía en el anterior: los parámetros vienen de la URL y se pueden
escribir a mano. En cada carga se vuelve a comprobar que la especialidad exista,
que el médico la atienda, que el día tenga agenda y que **el horario siga libre**.

Si algo no cierra, se desarma desde ahí en adelante y la persona vuelve al paso
que corresponde — nunca a una pantalla rota.

**Verificado:** `?esp=99999` vuelve al paso 1; un médico que no atiende esa
especialidad, al paso 2; una fecha pasada, al calendario.

### "Alguien lo reservó mientras yo elegía"

Es el caso que pidió cubrirse explícitamente. Pasa en el paso 5: entre que se
dibuja el resumen y se aprieta confirmar pueden pasar minutos.

El horario se revalida contra `obtenerSlots()` **en cada carga** del resumen, así
que quien tenía la pantalla abierta ve el aviso antes de confirmar y vuelve a
elegir. Y si aun así llegara a enviarse, el índice único del motor rechaza el
`INSERT`: hay dos redes, no una.

**Verificado:** con el horario tomado por otro paciente entre dos cargas, el
resumen avisa y no deja confirmar.

### Lo que muestra cada paso

| Paso | Datos |
|---|---|
| 1 · Especialidad | Precio desde, duración del turno, cuántos profesionales |
| 2 · Profesional | Foto, nombre, especialidad, **matrícula**, consultorios, calificación |
| 3 · Día | Calendario de 4 semanas con la cantidad de horarios libres por día |
| 4 · Horario | Sólo los libres en ese momento |
| 5 · Confirmación | Todo lo anterior + duración, precio de lista, cobertura, **monto final**, método de pago y aceptación de términos |

El calendario del paso 3 no llama a `obtenerSlots()` día por día —serían 30
llamadas—: resuelve las cuatro semanas con **tres consultas** (horarios del
médico, ausencias del período, turnos ya tomados por fecha) y las compara en
memoria. El único día que se calcula exacto es **hoy**, porque las horas que ya
pasaron reducen la disponibilidad y el conteo por día de la semana no las
contempla.

### Los términos no son decorativos

La casilla de aceptación se valida **también en el servidor**. Sin eso sería una
casilla decorativa que cualquiera saltea armando el POST a mano.

## Pago y confirmación

### Dos plazos, no uno

| Eligió | Retención | Por qué |
|---|---|---|
| **Pago ahora con tarjeta** | **15 minutos** | Es lo que tarda cargar una tarjeta. Si abre el checkout y se va, el horario tiene que volver enseguida |
| **Pago más tarde / en recepción** | **48 horas** | Avisó que no va a pagar ahora; el sistema le reserva el lugar |

Con un solo plazo largo, cualquiera que abandonara el checkout dejaba un horario
bloqueado dos días sin que nadie pagara. Con uno solo corto, quien elige pagar en
recepción perdía el turno a los quince minutos.

La pantalla de pago muestra una **cuenta regresiva** cuando la retención es corta.
El servidor imprime el tiempo restante, así que sin JavaScript se ve igual: sólo
deja de descontar. **Quien decide si el plazo venció es el servidor, no el reloj.**

### "Necesito más tiempo"

`Pago::extenderPlazo()` mueve la retención corta al plazo largo. Es un cambio de
decisión, no una trampa: nunca acorta un plazo ni pasa del inicio del turno, y
sólo toca pagos `Pendiente` — uno vencido no revive por volver a entrar.

> Antes, "Pagar más tarde" era **sólo un enlace al listado**. Quien lo tocaba se
> iba creyendo que tenía tiempo mientras el plazo original seguía corriendo.

### Si el pago se rechaza

El turno **no** se cancela: la persona sigue dentro de su ventana y puede
reintentar con otra tarjeta. Si se cancelara al primer rechazo, quien se equivocó
en un dígito perdería el horario y tendría que empezar de cero.

Recibe un aviso —en la app y por correo— que dice que el turno sigue reservado,
hasta cuándo, y ofrece reintentar.

### Si el pago se aprueba

```
pago Pagado → turno Confirmado → notificación → correo → comprobante
```

Va derecho al **comprobante**, no al listado: acaba de pagar y lo que quiere ver
es la constancia de que su turno está.

El cobro en recepción dispara **exactamente el mismo aviso**: para el paciente es
el mismo hecho, sin importar por dónde entró la plata. Por eso el armado del
correo está en una función y no escrito dos veces — así no puede pasar que un día
los dos digan cosas distintas.

### El comprobante es una página, no un PDF

Generar un PDF sin librerías —el proyecto no usa Composer— significa escribir un
generador entero. Una página con estilos de impresión hace lo mismo: `Ctrl+P` la
imprime o la guarda como PDF con el propio navegador, y además se puede mostrar
desde el teléfono en la puerta de la clínica sin descargar nada.

El **código de reserva** va grande y solo, porque es el dato que la persona busca
cuando abre esto en recepción.

> `@media print` incluye `print-color-adjust: exact`. Sin eso los navegadores
> omiten los fondos y la cabecera azul sale en blanco — justo lo que identifica
> al comprobante.

Un comprobante de un pago pendiente no existe: se redirige a pagarlo, que es lo
que la persona en realidad necesita.

## 🚨 El médico fantasma

Lo destapó una prueba: el sistema ofrecía turnos con un profesional **dado de
baja hacía un mes**.

`obtenerSlots()` leía `horario_atencion` sin mirar `medico.estado`. Los horarios
no se borran al dar de baja a alguien —son su historial— así que la consulta los
encontraba igual y el calendario los mostraba como disponibles.

El asistente de cuatro pasos lo tapaba por casualidad (el paso 2 sí filtra por
estado), pero el endpoint AJAX seguía devolviendo sus horarios y un POST armado a
mano reservaba sin problema.

**La corrección va donde corresponde:** `obtenerSlots()` es la fuente de verdad de
"qué se puede reservar", así que el filtro va ahí y no en cada una de las cuatro
pantallas que lo consultan.

Y se agregó el guard que faltaba al reservar:

```php
// El horario pedido tiene que ser uno de los que el sistema OFRECE ahora
$ofrecidos = $modelo->obtenerSlots($matricula, $fecha);
```

Una sola comprobación que cubre todo a la vez: profesional activo, sin ausencia,
horario existente, libre y futuro. Cada uno por separado sería un guard más para
olvidarse de agregar; contrastando contra la misma función que dibuja las
pantallas, **lo reservable es exactamente lo ofrecido**.

## Calificaciones

Lo que hace creíble a una calificación no es el promedio: es **quién puede
dejarla**. Sólo alguien que tuvo un turno `Realizado` con ese médico, y una sola
vez por turno.

Las dos condiciones están en el **esquema**, no sólo en PHP: `calificacion.id_turno`
es `UNIQUE` y no hay calificación sin turno. Si vivieran sólo en el código,
dependerían de que todo camino futuro se acuerde de comprobarlas.

El puntaje además tiene un `CHECK (puntaje BETWEEN 1 AND 5)` que **MariaDB 10.4
hace cumplir de verdad**: un 9 no entra ni escribiendo el `INSERT` a mano.

**Verificado:** puntaje 9 rechazado por el motor, segunda calificación del mismo
turno rechazada por el índice, y un turno ajeno devuelve `403`.

> Sin calificaciones **no se muestra un 0** ni "0 estrellas": sería castigar a
> quien todavía nadie calificó. Dice "Sin calificaciones aún".

### La trampa del promedio inflado

Es el error clásico de este tipo de listado, y estuvo a punto de entrar:

```sql
-- MAL: cada calificación se repite una vez por franja horaria del médico
FROM medico m
LEFT JOIN calificacion c     ON c.matricula = m.matricula
LEFT JOIN horario_atencion h ON h.matricula = m.matricula
...  AVG(c.puntaje)
```

Un médico con 3 franjas horarias y 1 calificación de 5 mostraría **3 opiniones**,
y con puntajes distintos el promedio saldría calculado sobre filas multiplicadas.
No daría mal por poco: daría cualquier cosa, y encima parecería creíble.

La corrección es calcular cada número en una **subconsulta** sobre su propia
tabla.

**Verificado con el caso exacto:** un médico con 3 franjas y 1 calificación
muestra "5,0 · 1 opinión".

## Reprogramar

Mover un turno **no** es cancelarlo y volver a reservarlo. Eso perdería el pago
ya hecho, generaría un turno nuevo con otro número y dejaría al paciente sin su
comprobante. Se actualiza el mismo turno.

La garantía contra la doble reserva **no hace falta programarla**:

```sql
slot_unico = IF(id_estado = 5, NULL, CONCAT(matricula,'|',fecha,'|',hora_inicio))
```

Es una columna generada a partir de fecha y hora: al moverlas se recalcula, y el
índice único rechaza el `UPDATE` si ese horario ya está tomado. **El motor
protege el reprograma igual que la reserva.**

**Verificado:** con el horario destino ocupado por otro paciente, el cambio se
rechaza y el turno no se mueve.

### Reglas

| Regla | Por qué |
|---|---|
| Sólo turnos futuros y vigentes | No se mueve algo ya realizado o cancelado |
| Mínimo 2 horas de antelación | Más cerca, la clínica ya organizó el día |
| Mismo profesional y especialidad | Si cambiara la especialidad cambiaría el precio y el pago dejaría de corresponderse. Para eso está cancelar y sacar otro |
| Se recalcula el vencimiento del pago | El plazo nunca puede terminar después del inicio del turno |

`motivoNoReprogramable()` devuelve **el motivo**, no un booleano: la pantalla
necesita explicar por qué el botón no está, y un `false` pelado obligaría a
repetir esas condiciones en la vista para adivinar cuál se incumplió.

### Queda registrado

El trigger `trg_turno_after_update` sólo miraba el estado, así que un turno que
cambiaba de fecha pasaba sin dejar rastro. Ahora también registra el movimiento:

```
Reprogramado: 25/07/2026 10:00 → 26/07/2026 08:00
```

Se extendió el trigger en vez de insertar desde PHP porque la regla del proyecto
es que los modelos no escriben en `historial_turno`. Además, así el cambio queda
registrado venga de donde venga — del paciente, de la recepción o de un `UPDATE`
hecho a mano desde phpMyAdmin.

## Aislamiento

`turnoPropio()` resuelve el turno **y** el permiso en un solo lugar, porque el
mismo control se necesita en `detalle`, `reprogramar` y `guardarReprogramacion`.
Repetido en los tres sería fácil de olvidar actualizar en alguno — que es
exactamente cómo aparecen los agujeros de IDOR.

El id del dueño sale **siempre** de la sesión, nunca de la petición. El
formulario de reserva ya ni siquiera lleva `id_paciente`.

**Verificado:** pedir el detalle o intentar mover el turno de otra persona
devuelve `403`.

## Una consulta por horario

`obtenerSlots()` preguntaba a la base **slot por slot** si estaba ocupado. Una
jornada de 8 a 16 con turnos de 20 minutos son 24 consultas para dibujar una
grilla — y el calendario la pide cada vez que se toca un día.

Ahora se traen los turnos del día en una sola consulta y se comparan en memoria
con dos índices, uno por médico y otro por consultorio. Mismo resultado, **24
consultas menos**.

**Verificado:** tras reservar un horario, desaparece de la grilla y los demás
siguen ofreciéndose.

## `require` comparte el ámbito: la variable que mató una pantalla

`navbar.php` se incluye con `require`, así que **todo lo que declara pisa lo que
la vista haya definido antes con el mismo nombre**. El archivo ya lo tenía
resuelto para `$usuario` —lo llamó `$usuarioSesion` justamente por esto— pero
faltaba `$iniciales`, y la trampa se cobró una:

```php
// agendar.php — una función para dibujar el avatar de cada médico
$iniciales = fn(array $m) => strtoupper(mb_substr($m['nombre'], 0, 1) . …);

require 'navbar.php';   // ← acá dentro: $iniciales = "DP";  (un string)

$iniciales($m);         // Fatal error: Call to undefined function DP()
```

`DP` eran, literalmente, las iniciales del usuario que estaba mirando la página.
El error cambia según quién esté logueado, que es de las formas más confusas en
que puede romperse algo.

Se renombró a `$inicialesSesion`, siguiendo la convención que el propio archivo
ya usaba. De paso quedó bien `perfil.php`, que definía su `$iniciales` antes del
navbar y funcionaba **por casualidad**: el navbar se lo pisaba con el valor del
mismo usuario.

> **Lección:** en un layout incluido con `require`, toda variable propia necesita
> un nombre que grite que es del layout. El ámbito compartido es una comodidad
> del patrón, pero convierte cualquier nombre genérico en una mina.

## Un token de color que no existía

`--gris2` estaba definida **sólo en `landing.css`**, que no se carga fuera de la
portada. `auth.css` la usaba seis veces —el color de los iconos y los
placeholders del login y el registro— así que esas seis reglas eran inválidas y
el color caía al heredado.

Un token que falta no rompe la página: la deja **distinta**, y nadie se entera.
Se movió a `estilos.css`, que es la hoja que cargan todos. `landing.css` conserva
su copia porque es autocontenida a propósito.

Lo mismo con `.solo-lectores`, que vivía en `auth.css` y hacía falta en las
pantallas del sistema.

## Notificaciones que dispara esta etapa

| Cuándo | Tipo | Correo |
|---|---|---|
| Se reserva un turno | `turno_reservado` | ✅ (aclarando que falta el pago) |
| Se cancela | `turno_cancelado` | ✅ (con el motivo, si se cargó) |
| Se reprograma | `turno_reprogramado` | ✅ (con el "antes" y el "ahora") |

Todas van por el servicio de [notificaciones](notificaciones.md), así que quedan
en el centro de avisos **y** llegan por correo sin que el controlador tenga que
saber cómo se manda un mail.

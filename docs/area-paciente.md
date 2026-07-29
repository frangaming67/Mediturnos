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

## Notificaciones que dispara esta etapa

| Cuándo | Tipo | Correo |
|---|---|---|
| Se reserva un turno | `turno_reservado` | ✅ (aclarando que falta el pago) |
| Se cancela | `turno_cancelado` | ✅ (con el motivo, si se cargó) |
| Se reprograma | `turno_reprogramado` | ✅ (con el "antes" y el "ahora") |

Todas van por el servicio de [notificaciones](notificaciones.md), así que quedan
en el centro de avisos **y** llegan por correo sin que el controlador tenga que
saber cómo se manda un mail.

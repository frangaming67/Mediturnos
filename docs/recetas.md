# Recetas y renovaciones

Etapa 5 del Área del Paciente. El médico prescribe, el paciente ve lo que le
prescribieron y pide la renovación cuando se le termina, y el profesional la
aprueba o la rechaza sin que haga falta un turno de por medio.

Se apoya en lo que dejó el [historial clínico](historial-clinico.md): prescribir
exige un turno **realizado**, igual que registrar la ficha.

> ## ⚠️ Esto no es una receta electrónica
>
> Una receta con validez legal en Argentina necesita firma digital del
> profesional y estar asentada en un registro habilitado. Acá no hay ni una cosa
> ni la otra.
>
> Lo que el sistema hace es llevar el **registro interno** de lo prescripto y
> resolver el circuito de renovación. La aclaración está escrita en la propia
> pantalla, y **dentro** del documento: si alguien lo imprime, la advertencia
> sale impresa. Al pie de la página no serviría de nada.

---

## De dónde salen los datos

| Lo que se ve | De dónde viene |
|---|---|
| Medicamentos, dosis, frecuencia | `receta_medicamento`, un renglón por medicamento |
| Hasta cuándo vale | `receta.vence_el`, calculado al emitir |
| Vigente / Vencida / Anulada | **se deduce**, no está guardado — ver abajo |
| Quién la firmó | `receta.matricula` → `medico` |
| Para quién es | `receta.id_paciente` → `paciente` |
| Pedidos de renovación | `renovacion_receta` |

Nada se dibuja con datos de ejemplo. Un paciente sin recetas ve un estado vacío
que lo explica.

---

## El vencimiento es una fecha, no un estado

La forma habitual de resolver esto sería `estado = 'Vencida'` y una tarea que lo
actualice. El proyecto ya tiene ese diseño en `pago` y ya conoce sus dos
problemas:

- necesita `expirarVencidos()` corriendo en **cada visita** para mantenerse al
  día (está anotado en la [deuda técnica](roadmap.md));
- y entre dos corridas la fila **miente**: dice "Vigente" algo que venció ayer.

Acá la vigencia se deduce de `vence_el`, que es un dato que no cambia solo.
`vence_el < CURDATE()` es verdad desde el instante exacto en que lo es, sin que
nadie pase a actualizar nada. No hay tarea programada y no hay estado
desactualizado.

El `estado` guarda entonces **sólo lo que una fecha no puede decir**: que alguien
la dio de baja a mano. `Vencida` no está en el ENUM porque no es una decisión, es
el paso del tiempo.

### Y se calcula en un solo lugar

`Receta::selectRecetas()` resuelve la columna `situacion` en SQL y **todas** las
pantallas leen esa columna. Si cada vista lo dedujera por su cuenta, una
compararía con `CURDATE()` y otra con la fecha de PHP, y en poco tiempo el
listado y el detalle dirían cosas distintas sobre la misma receta.

---

## Tres tablas

```
receta ──┬── receta_medicamento   (1:N, un renglón por medicamento)
         └── renovacion_receta    (1:N, los pedidos a lo largo del tiempo)
                     └── id_receta_nueva ──→ receta   (la que se emitió al aprobar)
```

### Por qué los medicamentos son una tabla y no un `TEXT`

- **Renovar es copiar los renglones.** Con un `TEXT` habría que interpretar lo
  que una persona escribió a mano.
- El paciente ve cada medicamento con su dosis y su frecuencia. Partir un `TEXT`
  para dibujarlo es adivinar.
- *"¿Cuáles son los medicamentos más recetados?"* —uno de los widgets que el
  tablero del administrador [hoy no puede mostrar](roadmap.md)— es un `GROUP BY`
  sobre esta tabla. Con un `TEXT` no es ninguna consulta.

El nombre del medicamento es un `VARCHAR` y no una clave foránea a un catálogo.
Es la misma decisión que `estudio.tipo`: un vademécum real son decenas de miles
de productos que se actualizan por fuera, y encerrarlo en una tabla propia
obligaría a mantenerlo a mano. Queda anotado: **si algún día hay que controlar
stock, ahí sí hace falta el catálogo cerrado.**

---

## 🚨 Dos renovaciones pendientes las frena el motor

Dos clics rápidos en "Solicitar renovación" son dos peticiones a la vez: las dos
consultan *"¿hay alguna pendiente?"*, las dos leen que no, y las dos insertan. El
médico ve el pedido duplicado en su bandeja y el paciente recibe dos correos.

Verificar en PHP **no alcanza**, porque entre el `SELECT` y el `INSERT` hay una
ventana. La garantía tiene que estar en el motor:

```sql
pendiente_unica INT GENERATED ALWAYS AS
                (IF(estado = 'Pendiente', id_receta, NULL)) STORED,
UNIQUE KEY uq_renov_pendiente (pendiente_unica)
```

Es el mismo recurso que `turno.slot_unico` (ver
[control de concurrencia](database.md)) y funciona por el mismo motivo: **en SQL
dos `NULL` no colisionan.** Se puede pedir la renovación de la misma receta
cuantas veces se quiera a lo largo del tiempo, pero nunca hay dos pendientes al
mismo tiempo.

La verificación en PHP sigue estando, en `motivoNoRenovable()`, pero para otra
cosa: explicarle a la persona por qué no está el botón. Lo que **garantiza** que
no haya dos es el índice; `solicitarRenovacion()` sólo traduce su error 1062 a un
`null`.

### El caso que el UNIQUE no cubre

Dos pestañas del médico apretando "Aprobar" sobre el mismo pedido no son dos
`INSERT`, son dos `UPDATE` sobre la misma fila: el índice no los ve. Ahí la
garantía es el `SELECT ... FOR UPDATE` que abre `resolverRenovacion()`, que
bloquea la fila hasta el `commit`. El segundo intento encuentra el pedido ya
resuelto y corta.

---

## Aprobar emite una receta nueva

Lo tentador es extenderle el vencimiento a la receta vieja: una línea de SQL y
listo. Sería **reescribir la historia clínica** — dejaría de existir el registro
de qué se prescribió en su momento y hasta cuándo valía.

Aprobar entonces:

1. emite una receta **nueva** con los mismos medicamentos, copiados con un
   `INSERT ... SELECT` (una sola ida a la base, imposible que se pierda un
   renglón);
2. la enlaza al pedido en `id_receta_nueva`;
3. y **no toca la original**.

Todo en una transacción. Una renovación `Aprobada` cuyo `id_receta_nueva` quedó
en `NULL` porque falló el segundo `INSERT` es un paciente al que el sistema le
dice "ya tenés tu receta" y no tiene ninguna.

Por eso una receta ya renovada deja de ser renovable: lo que hay que renovar es
la más reciente. Permitirlo generaría una cadena de recetas paralelas y el
paciente no sabría cuál está vigente.

---

## Cuándo NO se puede pedir la renovación

Todas las reglas viven en **un solo método**, `Receta::motivoNoRenovable()`, que
devuelve el texto a mostrar o `null` si se puede:

| Situación | Qué se le dice |
|---|---|
| Receta anulada | Hace falta una consulta para una nueva |
| Ya hay un pedido pendiente | Está esperando respuesta |
| Ya fue renovada | Pedí la renovación de la más reciente |
| El profesional ya no atiende | Agendá una consulta |

Lo usan **la vista** (para explicar por qué el botón no está) y **el
controlador** (para rechazar la petición aunque alguien arme la URL a mano). Es
el patrón de `Turno::motivoNoReprogramable()`, y por el mismo motivo: dos listas
de reglas en dos lugares terminan diciendo cosas distintas, y aparecen las dos
fallas clásicas — el botón que no hace nada, o el botón que no está pero la URL
sí funciona.

El último caso merece una nota. El pedido lo responde **el profesional que firmó
la receta** y nadie más. Si ese profesional ya no atiende, el pedido no tendría
quién lo responda: quedaría pendiente para siempre y el paciente esperando. Mejor
decírselo antes de que pida.

---

## Quién puede ver una receta

| Rol | Qué ve |
|---|---|
| Paciente | Sólo las suyas |
| Médico que la firmó | Siempre |
| Otro médico | Sólo si **atendió** a esa persona (turno realizado) |
| Admin y recepción | Todas: gestionan la clínica |

### `atendioAlPaciente` llega como parámetro, no se consulta acá

`Receta::puedeVer()` recibe un booleano en vez de hacer la consulta. A primera
vista es menos prolijo; la razón es concreta.

Esa regla vive en `Historial::atendioAlPaciente()` y **ya cambió una vez**:
contaba cualquier turno entre médico y paciente, lo que abría la historia clínica
con sólo agendar una cita a futuro —y no la cerraba nunca más si después se
cancelaba—. Hoy cuenta sólo los turnos realizados
([el detalle](historial-clinico.md)).

Una segunda copia de ese SQL en `Receta.php` habría quedado con la versión vieja.
Duplicar una regla de autorización es exactamente así como se reabre un agujero
que ya se había cerrado: la consulta vive en **un** solo lugar y acá se recibe su
resultado.

### Anular es otro permiso

Sólo el profesional que la firmó. Otro médico que atendió al paciente puede
**ver** la receta —eso es atención clínica— pero no dar de baja lo que prescribió
un colega. Es la misma distinción que en los estudios: ver y modificar no son el
mismo permiso.

Y anular **no borra la fila**. Una receta anulada es un hecho de la historia
clínica y el paciente tiene que poder ver que existió y que se dio de baja;
borrarla dejaría un hueco inexplicable entre dos consultas.

---

## El formulario funciona sin JavaScript

Los medicamentos son campos repetidos (`med_nombre[]`, `med_dosis[]`…). El
formulario trae tres renglones vacíos: se completan los que se necesiten y los
vacíos se descartan en el servidor.

El botón **"Agregar otro medicamento"** clona un renglón, y nace **oculto**: lo
muestra el propio script. Si el JavaScript no corre, no queda un botón que no
hace nada —el peor resultado posible—, quedan los tres renglones de siempre.

Lo que **no** se descarta en silencio es un renglón a medias (nombre sin dosis).
Eso vuelve como error: una dosis que falta es una dosis que el paciente no va a
saber.

---

## Notificaciones que dispara esta etapa

| Cuándo | Tipo | A quién | ¿Correo? |
|---|---|---|---|
| Se emite una receta | `RECETA_NUEVA` | Paciente | Sí |
| Se anula una receta | `RECETA_ANULADA` | Paciente | Sí |
| El paciente pide la renovación | `REFILL_SOLICITADO` | Médico | Sí |
| Se aprueba | `REFILL_APROBADO` | Paciente | Sí |
| Se rechaza | `REFILL_RECHAZADO` | Paciente | Sí |

Notificar es un **efecto colateral**: si el correo falla se registra en el log y
se sigue. Nunca se deshace una receta ya emitida por un problema de correo — el
mismo criterio que en el resto del sistema ([notificaciones](notificaciones.md)).

### Un tipo de aviso que estaba mal puesto

Pedir un estudio usaba `RECETA_NUEVA`. Funcionaba sólo porque las recetas todavía
no existían: en cuanto existieron, el paciente veía *"te pidieron un estudio"* con
el icono de una receta y no había forma de distinguir los dos avisos en el
listado. Ahora tiene su propio tipo, `ESTUDIO_PEDIDO`.

---

## Cómo se verifica

```bash
php pruebas/receta_modelo.php     # 81 comprobaciones
bash pruebas/receta_http.sh       # 96 comprobaciones
```

El primero cubre las reglas de negocio y las garantías del motor. El segundo
entra al sitio e intenta lo que **no** debería poder hacerse: CSRF sin token, los
tres roles cruzados, IDOR entre dos pacientes y dos médicos, `<script>` en el
nombre de un medicamento, los comodines de `LIKE` y el acceso sin sesión.

Los dos limpian lo que crean y restauran lo que modifican. Ver
[pruebas/LEEME.md](../pruebas/LEEME.md), que además documenta tres trampas de
este entorno que hacían fallar la prueba sin que el sistema tuviera nada que ver.

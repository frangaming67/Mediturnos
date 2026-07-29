# Base de datos

Motor **InnoDB** (obligatorio: se usan transacciones, claves foráneas y bloqueo a
nivel de fila). Codificación `utf8mb4`.

## Diagrama de relaciones

```
                    ┌──────────────┐
                    │ obra_social  │
                    └──────┬───────┘
                           │ 1:N
                    ┌──────▼───────┐         ┌──────────────────────┐
                    │   plan_os    │         │ descuento_os_medico  │
                    └──────┬───────┘         └──────────────────────┘
                           │                    (obra social × médico)
        ┌──────────────────┼───────────────────┐
        │                  │                   │
┌───────▼────────┐  ┌──────▼──────┐    ┌───────▼────────┐
│ paciente_plan  │  │    turno    │    │    medico      │
│ (N:M + nro     │  │             │    └───┬────────┬───┘
│  de afiliado)  │  │             │        │        │
└───────┬────────┘  └──┬───┬───┬──┘        │        │
        │              │   │   │           │        │
┌───────▼────────┐     │   │   │   ┌───────▼──┐  ┌──▼──────────────┐
│    paciente    │◄────┘   │   │   │ horario_ │  │ ausencia_medico │
└───────┬────────┘         │   │   │ atencion │  └─────────────────┘
        │                  │   │   └──────────┘
        │          ┌───────▼┐  └──────┐
        │          │  pago  │         │
        │          └────────┘  ┌──────▼──────────┐
        │                      │ historial_turno │
┌───────▼────────┐             └─────────────────┘
│    usuario     │──> rol ──< rol_permiso >── permiso
└────────────────┘
        │
        └──> password_reset · intento_login
```

## Tablas

### Núcleo

| Tabla | Descripción | Claves |
|---|---|---|
| `paciente` | Ficha del paciente | `dni` único |
| `medico` | Profesionales | PK `matricula` (número real, no autoincremental) |
| `especialidad` | Catálogo con precio y duración de turno | |
| `consultorio` | Consultorios físicos | `numero` + `piso` únicos |
| `turno` | **Entidad central** | Índices únicos de concurrencia |
| `estado_turno` | Catálogo de estados, ids fijos 1-5 | |
| `historial_turno` | Bitácora de cambios (la llenan triggers) | |
| `pago` | Un pago por turno | `id_turno` único |

### Coberturas

| Tabla | Descripción |
|---|---|
| `obra_social` | Obras sociales, `cuit` único |
| `plan_os` | Planes de cada obra social |
| `paciente_plan` | N:M paciente↔plan, con el número de afiliado |
| `descuento_os_medico` | Descuento por par (obra social, médico) |

### Acceso

| Tabla | Descripción |
|---|---|
| `usuario` | Cuentas. Enlaza a `paciente` o a `medico` según el rol |
| `rol` | admin · recepcionista · medico · paciente |
| `permiso` / `rol_permiso` | Permisos finos |
| `password_reset` | Tokens de recuperación (hasheados) |
| `intento_login` | Registro de intentos, para el bloqueo por fuerza bruta |

### Agenda

| Tabla | Descripción |
|---|---|
| `horario_atencion` | Franjas de atención por médico, día y consultorio |
| `ausencia_medico` | Días que un médico no atiende. Único `(matricula, fecha)` |

## Normalización

El modelo llegó a **4FN** a través de migraciones sucesivas:

### 1. Estado del turno → catálogo (`estado_turno.sql`)

`turno.estado` guardaba el texto (`'Reservado'`, `'Confirmado'`…) repetido en cada
fila. Es un atributo de dominio cerrado: corresponde una tabla de catálogo con FK.

Los ids se cargan **explícitos y fijos** (1-5) porque la columna generada
`slot_unico` los compara contra un literal y no admite subconsultas.

### 2. Número de afiliado → `paciente_plan` (`normalizacion.sql`)

`turno.nro_afiliado` era una dependencia transitiva: el número depende del par
(paciente, plan), no del turno. Un mismo paciente repetía el dato en cada turno.

### 3. `pago.monto_total` → columna generada

Era un valor derivado (`monto_base × (1 − descuento/100)`) guardado como dato
suelto, con riesgo de quedar inconsistente. Pasó a `GENERATED ALWAYS AS ... STORED`:
la base lo calcula y no se puede escribir a mano.

## Control de concurrencia

El punto más delicado: **evitar la doble reserva**.

```sql
-- Columna generada: identifica el "cupo" de un turno activo
ALTER TABLE turno ADD COLUMN slot_unico VARCHAR(60)
GENERATED ALWAYS AS (
    IF(id_estado = 5, NULL, CONCAT(matricula, '|', fecha, '|', hora_inicio))
) STORED;

ALTER TABLE turno ADD CONSTRAINT uq_turno_slot UNIQUE (slot_unico);
```

**El truco:** en un índice `UNIQUE`, varios `NULL` **no chocan entre sí**. Un turno
cancelado (`id_estado = 5`) produce `NULL`, así que libera el cupo y el horario
vuelve a estar disponible — sin perder el registro histórico.

Existe el equivalente `slot_consultorio` / `uq_turno_consultorio`, porque el primero
sólo miraba el médico: dos profesionales distintos podían quedar asignados al mismo
consultorio a la misma hora.

## Vistas

| Vista | Uso |
|---|---|
| `v_turnos_detalle` | Turno con todos sus datos resueltos. La usa `Turno::listar()` |
| `v_pagos_detalle` | Pago con datos del turno. La usa `Pago::listar()` |
| `v_agenda_hoy` | Turnos de hoy (sobre `v_turnos_detalle`) |
| `v_pagos_pendientes` | Pagos por vencer |
| `v_recaudacion_medico` | Agregación por médico |

Concentran los JOIN en un solo lugar: si se agrega una columna al listado se toca
la vista, no el modelo.

## Triggers

| Trigger | Cuándo | Qué hace |
|---|---|---|
| `trg_turno_after_insert` | Tras crear un turno | Registra el estado inicial |
| `trg_turno_after_update` | Tras modificarlo | Registra el cambio de estado **y** el de horario |

> `trg_turno_after_update` originalmente sólo miraba el estado, así que un turno
> que cambiaba de fecha pasaba sin dejar rastro. Desde `reprogramacion.sql`
> también registra el movimiento (`Reprogramado: 25/07 10:00 → 26/07 08:00`).
> Se extendió el trigger en vez de insertar desde PHP para que el cambio quede
> asentado venga de donde venga, incluso de un `UPDATE` hecho a mano.

Por eso los modelos **no** insertan en `historial_turno` a mano: quedaría duplicado.

## Procedimientos almacenados

### `ReservarTurno`

Valida que el médico y el consultorio estén libres y crea el turno. Devuelve el id
por parámetro `OUT`. Si hay conflicto lanza `SIGNAL SQLSTATE '45000'` con un mensaje
que el PHP traduce.

Asigna `p_id_turno = -1` antes del `SIGNAL`: convención defensiva para que la
variable no conserve el id de una reserva anterior si alguien la leyera tras el
fallo.

### `CancelarTurno`

Rechaza cancelar un turno inexistente o ya finalizado.

## Migraciones

Ejecutar **en este orden** (cada una asume la anterior):

| # | Archivo | Qué hace |
|---|---|---|
| 1 | `seed_datos.sql` | Tablas base y datos de prueba |
| 2 | `triggers_y_sp.sql` | Triggers, procedimientos e índices |
| 3 | `pagos_y_descuentos.sql` | Pagos y descuentos |
| 4 | `estado_turno.sql` | Normaliza el estado |
| 5 | `normalizacion.sql` | Afiliado y monto generado |
| 6 | `control_concurrencia.sql` | Índice único por médico |
| 7 | `concurrencia_consultorio.sql` | Índice único por consultorio |
| 8 | `ausencias_medico.sql` | Ausencias |
| 9 | `baja_medico.sql` | Baja lógica + permiso |
| 10 | `vistas.sql` | Vistas |
| 11 | `auth_v2.sql` | Autenticación v2 |
| 12 | `perfil.sql` | Columnas de contacto editables desde el perfil |
| 13 | `notificaciones.sql` | Centro de notificaciones |
| 14 | `reprogramacion.sql` | El trigger también registra el cambio de horario |
| 15 | `calificaciones.sql` | Calificación de profesionales |

## Defectos de esquema corregidos en `auth_v2.sql`

Se detectaron con datos reales:

### `paciente.telefono` era `int(10) unsigned`

Máximo 4.294.967.295. Un celular con característica (`91123456789`) **no entra**.
Al cargar los datos de prueba, MySQL aplastó **1000 de 1010 teléfonos** al valor
máximo — todos quedaron idénticos e inservibles.

Un teléfono nunca es un número con el que se opere: es una cadena. Pasó a `VARCHAR(30)`.

### `paciente.email` era `varchar(30)`

El estándar admite 254. Una dirección normal como
`maria.fernandez.lopez@hospitalitaliano.org.ar` (45 caracteres) se guardaba
**truncada y sin aviso**. Pasó a `VARCHAR(120)`.

> **Lección:** ambos defectos existían desde el diseño inicial y sólo aparecieron
> al cargar volumen real. Conviene probar con datos realistas antes de dar por
> cerrado un esquema.

## Los mismos defectos, corregidos en `perfil.sql`

Al habilitar la edición del propio teléfono y correo aparecieron **tres columnas
más** con el mismo problema. Ninguna se había notado porque hasta entonces esos
datos no se editaban desde la aplicación.

| Columna | Era | Es | Qué rompía |
|---|---|---|---|
| `medico.telefono` | `int(10) unsigned` | `VARCHAR(30)` | Un celular de 11 dígitos se aplastaba en 4294967295 |
| `medico.email` | `varchar(30)` | `VARCHAR(120)` | Truncaba direcciones institucionales normales |
| `usuario.email` | `varchar(100)` | `VARCHAR(120)` | Ver abajo |

### El caso de `usuario.email`

Es el más silencioso de los tres. El registro validaba hasta **120** caracteres y
guardaba el mismo correo en dos tablas: `paciente.email` (que ya era 120) y
`usuario.email` (que era 100).

Una dirección de entre 101 y 120 caracteres pasaba la validación, entraba entera
en `paciente` y **truncada** en `usuario`. Esa persona quedaba sin poder iniciar
sesión con su correo ni recuperar la contraseña: las dos búsquedas son por
igualdad exacta contra un valor que ya no era el suyo.

> **Por qué ninguno daba error:** el `sql_mode` de esta instalación no incluye
> `STRICT_TRANS_TABLES`. Sin modo estricto MySQL **satura o trunca en silencio**
> en vez de rechazar la escritura. Es lo que convierte un tipo mal elegido en una
> pérdida de datos invisible.

**Verificado** tras la migración: los 6 teléfonos de médicos se convirtieron sin
perder un dígito, y un teléfono de 11 dígitos guardado desde el perfil ahora se
almacena completo.

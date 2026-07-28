# Endpoints internos

El proyecto no expone una API pública. Sí hay endpoints que devuelven JSON,
consumidos por el JavaScript de la propia aplicación.

**Todos exigen sesión activa.** Sin cookie válida responden `302` hacia el login.

---

## `GET ControladorTurno.php?accion=horarios`

Días de la semana en que un médico atiende. Lo usa el calendario para pintar los
días disponibles.

**Parámetros:** `matricula` (int)

```json
["Lunes", "Miercoles", "Viernes"]
```

> Los días van **sin tilde**, tal como se guardan en `horario_atencion.dia_semana`.

---

## `GET ControladorTurno.php?accion=slots`

Horarios libres de un médico en una fecha.

**Parámetros:** `matricula` (int), `fecha` (`YYYY-MM-DD`)

```json
[
  {
    "hora": "08:00",
    "hora_full": "08:00:00",
    "id_consultorio": "1",
    "id_especialidad": "1",
    "especialidad": "Clinica Medica",
    "consultorio": "Cons. 1 - Piso 1"
  }
]
```

Devuelve `[]` si el médico no atiende ese día **o si está marcado como ausente**.
La lógica vive en `Turno::obtenerSlots()`: el endpoint sólo la expone.

---

## `GET ControladorTurno.php?accion=ausencias`

Fechas futuras en que un médico no atiende. El calendario las tacha.

**Parámetros:** `matricula` (int)

```json
["2026-08-05", "2026-08-12"]
```

---

## `GET ControladorTurno.php?accion=historial`

Historial de cambios de estado de un turno.

**Parámetros:** `id` (int)

```json
{
  "turno": { "id_turno": 52, "fecha": "2026-07-27", "paciente": "Perez, Juan" },
  "historial": [
    {
      "estado_anterior": "Reservado",
      "estado_nuevo": "Confirmado",
      "observacion": "Pago registrado",
      "fecha_cambio": "2026-07-27 10:15:00"
    }
  ]
}
```

**Autorización:** un paciente sólo puede consultar el historial de sus propios
turnos. Si pide otro, responde `403`:

```json
{ "error": "No autorizado" }
```

> El consumidor debe verificar `response.ok` **antes** de leer el JSON: sin ese
> chequeo, un `403` se confundía con "sin registros" y mostraba un mensaje
> engañoso.

---

## Notas de implementación

- Todas las respuestas van con `Content-Type: application/json`.
- No hay versionado ni paginación: son endpoints internos de alcance acotado.
- Los datos que llegan de estos endpoints se escriben en el DOM con `textContent`,
  nunca con `innerHTML`.
- Ante un fallo de base, el endpoint de ausencias devuelve `[]` en lugar de romper
  el calendario.

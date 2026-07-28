# ADR-0004 — Login por nombre de usuario o correo

- **Estado:** aceptada
- **Fecha:** 2026-07

## Contexto

El sistema nació con login por **nombre de usuario** (`admin`, `cfernandez`,
`recepcion`). Al rediseñar la autenticación se planteó pasar a **correo
electrónico**, que es lo habitual hoy: nadie recuerda un alias que usa dos veces
al año.

El problema: **cambiarlo de golpe deja afuera a todas las cuentas existentes**. Los
usuarios de prueba, el personal cargado y cualquier cuenta creada hasta ahora
entran con alias. Un cambio así rompe el acceso de todos.

## Decisión

Aceptar **ambos** en el mismo campo.

```sql
WHERE (u.usuario = :ident1 OR u.email = :ident2)
  AND u.estado = 'activo'
```

La etiqueta del formulario dice "Usuario o correo electrónico" y el
`autocomplete="username"` permite que los gestores de contraseñas funcionen con
cualquiera de los dos.

## Consecuencias

### A favor
- Nadie pierde el acceso
- Quien se registra hoy puede usar su correo, que es lo que va a recordar
- No hace falta migrar datos ni avisar a los usuarios
- El registro ya exige correo único, así que no hay ambigüedad

### En contra
- La consulta evalúa dos columnas en lugar de una (ambas indexadas: impacto nulo)
- Un usuario podría, en teoría, elegir como alias el correo de otra persona. Se
  mitiga con la validación del registro, que sólo admite
  `[a-zA-Z0-9._-]` — una arroba no pasa el filtro

### Detalle técnico

Se usan **dos marcadores distintos con el mismo valor**:

```php
$stmt->execute([':ident1' => $identificador, ':ident2' => $identificador]);
```

No es redundancia: con `PDO::ATTR_EMULATE_PREPARES = false` la preparación la hace
MySQL, que **no admite repetir un marcador** en la misma consulta.

## Alternativas descartadas

| Opción | Por qué no |
|---|---|
| Sólo correo | Rompe el acceso de todas las cuentas existentes |
| Sólo usuario | Se aleja de lo que espera cualquiera hoy |
| Dos campos separados | Peor experiencia: obliga a elegir antes de escribir |
| Detectar por la arroba | Frágil, y no aporta nada frente al `OR` |

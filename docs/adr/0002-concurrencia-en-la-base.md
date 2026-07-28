# ADR-0002 — La concurrencia se resuelve en el motor, no en PHP

- **Estado:** aceptada
- **Fecha:** 2026-06

## Contexto

Dos pacientes pueden intentar reservar el mismo horario con el mismo médico al
mismo tiempo. Hay que garantizar que sólo uno lo consiga.

El enfoque intuitivo es validar en PHP:

```php
if ($modelo->estaLibre($matricula, $fecha, $hora)) {
    $modelo->crear(...);
}
```

Ese código **tiene una condición de carrera**. Entre el chequeo y la inserción hay
una ventana de tiempo:

```
Usuario A: ¿libre? → sí
Usuario B: ¿libre? → sí          ← los dos pasaron
Usuario A: INSERT  → ok
Usuario B: INSERT  → ok          ← turno duplicado
```

No importa cuánto se acorte la ventana: siempre existe. Es el problema clásico de
tiempo-de-chequeo contra tiempo-de-uso (TOCTOU).

## Decisión

La garantía la da un **índice único de la base de datos** sobre una columna
generada. PHP sólo traduce el error a un mensaje comprensible.

```sql
ALTER TABLE turno ADD COLUMN slot_unico VARCHAR(60)
GENERATED ALWAYS AS (
    IF(id_estado = 5, NULL, CONCAT(matricula, '|', fecha, '|', hora_inicio))
) STORED;

ALTER TABLE turno ADD CONSTRAINT uq_turno_slot UNIQUE (slot_unico);
```

## Por qué funciona

InnoDB verifica el índice único de forma **atómica y serializada**. Con dos
inserciones simultáneas, una entra y la otra recibe el error 1062. No hay ventana.

**El detalle clave:** en un índice `UNIQUE`, varios `NULL` **no chocan entre sí**.
Un turno cancelado (`id_estado = 5`) produce `NULL`, así que libera el cupo y el
horario vuelve a estar disponible — sin borrar el registro histórico.

## Consecuencias

### A favor
- La regla no se puede saltear, ni siquiera desde otro cliente o desde la consola
- Funciona bajo cualquier nivel de concurrencia
- Es la fuente única de verdad

### En contra
- Lógica de negocio fuera del código de la aplicación
- Requiere InnoDB (MyISAM no sirve: no es transaccional)
- Los ids de `estado_turno` deben ser **fijos**: una columna generada no admite
  subconsultas y compara contra el literal `5`
- El mensaje de error llega como código 1062 y hay que traducirlo

### Complemento
Existe un segundo índice, `uq_turno_consultorio`, porque el primero sólo miraba al
médico: dos profesionales distintos podían quedar asignados al mismo consultorio a
la misma hora.

El procedimiento `ReservarTurno` hace además un pre-chequeo, pero es sólo para dar
un mensaje temprano y claro: **la garantía real sigue siendo el índice**.

## Alternativas descartadas

| Opción | Por qué no |
|---|---|
| Validar sólo en PHP | Tiene la condición de carrera descrita |
| `SELECT ... FOR UPDATE` | Funciona, pero bloquea filas y complica el flujo |
| `LOCK TABLES` | Serializa toda la tabla: inaceptable |
| Nivel de aislamiento serializable | Penaliza todas las consultas por un caso puntual |

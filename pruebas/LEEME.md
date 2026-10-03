# Pruebas

Guiones de verificación que se ejecutan a mano desde la línea de comandos.

```bash
php pruebas/receta_modelo.php
```

Cada uno imprime una línea por comprobación y termina con el total. El código
de salida es `0` si todo pasó y `1` si algo falló, así que sirven en un
`if` de un script o, el día que lo haya, en un *pipeline*.

## Por qué están versionados acá

Durante las primeras etapas estos guiones se escribían en la carpeta temporal
del sistema. Funcionó mientras duró la sesión y se perdieron todos: más de
quinientas comprobaciones de las etapas 1 a 4 que hoy no se pueden volver a
ejecutar. Lo que no está en el repositorio, no existe.

## Qué NO son

No son pruebas unitarias con *mocks*: corren contra la base de desarrollo real,
que es exactamente lo que se quiere verificar (los `CHECK`, los `UNIQUE`, las
claves foráneas y las columnas generadas son del motor, y un *mock* no las
ejecuta). La contrapartida es que **escriben en la base**, así que:

- Cada guión limpia al terminar lo que creó, y lo comprueba.
- Si modifica algo que ya existía —por ejemplo el estado de un médico— lo
  restaura y verifica que quedó como estaba.
- **No correrlos contra la base de producción.**

Pasar a pruebas unitarias de verdad pide PHPUnit, y PHPUnit pide Composer, que
el proyecto descartó a propósito ([ADR-0001](../docs/adr/0001-sin-framework.md)).
Está anotado en la deuda técnica.

# Pruebas

Guiones de verificación que se ejecutan a mano desde la línea de comandos.

```bash
php pruebas/receta_modelo.php     # 81 comprobaciones del modelo
bash pruebas/receta_http.sh       # 96 comprobaciones por HTTP
bash pruebas/humo.sh              # 24 pantallas, en los tres roles
```

Cada uno imprime una línea por comprobación y termina con el total. El código
de salida es `0` si todo pasó y `1` si algo falló, así que sirven en un `if` de
un script o, el día que lo haya, en un *pipeline*.

| Guión | Qué cubre | Necesita |
|---|---|---|
| `receta_modelo.php` | Las reglas de negocio y las garantías del motor (`CHECK`, `UNIQUE`, columnas generadas, `ON DELETE CASCADE`) | MySQL |
| `receta_http.sh` | Lo que sólo se ve entrando al sitio: roles, CSRF, IDOR, escapado en el HTML, redirecciones | MySQL, Apache y Git Bash |
| `humo.sh` | Que ninguna pantalla devuelva un código inesperado ni imprima un aviso de PHP, en los tres roles | MySQL, Apache y Git Bash |

## Por qué están versionados acá

Durante las primeras etapas estos guiones se escribían en la carpeta temporal
del sistema. Funcionó mientras duró la sesión y se perdieron todos: más de
quinientas comprobaciones de las etapas 1 a 4 que hoy no se pueden volver a
ejecutar. Lo que no está en el repositorio, no existe.

### Por qué `humo.sh` mira los avisos de PHP y no sólo el código

Un `Warning` o un `Notice` no cambia el código de respuesta: la página devuelve
200 con el aviso escrito arriba del contenido, y ahí se queda hasta que alguien
lo ve de casualidad. Un parámetro de la URL que llega como arreglo donde se
esperaba texto es exactamente eso, y por eso está entre las comprobaciones.

## Qué NO son

No son pruebas unitarias con *mocks*: corren contra la base de desarrollo real,
que es exactamente lo que se quiere verificar (los `CHECK`, los `UNIQUE`, las
claves foráneas y las columnas generadas son del motor, y un *mock* no los
ejecuta). La contrapartida es que **escriben en la base**, así que:

- Cada guión limpia al terminar lo que creó, y lo comprueba.
- Si modifica algo que ya existía —el estado de un médico, la contraseña de una
  cuenta de prueba— lo restaura y verifica que quedó como estaba. En
  `receta_http.sh` eso va en un `trap EXIT`, para que ocurra incluso si el
  guión se corta por la mitad.
- **No correrlos contra la base de producción.**

Pasar a pruebas unitarias de verdad pide PHPUnit, y PHPUnit pide Composer, que
el proyecto descartó a propósito ([ADR-0001](../docs/adr/0001-sin-framework.md)).
Está anotado en la deuda técnica.

## Tres trampas de este entorno, que ya costaron un rato

Las tres hacían que la prueba acusara al sistema de algo que no era suyo. Están
resueltas dentro de los guiones, pero conviene conocerlas antes de escribir uno
nuevo.

**1. Los acentos no sobreviven a un argumento de línea de comandos.**
Entre Git Bash y `curl.exe`, Windows convierte los argumentos a la página de
códigos del sistema: `días` sale de bash como UTF-8 (`64 C3 AD 61 73`) y le
llega a PHP como latin-1 (`64 ED 61 73`), que se guarda como `d?as`. Un
navegador manda UTF-8 y se guarda perfecto —está comprobado—, así que es un
problema del intérprete de comandos y no del sistema. `receta_http.sh` lo evita
con `pct()`, que percent-encoda el valor antes de pasarlo: el argumento queda
en ASCII puro y Windows no tiene nada que convertir.

**2. El cliente de MySQL devuelve el texto en la página de códigos de la
consola**, incluso con `--default-character-set=utf8mb4`. Comparar texto con
acentos leído de la base, entonces, no se puede. Para verificar que un acento
se guardó bien se mira `HEX(columna) LIKE '%C3AD%'`: el HEX es ASCII y cruza sin
que nadie lo convierta. Y devuelve los valores con CR al final, que `$( )` no
saca — por eso las lecturas pasan por `my()`.

**3. Apache y MySQL de este XAMPP se caen.**
Cuando el servidor se cae a mitad de la corrida, todas las peticiones devuelven
el código `000` y aparecen ochenta fallas seguidas que parecen del sistema. Los
guiones cortan a la primera, con el motivo a la vista: `vivo()` para Apache y
`my()` para la base.

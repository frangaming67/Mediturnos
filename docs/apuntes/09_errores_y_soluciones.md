# Errores y soluciones

> Apunte original del proyecto, conservado tal cual se escribió durante
> el desarrollo. La documentación técnica vigente está en la carpeta
> `docs/` (un nivel arriba); estos textos se mantienen como referencia
> histórica y material de estudio para la defensa.

```text
================================================================
MEDITURNOS — ERRORES ENCONTRADOS Y CÓMO SE SOLUCIONARON
================================================================

Este documento explica, en lenguaje simple, los 3 problemas que se
arreglaron y, en cada uno:
  - DÓNDE estaba el error (archivo y línea)
  - POR QUÉ daba ese error
  - CÓMO funciona la lógica de la solución

Orden: del más importante (concurrencia) al más simple.


================================================================
ERROR 1 — DOS PACIENTES PODÍAN RESERVAR EL MISMO TURNO
================================================================

QUÉ PASABA
----------
Dos pacientes podían reservar el MISMO turno (mismo médico, misma
fecha y misma hora). De hecho ya había ocurrido en los datos: el
médico 10001 tenía dos turnos activos el 03/06/2026 a las 08:40.

DÓNDE ESTABA
------------
- sistema/modelos/Turno.php  -> método reservar()
- seed_datos.sql             -> la tabla "turno" no tenía ninguna
                                regla que impidiera el duplicado.

POR QUÉ DABA EL ERROR
---------------------
La reserva hacía "chequear y después insertar":
  1) Primero contaba si el horario estaba ocupado (un SELECT).
  2) Si estaba libre, hacía el INSERT.

Entre el paso 1 y el 2 pasa un instante de tiempo. Si dos personas
reservan a la vez, las dos leen "libre" antes de que cualquiera
inserte, y las dos insertan. Resultado: turno duplicado.
Esto se llama "condición de carrera" (race condition).

Validar solo con PHP NO alcanza: por más que el código revise, dos
pedidos al mismo tiempo pueden pasar la revisión antes de guardar.

CÓMO SE SOLUCIONÓ (la lógica)
-----------------------------
La regla la tiene que hacer cumplir la BASE DE DATOS, no el PHP,
usando las propiedades ACID (la base es InnoDB, que las soporta):

  A (Atomicidad): el INSERT corre dentro de una transacción
                  (beginTransaction / commit / rollBack): se hace
                  todo o no se hace nada.
  C (Consistencia): se creó una restricción UNIQUE que prohíbe dos
                  turnos activos en el mismo médico/fecha/hora.
  I (Aislamiento): InnoDB pone en fila las reservas que pelean por
                  el mismo lugar; una entra, la otra es rechazada.
  D (Durabilidad): al confirmar (commit), el turno queda guardado.

Para poder reservar de nuevo un horario que fue CANCELADO, se usó una
columna generada "slot_unico":
  - Turno activo    -> guarda "matricula|fecha|hora" (texto único)
  - Turno cancelado -> guarda NULL
En un índice UNIQUE varios NULL no chocan entre sí, así el horario
cancelado vuelve a quedar libre sin borrar el historial.

Y en el PHP, si la base rechaza el duplicado (error 1062), se atrapa
y se muestra un mensaje claro: "Ese turno acaba de ser reservado por
otra persona. Elegí otro horario."

ARCHIVOS TOCADOS
----------------
- control_concurrencia.sql (NUEVO): crea la columna y el índice único.
- sistema/modelos/Turno.php: reservar() con transacción y manejo del
  error de duplicado.
- sistema/controladores/ControladorTurno.php: el catch ahora muestra
  ese mensaje amable.
- triggers_y_sp.sql: se arregló un bug del SP ReservarTurno (decía
  "p_fecha2", una variable que no existe; debía ser "p_fecha").

CÓMO PROBARLO
-------------
- Intentar guardar dos turnos iguales -> el 2do da "ERROR 1062
  Duplicate entry ... for key 'uq_turno_slot'".
- Cancelar un turno y volver a reservar ese horario -> funciona.


================================================================
ERROR 2 — AL ELEGIR UN HORARIO NO SE SELECCIONABA
================================================================

QUÉ PASABA
----------
En "Nuevo turno", Paso 2, al hacer click en un horario no se marcaba
nada, y al "Confirmar reserva" no se reservaba.

DÓNDE ESTABA
------------
sistema/vistas/turnos/nuevo.php  (la grilla de horarios del paso 2).

POR QUÉ DABA EL ERROR
---------------------
Cada horario era una etiqueta <label class="slot-opcion"> PERO le
faltaba adentro el <input type="radio">. Sin ese input:
  - No hay nada que el navegador pueda "seleccionar" (por eso no se
    marcaba al hacer click).
  - El dato del horario llegaba vacío al servidor, así que la reserva
    fallaba con "Datos incompletos".
El CSS ya estaba listo para resaltar el seleccionado, pero no había
input que marcar.

CÓMO SE SOLUCIONÓ (la lógica)
-----------------------------
Se agregó el input que faltaba dentro de cada etiqueta:

  <input type="radio" name="slot" value="hora|consultorio|especialidad" required>

  - name="slot": todos los horarios comparten el mismo nombre, así
    solo se puede elegir uno (comportamiento de "radio").
  - value="...": guarda los 3 datos que el servidor necesita
    (hora, consultorio y especialidad), separados por "|".
  - required: el navegador no deja confirmar si no se eligió ninguno.

El CSS oculta el círculo del radio y, gracias a la regla
".slot-opcion:has(input:checked)", pinta de azul el horario elegido.

ARCHIVO TOCADO
--------------
- sistema/vistas/turnos/nuevo.php


================================================================
ERROR 3 — FATAL ERROR AL BUSCAR USUARIOS POR NOMBRE
================================================================

QUÉ PASABA
----------
En "Usuarios del sistema", al escribir algo en "Buscar" y filtrar,
explotaba con:
  Fatal error: SQLSTATE[HY093]: Invalid parameter number
Los filtros de Rol y Estado funcionaban; solo se rompía la búsqueda.

DÓNDE ESTABA
------------
sistema/modelos/Usuario.php  -> método listar() (línea 85 aprox).

POR QUÉ DABA EL ERROR
---------------------
La consulta buscaba en 4 columnas usando el MISMO marcador ":q":
  (u.usuario LIKE :q OR u.nombre LIKE :q OR u.apellido LIKE :q OR u.email LIKE :q)
pero al ejecutar se le pasaba el valor de ":q" UNA sola vez.

La conexión usa "prepared statements reales"
(PDO::ATTR_EMULATE_PREPARES = false, en config/conexion.php).
En ese modo, MySQL exige un marcador distinto por cada posición: no
se puede repetir el mismo ":q" cuatro veces. Como había 4 usos y 1
solo valor, los números no coincidían -> "Invalid parameter number".

CÓMO SE SOLUCIONÓ (la lógica)
-----------------------------
Se usó un marcador distinto por columna, todos con el mismo valor:

  (u.usuario LIKE :q1 OR u.nombre LIKE :q2 OR u.apellido LIKE :q3 OR u.email LIKE :q4)
  $like = '%' . $busqueda . '%';
  :q1 = :q2 = :q3 = :q4 = $like

Así hay 4 marcadores y 4 valores: los números coinciden y la
búsqueda funciona en usuario, nombre, apellido y email a la vez.

ARCHIVO TOCADO
--------------
- sistema/modelos/Usuario.php


================================================================
ERROR 4 — EL HISTORIAL DE TURNOS NO SE REGISTRABA
================================================================

QUÉ PASABA
----------
La tabla historial_turno casi no se llenaba: había 1 sola fila para
11 turnos. Reservar un turno o cambiarle el estado (Confirmado,
Realizado, Ausente) NO dejaba ningún registro de historial.

DÓNDE ESTABA
------------
- La base de datos: los TRIGGERS y los PROCEDURES nunca se habían
  instalado (el archivo triggers_y_sp.sql no se había ejecutado).
- sistema/modelos/Turno.php -> cancelar() insertaba el historial "a
  mano", lo que iba a quedar DUPLICADO una vez instalado el trigger.

POR QUÉ DABA EL ERROR
---------------------
El código confía en que dos triggers escriban el historial solos:
  - trg_turno_after_insert  -> registra "Turno creado" al reservar.
  - trg_turno_after_update  -> registra cada cambio de estado.
Como esos triggers no existían en la base, esas escrituras nunca
ocurrían. El único historial que aparecía era el que cancelar()
insertaba a mano.

CÓMO SE SOLUCIONÓ (la lógica)
-----------------------------
1) Se ejecutó triggers_y_sp.sql para instalar los 2 triggers y los 2
   procedimientos (ReservarTurno, CancelarTurno).
2) Se sacó el INSERT manual de historial de cancelar(): ahora el
   historial lo escribe SIEMPRE el trigger, en un solo lugar, así no
   se duplica. (Una sola "fuente de la verdad").

Prueba: reservar + cancelar deja exactamente 2 filas de historial:
  "Turno creado" y "Reservado -> Cancelado". Sin duplicados.

ARCHIVOS TOCADOS
----------------
- sistema/modelos/Turno.php (cancelar simplificado)
- triggers_y_sp.sql (se ejecutó en la base; ya estaba el fix del SP)


================================================================
ERROR 5 — NO SE PODÍA ENTRAR COMO MÉDICO NI RECEPCIONISTA
================================================================

QUÉ PASABA
----------
El sistema maneja 4 roles (admin, recepcionista, medico, paciente) y
hay 5 médicos cargados, pero solo existían cuentas de admin y de
paciente. Nadie podía iniciar sesión como médico o recepcionista, así
que esas pantallas y permisos no se podían usar ni probar.

DÓNDE ESTABA
------------
Faltaban filas en la tabla "usuario" para esos roles (los roles SÍ
estaban definidos en la tabla "rol").

CÓMO SE SOLUCIONÓ (la lógica)
-----------------------------
Se crearon dos cuentas de prueba con la misma contraseña encriptada
(bcrypt) que usa el login, enlazando el médico a una matrícula real:

  Usuario: recepcion   Contraseña: recepcion123   (rol recepcionista)
  Usuario: medico      Contraseña: medico123       (rol medico, matricula 10001)

(Cambiá estas contraseñas antes de usar el sistema en serio.)


================================================================
ERROR 6 — UN PACIENTE PODÍA VER LA LISTA DE TODOS LOS PACIENTES
================================================================

QUÉ PASABA
----------
En el menú, "Pacientes" solo se muestra a admin/recepcionista, pero si
un paciente escribía la URL del listado a mano, igual lo veía (datos
personales de todos: DNI, teléfono, email).

DÓNDE ESTABA
------------
sistema/controladores/ControladorPaciente.php: la acción "index" solo
llamaba a verificarSesion() y le faltaba verificarRol().

POR QUÉ DABA EL ERROR
---------------------
verificarSesion() solo comprueba que HAYA sesión, no QUÉ rol tiene.
Sin verificarRol(), cualquier usuario logueado pasaba.

CÓMO SE SOLUCIONÓ (la lógica)
-----------------------------
Se agregó verificarRol(['admin','recepcionista']) al principio del
controlador, así protege TODAS sus acciones de una sola vez. Si un
paciente intenta entrar, ve la pantalla 403 (Acceso denegado).

ARCHIVO TOCADO
--------------
- sistema/controladores/ControladorPaciente.php


================================================================
OBSERVACIONES MENORES (no rompen, conviene revisar)
================================================================
- seed_datos.sql define la columna "id_historial", pero la base real
  la tiene como "id_hist". No afecta al sistema (el código no usa ese
  nombre), pero el archivo del repo no coincide con la base.
- medico.email es NOT NULL: si se crea un médico sin email, el INSERT
  falla. Faltaría hacer el email obligatorio en el formulario o
  permitir NULL en la columna.
- Al cancelar un turno no se valida el estado previo (se puede
  "cancelar" uno ya Realizado). El procedimiento CancelarTurno sí
  valida eso, pero el modelo no lo usa.


================================================================
RESUMEN RÁPIDO
================================================================
1) Doble reserva    -> la EVITA la base con un índice UNIQUE (ACID),
                       no el PHP. + transacción + mensaje claro.
2) Horario no se     -> faltaba el <input type="radio"> dentro del
   seleccionaba         <label>; se agregó (con name, value y required).
3) Buscar usuario    -> un mismo marcador ":q" repetido 4 veces con
   reventaba            prepares reales; se separó en :q1..:q4.
4) Historial vacío   -> faltaban los triggers; se instalaron y se quitó
                       el INSERT manual de cancelar() (no duplicar).
5) Médico/recepción  -> no había cuentas; se crearon de prueba.
   sin login
6) Paciente veía     -> faltaba verificarRol() en el controlador de
   todos los pacientes  pacientes; se agregó.
```

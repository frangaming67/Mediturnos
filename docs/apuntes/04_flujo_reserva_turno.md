# Flujo reserva turno

> Apunte original del proyecto, conservado tal cual se escribió durante
> el desarrollo. La documentación técnica vigente está en la carpeta
> `docs/` (un nivel arriba); estos textos se mantienen como referencia
> histórica y material de estudio para la defensa.

```text
================================================================
MEDITURNOS — FLUJO COMPLETO DE RESERVA DE TURNO
================================================================

HAY DOS CAMINOS PARA RESERVAR UN TURNO:

A) DESDE EL DASHBOARD (solo rol paciente) → usa JavaScript + AJAX
B) DESDE TURNOS → NUEVO TURNO (todos los roles) → usa formulario PHP en dos pasos

Este documento explica el camino B (el PHP puro, sin JavaScript).

================================================================
CAMINO B: FORMULARIO EN DOS PASOS
================================================================

PASO 1 — El usuario elige médico, fecha y plan
----------------------------------------------
URL: ControladorTurno.php?accion=nuevo

1. El controlador verifica la sesión y que el rol sea admin, recepcionista o paciente.
2. Carga las listas necesarias para los selects del formulario:
     $medicos   = $modelo->listarMedicos()    → SELECT de la tabla medico
     $pacientes = $modelo->listarPacientes()  → SELECT de la tabla paciente
     $planes    = $modelo->listarPlanes()     → SELECT con JOIN plan_os + obra_social
3. La variable $paso vale 1 (viene por defecto porque no hay POST todavía).
4. El controlador hace require de la vista nuevo.php.
5. La vista muestra el formulario con los selects de médico, fecha, plan y plan.
   - Si el usuario es 'paciente', NO ve el select de paciente
     (el sistema usa su propio id de la sesión)
   - El formulario tiene un campo oculto: <input type="hidden" name="paso" value="2">
     Esto le indica al controlador que cuando se envíe, es el paso 2.

PASO 2 — El servidor calcula los slots disponibles
---------------------------------------------------
URL: ControladorTurno.php?accion=nuevo (mismo controlador, método POST)

1. El controlador recibe los datos del paso 1 por $_POST.
2. Lee $paso = (int)($_POST['paso'] ?? 1) → vale 2.
3. Valida que médico, fecha, plan y paciente estén completos.
   Si falta alguno, vuelve a mostrar el paso 1 con un mensaje de error.
4. Llama a $modelo->obtenerSlots($matricula, $fecha).

¿QUÉ HACE obtenerSlots()?
--------------------------
Este método está en Turno.php (el modelo).

a) Convierte la fecha a día de la semana en español:
     date('N', strtotime($fecha)) devuelve 1=Lunes, 2=Martes, ... 7=Domingo
     Se mapea a ['Lunes','Martes','Miercoles',...] para coincidir con la DB

b) Busca en horario_atencion todos los bloques donde ese médico atiende ese día:
     SELECT hora_inicio, hora_fin, id_consultorio, id_especialidad, ...
     FROM horario_atencion
     WHERE matricula = X AND dia_semana = 'Lunes'

c) Para cada bloque horario, genera los slots individuales según duracion_turno_min.
   Por ejemplo: si el médico atiende de 08:00 a 12:00 con turnos de 20 minutos,
   genera: 08:00, 08:20, 08:40, 09:00, 09:20, ... 11:40

d) Para cada slot, verifica si ya está ocupado:
     SELECT COUNT(*) FROM turno
     WHERE matricula = X AND fecha = Y AND hora_inicio = Z AND estado <> 'Cancelado'
   Si COUNT = 0, el slot está libre y se agrega al array.

e) Devuelve un array con todos los slots libres, cada uno con:
     hora (para mostrar: "08:20")
     hora_full (para guardar: "08:20:00")
     id_consultorio, id_especialidad (para el INSERT)
     especialidad, consultorio (para mostrar en pantalla)

5. Si no hay slots disponibles, vuelve al paso 1 con mensaje de error.
6. Si hay slots, el controlador pasa $slots a la vista.

La vista (paso 2) muestra cada slot como un botón de radio:
   <label class="slot-opcion">
     <input type="radio" name="slot" value="08:20:00|1|1">
     08:20 — Clínica Médica — Cons. 1 Piso 1
   </label>

El value del radio codifica los tres datos que se necesitan para reservar:
   "hora_full | id_consultorio | id_especialidad"
separados por el carácter | (pipe).

También viajan los datos del paso 1 como campos ocultos:
   <input type="hidden" name="matricula" value="10001">
   <input type="hidden" name="fecha" value="2026-06-02">
   <input type="hidden" name="id_plan" value="1">
   <input type="hidden" name="id_paciente" value="5">

CONFIRMACIÓN — El servidor guarda el turno
------------------------------------------
URL: ControladorTurno.php?accion=reservar (método POST)

1. El controlador recibe todos los datos.
2. Separa el slot con explode('|', $_POST['slot']):
     $partes[0] → hora_inicio (ej: "08:20:00")
     $partes[1] → id_consultorio (ej: 1)
     $partes[2] → id_especialidad (ej: 1)
3. Arma el array $datos con todos los campos del turno.
4. Si el usuario es 'paciente', usa $_SESSION['id_paciente'] en lugar del POST.
   (Seguridad: un paciente no puede reservar un turno para otra persona.)
5. Valida que todos los campos obligatorios estén presentes.
6. Llama a $modelo->reservar($datos) que ejecuta un INSERT en la tabla turno.
7. El TRIGGER trg_turno_after_insert se dispara automáticamente
   y guarda el primer registro en historial_turno.
8. Redirige a la lista de turnos con ?msg=reservado.
9. La vista index.php lee ese parámetro y muestra "Turno reservado correctamente."

================================================================
CAMINO A: DASHBOARD DEL PACIENTE (con JavaScript/AJAX)
================================================================

Este camino existe en dashboard.php y usa JavaScript para una mejor experiencia.
La lógica del servidor es la misma (mismos endpoints), pero la interacción es diferente:

1. El paciente hace click en un médico → JS llama a ?accion=horarios
   El servidor responde con un array JSON de días de la semana disponibles.
   El JS pinta los días disponibles en el calendario con fondo azul.

2. El paciente hace click en un día disponible → JS llama a ?accion=slots
   El servidor responde con un array JSON de slots libres.
   El JS muestra los horarios como botones.

3. El paciente hace click en un horario → JS abre un modal con los detalles
   y un formulario para elegir plan y confirmar.

4. Al confirmar, el formulario hace POST a ?accion=reservar
   (misma acción que el camino B, reutiliza el mismo controlador).

DIFERENCIA CLAVE ENTRE CAMINO A y B:
- En el camino A (dashboard), el servidor devuelve JSON y JS actualiza la página sin recargarla.
- En el camino B (formulario), cada envío recarga la página completa con HTML nuevo.
- El resultado final es el mismo: se inserta un registro en la tabla turno.

================================================================
CONTROL DE CONCURRENCIA — QUE DOS PACIENTES NO RESERVEN LO MISMO
================================================================

EL PROBLEMA (race condition / TOCTOU)
-------------------------------------
La reserva hace "chequear y después insertar":
  1) obtenerSlots() cuenta si el horario está ocupado (SELECT COUNT).
  2) Si está libre, reservar() hace el INSERT.

Entre el paso 1 y el 2 hay una ventana de tiempo. Si dos pacientes
reservan el MISMO slot (mismo médico, fecha y hora) casi al mismo
instante, los dos leen "libre" antes de que cualquiera inserte, y los
dos insertan -> turno duplicado.

Esto se llama "race condition" (condición de carrera) o TOCTOU
(time-of-check to time-of-use). Validar sólo en PHP NO alcanza: por
más que el código chequee, dos pedidos simultáneos pueden pasar el
chequeo antes de insertar.

LA SOLUCIÓN: QUE LO GARANTICE LA BASE DE DATOS (ACID)
-----------------------------------------------------
La única forma 100% confiable es que la regla "un solo turno activo
por médico/fecha/hora" la imponga el motor de la base (InnoDB), no el
PHP. Nos apoyamos en las cuatro propiedades ACID:

  A - Atomicidad:  el INSERT (y su trigger de historial) corre dentro
                   de una transacción. O se hace todo, o nada.
  C - Consistencia: una restricción UNIQUE impide guardar dos turnos
                   activos en el mismo horario. Es una regla que la
                   base verifica SIEMPRE, sin importar el código.
  I - Aislamiento: InnoDB serializa las inserciones que compiten por
                   la misma clave única; la segunda espera y luego es
                   rechazada, en vez de colarse.
  D - Durabilidad: al confirmar (COMMIT) el turno queda persistido,
                   aunque se corte la luz un instante después.

CÓMO SE IMPLEMENTÓ (archivo control_concurrencia.sql)
-----------------------------------------------------
1) La tabla turno usa motor InnoDB (el único que es ACID; MyISAM no).

2) Se agrega una COLUMNA GENERADA llamada slot_unico:
     - Turno NO cancelado -> 'matricula|fecha|hora'  (texto único)
     - Turno Cancelado     -> NULL
   Detalle clave: en un índice UNIQUE varios NULL NO chocan entre sí.
   Por eso, al cancelar un turno su cupo queda libre y el horario se
   puede volver a reservar, sin borrar el registro histórico.

     ALTER TABLE turno
       ADD COLUMN slot_unico VARCHAR(60)
       GENERATED ALWAYS AS (
         IF(estado = 'Cancelado', NULL,
            CONCAT(matricula, '|', fecha, '|', hora_inicio))
       ) STORED;

3) LA REGLA DE ORO: índice único sobre esa columna.

     ALTER TABLE turno
       ADD CONSTRAINT uq_turno_slot UNIQUE (slot_unico);

   A partir de acá, si dos reservas compiten por el mismo cupo, una
   entra y la otra recibe el error MySQL 1062 (Duplicate entry).

QUÉ CAMBIÓ EN EL PHP (modelo Turno.php -> reservar())
-----------------------------------------------------
El INSERT ahora corre dentro de una transacción y captura el error de
clave duplicada para mostrar un mensaje claro al paciente:

  try {
      $this->pdo->beginTransaction();
      // ... INSERT del turno ...
      $this->pdo->commit();
      return $idTurno;
  } catch (PDOException $e) {
      if ($this->pdo->inTransaction()) $this->pdo->rollBack();
      // 23000 = violación de integridad (índice único, error 1062)
      if ($e->getCode() === '23000') {
          throw new RuntimeException(
              'Ese turno acaba de ser reservado por otra persona. '
            . 'Por favor, elegí otro horario.'
          );
      }
      throw $e;
  }

El controlador (acción 'reservar') captura esa excepción y redirige al
formulario mostrando el mensaje, igual que cualquier otro error.

IMPORTANTE: obtenerSlots() sigue siendo "chequear y mostrar", así que
dos personas PUEDEN ver el mismo horario disponible. Eso está bien: la
red de seguridad es el índice único, que al confirmar rechaza al
segundo y le muestra el aviso. La base es la que garantiza que nunca
queden dos turnos pisados.

CÓMO DEMOSTRARLO EN LA DEFENSA
------------------------------
- Intentar insertar dos turnos activos en el mismo médico/fecha/hora:
  el segundo devuelve "ERROR 1062 (23000) Duplicate entry ... for key
  'uq_turno_slot'".
- Cancelar un turno y volver a reservar ese horario: funciona, porque
  el cancelado pasa a NULL y libera el cupo.
```

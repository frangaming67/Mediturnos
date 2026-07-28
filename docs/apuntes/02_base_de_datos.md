# Base de datos

> Apunte original del proyecto, conservado tal cual se escribió durante
> el desarrollo. La documentación técnica vigente está en la carpeta
> `docs/` (un nivel arriba); estos textos se mantienen como referencia
> histórica y material de estudio para la defensa.

```text
================================================================
MEDITURNOS — BASE DE DATOS
================================================================

TABLAS Y SU PROPÓSITO
---------------------

  rol             → Los tipos de usuario: admin, recepcionista, medico, paciente
  permiso         → Acciones posibles: ver_turnos, crear_turno, cancelar_turno, etc.
  rol_permiso     → Tabla intermedia que une rol con permiso (relación muchos a muchos)
  usuario         → Personas que pueden iniciar sesión
  paciente        → Datos clínicos del paciente
  medico          → Datos del médico (matrícula, nombre, etc.)
  especialidad    → Clínica Médica, Cardiología, Pediatría, etc.
  medico_especialidad → Tabla intermedia: un médico puede tener varias especialidades
  consultorio     → Consultorio 1 Piso 1, Consultorio 2 Piso 2, etc.
  obra_social     → OSDE, Swiss Medical, IOMA, etc.
  plan_os         → Plan 210, Plan 310 (pertenece a una obra_social)
  horario_atencion → Qué días y en qué horarios atiende cada médico
  turno           → El turno reservado (une paciente, médico, fecha, hora, etc.)
  historial_turno → Registro de cada cambio de estado de un turno

RELACIONES IMPORTANTES
----------------------

usuario ──────────────── rol (cada usuario tiene UN rol)
         └── id_paciente (si el rol es 'paciente', apunta al registro en tabla paciente)
         └── matricula   (si el rol es 'medico', apunta al registro en tabla medico)

medico ──────────────── medico_especialidad ──── especialidad
                        (un médico puede tener varias especialidades)

turno ──── paciente       (qué paciente sacó el turno)
      ──── medico         (con qué médico)
      ──── especialidad   (para qué especialidad)
      ──── consultorio    (en qué consultorio)
      ──── plan_os        (con qué cobertura)

horario_atencion ──── medico       (de qué médico es el horario)
                 ──── especialidad (qué especialidad atiende en ese horario)
                 ──── consultorio  (en qué consultorio atiende)

¿POR QUÉ SE USA PDO?
--------------------
PDO (PHP Data Objects) es la forma recomendada de conectarse a MySQL en PHP.
Se configura en config/conexion.php y se usa en todo el proyecto.

Ventajas de PDO frente a mysql_query() antiguo:
  1. Prepared statements: evitan inyección SQL
  2. Compatible con varios motores de base de datos
  3. Manejo de errores con excepciones (try/catch)

¿QUÉ SON LOS PREPARED STATEMENTS?
----------------------------------
En lugar de armar la consulta con datos del usuario directo:
  $sql = "SELECT * FROM usuario WHERE usuario = '$input'";  // PELIGROSO

Se usa un placeholder :nombre y PDO se encarga de escapar el valor:
  $stmt = $pdo->prepare("SELECT * FROM usuario WHERE usuario = :usuario");
  $stmt->execute([':usuario' => $input]);  // SEGURO

Así, aunque el usuario escriba  ' OR '1'='1  en el campo, no puede romper la consulta.

TRIGGERS (triggers_y_sp.sql)
-----------------------------
Son acciones automáticas que ejecuta MySQL cuando pasa algo en una tabla.

  trg_turno_after_insert → Cada vez que se crea un turno, automáticamente
                            inserta un registro en historial_turno con estado "Turno creado"

  trg_turno_after_update → Cada vez que cambia el estado de un turno,
                            guarda el estado anterior y el nuevo en historial_turno

STORED PROCEDURES (triggers_y_sp.sql)
--------------------------------------
Son procedimientos guardados en la base de datos que se llaman desde PHP.

  CancelarTurno(id, observacion) → Verifica que el turno no esté ya cancelado
                                    o realizado antes de cancelarlo.
                                    Si el estado no permite cancelar, lanza un error.

  ReservarTurno(...) → Verificaba conflicto de horario antes de insertar.
                       (En la versión actual del código se usa INSERT directo
                        con la misma lógica de validación en el modelo)

ÍNDICES
-------
Los índices aceleran las búsquedas. Se definieron en:

  idx_turno_fecha     → Para buscar turnos por fecha (filtro más común)
  idx_turno_estado    → Para filtrar por Reservado/Cancelado/etc.
  idx_turno_medico    → Para ver la agenda de un médico
  idx_turno_paciente  → Para ver los turnos de un paciente
  idx_paciente_dni    → Para buscar paciente por DNI
  idx_usuario_usuario → Para el login
```

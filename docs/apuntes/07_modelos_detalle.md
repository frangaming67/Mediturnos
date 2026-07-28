# Modelos detalle

> Apunte original del proyecto, conservado tal cual se escribió durante
> el desarrollo. La documentación técnica vigente está en la carpeta
> `docs/` (un nivel arriba); estos textos se mantienen como referencia
> histórica y material de estudio para la defensa.

```text
================================================================
MEDITURNOS — DETALLE DE CADA MODELO
================================================================

Los modelos son clases PHP. Cada una recibe $pdo en el constructor
y lo guarda como propiedad privada para usarlo en todos sus métodos.
Así, la conexión a la base se crea una sola vez y se comparte.

================================================================
TURNO.PHP
================================================================

listar(array $filtros)
  → Lista turnos con JOINs a paciente, medico, especialidad, consultorio,
    plan_os y obra_social para traer todo en una sola consulta.
  → Acepta filtros opcionales: fecha, estado, matricula, id_paciente,
    id_especialidad, dni_paciente.
  → Construye el WHERE dinámicamente: solo agrega condiciones para los
    filtros que no están vacíos.
  → Devuelve un array con todas las columnas necesarias para la tabla
    de la vista (paciente, medico, especialidad, consultorio, obra_social, plan).

buscarPorId(int $id)
  → Trae un turno completo por ID con datos de paciente y médico.
  → Se usa para el modal de historial.

reservar(array $datos)
  → Hace un INSERT en la tabla turno con estado 'Reservado'.
  → Devuelve el ID del turno recién creado con lastInsertId().
  → El trigger trg_turno_after_insert se dispara automáticamente
    y guarda el evento en historial_turno.

cancelar(int $idTurno, string $observacion)
  → Llama al stored procedure CancelarTurno que verifica el estado
    antes de cancelar. Si el turno ya está cancelado o realizado, lanza excepción.

actualizarEstado(int $idTurno, string $estado, string $observacion)
  → UPDATE directo para cambiar a Confirmado, Realizado o Ausente.
  → El trigger trg_turno_after_update guarda el cambio en historial_turno.

obtenerHistorial(int $idTurno)
  → SELECT en historial_turno ordenado por fecha_cambio ASC.
  → Devuelve todos los cambios de estado de ese turno.

obtenerSlots(int $matricula, string $fecha)
  → Traduce la fecha a día de la semana en español.
  → Busca los bloques horarios del médico en ese día.
  → Genera los turnos individuales según duracion_turno_min.
  → Verifica cuáles ya están ocupados.
  → Devuelve solo los slots libres.

listarPacientes / listarMedicos / listarEspecialidades / listarPlanes / listarConsultorios
  → Métodos auxiliares para llenar los selects de los formularios.

kpis()
  → Subconsultas para el dashboard: cuántos turnos hay hoy por estado,
    total de pacientes, total de médicos.

================================================================
MEDICO.PHP
================================================================

listar(array $filtros)
  → Lista médicos con GROUP_CONCAT para mostrar todas sus especialidades
    en una sola columna: "Cardiología, Clínica Médica".
  → Filtros: nombre, apellido, id_especialidad.

buscarPorMatricula(int $matricula)
  → Trae los datos del médico y además un array con sus id_especialidad.
  → Se usa para pre-seleccionar los checkboxes al editar un médico.

crear(array $datos)
  → Usa una TRANSACCIÓN porque involucra dos tablas:
    1. INSERT en medico
    2. INSERT en medico_especialidad (una fila por especialidad)
  → Si algo falla, el rollBack() deshace ambas operaciones.
  → Sin transacción podría quedar un médico sin especialidades.

actualizar(int $matricula, array $datos)
  → También usa transacción.
  → Borra todas las especialidades del médico y las vuelve a insertar.
  → Es más simple que comparar cuáles se agregaron o eliminaron.

obtenerEspecialidades()
  → Lista todas las especialidades para los checkboxes del formulario.

================================================================
PACIENTE.PHP
================================================================

listar(array $filtros)
  → SELECT simple con filtros opcionales por nombre, apellido, dni.

buscarPorId(int $id)
  → Trae un paciente por ID. Se usa para el formulario de edición.

crear(array $datos)
  → INSERT en la tabla paciente. Devuelve el id_paciente generado.

actualizar(int $id, array $datos)
  → UPDATE de todos los campos del paciente.

existeDni(string $dni, int $excludeId)
  → Verifica si ya existe otro paciente con ese DNI.
  → $excludeId permite excluir al paciente actual en las ediciones
    (así no da error cuando guardás sin cambiar el DNI).

================================================================
USUARIO.PHP
================================================================

buscarParaLogin(string $usuario)
  → Busca el usuario por nombre y estado 'activo'.
  → Hace JOIN con rol para traer el nombre del rol.
  → Luego carga los permisos del rol desde rol_permiso JOIN permiso.
  → Devuelve todo junto para que el controlador lo ponga en sesión.

registrarLogin / registrarLogout
  → Actualizan ultimo_login y el campo online en la tabla usuario.

listar()
  → Lista usuarios con su rol para la pantalla de administración.

crear(array $datos)
  → Hashea la contraseña con password_hash antes de guardarla.
  → Los campos id_paciente y matricula pueden ser NULL
    (solo se llenan según el rol del usuario).

actualizar(int $id, array $datos)
  → Actualiza datos pero NO la contraseña (eso sería otro método).

darDeBaja(int $id)
  → No borra el usuario. Cambia estado a 'inactivo' y guarda la fecha_baja.
  → Baja lógica: el registro queda en la base para auditoría.

listarRoles()
  → Lista los roles para el select del formulario de usuarios.

existeUsuario(string $usuario, int $excludeId)
  → Similar a existeDni: evita duplicados de nombre de usuario.
```

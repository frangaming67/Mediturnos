# Preguntas frecuentes defensa

> Apunte original del proyecto, conservado tal cual se escribió durante
> el desarrollo. La documentación técnica vigente está en la carpeta
> `docs/` (un nivel arriba); estos textos se mantienen como referencia
> histórica y material de estudio para la defensa.

```text
================================================================
MEDITURNOS — PREGUNTAS QUE TE PUEDEN HACER EN LA DEFENSA
================================================================

P: ¿Qué patrón de diseño usaste y por qué?
R: MVC (Modelo-Vista-Controlador). Lo elegí porque separa claramente
   las responsabilidades: el modelo maneja la base de datos, la vista
   genera el HTML, y el controlador une ambas capas.
   Si necesito cambiar cómo se muestran los datos, solo toco la vista.
   Si cambia la base de datos, solo toco el modelo.

P: ¿Por qué usás PDO en lugar de mysqli?
R: PDO es más seguro porque soporta prepared statements que previenen
   inyección SQL. Además es compatible con distintos motores de base
   de datos si en el futuro se quisiera cambiar de MySQL a otro motor.

P: ¿Cómo prevenís la inyección SQL?
R: Con prepared statements. En lugar de armar la consulta concatenando
   el input del usuario, uso placeholders (:nombre) y PDO se encarga
   de escapar los valores automáticamente.

P: ¿Cómo guardás las contraseñas?
R: Con bcrypt usando password_hash(). Nunca se guarda en texto plano.
   Al hacer login se usa password_verify() para comparar el input
   contra el hash guardado.

P: ¿Cómo controlás que un usuario no acceda a páginas que no le corresponden?
R: Cada controlador llama a verificarSesion() para confirmar que hay
   sesión activa, y luego verificarRol(['roles permitidos']) para
   confirmar que el rol del usuario está en la lista.
   Si no cumple, devuelve un 403.

P: ¿Qué pasa si el usuario cierra el navegador sin cerrar sesión?
R: La sesión en el servidor tiene un timeout de 30 minutos de inactividad.
   Si no hay actividad en ese tiempo, verificarSesion() destruye la sesión
   y redirige al login. Igual, el navegador sin cookie tampoco puede acceder.

P: ¿Por qué el formulario de nuevo turno tiene dos pasos?
R: Porque para mostrar los horarios disponibles necesito saber qué médico
   eligió el usuario y para qué fecha. Ese cálculo lo hace el servidor
   consultando la tabla horario_atencion. Con un solo formulario necesitaría
   JavaScript para hacer esa consulta de forma dinámica. Los dos pasos
   permiten hacerlo solo con PHP.

P: ¿Qué son los triggers y para qué los usás?
R: Son acciones automáticas que MySQL ejecuta cuando ocurre un evento
   en una tabla. Usé dos:
   - trg_turno_after_insert: cuando se crea un turno, guarda
     automáticamente el evento en historial_turno.
   - trg_turno_after_update: cuando cambia el estado de un turno,
     registra el estado anterior y el nuevo en historial_turno.
   Así el historial se mantiene automáticamente sin que el PHP tenga
   que hacer un INSERT manual cada vez.

P: ¿Por qué usás transacciones en el modelo de Médico?
R: Porque crear un médico involucra escribir en dos tablas: medico
   y medico_especialidad. Si el INSERT en medico funciona pero falla
   el de medico_especialidad, quedaría un médico sin especialidades.
   La transacción garantiza que ambas operaciones se completen,
   o ninguna.

P: ¿Cómo funciona el historial de un turno?
R: Cada cambio de estado queda registrado en la tabla historial_turno.
   Esto se hace automáticamente con triggers de MySQL, no desde PHP.
   Desde la vista de turnos hay un botón que llama a ?accion=historial,
   el servidor responde con JSON, y un pequeño script de JavaScript
   muestra esos datos en un modal sin recargar la página.

P: ¿Qué diferencia hay entre require y require_once?
R: require incluye el archivo cada vez que se llama.
   require_once lo incluye solo la primera vez; si ya fue incluido,
   lo ignora. Se usa require_once para archivos que definen clases
   o funciones para evitar el error "Cannot redeclare".

P: ¿Por qué el valor del radio button del slot es "hora|consultorio|especialidad"?
R: Porque un slot de horario siempre tiene esos tres datos juntos:
   el médico atiende una especialidad específica en un consultorio específico
   en ese horario. Separarlos en tres selects independientes rompería esa
   relación y permitiría combinaciones inválidas (ej: la especialidad de
   un médico en el consultorio de otro). Codificando los tres en un solo
   valor con | se garantiza que siempre van juntos.

P: ¿Por qué el campo $paso viaja como campo oculto en el formulario?
R: Porque HTTP no tiene memoria entre peticiones. Si el usuario llena
   el paso 1 y hace click en el botón, el servidor necesita saber que
   esa petición es el paso 2, no una nueva carga del formulario.
   El campo oculto <input name="paso" value="2"> es la forma de indicárselo.

P: ¿Qué es BASE_URL y para qué sirve?
R: Es una constante definida en config/conexion.php con el valor '/mediturnos/'.
   Se usa en todos los links y acciones de formularios para que las rutas
   sean absolutas. Si se cambia el nombre de la carpeta del proyecto,
   solo hay que actualizar este valor en un lugar.

P: ¿Cómo sabe el servidor qué acción ejecutar?
R: El parámetro ?accion=... en la URL. El controlador lo lee con:
     $accion = $_GET['accion'] ?? 'index';
   Y luego usa un switch/case para ejecutar la lógica correspondiente.
   Es una forma simple de tener múltiples comportamientos en un solo archivo.
```

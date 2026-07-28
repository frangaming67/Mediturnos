# Mejoras abm y seguridad

> Apunte original del proyecto, conservado tal cual se escribió durante
> el desarrollo. La documentación técnica vigente está en la carpeta
> `docs/` (un nivel arriba); estos textos se mantienen como referencia
> histórica y material de estudio para la defensa.

```text
================================================================
MEDITURNOS — MEJORAS DE ABM Y SEGURIDAD (REGISTRO COMPLETO)
================================================================

Este documento detalla TODO lo que se hizo en la ronda de mejoras
"arreglar todo, prioridad ABM y seguridad". Para cada cambio explica:
  - QUÉ se hizo
  - POR QUÉ (el problema que resuelve)
  - CÓMO funciona la lógica
  - ARCHIVOS tocados

Orden: 1) ABM (lo prioritario)  2) Seguridad  3) Pruebas hechas.

Nota de método: cada cambio se probó "en vivo" contra
http://localhost/mediturnos con curl + consultas a la base. Todos los
datos de prueba se borraron después (la base quedó igual que antes,
"net-zero"). Ningún archivo del proyecto quedó a medias.


################################################################
PARTE 1 — ABM (ALTA / BAJA / MODIFICACIÓN)
################################################################

Todos los ABM nuevos siguen el MISMO patrón que el ABM de pacientes,
que es el template del proyecto:
  - Un MODELO (clase con PDO) que habla con la base.
  - Un CONTROLADOR con un switch de acciones:
      index    -> listar
      nuevo    -> mostrar formulario de alta
      guardar  -> procesar el alta (POST)
      editar   -> mostrar formulario de edición
      actualizar -> procesar la edición (POST)
  - VISTAS (index.php, nuevo.php, editar.php) que arman el HTML.
  - Validación en el controlador, mensajes de error/éxito, y
    redirección con ?msg=... cuando sale bien (patrón PRG:
    Post-Redirect-Get, evita reenviar el formulario al recargar).


================================================================
1.1 — AUTO-REGISTRO DE PACIENTES: ARREGLADO
================================================================

QUÉ SE HIZO
-----------
Se hizo el email OBLIGATORIO en el registro público y en el alta de
usuarios por el admin, con validación de formato y de duplicado.

POR QUÉ
-------
La columna usuario.email en la base real es NOT NULL UNIQUE, pero el
formulario lo trataba como opcional. Si el paciente no ponía email,
el INSERT fallaba con "Column 'email' cannot be null", el error se
ocultaba y se mostraba "No se pudo crear la cuenta" sin más. Resultado:
registrarse sin email era IMPOSIBLE y nadie sabía por qué (falla
silenciosa).

CÓMO FUNCIONA LA LÓGICA
-----------------------
- En ControladorAuth::registrar() se agregó:
    * email entra en la lista de campos obligatorios.
    * filter_var($email, FILTER_VALIDATE_EMAIL) valida el formato.
    * $modelo->existeEmail($email) avisa "ya existe una cuenta con ese
      email" ANTES de intentar el INSERT (mensaje claro en vez del
      error 1062/1048 crudo).
- En el catch del registro se agregó error_log(...) para dejar
  registrado el error real en el log del servidor (debug sin exponerlo).
- Se agregó el método Usuario::existeEmail() (consulta COUNT con
  prepared statement, igual que existeUsuario/existeDni).
- Mismo refuerzo en el alta de usuarios del admin
  (ControladorUsuario::guardar): email obligatorio + formato + duplicado.
- En los formularios (registro.php y usuarios/nuevo.php) el campo email
  pasó a tener el asterisco * y el atributo required.

ARCHIVOS
--------
- sistema/controladores/ControladorAuth.php
- sistema/controladores/ControladorUsuario.php
- sistema/modelos/Usuario.php   (nuevo método existeEmail)
- registro.php
- sistema/vistas/usuarios/nuevo.php


================================================================
1.2 — ABM OBRAS SOCIALES Y PLANES (NUEVO)
================================================================

QUÉ SE HIZO
-----------
Módulo completo para gestionar obras sociales y sus planes. Antes
existían las tablas (obra_social, plan_os) pero solo se LEÍAN en los
desplegables de turnos; no había forma de crear/editar desde la app.

CÓMO FUNCIONA LA LÓGICA
-----------------------
- Modelo ObraSocial con dos grupos de métodos:
    Obras:  listar (con cantidad de planes por obra), buscarPorId,
            crear, actualizar, existeCuit (el CUIT es UNIQUE),
            listarSimple (para el select del formulario de plan).
    Planes: listarPlanes (con JOIN a obra para mostrar su nombre),
            buscarPlanPorId, crearPlan, actualizarPlan.
- El índice (index.php) muestra DOS tablas: obras sociales arriba
  (con botón "+ Nueva obra social") y planes abajo (con "+ Nuevo plan").
  Así se ve la relación obra -> planes de un vistazo.
- Validaciones en el controlador: nombre y CUIT obligatorios; CUIT no
  duplicado (existeCuit, excluyendo el propio id al editar). Para el
  plan: obra y nombre obligatorios; la cobertura es opcional (se guarda
  NULL si viene vacía).
- Errores de base ocultos (mensaje genérico + error_log).

ARCHIVOS
--------
- sistema/modelos/ObraSocial.php                 (nuevo)
- sistema/controladores/ControladorObraSocial.php (nuevo)
- sistema/vistas/obras/index.php                 (nuevo)
- sistema/vistas/obras/nuevo.php                 (nuevo)
- sistema/vistas/obras/editar.php                (nuevo)
- sistema/vistas/obras/plan_nuevo.php            (nuevo)
- sistema/vistas/obras/plan_editar.php           (nuevo)


================================================================
1.3 — ABM CONSULTORIOS (NUEVO)
================================================================

QUÉ SE HIZO
-----------
Módulo para gestionar consultorios (número, piso, equipamiento). Igual
que arriba: existía la tabla y se leía en turnos, pero no se podía
administrar.

CÓMO FUNCIONA LA LÓGICA
-----------------------
- Modelo Consultorio: listar, buscarPorId, crear, actualizar y
  existeNumeroPiso (evita dos consultorios con el mismo número en el
  mismo piso).
- Validación: número y piso obligatorios y numéricos (ctype_digit);
  el equipamiento es opcional (NULL si vacío).
- Campo "equipamiento" ocupa el ancho completo del formulario con la
  clase nueva .form-group--full (grid-column: 1 / -1) agregada al CSS.

ARCHIVOS
--------
- sistema/modelos/Consultorio.php                 (nuevo)
- sistema/controladores/ControladorConsultorio.php (nuevo)
- sistema/vistas/consultorios/index.php           (nuevo)
- sistema/vistas/consultorios/nuevo.php           (nuevo)
- sistema/vistas/consultorios/editar.php          (nuevo)
- publico/css/utilidades.css                      (clase .form-group--full)


================================================================
1.4 — ABM HORARIOS DE ATENCIÓN DE MÉDICOS (NUEVO)
================================================================

QUÉ SE HIZO
-----------
Módulo para cargar/editar los horarios en que cada médico atiende. Esto
es lo que ALIMENTA la generación de turnos disponibles. Antes los
horarios solo se podían cargar por SQL directo; ahora hay pantalla.

POR QUÉ IMPORTA
---------------
El cálculo de slots disponibles (Turno::obtenerSlots) lee la tabla
horario_atencion para saber qué días/horas atiende el médico y partirlo
en turnos. Sin una forma de cargar horarios, no se podían generar turnos
para un médico nuevo.

CÓMO FUNCIONA LA LÓGICA
-----------------------
- Un horario relaciona: médico (matrícula) + especialidad + consultorio
  + día de la semana + hora inicio + hora fin.
- IMPORTANTE: el día se guarda SIN tilde (Lunes, Martes, Miercoles,
  Jueves, Viernes, Sabado, Domingo) porque así lo espera el resto del
  sistema. La constante Horario::DIAS centraliza esa lista y se usa
  tanto en el <select> como en la validación.
- Validaciones en el controlador (función validarHorario):
    * todos los campos obligatorios,
    * el día debe estar en la lista válida,
    * hora_inicio < hora_fin (comparación de strings "HH:MM", que para
      horas con ceros a la izquierda funciona correctamente).
- Detección de SOLAPAMIENTOS (Horario::haySolapamiento): antes de
  guardar, verifica que el médico no tenga ya un bloque que se cruce
  ese mismo día. La condición SQL clásica de cruce de rangos es:
      hora_inicio_existente < hora_fin_nuevo
      AND hora_fin_existente > hora_inicio_nuevo
  Al editar se excluye el propio id (id_horario <> :id).
- El índice ordena por médico y por día en orden real de la semana con
  FIELD(dia_semana,'Lunes',...,'Domingo'), no alfabético.
- Tiene filtro por médico en el listado.

ARCHIVOS
--------
- sistema/modelos/Horario.php                  (nuevo)
- sistema/controladores/ControladorHorario.php (nuevo)
- sistema/vistas/horarios/index.php            (nuevo)
- sistema/vistas/horarios/nuevo.php            (nuevo)
- sistema/vistas/horarios/editar.php           (nuevo)


================================================================
1.5 — ENLACES EN EL MENÚ (NAVBAR)
================================================================

QUÉ SE HIZO
-----------
Se agregaron al menú lateral (sección "Gestión", visible solo para
admin y recepcionista) los enlaces a: Horarios, Obras sociales y
Consultorios, con sus íconos y el resaltado de "activo" cuando estás
en esa pantalla.

ARCHIVO
-------
- sistema/vistas/layouts/navbar.php


================================================================
1.6 — POR QUÉ NO HAY "BAJA" EN ESTOS ABM
================================================================
El template (pacientes y médicos) NO tiene baja/eliminación; solo alta
y edición. Para mantener la consistencia que se pidió, los módulos
nuevos siguen ese mismo patrón (sin botón de borrar). Eliminar obras
sociales, planes, consultorios u horarios que ya estén usados en turnos
rompería claves foráneas, así que dejarlo fuera también es lo más seguro.


################################################################
PARTE 2 — SEGURIDAD
################################################################

================================================================
2.1 — NO MOSTRAR ERRORES INTERNOS DE LA BASE AL USUARIO
================================================================

QUÉ SE HIZO
-----------
Donde antes se mostraba 'Error: ' . $e->getMessage() (que filtraba
cosas como "SQLSTATE[23000] ... Duplicate entry '10001' for key
'PRIMARY'"), ahora se muestra un mensaje genérico y el error real se
guarda con error_log().

POR QUÉ
-------
Mostrar el error crudo expone el esquema de la base (nombres de tablas,
claves, motor) y ayuda a un atacante. Además es feo para el usuario.

CÓMO FUNCIONA LA LÓGICA
-----------------------
- En cada catch (PDOException $e):
    error_log('Controlador... : ' . $e->getMessage());   // queda en el log
    $mensaje = 'No se pudo guardar ... Intentá de nuevo.'; // ve el usuario
- En el alta de médico se distingue el caso de matrícula duplicada
  (código SQLSTATE 23000) para dar el mensaje preciso "Ya existe un
  médico con esa matrícula" sin revelar el detalle técnico.

ARCHIVOS
--------
- sistema/controladores/ControladorMedico.php
- sistema/controladores/ControladorPaciente.php
- sistema/controladores/ControladorUsuario.php
- sistema/controladores/ControladorTurno.php (catch de cancelar)


================================================================
2.2 — PROTECCIÓN CSRF (TOKEN ANTI-FALSIFICACIÓN)
================================================================

QUÉ ES UN ATAQUE CSRF
---------------------
"Cross-Site Request Forgery": un sitio malicioso hace que TU navegador
(que ya tiene la sesión abierta en MediTurnos) envíe un formulario sin
que vos quieras (por ejemplo, cancelar un turno o crear un usuario). El
servidor, al ver tu cookie de sesión válida, lo ejecuta.

QUÉ SE HIZO
-----------
Se agregó un token secreto por sesión que viaja oculto en cada
formulario de cambio de estado y se valida en el servidor. Si no llega
o no coincide, la acción se rechaza.

CÓMO FUNCIONA LA LÓGICA (3 funciones en includes/auth.php)
----------------------------------------------------------
- csrf_token(): genera una vez por sesión un valor aleatorio
  (bin2hex(random_bytes(32))) y lo guarda en $_SESSION['csrf_token'].
- csrf_field(): imprime <input type="hidden" name="csrf_token" value="...">
  para pegar dentro de cada <form>.
- csrf_verificar(): si la petición es POST, compara el token enviado
  contra el de la sesión con hash_equals() (comparación segura, sin
  filtrar tiempos). Si no coincide -> http_response_code(403) + die()
  con un mensaje para recargar la página.

DÓNDE SE APLICÓ
---------------
- En los 6 controladores ABM se llama csrf_verificar() al inicio
  (después de verificar la sesión y el rol): así TODO POST a esos
  controladores exige token.
- En ControladorTurno se aplica solo a las acciones que cambian estado
  (reservar, cancelar, cambiarEstado), no a los pasos intermedios de
  "nuevo turno" (que no modifican datos).
- Se agregó <?php csrf_field(); ?> dentro de cada formulario afectado
  (pacientes, médicos, usuarios, obras, planes, consultorios, horarios,
  y los modales de cancelar/cambiar estado/reservar).

QUÉ QUEDÓ FUERA A PROPÓSITO ("seguridad, pero no tanto")
-------------------------------------------------------
- login.php y registro.php NO llevan CSRF: son páginas públicas / de
  bajo riesgo y meterles token complicaba el flujo sin beneficio real.

DETALLE TÉCNICO
---------------
Primero se probó con el código HTTP 419 (usado por algunos frameworks
para "token expirado"), pero Apache lo convertía en 500 (parece error
del servidor). Se cambió a 403 (Prohibido), que es estándar y se ve bien.

ARCHIVOS
--------
- includes/auth.php (las 3 funciones)
- los 6 controladores ABM + ControladorTurno (llamadas a csrf_verificar)
- ~16 formularios (campo csrf_field)


================================================================
2.3 — IDOR: UN PACIENTE SOLO TOCA SUS PROPIOS TURNOS
================================================================

QUÉ ES IDOR
-----------
"Insecure Direct Object Reference": cuando el sistema confía en un id
que manda el usuario sin verificar que sea suyo. Acá, un paciente podía
mandar cualquier id_turno y cancelar/ver el turno de OTRA persona.

QUÉ SE HIZO
-----------
En ControladorTurno se agregó, para el rol paciente, la verificación de
que el turno le pertenezca antes de cancelar o de ver su historial.

CÓMO FUNCIONA LA LÓGICA
-----------------------
- Cancelar: si el rol es 'paciente', se busca el turno
  ($modelo->buscarPorId) y se compara turno.id_paciente con el
  id_paciente de la sesión. Si no coincide (o no existe), redirige con
  "No tenés permiso para cancelar ese turno" y NO cancela.
- Historial (endpoint JSON): mismo chequeo; si no es suyo, responde
  HTTP 403 con {"error":"No autorizado"} en vez de devolver los datos.
- Admin/recepcionista no tienen esta restricción (gestionan todos).

ARCHIVO
-------
- sistema/controladores/ControladorTurno.php


################################################################
PARTE 3 — PRUEBAS EN VIVO (todas pasaron, datos borrados después)
################################################################

- AUTO-REGISTRO:
    sin email  -> mensaje claro, no crea nada.
    con email  -> 302 a login?msg=registro_ok, cuenta creada.

- ERRORES DE BASE:
    recepcionista crea médico con matrícula duplicada ->
    "Ya existe un médico con esa matrícula" y 0 'SQLSTATE' en la página.

- ABM NUEVOS (con token CSRF):
    consultorio, obra social, plan y horario -> creados (302),
    verificados en la base.

- CSRF:
    POST sin token -> 403 ("Token de seguridad inválido").
    POST con token -> funciona.

- HORARIOS:
    slot válido -> crea.
    slot solapado con uno existente -> rechaza ("se superpone ese día").

- ROLES (módulos nuevos):
    médico -> 403 ;  recepcionista -> 200.

- IDOR:
    paciente intenta cancelar turno ajeno (id 9) -> bloqueado,
    el turno quedó en 'Reservado' (intacto).

- REGRESIÓN:
    ABM de pacientes sigue funcionando con el CSRF nuevo;
    login.php, registro.php y dashboard.php cargan 200.


################################################################
PENDIENTES MENORES (no críticos, quedaron sin tocar)
################################################################
- medico.email es NOT NULL pero su formulario no lo exige (se podría
  hacer obligatorio igual que en usuarios, o permitir NULL en la columna).
- seed_datos.sql no coincide con la base real (id_hist vs id_historial,
  usuario.email NOT NULL UNIQUE, etc.). No afecta a la app corriendo,
  pero el repo no reconstruye exactamente la base actual.
```

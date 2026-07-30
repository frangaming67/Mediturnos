# Historial de cambios

Formato basado en [Keep a Changelog](https://keepachangelog.com/es/1.1.0/).
Versionado según [SemVer](https://semver.org/lang/es/).

---

## [Sin publicar]

### Agregado

**Pago y confirmación** — ver [area-paciente.md](area-paciente.md)
- Dos plazos de retención: 15 minutos si paga con tarjeta ahora, 48 horas si
  elige pagar más tarde o en recepción
- Cuenta regresiva en la pantalla de pago, con opción de pedir más tiempo
- "Pagar más tarde" ahora **hace algo**: antes era sólo un enlace al listado y
  el plazo original seguía corriendo
- Pago rechazado: el turno no se cancela, se puede reintentar, y avisa por
  correo con el plazo que queda
- Pago aprobado: turno confirmado, **comprobante imprimible con código de
  reserva** y correo con todos los datos de la consulta
- El cobro en recepción dispara el mismo aviso que el pago con tarjeta

**Agendar en cuatro pasos** — ver [area-paciente.md](area-paciente.md)
- Especialidad → Profesional → Día → Horario → Confirmación, cada paso con su
  propia URL: el botón "atrás" funciona y la pantalla anda sin JavaScript
- Los profesionales se muestran con foto, matrícula, consultorios y calificación
- Calendario de 4 semanas con la cantidad de horarios libres por día
- Resumen final con duración, precio, cobertura, monto con descuento aplicado,
  método de pago y aceptación de términos (validada también en el servidor)
- Si otra persona toma el horario mientras se elige, el resumen lo detecta y
  avisa antes de confirmar
- **Calificación de profesionales**: sólo se puede calificar un turno realizado
  propio, una sola vez, con el puntaje acotado por el motor

**Panel del paciente** — ver [area-paciente.md](area-paciente.md)
- Menú propio: Inicio · Agendar cita · Mis turnos · Mis pagos · Mi perfil
- El inicio pasa a ser el resumen de la cuenta (antes era el calendario de
  reserva, que se mudó a `agendar.php`)
- Próxima cita real con estado, aviso de pago pendiente y su vencimiento
- Ver detalle del turno, con su historial de cambios
- **Reprogramar**: mover el turno sin perder el pago ni el número
- Cancelar desde el panel, volviendo al panel
- Estado vacío que explica qué pasa y ofrece la acción que corresponde

**Notificaciones y correos** — cimientos del Área del Paciente
- Tabla `notificacion` y centro de avisos por usuario
- Servicio `Notificador` con canales intercambiables (in-app + correo), con la
  arquitectura lista para enchufar push sin tocar ningún controlador
- Catálogo de 18 tipos de aviso, cada uno con su icono y su decisión de si
  además sale por correo
- `emailPlantilla()`: un único diseño para todos los correos del sistema
- `notificarUnaVez()` para recordatorios que no se repiten
- El correo de recuperación pasa a usar la plantilla común

**Perfil del usuario** (`perfil.php`)
- Cambiar la foto (arrastrar y soltar, con vista previa) y quitarla
- Editar nombre, apellido y correo, sincronizados entre `usuario` y la ficha
- Datos personales: fecha de nacimiento, sexo, teléfono y dirección
- Obra social y número de afiliado, con el plan actual preseleccionado
- Cambio de contraseña pidiendo la actual, con medidor de fortaleza
- Se adapta al rol: el admin no ve cobertura, el médico no ve DNI ni obra social
- La barra lateral muestra la foto y enlaza al perfil

**Validación unificada** (`includes/validacion.php`)
- Las reglas de campo dejan de estar escritas tres veces (registro,
  restablecimiento y perfil) y pasan a un único lugar

### Corregido

- **Turnos gratis.** El formulario de reserva ofrecía las quince obras sociales
  del sistema y nadie verificaba que la elegida fuera del paciente. Como IOMA
  tiene 100% de descuento con una de las médicas, cualquiera podía sacar un
  turno sin pagar: con el monto en cero el pago se da por saldado solo y el
  turno se confirma. Ahora sólo se ofrecen —y se aceptan— las coberturas
  propias más el pago particular.
- **El sistema ofrecía turnos con un médico dado de baja hacía un mes.**
  `obtenerSlots()` leía los horarios sin mirar `medico.estado`, y los horarios
  no se borran al dar de baja a alguien. El asistente lo tapaba por casualidad,
  pero el endpoint AJAX seguía devolviéndolos y un POST a mano reservaba igual.
- **Al reservar no se verificaba que el horario siguiera ofreciéndose.** Ahora
  se contrasta contra `obtenerSlots()`, que cubre de una vez profesional
  activo, ausencias, horario existente, ocupación y horarios pasados.
- **Los correos a `@example.com` llenaban de rebotes la casilla del sistema.**
  Los datos de prueba usan direcciones de dominios reservados que por norma no
  pueden recibir correo. Ahora se descartan antes de intentar el envío.
- **El buzón de desarrollo perdía correos.** `MailerArchivo` nombraba los
  archivos con la fecha al segundo más el destinatario: dos correos a la misma
  persona dentro del mismo segundo se pisaban en silencio. Pasa de verdad —al
  reservar y que falle el pago salen dos avisos casi juntos— y el síntoma es el
  peor posible: un correo que el sistema da por enviado y no está en ningún lado.
- **`navbar.php` pisaba variables de la vista.** Se incluye con `require`, que
  comparte el ámbito: su `$iniciales` (un string) reemplazaba a la función del
  mismo nombre que `agendar.php` usaba para dibujar los avatares, y la página
  moría con `Call to undefined function DP()` — las iniciales de quien estuviera
  mirando. Ahora se llama `$inicialesSesion`.
- **`--gris2` no existía fuera de la portada.** Estaba definida sólo en
  `landing.css`; `auth.css` la usaba seis veces, así que el color de los iconos
  y placeholders del login y el registro caía al heredado sin que se notara.
- **`obtenerSlots()` hacía una consulta por horario.** Una jornada de 8 a 16 con
  turnos de 20 minutos eran 24 consultas para dibujar una grilla. Ahora es una.
- **Un turno que cambiaba de fecha no dejaba rastro**: el trigger de historial
  sólo miraba el estado.
- **`medico.telefono` era `int`.** Mismo defecto que ya se había corregido en
  `paciente`: un celular de 11 dígitos se aplastaba en 4294967295. Se detectó al
  habilitar la edición del teléfono desde el perfil.
- **`medico.email` era `varchar(30)`.** Truncaba direcciones institucionales.
- **`usuario.email` era `varchar(100)`** mientras el registro validaba 120 y
  `paciente.email` aceptaba 120. Una dirección de 101 a 120 caracteres se
  guardaba entera en una tabla y truncada en la otra: esa persona quedaba sin
  poder iniciar sesión con su correo ni recuperar la contraseña.
- **`restablecer.php` tenía su propia `PASS_MIN`** sin relación con la de
  `ControladorAuth`. Cambiar una y olvidar la otra no habría dado ningún error.
- Nombre y apellido no validaban largo: MySQL los recortaba a 30 en silencio.

### Pendiente
- Dashboard ejecutivo del administrador

---

## [1.0.0] — 2026-07-28

Primera versión con el sistema completo de autenticación y el sitio público.

### Agregado

**Sitio público**
- Landing con especialidades, equipo y estadísticas leídas de la base
- Buscador que filtra especialidades y médicos en vivo
- Preguntas frecuentes, testimonios y llamada a la acción

**Autenticación**
- Login rediseñado, con acceso por nombre de usuario **o** correo
- Mostrar/ocultar contraseña, aviso de Bloq Mayús, recordar usuario
- Bloqueo tras 5 intentos fallidos en 15 minutos
- Recuperación de contraseña con enlace de un solo uso que vence en 1 hora
- Registro en tres pasos, con foto de perfil y obra social

**Médicos**
- Pantalla propia para el rol médico: agenda del día, carga semanal y sus pacientes

**Infraestructura**
- Envío de correo con dos implementaciones intercambiables (SMTP y archivo)
- Módulo de subida segura de imágenes con re-codificación
- Sesiones endurecidas y cabeceras de seguridad
- Sistema de diseño unificado entre el sitio público y el interno

### Corregido

- **XSS almacenado** en el historial de turnos. Un motivo de cancelación con HTML
  se ejecutaba en el navegador de quien abriera el historial, típicamente un
  administrador. Escalada de paciente a admin.
- **Fuga de datos.** Una cuenta de paciente sin ficha vinculada veía el listado
  completo de turnos y pagos, con nombres y DNI de todos.
- **Desborde horizontal** en toda la aplicación, causado por el `min-width: auto`
  de un contenedor flex.
- **`paciente.telefono` era `int`.** Los celulares con característica no entraban
  en el rango y 1000 de 1010 quedaron aplastados al valor máximo.
- **`paciente.email` era `varchar(30)`.** Truncaba direcciones normales sin avisar.
- Un `403` en el endpoint de historial se mostraba como "sin registros".

### Rendimiento

- Paginación en pacientes y usuarios: de **1,35 MB a 45 KB** y de **1,95 MB a 60 KB**

### Base de datos

- Migración `auth_v2.sql`: agrega `sexo`, `direccion`, `usuario.foto`,
  `password_reset` e `intento_login`, y corrige los tipos de `telefono` y `email`

---

## [0.1.0] — 2026-06

Sistema base: arquitectura MVC, turnos, pacientes, médicos, pagos, obras sociales,
normalización a 4FN y control de concurrencia mediante índices únicos.

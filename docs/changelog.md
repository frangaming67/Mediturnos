# Historial de cambios

Formato basado en [Keep a Changelog](https://keepachangelog.com/es/1.1.0/).
Versionado según [SemVer](https://semver.org/lang/es/).

---

## [Sin publicar]

### Pendiente
- Pantalla de perfil del usuario
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

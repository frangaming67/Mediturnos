# Hoja de ruta

## Próximo

### Perfil del usuario
Pantalla para que cada uno gestione su cuenta: cambiar foto y contraseña, editar
datos personales, actualizar obra social y número de afiliado.
Reutiliza `SubidaImagen` y el patrón de validación del registro.

### Dashboard ejecutivo del administrador
Panel con KPIs del día, pacientes por especialidad, mapa de ocupación de
consultorios y recaudación por médico.

> **A tener en cuenta:** tres widgets del diseño de referencia (espera promedio,
> stock de medicamentos y medicamentos más recetados) **no tienen datos detrás**.
> O se agregan las tablas correspondientes, o se reemplazan por métricas que sí
> existan. No se van a mostrar números inventados.

---

## Más adelante

### Historial clínico
Requiere tablas nuevas: `consulta`, `receta`, `receta_medicamento` y `estudio`.
Es la expansión más grande pendiente y amplía bastante el alcance del sistema.

### Notificaciones
Recordatorios de turno por correo. La infraestructura de envío ya está lista.

### Panel de admisión para recepción
Confirmar la llegada del paciente, estado de la sala de espera y cobro rápido.
Necesita un estado nuevo en `estado_turno` y registrar la hora de llegada.

---

## Deuda técnica

Ordenada por impacto:

| Prioridad | Tema | Detalle |
|---|---|---|
| Alta | Unificar la autorización | Conviven `verificarRol()` y `verificarPermiso()` |
| Alta | Tareas por petición | `expirarVencidos()` corre en cada visita; debería ser un evento programado |
| Media | Accesibilidad pendiente | Etiquetas en los formularios viejos, modales sin `role="dialog"`, calendario no operable por teclado |
| Media | CSRF en el registro | Es alta pública: convendría token más CAPTCHA |
| Media | Buscador de pacientes | `turnos/nuevo` carga los 1010 pacientes en un `datalist` (155 KB) |
| Baja | CSP estricta | Requiere sacar el JavaScript y los estilos en línea |
| Baja | Pruebas automatizadas | Hoy la verificación es manual |

---

## Descartado

| Idea | Por qué |
|---|---|
| Migrar a un framework | El proyecto es académico y el objetivo es entender los mecanismos, no delegarlos. Ver [ADR-0001](adr/0001-sin-framework.md) |
| API REST pública | No hay ningún consumidor externo |
| Aplicación móvil | El sitio ya es responsive |

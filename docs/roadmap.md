# Hoja de ruta

## Próximo

### Dashboard ejecutivo del administrador
Panel con KPIs del día, pacientes por especialidad, mapa de ocupación de
consultorios y recaudación por médico.

> **A tener en cuenta:** dos widgets del diseño de referencia (espera promedio y
> stock de medicamentos) **no tienen datos detrás**. O se agregan las tablas
> correspondientes, o se reemplazan por métricas que sí existan. No se van a
> mostrar números inventados.
>
> El tercero, **medicamentos más recetados**, pasó a ser posible con la etapa 5:
> es un `GROUP BY` sobre `receta_medicamento`. Fue una de las razones para
> guardar los medicamentos en una tabla y no en un campo de texto — ver
> [recetas.md](recetas.md).

---

## Más adelante

### Panel de admisión para recepción
Confirmar la llegada del paciente, estado de la sala de espera y cobro rápido.
Necesita un estado nuevo en `estado_turno` y registrar la hora de llegada.

---

## Deuda técnica

Ordenada por impacto:

| Prioridad | Tema | Detalle |
|---|---|---|
| Alta | Unificar la autorización | Conviven `verificarRol()` y `verificarPermiso()` |
| Alta | Tareas por petición | `expirarVencidos()` y `marcarRealizadosAutomaticamente()` corren en cada visita. Los recordatorios y los avisos de vencimiento ya tienen un camino con cron (`tareas/ejecutar.php`), con el de las visitas como reserva y un freno de diez minutos; faltan migrar los otros dos |
| Media | Accesibilidad pendiente | Etiquetas en los formularios viejos, modales sin `role="dialog"`, calendario no operable por teclado |
| Media | CSRF en el registro | Es alta pública: convendría token más CAPTCHA |
| Media | Buscador de pacientes | `turnos/nuevo` carga los 1010 pacientes en un `datalist` (155 KB) |
| Media | Nombre duplicado en dos tablas | Nombre, apellido y correo viven en `usuario` y en `paciente`/`medico`. `Perfil::guardarCuenta()` escribe las dos en una transacción, pero cualquier código nuevo que toque una sola las desincroniza |
| Media | Pruebas sin integrar | Los guiones de `pruebas/` ya están versionados y devuelven código de salida, pero se corren a mano y escriben en la base de desarrollo. Pruebas unitarias de verdad piden PHPUnit, que pide Composer — descartado en [ADR-0001](adr/0001-sin-framework.md) |
| Media | Las etapas 1 a 4 quedaron sin guiones | Más de quinientas comprobaciones se escribieron en la carpeta temporal del sistema y se perdieron entre sesiones. Sólo las etapas 5 en adelante tienen sus pruebas versionadas |
| Baja | Widgets de formulario en `auth.css` | La zona de foto, el medidor de contraseña y la lista de requisitos los usa también `perfil.php`, que por eso carga `auth.css`. Merecen un archivo propio con un nombre que no diga "auth" |
| Baja | Modo estricto de MySQL | Sin `STRICT_TRANS_TABLES` un tipo mal elegido trunca en silencio. Los tres casos conocidos ya se corrigieron, pero el motor sigue permitiendo el próximo |
| Baja | Unificar el collation hacia `unicode_ci` | Todo el esquema usa `utf8mb4_general_ci`, que ordena mal los acentos en castellano. Migrar 26 tablas con claves foráneas entre sí pide la base fuera de servicio y respaldo — ver [historial-clinico.md](historial-clinico.md) |
| Baja | Campos como array en los formularios viejos | Un POST con `nombre[]=a` hace que `trim()` reciba un array y devuelva un 500. El perfil, el historial y las recetas ya lo cubren; el registro y los ABM no |
| Baja | CSP estricta | Requiere sacar el JavaScript y los estilos en línea |
| Baja | Catálogo de medicamentos abierto | `receta_medicamento.nombre` es texto libre, igual que `estudio.tipo`. Alcanza para prescribir y para contar los más recetados, pero controlar stock pide un catálogo cerrado — ver [recetas.md](recetas.md) |

---

## Descartado

| Idea | Por qué |
|---|---|
| Migrar a un framework | El proyecto es académico y el objetivo es entender los mecanismos, no delegarlos. Ver [ADR-0001](adr/0001-sin-framework.md) |
| API REST pública | No hay ningún consumidor externo |
| Aplicación móvil | El sitio ya es responsive |

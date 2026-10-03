# Paquete de publicación

Lo que hace falta para poner MediTurnos en un hosting PHP compartido.

| Archivo | Qué es |
|---|---|
| `generar_base_demo.sh` | Genera `mediturnos_demo.sql` desde la base de desarrollo |
| `mediturnos_demo.sql` | **El archivo que se importa en el hosting** |
| `verificar_demo.sh` | Prueba el paquete: importa, apunta el sitio a esa base y recorre las pantallas |

El paso a paso está en [docs/deployment.md](../docs/deployment.md). Esto es la
referencia corta de las tres herramientas.

---

## La base de demostración

```bash
bash publicacion/generar_base_demo.sh
```

Produce `mediturnos_demo.sql`: el esquema completo (28 tablas, 5 vistas, 2
triggers y 2 procedimientos), los catálogos reales de la clínica y tres cuentas
de demostración con algo de actividad.

**No lleva** ninguno de los 1012 pacientes del entorno de desarrollo, ni sus
turnos, pagos, notificaciones o intentos de login.

### Las tres cuentas

| Usuario | Rol | Contraseña |
|---|---|---|
| `demo.admin` | Administración | `Demo.2026` |
| `demo.medico` | Profesional | `Demo.2026` |
| `demo.paciente` | Paciente | `Demo.2026` |

> ### ⚠️ La contraseña del administrador es pública
>
> Para que la gente pueda probar el sistema, la contraseña tiene que ser
> conocida. Pero `demo.admin` puede dar de baja profesionales, cambiar
> descuentos y borrar usuarios: **cualquiera que entre puede dejar la demo
> inservible.**
>
> Para una demostración de un trabajo académico suele alcanzar —se vuelve a
> importar el `.sql` y queda como nueva—. Si el sitio tiene que resistir sola,
> cambiá la contraseña de `demo.admin` y publicá sólo las otras dos.

### Por qué se genera y no se escribe a mano

El esquema sale de la base de desarrollo, que es la que tiene las dieciocho
migraciones aplicadas. Un archivo escrito a mano —o los dieciocho `.sql`
concatenados— queda atrás la primera vez que una migración cambie, sin que nadie
se entere hasta que algo falla en el servidor.

### La actividad de la demo

El paciente viene con un turno atendido (con su ficha clínica, un estudio
pendiente y una receta vigente) y uno próximo confirmado con el pago pendiente.
Sin eso, entra y encuentra cinco pantallas vacías: no se puede mostrar el
historial, ni las recetas, ni la renovación, ni la pantalla de pago.

Para quitarlo son dos `DELETE`, anotados dentro del propio `.sql`.

---

## La verificación

```bash
bash publicacion/verificar_demo.sh
```

67 comprobaciones. Importa el `.sql` en una base aparte, **apunta el sitio a esa
base**, entra con las tres cuentas y recorre las pantallas; después restaura todo
y elimina la base de prueba.

### Por qué no alcanza con que el `.sql` se importe

Que el motor no se queje no quiere decir que el sitio funcione. Las dos cosas que
encontró este guión, las dos invisibles para el importador:

- **Nombres de columna inventados.** `paciente` tiene `fecha_nac`, no
  `fecha_nacimiento`; `paciente_plan` tiene `fecha_alta`, no `desde`. El
  importador cortó en la línea 986 y dejó la base a medias.
- **Turnos sin su fila en `pago`.** El pago no lo crea ningún trigger, lo crea el
  flujo de reserva. Un turno insertado a mano se queda sin pago, así que
  `v_turnos_detalle.estado_pago` viene en `NULL`: "Mis pagos" aparecía vacío y la
  pantalla de pago no se podía abrir desde ningún lado. La importación fue
  perfecta y la demo estaba rota.

También comprueba lo que NO tiene que estar (ninguna cuenta del seed, ningún
token de recuperación), que en modo producción un error no filtre rutas ni
números de línea, y que las nueve carpetas privadas respondan 403.

### Si lo cortás por la mitad

El guión crea un `config/entorno.php` temporal mientras corre. Si lo interrumpís
—o canalizás su salida a `head`, que cierra la tubería y mata el proceso—, ese
archivo puede quedar suelto apuntando a una base que después se elimina, y el
sitio local se queda sin datos.

Ya pasó. Ahora el archivo generado lleva una marca adentro, y la corrida
siguiente lo reconoce y lo descarta en vez de tomarlo por tu configuración. Si
igual lo encontrás suelto: **borralo**, no hay nada que conservar ahí.

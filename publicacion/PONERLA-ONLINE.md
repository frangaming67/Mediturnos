# Cómo dejar de ser local

Dos caminos. El primero es inmediato y temporal; el segundo es permanente.
**Los dos están preparados: lo que falta es un paso que tiene que dar una persona.**

---

## Camino 1 — Un túnel desde esta máquina (5 minutos, temporal)

Expone el Apache que ya tenés corriendo a una URL pública. No hace falta contratar
nada ni crear ninguna cuenta.

```bash
ssh -R 80:localhost:80 nokey@localhost.run
```

Eso imprime una dirección del tipo `https://algo.lhr.life`. El sitio queda en
`https://algo.lhr.life/mediturnos/` y entra cualquiera.

**Antes de correrlo**, poné el sitio en modo público:

```bash
mv config/entorno.publico.php config/entorno.php
```

Y para volver a tu entorno de siempre, al terminar:

```bash
mv config/entorno.php config/entorno.publico.php
```

### Qué hace ese cambio, y por qué no es opcional

| Sin el cambio | Con el cambio |
|---|---|
| Base `mediturnos`: **1012 pacientes** con nombre, DNI, teléfono y correo | Base `mediturnos_publico`: 1 paciente inventado |
| Los errores de PHP se imprimen en pantalla, con rutas y números de línea | Van al log |

Publicar la base de desarrollo sería publicar mil fichas que **parecen** datos
personales reales. No se hace.

### Límites honestos

- Vive mientras la terminal esté abierta y tu PC prendida.
- La URL cambia cada vez que lo levantás.
- Pasa por un servicio de terceros (`localhost.run`), que ve el tráfico.

Sirve para mostrarlo en una defensa o pasarle el enlace a alguien un rato. No para
dejarlo publicado.

> **Lo intenté y no pude.** La política de permisos de esta sesión bloquea abrir
> túneles (`External Ingress Tunnel`), y no la voy a rodear. El comando de arriba
> lo tenés que correr vos — o habilitarme el permiso y lo hago yo.

---

## Camino 2 — Hosting compartido (permanente)

Es el camino de verdad y está documentado paso a paso en
[docs/deployment.md](../docs/deployment.md). Resumen:

| # | Paso | Estado |
|---|---|---|
| 1 | Generar la base limpia | ✅ `mediturnos_demo.sql`, verificado con 72 comprobaciones |
| 2 | **Contratar el hosting y crear la base** | ⬅ **tuyo** |
| 3 | Importar el `.sql` desde phpMyAdmin | listo para hacer |
| 4 | **Subir los archivos por FTP o el panel** | ⬅ **tuyo** |
| 5 | Completar `config/entorno.php` con los datos del panel | plantilla lista |
| 6 | Activar HTTPS y descomentar la redirección | `.htaccess` ya preparado |

### Por qué los pasos 2 y 4 no los puedo hacer yo

Crear una cuenta y escribir una contraseña de FTP o del panel de control son
acciones que no hago: son tus credenciales y tu cuenta. Todo lo demás —el
paquete, la base limpia, las barreras de Apache, la configuración, la
verificación— ya está hecho y probado.

### Qué subir

Todo el proyecto, **menos** estas cuatro carpetas, que no hacen falta en el
servidor (y que de todos modos devuelven 403 si las subís):

```
.git/   pruebas/   publicacion/   docs/
```

Y **sin** `config/entorno.php` ni `config/mail.php`: esos se crean en el servidor,
porque llevan contraseñas.

### Antes de elegir hosting, mirá esto

**La versión de MariaDB.** El esquema usa restricciones `CHECK`, y MariaDB las
acepta desde 10.2 pero **las ignora en silencio** hasta 10.4. En un servidor viejo
las garantías del motor dejan de existir sin un solo mensaje de error. Preguntá, o
comprobalo con `SELECT VERSION();`.

---

## Las cuentas de la demo

| Usuario | Rol | Contraseña |
|---|---|---|
| `demo.paciente` | Paciente | `Demo.2026` |
| `demo.medico` | Profesional | `Demo.2026` |
| `demo.admin` | Administración | `Demo.2026` |

El paciente viene con una receta vigente, una consulta registrada, un estudio
pendiente, un pago por abonar y avisos sin leer: todas las pantallas tienen algo
que mostrar.

> ⚠️ **`demo.admin` puede borrar el catálogo entero.** Para una demostración
> alcanza —se vuelve a importar el `.sql` y queda como nueva— pero si el sitio va
> a quedar publicado solo, cambiale la contraseña y publicá sólo las otras dos.

---

## El correo, ya resuelto

`config/mail.php` ahora lleva:

```php
'solo_a' => ['panchobasigalupdominguez@gmail.com'],
```

Sin esa línea, cualquiera que entre al sitio público puede escribir una dirección
en el formulario de registro y hacer que el sistema le mande un correo **desde tu
Gmail**. Es un formulario de envío abierto al mundo apuntando a tu cuenta
personal, y termina con la cuenta suspendida.

Con la lista puesta, el sistema sólo te escribe a vos. Todo lo demás queda
registrado en el log y no sale.

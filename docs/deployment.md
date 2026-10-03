# Puesta en producción

Cómo publicar MediTurnos en un **hosting PHP compartido**, que es el caso para el
que está preparado el paquete de [`publicacion/`](../publicacion/LEEME.md).

> El proyecto se desarrolla en XAMPP. Todo lo de este documento está probado
> contra el servidor local con `bash publicacion/verificar_demo.sh` (67
> comprobaciones): la base limpia se importa, el sitio corre sobre ella con las
> tres cuentas de demostración, el modo producción no filtra rutas y las carpetas
> privadas devuelven 403.

## Requisitos del servidor

| Componente | Versión | Notas |
|---|---|---|
| PHP | 8.0+ | Con `pdo_mysql`, `gd`, `fileinfo`, `openssl`, `mbstring` |
| MariaDB / MySQL | **10.4+** / 5.7+ | Motor InnoDB. Ver la nota de abajo |
| Apache | 2.4 | Con `AllowOverride All` |

> **Por qué MariaDB 10.4 y no 10.2.** El esquema usa restricciones `CHECK`, y
> MariaDB las acepta desde 10.2 pero **las ignora en silencio** hasta 10.4. En un
> servidor viejo las garantías del motor dejan de existir sin un solo mensaje de
> error: una receta podría vencer antes de emitirse. Si el hosting no dice la
> versión, consultala con `SELECT VERSION();` antes de confiar en nada.

---

## Paso a paso

### 1. Generar la base de demostración

En la máquina de desarrollo, con XAMPP andando:

```bash
bash publicacion/generar_base_demo.sh
bash publicacion/verificar_demo.sh
```

El primero produce `publicacion/mediturnos_demo.sql`. El segundo comprueba que el
sitio funcione con esa base antes de que salga de tu máquina — es más rápido
encontrar un problema acá que en el servidor.

### 2. Crear la base en el hosting

Desde el panel (cPanel, Plesk o el que sea): crear una base de datos y un usuario
con permisos sobre ella.

**No usar el usuario administrador del servidor.** El sistema necesita exactamente
esto y nada más:

```sql
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE ON la_base.* TO 'el_usuario'@'localhost';
```

`EXECUTE` es necesario: la reserva de turnos pasa por los procedimientos
`ReservarTurno` y `CancelarTurno`, y sin ese permiso falla con un error que no
dice nada útil.

No necesita `DROP`, `ALTER` ni `CREATE`: el esquema ya viene hecho en el `.sql`.
Un usuario que no puede alterar tablas es un usuario que, si alguien le roba las
credenciales, no puede destruir el esquema.

> **El nombre de la base casi nunca es el que elegís.** Muchos paneles le ponen un
> prefijo con tu usuario: pedís `mediturnos` y queda `miusuario_mediturnos`. Por
> eso el `.sql` **no lleva `CREATE DATABASE` ni `USE`**: se importa sobre la base
> que el panel ya creó, se llame como se llame.

### 3. Importar

En phpMyAdmin: elegir la base, pestaña **Importar**, subir
`publicacion/mediturnos_demo.sql`.

Si el panel da acceso por consola:

```bash
mysql -u EL_USUARIO -p LA_BASE < mediturnos_demo.sql
```

Verificar que entró completo:

```sql
SELECT COUNT(*) FROM especialidad;       -- 8
SELECT COUNT(*) FROM horario_atencion;   -- 24
SELECT usuario FROM usuario;             -- demo.admin, demo.medico, demo.paciente
```

### 4. Subir los archivos

Todo el proyecto va dentro de la carpeta pública del hosting (`public_html`,
`httpdocs` o la que use el panel), **incluidas** las carpetas `config/`,
`includes/` y `sistema/`: el código las necesita, y sus `.htaccess` ya impiden
que se sirvan por la web.

Lo que **no** hace falta subir:

```
.git/            el repositorio
pruebas/         los guiones de verificación
publicacion/     este paquete, incluido el .sql
docs/            la documentación
```

Si los subís igual no pasa nada —los cuatro tienen su `.htaccess` y devuelven
403—, pero es peso que nadie va a usar.

### 5. Configurar

```bash
cp config/entorno.ejemplo.php config/entorno.php
cp config/mail.ejemplo.php    config/mail.php
```

En `config/entorno.php`:

```php
define('DB_HOST', 'localhost');            // lo que diga el panel
define('DB_NAME', 'miusuario_mediturnos'); // el nombre REAL, con prefijo
define('DB_USER', 'miusuario_medi');
define('DB_PASS', 'la-clave-del-panel');

define('BASE_URL', '/');                   // '/' si el sitio está en la raíz
define('EN_PRODUCCION', true);             // errores al log, no al visitante
define('RUTA_LOG', '/home/usuario/logs/mediturnos.log');
```

`BASE_URL` es el error más frecuente: si el sitio queda en la raíz del dominio es
`'/'`, y si está en una subcarpeta es `'/esa-carpeta/'`, con las dos barras. Mal
puesta, el sitio carga pero sin estilos y ningún enlace funciona.

`RUTA_LOG` conviene **fuera** de la carpeta pública: un `.log` dentro de
`public_html` es un archivo descargable. El `.htaccess` del raíz ya bloquea la
extensión `.log`, pero no depender de eso es más barato que confiar en que se
aplicó.

Ninguno de los dos archivos está en el repositorio (los dos llevan contraseñas),
así que hay que crearlos en el servidor cada vez.

### 6. Permisos de escritura

Sólo tres carpetas necesitan escritura:

```bash
chmod 775 publico/img/perfiles      # fotos de perfil
chmod 775 almacenamiento/estudios   # resultados de estudios
chmod 775 almacenamiento/mails      # sólo si el correo queda en modo archivo
```

El resto del proyecto debe ser de **sólo lectura** para el servidor web. Un
directorio con escritura y ejecución de PHP es el camino más corto a que alguien
suba un archivo y lo ejecute.

### 7. El evento programado (recomendado)

Los recordatorios de turno y los avisos de pago por vencer no los dispara
ninguna acción: los dispara el reloj. Lo correcto es un evento programado que
corra la tarea una vez por hora.

En Linux (`crontab -e`):

```bash
0 * * * * /usr/bin/php /ruta/al/proyecto/tareas/ejecutar.php >> /ruta/tareas.log 2>&1
```

Muchos paneles de hosting tienen una sección "Cron jobs" donde se pega la
misma línea.

**Si tu hosting no da cron**, no hace falta hacer nada: el panel del sistema ya
corre las tareas con un freno de diez minutos, así que la primera visita de
cualquier usuario después de ese rato dispara los avisos de todos. El defecto de
ese camino es claro y conviene saberlo: **si nadie entra al sitio, nadie recibe
su recordatorio.** Con cron, ese agujero desaparece.

Repetir la tarea es inofensivo: `notificarUnaVez()` comprueba si ya se avisó por
ese motivo, así que correrla mil veces produce los mismos avisos que correrla
una.

### 8. HTTPS

Casi todo hosting compartido da un certificado Let's Encrypt gratis desde el
panel. Activarlo y después descomentar, en el `.htaccess` del raíz, el bloque de
redirección a HTTPS.

**No es opcional.** Sin HTTPS la cookie de sesión no puede llevar el flag
`Secure`, así que viaja en texto claro: en cualquier red abierta, quien esté en el
medio se queda con la sesión de quien esté usando el sitio. Y acá las sesiones dan
acceso a historias clínicas.

El flag `Secure` se activa solo en cuanto hay HTTPS: `esHttps()` mira también las
cabeceras del proxy (`X-Forwarded-Proto`), que es como llega la petición cuando el
certificado lo termina el balanceador del hosting y no tu Apache.

**HSTS queda comentado a propósito.** La orden que manda al navegador dura un año
y no se puede retirar antes: si después el certificado vence, el sitio queda
inaccesible para todo el que ya lo visitó. Se activa cuando el HTTPS está firme.

---

## Comprobar que quedó bien

Con el sitio arriba:

| Pedir | Tiene que dar |
|---|---|
| `https://tu-dominio/` | La landing |
| `https://tu-dominio/sql/` | **403** |
| `https://tu-dominio/config/entorno.php` | **403** |
| `https://tu-dominio/almacenamiento/mails/` | **403** |
| `https://tu-dominio/docs/security.md` | **403** |
| `https://tu-dominio/sistema/controladores/ControladorTurno.php` | Redirección al login |

Si las que tienen que dar 403 dan 200, **Apache no está leyendo los `.htaccess`**:
falta `AllowOverride All`. Es lo primero que hay que mirar, porque en ese caso
está descargable el esquema completo de la base.

Después, entrar con `demo.paciente` / `Demo.2026` y recorrer: panel, agendar,
mis turnos, mis pagos, historial, mis recetas, mi perfil. Es lo mismo que hace
`verificar_demo.sh` en local.

---

## Antes de publicar: la lista

### Seguridad

- [ ] **HTTPS activo** y la redirección descomentada
- [ ] `config/entorno.php` con `EN_PRODUCCION` en `true`
- [ ] Usuario de base sin `DROP`, `ALTER` ni `CREATE`
- [ ] Las seis carpetas privadas devuelven 403 (comprobado, no supuesto)
- [ ] `probar_mail.php` **no subido** — permite enviar correos desde la casilla
      del sistema sin autenticarse. Está en `.gitignore`, así que un clon no lo
      trae; si lo copiaste a mano, borralo
- [ ] `config/mail.php` creado en el servidor y fuera del repositorio
- [ ] La contraseña de `demo.admin` decidida a conciencia
      (ver [publicacion/LEEME.md](../publicacion/LEEME.md))

### Datos

- [ ] Base importada desde `publicacion/mediturnos_demo.sql`, **no** copiada de
      desarrollo: ahí hay 1012 pacientes con nombre, DNI y teléfono
- [ ] Copia de seguridad configurada, o al menos sabida a mano

### Rendimiento

- [ ] Compresión y caché: ya vienen en el `.htaccess` del raíz
- [ ] **Evento programado configurado** para `tareas/ejecutar.php` (paso 7). Sin
      él los recordatorios dependen de que alguien entre al sitio
- [ ] `expirarVencidos()` y `marcarRealizadosAutomaticamente()` siguen corriendo
      en cada visita. Funciona, pero deberían migrar al mismo evento programado
      — está anotado en la [deuda técnica](roadmap.md)

---

## Lo que este paquete NO resuelve

Conviene tenerlo claro antes de mostrarle el sitio a alguien.

**El correo sale desde una casilla de Gmail.** `config/mail.php` guarda la
contraseña de aplicación de una cuenta personal. Para una demo alcanza; para algo
real hay que usar el SMTP del hosting o un servicio de envío, porque Gmail limita
los envíos y puede bloquear la cuenta por actividad inusual.

**No hay copias de seguridad automáticas.** Si alguien entra con `demo.admin` y
borra medio catálogo, se recupera volviendo a importar el `.sql`. Nada más.

**La CSP permite `unsafe-inline`.** El proyecto tiene JavaScript y estilos en
línea en las vistas, así que una política estricta rompería la aplicación. Está
en la deuda técnica, y mientras tanto la protección contra XSS depende del
escapado —que sí está, y verificado— y no de la CSP.

**Las tareas por petición.** Vencer pagos y marcar turnos realizados ocurre en
cada carga de página. Con poco tráfico no se nota; con mucho, es trabajo repetido
miles de veces por día.

---

## Copias de seguridad

```bash
mysqldump -u usuario -p la_base > respaldo_$(date +%F).sql
tar -czf archivos_$(date +%F).tar.gz publico/img/perfiles/ almacenamiento/estudios/
```

Las fotos de perfil y los resultados de estudios **no están en la base**: hay que
respaldarlos por separado, y los segundos son información de salud, así que el
respaldo merece el mismo cuidado que el original.

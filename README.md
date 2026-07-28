# MediTurnos

Sistema de gestión de turnos médicos para clínicas y consultorios. Permite a los
pacientes reservar turnos online eligiendo profesional y horario, y al personal de
salud administrar agendas, cobros y coberturas desde un panel interno.

> Proyecto académico — Licenciatura en Sistemas.

---

## Índice

- [Características](#características)
- [Tecnologías](#tecnologías)
- [Requisitos](#requisitos)
- [Instalación](#instalación)
- [Configuración](#configuración)
- [Base de datos](#base-de-datos)
- [Usuarios de prueba](#usuarios-de-prueba)
- [Estructura del proyecto](#estructura-del-proyecto)
- [Guía para desarrolladores](#guía-para-desarrolladores)
- [Documentación técnica](#documentación-técnica)

---

## Características

### Portal público
- Landing con especialidades, equipo médico y estadísticas **leídas de la base**.
- Buscador que filtra especialidades y profesionales en vivo.
- Registro de pacientes en tres pasos, con foto de perfil y obra social.
- Recuperación de contraseña por correo, con enlace de un solo uso.

### Pacientes
- Calendario con la **disponibilidad real** de cada profesional.
- Reserva con cálculo automático del descuento según la obra social.
- Pago con tarjeta (pasarela simulada) o en recepción.
- Consulta del historial de turnos y pagos propios.

### Profesionales
- Agenda del día con contadores por estado.
- Listado de sus pacientes con cantidad de consultas y última visita.
- Registro de la atención sobre el turno.

### Administración
- ABM de pacientes, médicos, horarios, ausencias, consultorios y obras sociales.
- Panel con KPIs, agenda del día, pagos pendientes y recaudación por médico.
- Gestión de usuarios y permisos.

### Reglas de negocio destacadas
- **Imposible reservar dos veces el mismo horario.** La garantía la da un índice
  único del motor de base de datos, no el código PHP: dos pacientes simultáneos
  no pueden tomar el mismo turno ni el mismo consultorio.
- **Los turnos impagos se cancelan solos** al vencer el plazo, liberando el horario.
- **Al marcar una ausencia**, los turnos de ese día se cancelan en la misma
  transacción: nunca queda un médico ausente con turnos activos.

---

## Tecnologías

| Capa | Tecnología |
|---|---|
| Backend | PHP 8.2 (sin framework, MVC propio) |
| Base de datos | MariaDB 10.4 / MySQL 5.7+ — InnoDB |
| Frontend | HTML5, CSS3 y JavaScript sin librerías |
| Servidor | Apache 2.4 (XAMPP) |
| Acceso a datos | PDO con consultas preparadas |
| Tipografía | Inter (Google Fonts) |

**Sin dependencias externas ni gestor de paquetes.** Todo el código —incluido el
cliente SMTP— está escrito a mano. Es una decisión deliberada: ver
[ADR-0001](docs/adr/0001-sin-framework.md).

---

## Requisitos

- PHP **8.0 o superior** con las extensiones `pdo_mysql`, `gd`, `fileinfo` y `openssl`
- MySQL 5.7+ o MariaDB 10.2+
- Apache con `mod_rewrite`

> **`gd` es obligatoria** para procesar las fotos de perfil. En XAMPP viene
> desactivada: hay que descomentar `extension=gd` en `php.ini` y reiniciar Apache.

---

## Instalación

```bash
git clone https://github.com/frangaming67/Mediturnos.git
```

Copiar el proyecto a la carpeta pública del servidor (en XAMPP, `C:\xampp\htdocs\mediturnos`).

Crear la base y ejecutar los scripts **en este orden**:

```bash
mysql -u root -e "CREATE DATABASE mediturnos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

cd sql
mysql -u root --default-character-set=utf8mb4 mediturnos < seed_datos.sql
mysql -u root --default-character-set=utf8mb4 mediturnos < triggers_y_sp.sql
mysql -u root --default-character-set=utf8mb4 mediturnos < pagos_y_descuentos.sql
mysql -u root --default-character-set=utf8mb4 mediturnos < estado_turno.sql
mysql -u root --default-character-set=utf8mb4 mediturnos < normalizacion.sql
mysql -u root --default-character-set=utf8mb4 mediturnos < control_concurrencia.sql
mysql -u root --default-character-set=utf8mb4 mediturnos < concurrencia_consultorio.sql
mysql -u root --default-character-set=utf8mb4 mediturnos < ausencias_medico.sql
mysql -u root --default-character-set=utf8mb4 mediturnos < baja_medico.sql
mysql -u root --default-character-set=utf8mb4 mediturnos < vistas.sql
mysql -u root --default-character-set=utf8mb4 mediturnos < auth_v2.sql
```

> El orden importa: las migraciones dependen de las anteriores. `auth_v2.sql` va
> siempre al final y es idempotente (se puede volver a correr sin romper nada).

Abrir `http://localhost/mediturnos/`.

---

## Configuración

### Conexión a la base

`config/conexion.php` — único punto donde se abre la conexión:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'mediturnos');
define('DB_USER', 'root');
define('DB_PASS', '');
define('BASE_URL', '/mediturnos/');
```

### Correo

El proyecto funciona **sin configurar nada**: los correos se guardan como archivos
HTML en `almacenamiento/mails/` y se pueden abrir en el navegador. Es el modo por
defecto y alcanza para desarrollo.

Para envío real:

```bash
cp config/mail.ejemplo.php config/mail.php
```

Editar `config/mail.php` con los datos del servidor SMTP. Con Gmail hay que usar
una **contraseña de aplicación** de 16 caracteres, no la contraseña de la cuenta.

> `config/mail.php` está en `.gitignore`. **Nunca subas credenciales al repositorio.**

Para verificar la configuración existe `probar_mail.php` (también ignorado por git):
muestra el estado de cada requisito y permite enviar un correo de prueba.
**Borralo antes de publicar el sitio.**

### Variables de configuración

| Constante | Archivo | Descripción |
|---|---|---|
| `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASS` | `config/conexion.php` | Conexión a la base |
| `BASE_URL` | `config/conexion.php` | Ruta pública del proyecto |
| `LOGIN_MAX_INTENTOS` | `includes/seguridad.php` | Intentos antes del bloqueo (5) |
| `LOGIN_VENTANA_MIN` | `includes/seguridad.php` | Ventana del bloqueo en minutos (15) |
| `Usuario::RESET_MINUTOS` | `sistema/modelos/Usuario.php` | Validez del enlace de recuperación (60) |
| `SubidaImagen::MAX_BYTES` | `includes/subida_imagen.php` | Peso máximo de la foto (3 MB) |
| `Pago::HORAS_PLAZO` | `sistema/modelos/Pago.php` | Plazo para abonar un turno (48 h) |

---

## Base de datos

19 tablas y 5 vistas. El modelo está normalizado hasta 4FN. Detalle completo en
[docs/database.md](docs/database.md).

```
paciente ──< turno >── medico
                │
                ├──> especialidad
                ├──> consultorio
                ├──> plan_os ──> obra_social
                ├──> estado_turno
                ├──< historial_turno
                └──< pago
```

---

## Usuarios de prueba

Todos con la contraseña **`password123`** (definida en `seed_datos.sql`).

| Usuario | Rol | Qué puede hacer |
|---|---|---|
| `admin` | Administrador | Todo, incluida la gestión de usuarios |
| `recepcion` | Recepcionista | Turnos, pacientes, cobros |
| `cfernandez` | Médico | Su agenda y sus pacientes |
| `jperez` | Paciente | Reservar y gestionar sus turnos |

> Se puede entrar con el **nombre de usuario o con el correo**, indistintamente.
>
> ⚠️ Son credenciales de desarrollo. Cambiarlas antes de cualquier uso real.

---

## Estructura del proyecto

```
mediturnos/
├── index.php               Landing pública
├── login.php               Acceso
├── registro.php            Alta de pacientes (3 pasos)
├── recuperar.php           Pedido de recuperación
├── restablecer.php         Nueva contraseña
├── logout.php              Cierre de sesión
├── dashboard.php           Router por rol
│
├── config/
│   ├── conexion.php        Conexión PDO + zona horaria
│   └── mail.ejemplo.php    Plantilla de correo (sin credenciales)
│
├── includes/
│   ├── auth.php            Sesión, roles, permisos, CSRF
│   ├── seguridad.php       Cookies, cabeceras, anti fuerza bruta
│   ├── mailer.php          Envío de correo (SMTP / archivo)
│   └── subida_imagen.php   Subida segura de imágenes
│
├── sistema/
│   ├── modelos/            9 clases de acceso a datos
│   ├── controladores/      10 controladores
│   └── vistas/             Plantillas, layouts y componentes
│
├── dashboard/              Paneles por rol + componentes
├── publico/css/            Hojas de estilo
├── assets/js/              Calendario y modales
├── sql/                    Esquema y migraciones
└── docs/                   Documentación técnica
```

---

## Guía para desarrolladores

### Flujo de trabajo (Git Flow)

```
main       Releases estables
develop    Integración — rama por defecto
feature/*  Una funcionalidad por rama
hotfix/*   Correcciones urgentes sobre main
release/*  Preparación de versión
```

**No se trabaja directamente sobre `main`.**

```bash
git checkout develop
git pull
git checkout -b feature/mi-funcionalidad
# ... commits ...
git push -u origin feature/mi-funcionalidad
gh pr create --base develop
```

### Convención de commits

[Conventional Commits](https://www.conventionalcommits.org/):

```
feat(auth): redesign login page
fix(turnos): resolve double booking on concurrent requests
perf(listings): paginate patient list
refactor(models): extract shared filter builder
docs: add database documentation
chore: update gitignore
```

El cuerpo del commit explica **por qué** se hizo el cambio, no sólo qué cambió.

### Convenciones de código

Detalle en [docs/coding-standards.md](docs/coding-standards.md). En resumen:

- **Código y comentarios en castellano**; mensajes de commit en inglés.
- Nombres de tablas y columnas en `snake_case` y singular.
- Clases en `PascalCase`, métodos y variables en `camelCase`.
- **Toda** consulta va con sentencias preparadas. Nunca concatenación.
- **Toda** salida se escapa con `htmlspecialchars`. En JavaScript, `textContent`
  y jamás `innerHTML` con datos de la base.
- La validación del cliente es sólo ayuda visual: **siempre** se repite en el servidor.

### Antes de abrir un Pull Request

- [ ] `php -l` sin errores en los archivos tocados
- [ ] Las pantallas afectadas cargan sin warnings de PHP
- [ ] Sin scroll horizontal en 375 px, 768 px y 1280 px
- [ ] Consola del navegador sin errores
- [ ] Formularios validados también en el servidor
- [ ] Sin código muerto ni duplicado
- [ ] Documentación actualizada si cambió el comportamiento

---

## Documentación técnica

| Documento | Contenido |
|---|---|
| [architecture.md](docs/architecture.md) | Estructura, capas, flujo de datos y patrones |
| [database.md](docs/database.md) | Tablas, relaciones, vistas, triggers y procedimientos |
| [authentication.md](docs/authentication.md) | Sesiones, login, registro y recuperación |
| [authorization.md](docs/authorization.md) | Roles, permisos y aislamiento de datos |
| [security.md](docs/security.md) | Amenazas cubiertas y decisiones de seguridad |
| [frontend.md](docs/frontend.md) | Sistema de diseño, CSS y accesibilidad |
| [backend.md](docs/backend.md) | Modelos, controladores y convenciones |
| [api.md](docs/api.md) | Endpoints internos JSON |
| [deployment.md](docs/deployment.md) | Puesta en producción |
| [contributing.md](docs/contributing.md) | Cómo contribuir |
| [coding-standards.md](docs/coding-standards.md) | Convenciones de código |
| [changelog.md](docs/changelog.md) | Historial de versiones |
| [roadmap.md](docs/roadmap.md) | Próximos pasos |
| [adr/](docs/adr/) | Decisiones de arquitectura registradas |

---

## Licencia

Proyecto académico sin licencia de distribución.

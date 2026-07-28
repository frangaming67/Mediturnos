# Puesta en producción

> El proyecto está pensado para XAMPP en entorno local. Este documento describe
> qué habría que cambiar para publicarlo de verdad.

## Requisitos del servidor

| Componente | Versión | Notas |
|---|---|---|
| PHP | 8.0+ | Con `pdo_mysql`, `gd`, `fileinfo`, `openssl` |
| MariaDB / MySQL | 10.2+ / 5.7+ | Motor InnoDB obligatorio |
| Apache | 2.4 | Con `mod_rewrite` y `AllowOverride All` |

## Pasos

### 1. Código

```bash
git clone https://github.com/frangaming67/Mediturnos.git
cd Mediturnos
git checkout main
```

### 2. Base de datos

Crear la base y ejecutar las migraciones en el orden indicado en
[database.md](database.md).

**No usar `root` sin contraseña.** Crear un usuario con permisos acotados:

```sql
CREATE USER 'mediturnos'@'localhost' IDENTIFIED BY 'una-clave-larga';
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE ON mediturnos.* TO 'mediturnos'@'localhost';
FLUSH PRIVILEGES;
```

No necesita `DROP` ni `ALTER`: las migraciones se corren aparte, con otro usuario.

### 3. Configuración

Editar `config/conexion.php` con las credenciales del nuevo usuario y la `BASE_URL`
real. Copiar `config/mail.ejemplo.php` a `config/mail.php` y completar el SMTP.

### 4. Permisos de escritura

Sólo dos carpetas necesitan escritura:

```bash
chmod 775 publico/img/perfiles
chmod 775 almacenamiento/mails
```

El resto del proyecto debe ser de sólo lectura para el servidor web.

### 5. Borrar lo que no va a producción

```bash
rm probar_mail.php
```

Es una utilidad de instalación: permite enviar correos desde la casilla del sistema
sin autenticarse.

## Checklist antes de publicar

### Seguridad

- [ ] **HTTPS activo** — sin él la cookie no puede llevar el flag `Secure`
- [ ] Contraseñas de los usuarios de prueba cambiadas, o cuentas eliminadas
- [ ] Usuario de base sin privilegios administrativos
- [ ] `probar_mail.php` eliminado
- [ ] `config/mail.php` fuera del control de versiones
- [ ] `display_errors = Off` y `log_errors = On`
- [ ] `.htaccess` que bloquee el acceso directo a `config/`, `includes/` y `sistema/`

### Datos

- [ ] Datos de prueba eliminados (los 1000 pacientes generados)
- [ ] Copia de seguridad automática configurada

### Rendimiento

- [ ] Mover `expirarVencidos()` y `marcarRealizadosAutomaticamente()` a un evento
      programado, en lugar de ejecutarlos en cada visita
- [ ] Compresión gzip activada
- [ ] Cabeceras de caché para CSS, JS e imágenes

## Configuración recomendada de PHP

```ini
display_errors = Off
log_errors = On
error_log = /ruta/al/log/php_error.log

session.cookie_httponly = 1
session.cookie_secure = 1        ; sólo con HTTPS
session.cookie_samesite = Lax
session.use_strict_mode = 1

upload_max_filesize = 4M
post_max_size = 8M
```

## Copias de seguridad

```bash
mysqldump -u usuario -p mediturnos > backup_$(date +%F).sql
tar -czf perfiles_$(date +%F).tar.gz publico/img/perfiles/
```

Las fotos de perfil **no** están en la base: hay que respaldarlas por separado.

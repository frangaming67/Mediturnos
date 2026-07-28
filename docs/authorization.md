# Autorización

## Roles

| Rol | Alcance |
|---|---|
| `admin` | Todo, incluida la gestión de usuarios |
| `recepcionista` | Turnos, pacientes, cobros, agenda |
| `medico` | Su propia agenda y sus pacientes |
| `paciente` | Sus turnos y sus pagos |

## Dos niveles de control

### Por rol — `verificarRol()`

```php
verificarSesion();
verificarRol(['admin', 'recepcionista']);   // corta con 403 si no coincide
```

### Por permiso — `verificarPermiso()`

Control fino sobre la tabla `rol_permiso`:

```php
verificarPermiso('medicos.crear');   // el recepcionista sólo tiene medicos.ver
```

> **Deuda técnica reconocida.** Conviven los dos sistemas: casi todos los
> controladores usan roles y sólo `ControladorMedico` usa permisos. Además la
> tabla `permiso` define permisos que el código no consulta. Habría que unificar.

## Aislamiento de datos (anti-IDOR)

No alcanza con controlar *quién entra*: hay que garantizar que **cada uno vea sólo
lo suyo**.

### Pacientes

```php
if ($_SESSION['rol'] === 'paciente') {
    $idp = (int)($_SESSION['id_paciente'] ?? 0);
    if ($idp <= 0) { http_response_code(403); /* … */ exit; }
    $filtros['id_paciente'] = $idp;
}
```

El guard de `$idp <= 0` es importante: sin él, una cuenta con rol paciente pero
sin ficha vinculada pasaba el filtro con valor `0` y **veía el listado completo**
con nombres y DNI de todos.

Al cancelar un turno o abrir su historial se verifica además la propiedad:

```php
$t = $modelo->buscarPorId($id);
if (!$t || (int)$t['id_paciente'] !== (int)$_SESSION['id_paciente']) { /* 403 */ }
```

### Médicos

Las consultas de la agenda llevan el filtro **en el SQL**, no en PHP:

```sql
WHERE t.matricula = :m AND t.fecha = :f
```

Así es imposible saltearlo desde la vista. Y al cambiar el estado de un turno se
verifica que sea suyo.

## Protección de rutas

Toda página protegida arranca igual:

```php
require conexion + auth;
verificarSesion();          // ¿hay sesión? ¿venció?
verificarRol([...]);        // ¿el rol corresponde?
csrf_verificar();           // ¿el POST trae token válido?
```

**Verificado:** sin cookie de sesión, los 10 controladores responden `302` hacia
el login.

# Convenciones de código

## Idioma

| Elemento | Idioma | Ejemplo |
|---|---|---|
| Código y comentarios | Castellano | `function buscarPorId()` |
| Nombres de tablas y columnas | Castellano | `paciente.fecha_nac` |
| Mensajes de commit | Inglés | `feat(auth): redesign login` |
| Documentación | Castellano | este archivo |

Se eligió castellano en el código para que el dominio del problema (turnos,
obras sociales, matrículas) se lea igual que se habla.

## Nombres

| Elemento | Estilo | Ejemplo |
|---|---|---|
| Clases | PascalCase | `ControladorTurno` |
| Métodos y variables | camelCase | `$idPaciente`, `buscarPorId()` |
| Constantes | MAYUSCULA_GUION | `LOGIN_MAX_INTENTOS` |
| Tablas y columnas | snake_case singular | `historial_turno` |
| Archivos de vista | minúscula | `nuevo.php`, `index.php` |
| Clases CSS | kebab-case | `.panel-header`, `.btn-primario` |
| Ramas | kebab-case con prefijo | `feature/perfil-usuario` |

## Estructura de un archivo PHP

Encabezado explicando **qué hace y por qué está donde está**:

```php
<?php
// sistema/modelos/Turno.php
// -----------------------------------------------------------------
// Es el modelo más grande porque el turno es la entidad central del
// sistema. Dos decisiones importantes viven acá: reservar() llama a
// un procedimiento almacenado (por la validación atómica) y listar()
// consulta una vista SQL en vez de repetir los JOIN.
// -----------------------------------------------------------------
```

## Comentarios

Explican **por qué**, no **qué**. El código ya dice qué hace.

```php
// ✗ Mal
$stmt = $this->pdo->prepare("SELECT ...");   // prepara la consulta

// ✓ Bien
// Se usan dos marcadores con el mismo valor porque con
// EMULATE_PREPARES en false no se puede repetir uno.
$stmt->execute([':ident1' => $x, ':ident2' => $x]);
```

Cuando algo parece raro pero es intencional, hay que decirlo:

```php
// LIMIT va interpolado y no como parámetro porque MySQL lo recibiría
// como string y rechazaría la consulta. El casteo a int lo hace seguro.
$sql .= " LIMIT {$porPagina} OFFSET {$offset}";
```

## Reglas no negociables

### 1. Consultas siempre preparadas

```php
// ✗ NUNCA
$pdo->query("SELECT * FROM paciente WHERE dni = '$dni'");

// ✓ SIEMPRE
$stmt = $pdo->prepare("SELECT * FROM paciente WHERE dni = :dni");
$stmt->execute([':dni' => $dni]);
```

### 2. Toda salida escapada

```php
<?= htmlspecialchars($paciente['nombre']) ?>
```

### 3. En JavaScript, textContent

```javascript
// ✗ NUNCA con datos de la base
elemento.innerHTML = dato;

// ✓ SIEMPRE
elemento.textContent = dato;
```

### 4. Validar en el servidor

La validación del cliente es ayuda visual. El JavaScript se desactiva y el POST se
arma a mano: **toda** regla se repite en el servidor.

### 5. Redirigir después de un POST

```php
$modelo->crear($datos);
header('Location: ' . BASE_URL . '...?msg=creado');
exit;   // el exit es obligatorio
```

## Formato

- Indentación de 4 espacios, nunca tabulaciones
- Llave de apertura en la misma línea salvo en clases y funciones
- Máximo ~100 caracteres por línea
- Una línea en blanco entre bloques lógicos

## Qué evitar

| Práctica | Por qué |
|---|---|
| `SELECT *` en producción | Trae columnas que no se usan y rompe si cambia el esquema |
| Lógica de negocio en las vistas | Las vistas sólo presentan |
| SQL fuera de los modelos | El acceso a datos queda disperso |
| `die()` / `exit()` con mensaje técnico | Filtra información del sistema |
| Números mágicos | Usar constantes con nombre |
| Comentar código muerto | Para eso está el historial de git |

# Backend

## Modelos

Nueve clases en `sistema/modelos/`. Son el **único lugar del proyecto que ejecuta
SQL**. Reciben el `PDO` por constructor.

| Modelo | Responsabilidad |
|---|---|
| `Turno` | Reserva, cancelación, agenda, KPIs, slots disponibles |
| `Paciente` | ABM de pacientes (gestión interna) |
| `Medico` | ABM, especialidades, baja lógica |
| `Usuario` | Cuentas, login, registro público, tokens de recuperación |
| `Pago` | Cálculo de monto, cobro, vencimientos |
| `Horario` | Franjas de atención, detección de solapamiento |
| `Ausencia` | Ausencias con cancelación de turnos |
| `Consultorio` | ABM de consultorios |
| `ObraSocial` | Obras sociales, planes y descuentos |

### Convenciones

```php
public function listar(array $filtros = [], int $pagina = 1): array
public function buscarPorId(int $id): array|false
public function crear(array $datos): int
public function actualizar(int $id, array $datos): bool
public function existeX(string $valor, int $excluirId = 0): bool
```

Ningún modelo imprime HTML ni redirige: devuelve datos o lanza una excepción.

### Filtros compartidos

Cuando un modelo pagina, `listar()` y `contar()` comparten el armado del `WHERE`:

```php
private function construirFiltros(array $filtros): array
{
    // devuelve [$whereSql, $params]
}
```

Si estuviera duplicado, el total del paginador podría no coincidir con las filas
mostradas.

## Controladores

Diez archivos en `sistema/controladores/`, todos con la misma forma:

```php
require conexion + auth + modelo;

verificarSesion();
verificarRol(['admin', 'recepcionista']);
csrf_verificar();

switch ($accion) {
    case 'index':
        $datos = $modelo->listar($filtros);
        require __DIR__ . '/../vistas/x/index.php';
        break;

    case 'guardar':
        // validar → guardar → redirigir
        header('Location: ' . BASE_URL . '...?msg=creado');
        exit;

    default:
        header('Location: ...?accion=index');
        exit;
}
```

Después de una operación exitosa **siempre redirigen** (POST/Redirect/GET): al
recargar no se repite el envío.

`ControladorAuth` es la excepción y es una clase con métodos, porque no se visita
como URL: lo incluyen `login.php`, `registro.php` y `logout.php`, cada uno con su
propio flujo.

## Manejo de errores

```php
try {
    $modelo->crear($datos);
    header('Location: ...?msg=creado');
    exit;
} catch (PDOException $e) {
    error_log('ControladorX guardar: ' . $e->getMessage());
    $mensaje = 'No se pudo guardar. Revisá los datos e intentá de nuevo.';
    $tipoMsg = 'error';
    require __DIR__ . '/../vistas/x/nuevo.php';   // vuelve al formulario
}
```

El detalle técnico va al log; el usuario ve un mensaje comprensible.

Cuando la base señala una regla de negocio (`SIGNAL SQLSTATE '45000'`), el modelo
la traduce a `RuntimeException` y el controlador la muestra:

```php
if ($e->getCode() === '45000') {
    throw new RuntimeException($e->errorInfo[2] ?? 'No se pudo cancelar el turno.');
}
```

## Transacciones

Se usan siempre que dos o más escrituras deban ocurrir juntas:

| Operación | Qué agrupa |
|---|---|
| `Usuario::registrarPaciente()` | Ficha + cuenta + cobertura |
| `Ausencia::registrar()` | Marcar ausencia + cancelar sus turnos |
| `Medico::crear()` | Médico + sus especialidades |
| `Usuario::restablecerPassword()` | Cambiar clave + quemar el token |
| `Pago::expirarVencidos()` | Vencer pagos + cancelar turnos |

El patrón:

```php
$this->pdo->beginTransaction();
try {
    // ... varias escrituras ...
    $this->pdo->commit();
} catch (PDOException $e) {
    $this->pdo->rollBack();
    throw $e;
}
```

## Módulos de soporte

| Archivo | Función |
|---|---|
| `includes/auth.php` | Sesión, roles, permisos, CSRF |
| `includes/seguridad.php` | Cookies endurecidas, cabeceras, anti fuerza bruta |
| `includes/mailer.php` | Envío de correo con dos implementaciones |
| `includes/subida_imagen.php` | Subida y procesamiento seguro de imágenes |

`auth.php` y `seguridad.php` están separados a propósito: el primero responde
preguntas de negocio (quién sos, qué podés hacer) y el segundo aplica medidas de
infraestructura que valen igual para todas las páginas.

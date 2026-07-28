# ADR-0003 — Envío de correo con implementaciones intercambiables

- **Estado:** aceptada
- **Fecha:** 2026-07

## Contexto

La recuperación de contraseña necesita enviar correo. El entorno de desarrollo
(XAMPP) no puede:

```
sendmail_path: (vacío)
SMTP: localhost        ← no hay servidor
Sin Composer           ← no se puede instalar PHPMailer
```

`mail()` de PHP falla en silencio. Sin resolverlo, el flujo de recuperación no se
puede desarrollar ni demostrar.

Además, quien clone el repositorio no debería tener que configurar un servidor SMTP
sólo para que el proyecto arranque.

## Decisión

Definir una interfaz `Mailer` con dos implementaciones:

| Implementación | Qué hace | Cuándo se usa |
|---|---|---|
| `MailerArchivo` | Guarda el correo como HTML en `almacenamiento/mails/` | Por defecto |
| `MailerSmtp` | Envía por SMTP hablando el protocolo por sockets | Si existe `config/mail.php` |

La función `obtenerMailer()` elige según haya o no configuración. La aplicación
**sólo conoce la interfaz**.

## Consecuencias

### A favor
- El proyecto funciona recién clonado, sin configurar nada
- El flujo de recuperación es completamente demostrable sin servidor de correo
- Pasar a envío real no toca una sola línea del código de la aplicación
- Sin dependencias externas

### En contra
- Hay que implementar SMTP a mano
- `MailerArchivo` genera archivos que contienen **enlaces de recuperación válidos**:
  por eso están en `.gitignore` y conviene limpiarlos

## Nota sobre la implementación de SMTP

Escribir un cliente SMTP tiene una trampa que costó encontrar: **las respuestas
pueden ocupar varias líneas**. El servidor indica que continúa poniendo un guion en
la cuarta posición (`250-...`) y cierra con un espacio (`250 ...`).

Gmail responde al `EHLO` con **ocho líneas**. Leer sólo la primera deja las otras
siete en el buffer y **desincroniza todo el diálogo**: el comando siguiente lee una
respuesta vieja y el envío falla de formas confusas.

```php
private function leerRespuesta($con): string
{
    $respuesta = '';
    while (($linea = fgets($con, 515)) !== false) {
        $respuesta .= $linea;
        if (strlen($linea) < 4 || $linea[3] !== '-') break;   // ' ' = última
    }
    return $respuesta;
}
```

También hace falta *dot-stuffing*: una línea del cuerpo que empiece con un punto
cortaría el mensaje, porque un punto solo es la marca de fin (RFC 5321).

## Alternativas descartadas

| Opción | Por qué no |
|---|---|
| `mail()` de PHP | No funciona sin servidor local configurado |
| PHPMailer vía Composer | Contradice la decisión de no usar dependencias (ADR-0001) |
| Copiar PHPMailer a mano | Miles de líneas de terceros para un caso acotado |
| Exigir SMTP siempre | El proyecto no arrancaría sin configuración previa |

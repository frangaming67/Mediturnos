<?php
// =============================================================
// config/mail.ejemplo.php — PLANTILLA de configuración de correo
// =============================================================
// CÓMO ACTIVAR EL ENVÍO REAL
//   1. Copiá este archivo como  config/mail.php
//   2. Completá los datos de abajo
//   3. Probá con  probar_mail.php  (en la raíz del proyecto)
//
// Mientras NO exista config/mail.php, el sistema usa el modo
// simulado: guarda cada correo como archivo en almacenamiento/mails/.
// No hay que tocar ni una línea del código de la aplicación para
// cambiar de uno a otro.
//
// ⚠️ IMPORTANTE — config/mail.php NO se versiona (está en .gitignore).
//    Nunca subas contraseñas a un repositorio.
// =============================================================

// -------------------------------------------------------------
// GMAIL — el caso más común
// -------------------------------------------------------------
// Gmail NO acepta la contraseña normal de la cuenta. Hay que crear
// una "contraseña de aplicación" de 16 caracteres:
//
//   1. Activá la Verificación en 2 pasos:
//      https://myaccount.google.com/signinoptions/two-step-verification
//      (sin esto, Google no ofrece la opción de contraseñas de aplicación)
//
//   2. Generá la contraseña de aplicación:
//      https://myaccount.google.com/apppasswords
//      Elegí "Correo" y el dispositivo que quieras; te da algo como
//      "abcd efgh ijkl mnop".
//
//   3. Pegala abajo en 'clave' — con o sin espacios, da igual.
//
// Esa contraseña sirve SOLO para enviar correo desde esta app y la
// podés revocar cuando quieras sin cambiar la clave de tu cuenta.
// -------------------------------------------------------------

return [
    // Servidor SMTP
    'host'   => 'smtp.gmail.com',
    'puerto' => 587,          // 587 con 'tls' · 465 con 'ssl'
    'seguro' => 'tls',        // 'tls' (recomendado) o 'ssl'

    // Credenciales
    'usuario' => 'TU_CUENTA@gmail.com',
    'clave'   => 'PEGA_ACA_TU_CONTRASENA_DE_APLICACION',

    // Remitente que verá quien reciba el correo.
    // En Gmail 'desde' debe ser la MISMA dirección que 'usuario':
    // si ponés otra, Google la reescribe o rechaza el envío.
    'desde'        => 'TU_CUENTA@gmail.com',
    'desde_nombre' => 'MediTurnos',

    // Nombre con el que la app se presenta ante el servidor (EHLO).
    'dominio' => 'localhost',
];

// -------------------------------------------------------------
// OTRAS OPCIONES (por si no querés usar Gmail)
// -------------------------------------------------------------
// Brevo (ex Sendinblue) — 300 correos/día gratis, sin tarjeta:
//     'host' => 'smtp-relay.brevo.com', 'puerto' => 587, 'seguro' => 'tls'
//
// Mailtrap — bandeja de PRUEBA: captura todo sin enviarlo a nadie.
// Es la mejor opción para mostrar el flujo en una defensa sin
// mandarle correos de verdad a nadie:
//     'host' => 'sandbox.smtp.mailtrap.io', 'puerto' => 587, 'seguro' => 'tls'
//
// Outlook / Hotmail:
//     'host' => 'smtp-mail.outlook.com', 'puerto' => 587, 'seguro' => 'tls'
// -------------------------------------------------------------

# Frontend

## Sistema de diseño

Tokens definidos en `:root` (`publico/css/estilos.css`) y replicados en
`landing.css`, para que el sitio público y el sistema interno se vean como un solo
producto y no como dos aplicaciones distintas.

```css
--azul: #2563eb;      --azul-osc: #1e3a8a;    --azul-claro: #eff6ff;
--verde: #16a34a;     --rojo: #dc2626;        --amarillo: #d97706;
--gris-osc: #0f172a;  --gris-med: #334155;    --gris: #64748b;
--radio: 14px;        --radio-sm: 10px;
--sombra / --sombra-md / --sombra-lg
```

Tipografía **Inter**, con reserva a la fuente del sistema para que el texto no
caiga a Times si Google Fonts no carga.

> Al unificar el sistema se conservaron **todos los nombres de variables
> originales** y sólo cambiaron los valores. Así las ~30 vistas y los otros dos
> CSS que ya las usaban siguieron funcionando sin tocar una línea.

## Organización del CSS

| Archivo | Alcance |
|---|---|
| `estilos.css` | Compartido: layout, sidebar, botones, tablas, modales |
| `dashboard.css` | Sólo el calendario del paciente |
| `medico.css` | Sólo la agenda del médico (se carga únicamente para ese rol) |
| `landing.css` | Sólo la landing (autocontenido) |
| `auth.css` | Login, registro y recuperación **+ widgets de formulario reutilizables** |
| `perfil.css` | Sólo "Mi perfil" |
| `paciente.css` | Área del Paciente: inicio, detalle de turno, reprogramación |
| `utilidades.css` | Helpers de una sola propiedad, para evitar estilos en línea |

Están separados por **responsabilidad**, no por rendimiento: los tres primeros se
cargan siempre juntos.

### Hojas de una sola pantalla

`navbar.php` lee una variable opcional `$cssExtra` que la vista declara antes de
incluirlo:

```php
$cssExtra = ['auth.css', 'perfil.css'];
require_once __DIR__ . '/sistema/vistas/layouts/navbar.php';
```

Se cargan al final, después de `estilos.css`, así que pueden ajustar lo que este
definió. Sin la variable no cambia nada: las ~30 vistas anteriores siguen igual.

> `perfil.php` carga también `auth.css` aunque no sea una pantalla de
> autenticación. Ahí viven la zona de arrastrar la foto, el medidor de fortaleza,
> la lista de requisitos y el aviso de Bloq Mayús, que el perfil reutiliza tal
> cual. Copiarlos sería duplicar CSS ya probado para que después los dos se
> separen. Está anotado en la deuda técnica que merecen un archivo propio.

## Mejora progresiva

El principio: **nunca ocultar contenido esperando que un script lo revele.**

```css
/* Visible por defecto. Sólo se oculta si el script confirmó que puede animarlo */
.js-anim .reveal { opacity: 0; transform: translateY(24px); }
.js-anim .reveal.visible { opacity: 1; transform: none; }
```

Se aplica en la landing (animaciones al hacer scroll) y en el registro (asistente
por pasos). Si el JavaScript falla, la landing se ve igual en lugar de quedar en
blanco, y el registro muestra los tres pasos en vez de atrapar al usuario en el
primero.

Además hay una red de seguridad: a los 1,5 segundos se revela todo aunque el
`IntersectionObserver` no haya llegado a disparar.

## Responsive

Puntos de corte: **560 px**, **768/860 px**, **900 px** y **1024 px**.
Verificado sin scroll horizontal en 375, 512, 768 y 1280 px.

### Dos defectos de desborde encontrados y corregidos

**1. `.main-wrap` como flex item.** Por defecto un flex item vale
`min-width: auto`, o sea que **no puede achicarse por debajo del ancho de su
contenido**. La tabla de turnos (10 columnas con `white-space: nowrap`) lo estiraba
135 px más allá del viewport y aparecía scroll horizontal en toda la página. Se
corrige con `min-width: 0`: recién ahí el `overflow-x` de `.tabla-wrap` hace su
trabajo y scrollea la tabla por dentro.

**2. Barra de navegación de la landing en 375 px.** Logo, dos botones y el menú no
entran. Se oculta "Ingresar" de la barra y reaparece dentro del menú hamburguesa,
para no perder la acción.

## Accesibilidad

| Aspecto | Estado |
|---|---|
| Foco visible | ✅ `:focus-visible` global |
| Contraste | ✅ `--gris` sobre blanco = 4.76:1 (cumple AA) |
| `prefers-reduced-motion` | ✅ Respetado |
| Etiquetas de formulario | ⚠️ Asociadas en las pantallas nuevas; faltan en las viejas |
| Modales | ⚠️ Sin `role="dialog"` ni trampa de foco |
| Calendario del paciente | ⚠️ Los días son `div` con `onclick`: no operables por teclado |

En las pantallas nuevas sí se aplicó: `aria-pressed` en mostrar/ocultar contraseña,
`aria-current` en el paginador, `role="alert"` en los errores, `aria-label` en el
gráfico semanal y enlace de salto al contenido.

## JavaScript

Sin librerías. Cada script vive donde se usa:

| Ubicación | Función |
|---|---|
| `assets/js/calendario.js` | Calendario mensual del paciente |
| `assets/js/modal_turno.js` | Modal de confirmación de turno |
| En línea en `footer.php` | Modales genéricos y menú móvil |
| En línea en cada vista | Lógica específica de esa pantalla |

**Regla no negociable:** todo dato que venga de la base se escribe con
`textContent`. Nunca `innerHTML`.

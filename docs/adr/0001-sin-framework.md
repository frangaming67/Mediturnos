# ADR-0001 — PHP puro, sin framework

- **Estado:** aceptada
- **Fecha:** 2026-06

## Contexto

El proyecto necesita una capa web, acceso a datos, sesiones, plantillas y
validación. Laravel, Symfony o CodeIgniter resuelven todo eso de entrada.

Es un trabajo académico de Licenciatura en Sistemas: se defiende oralmente y se
evalúa la comprensión de los mecanismos, no la velocidad de entrega.

## Decisión

Implementar el MVC a mano sobre PHP puro, sin framework ni Composer.

## Motivos

- **El objetivo es entender, no delegar.** Con un framework, la respuesta a "¿cómo
  evitás la doble reserva?" sería "el ORM lo maneja". Sin él hay que resolverlo y
  poder explicarlo.
- **Sin caja negra.** Cada línea del recorrido —de la petición al HTML— es visible
  y defendible.
- **Cero fricción de despliegue.** Se copia a `htdocs` y funciona. No hay
  `composer install`, ni `.env`, ni build.
- **El alcance lo permite.** Nueve entidades y cuatro roles no justifican la
  complejidad de un framework.

## Consecuencias

### A favor
- Control total del comportamiento
- Sin dependencias que actualizar ni vulnerabilidades de terceros
- Cada decisión es explicable en una defensa

### En contra
- Hay que escribir a mano cosas resueltas: enrutado, validación, correo
- Sin ORM: el SQL se escribe a mano (mitigado con vistas SQL)
- Sin ecosistema de paquetes
- Más superficie propia donde equivocarse

### Mitigaciones
- Consultas **siempre** preparadas, para que la ausencia de ORM no traiga SQL injection
- Vistas SQL que concentran los JOIN y evitan repetirlos
- Convenciones documentadas en [coding-standards.md](../coding-standards.md)

## Alternativas descartadas

| Opción | Por qué no |
|---|---|
| Laravel | Curva de aprendizaje que compite con el objetivo pedagógico |
| CodeIgniter | Más liviano, pero sigue ocultando el enrutado y el ciclo de vida |
| Slim + librerías | Obliga a Composer sin resolver lo importante del problema |

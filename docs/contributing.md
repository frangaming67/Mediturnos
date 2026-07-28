# Cómo contribuir

## Flujo de trabajo

Se usa **Git Flow**:

```
main       Releases estables
develop    Integración (rama por defecto)
feature/*  Una funcionalidad por rama
hotfix/*   Corrección urgente sobre main
release/*  Preparación de versión
```

**No se trabaja directamente sobre `main` ni sobre `develop`.**

## Crear una funcionalidad

```bash
git checkout develop
git pull origin develop
git checkout -b feature/nombre-descriptivo
```

Commits pequeños y coherentes durante el desarrollo. Al terminar:

```bash
git push -u origin feature/nombre-descriptivo
gh pr create --base develop --title "feat(x): descripcion"
```

## Mensajes de commit

Se sigue [Conventional Commits](https://www.conventionalcommits.org/).

```
<tipo>(<alcance>): <descripcion en imperativo>

<cuerpo: POR QUE se hizo el cambio>
```

| Tipo | Cuándo |
|---|---|
| `feat` | Funcionalidad nueva |
| `fix` | Corrección de un error |
| `perf` | Mejora de rendimiento |
| `refactor` | Cambio interno sin alterar el comportamiento |
| `docs` | Documentación |
| `style` | Formato, sin efecto funcional |
| `test` | Pruebas |
| `chore` | Tareas de mantenimiento |

Alcances habituales: `auth`, `turnos`, `pacientes`, `medicos`, `pagos`,
`obras-sociales`, `usuarios`, `dashboard`, `landing`, `ui`, `db`, `core`,
`security`, `uploads`, `mail`.

### Ejemplo completo

```
fix(turnos): prevent double booking on concurrent requests

Validar en PHP y despues insertar deja una ventana entre las dos
operaciones: con dos usuarios simultaneos ambos pasaban el chequeo
y se creaban turnos duplicados.

Se agrega un indice unico sobre una columna generada, de modo que la
garantia la da el motor y no el codigo.
```

**El cuerpo explica el porqué.** Un commit sin contexto obliga a reconstruirlo
leyendo el diff seis meses después.

## Antes de abrir un Pull Request

- [ ] `php -l` sin errores en cada archivo tocado
- [ ] Las pantallas afectadas cargan sin warnings de PHP
- [ ] Sin scroll horizontal en 375, 768 y 1280 px
- [ ] Consola del navegador sin errores
- [ ] Toda validación del cliente repetida en el servidor
- [ ] Sin código muerto ni duplicado
- [ ] Sin credenciales ni datos personales en el commit
- [ ] Documentación actualizada si cambió el comportamiento

## Revisión

Antes de fusionar se revisa:

1. **Correctitud** — ¿hace lo que dice? ¿hay casos límite sin cubrir?
2. **Seguridad** — ¿consultas preparadas? ¿salida escapada? ¿validación en servidor?
3. **Regresiones** — ¿rompe algo que funcionaba?
4. **Duplicación** — ¿esto ya existía en otro lado?
5. **Documentación** — ¿los comentarios explican el porqué?

## Qué NO subir

- `config/mail.php` — credenciales
- `probar_mail.php` — utilidad de instalación
- Fotos de perfil de usuarios
- Correos generados en `almacenamiento/mails/`
- Volcados de base con datos reales

Todo está cubierto por `.gitignore`, pero conviene revisar `git status` antes de
cada commit.

---
name: fpm-verify
description: Verifica un cambio de Food & Pantry Manager antes de darlo por terminado - elige las comprobaciones según los archivos modificados, investiga los fallos, corrige los causados por el cambio y resume resultados y siguiente paso.
argument-hint: "[qué se ha cambiado o 'completa']"
---

# Verificación de cambios — Food & Pantry Manager

Objetivo: $ARGUMENTS

Aplica `CLAUDE.md` (cargado en la sesión): no se repite aquí. `PROJECT-STATUS.md` ya está en contexto; abre otros documentos solo si la tarea los toca (p. ej. el `CA-xx` afectado en `PRD.md`).

## 1. Delimita el cambio
Identifica los archivos modificados en esta tarea (`git status --short` y `git diff --stat` cuando haya commits). Verifica solo lo que tu cambio puede afectar.

## 2. Elige las comprobaciones
| Archivos cambiados | Ejecuta |
|---|---|
| PHP en `app/`, `config/`, `database/`, `bootstrap/` | `composer lint:check`, `composer types:check`, `php artisan test` (primero `--filter` del área tocada; después la suite) |
| `routes/` o controladores | Lo anterior y además `npm run build` **antes** de `npm run types:check` (Wayfinder regenera `resources/js/{actions,routes}`) |
| `tests/` | `php artisan test` y `composer lint:check` |
| `resources/js/**` (`.vue`, `.ts`) | `npm run types:check`, `npm run check`, `npm run build` |
| `resources/css/**`, `vite.config.ts`, `tsconfig.json` | `npm run check`, `npm run build` |
| Solo documentos `*.md` | Sin tests: IDs `RN`/`CA`/`DT` y enlaces válidos, sin espacios finales |

**Batería completa** (`composer ci:check` y `npm run build`, más `npm audit` si cambiaron `composer.*` o `package*.json`) cuando: cambian dependencias o configuración, el cambio cruza backend y frontend, se cierra una fase o lo pide el usuario.

Los tests usan la configuración actual (`phpunit.xml`, `.env.testing` si existe). No la cambies para que pasen.

## 3. Si algo falla
1. Lee el error completo y localiza archivo y línea.
2. Determina el origen con pruebas:
   - **Introducido por el cambio**: el fallo está en archivos tocados o depende de ellos → corrígelo dentro del alcance autorizado y repite la comprobación.
   - **Preexistente**: también falla sin relación con el cambio (archivo no tocado, ya registrado en `PROJECT-STATUS.md` o `TECHNICAL-DESIGN.md`) → no lo corrijas sin autorización; infórmalo con la evidencia.
   - **Entorno**: solo con un mensaje que lo demuestre (extensión ausente, límite de memoria, servicio caído). Cita el mensaje.
3. Si no puedes determinarlo, dilo; no supongas.
4. Prohibido para "pasar": saltar o borrar tests, rebajar niveles de análisis, añadir ignores o overrides, o usar `git stash`/`reset` para comparar sin autorización.

## 4. Estado
Actualiza `PROJECT-STATUS.md` solo si cambió el estado, una decisión o el siguiente paso, según `CLAUDE.md` › "5. Cerrar la tarea". Marca como verificado únicamente lo comprobado en esta ejecución, con comando y fecha.

## 5. Informe final
- **Cambios:** archivos y propósito.
- **Comprobaciones:** comando → resultado real (fallos incluidos).
- **No ejecutado:** qué y por qué.
- **Fallos:** origen y evidencia.
- **Estado:** si se actualizó `PROJECT-STATUS.md` y qué.
- **Siguiente paso.**

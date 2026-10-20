# Estado del proyecto — Food & Pantry Manager

> Fuente de verdad del estado actual. Se carga automáticamente en cada sesión (importado desde `CLAUDE.md`). Mantenerlo breve y actualizar solo las secciones que cambien, según `CLAUDE.md` › "5. Cerrar la tarea".

**Última actualización:** 2026-10-20
**Objetivo final:** MVP de [PRD.md](PRD.md) publicado (Fase 7 de [ROADMAP.md](ROADMAP.md)).
**Fase actual:** 1 — Fundamentos, en curso. Fase 0 cerrada.
**Resumen:** esqueleto Laravel 13 con el kit Vue instalado y verificado. Sin código de dominio, sin conexión a MySQL (`.env` en SQLite), sin migraciones ejecutadas y sin commits.

## Siguiente paso
Obtener la decisión del usuario sobre la configuración de Claude Code del proyecto (decisión 1). No aplicar nada sin autorización.

## Esperando decisión del usuario
| # | Asunto | Propuesta | Preguntas abiertas |
|---|---|---|---|
| 1 | Configuración de Claude Code del proyecto | `.gitignore`: `/.claude` → `/.claude/settings.local.json`. Nuevo `.claude/settings.json` con: `deny` (`composer setup`, `migrate:fresh`/`reset`/`refresh`, `db:wipe`, `laravel new --force`, push forzado, lectura de `.env` y `.env.*` salvo `.env.example`); `ask` (migraciones, `db:seed`, cambios de dependencias, `shadcn-vue add`, `git push`, edición de los documentos principales); `allow` (comprobaciones de solo lectura). | a) ¿Se mantienen los añadidos `migrate:refresh`, `db:seed`, variantes de dependencias, `git push` en `ask` y la excepción de `.env.example`? b) `.env`: opción A (lo edita el usuario) u opción B (quitar temporalmente la regla). c) ¿Excluir `PROJECT-STATUS.md` de `ask` para no pedir confirmación en cada cierre? |
| 2 | Commit inicial | Hacerlo después de la decisión 1, para que incluya la configuración. | Rama y momento (según `CLAUDE.md`, rama propia si se está en `main`). |

## Hecho y verificado
| Qué | Cómo se verificó | Fecha |
|---|---|---|
| Documentación de la Fase 0 | Referencias cruzadas (`RN`/`CA`/`DT`, enlaces) sin errores | 2026-10-20 |
| Esqueleto Laravel 13.37.0, kit Vue y Pest 4.7.8 ([TECHNICAL-DESIGN.md §1 y §2.1](TECHNICAL-DESIGN.md)) | `composer show` / `npm ls`; hashes de los ocho documentos intactos | 2026-10-20 |
| Ajustes al kit ([TECHNICAL-DESIGN.md §2.2 y §2.3](TECHNICAL-DESIGN.md)) | `composer types:check`, `npm run check`, `npm explain tinypool` | 2026-10-20 |
| Siete comprobaciones: 40 tests, build y 0 vulnerabilidades npm | [TECHNICAL-DESIGN.md §7.1](TECHNICAL-DESIGN.md) | 2026-10-20 |
| Continuidad entre sesiones: `CLAUDE.md` importa este archivo y define el cierre de tareas | Referencias cruzadas y enlaces sin errores; carga en una sesión nueva **pendiente de comprobar** (ver Fase 1, punto 1) | 2026-10-20 |

## Declarado por el usuario (no verificado por Claude)
- Bases MySQL `food_pantry_manager` y `food_pantry_manager_test`, con los usuarios `food_pantry_app` y `food_pantry_app_test`, creadas y con conexión comprobada por el usuario (2026-10-20). Claude lo verificará al conectar MySQL.

## Pendiente en la Fase 1 (en orden)
1. Comprobar en una sesión nueva que `/memory` o `/context` muestran `CLAUDE.md` y `PROJECT-STATUS.md` cargados.
2. Decisiones 1 y 2 de la tabla anterior.
3. Conectar MySQL: `DB_*` en `.env` y `.env.testing` (las contraseñas las escribe el usuario); collation (DT-01); tests contra MySQL (DT-02).
4. Primera migración contra `food_pantry_manager`, con autorización.
5. Verificación de email no bloqueante en desarrollo (DT-09).
6. `APP_LOCALE=es` y textos del kit en español (DT-04).
7. `households`, `household_user`, hogar al registrarse, ámbito de hogar, policy base, layout y estados.

Criterios de cierre en [ROADMAP.md](ROADMAP.md) › Fase 1.

## Otras cuestiones pendientes
| Cuestión | Dónde | Cuándo |
|---|---|---|
| Retirar el override de `tinypool` | DT-10, [TECHNICAL-DESIGN.md §2.3](TECHNICAL-DESIGN.md) | En cada actualización del kit o de `vite-plus` |
| Una o varias recepciones por lista | [DATA-MODEL.md §8](DATA-MODEL.md) | Antes de la Fase 6 |
| Herramienta E2E (Playwright) | DT-03 | Antes de la Fase 6 |
| Email, correo, 2FA y passkeys en producción | DT-05 | Fase 7 |
| Despliegue, CI, backups y versión de MySQL en producción | DT-06, DT-07 | Fase 7 |
| Identidad visual, modo oscuro y navegación móvil | [DESIGN-SYSTEM.md §8](DESIGN-SYSTEM.md) | Antes de la Fase 7 |

## Riesgos conocidos
- MySQL 8.0 está en *Sustaining Support* desde el 21-04-2026: sin impacto en local; condiciona DT-07.
- El override de `tinypool` está fuera de la cadena oficial del kit (DT-10).
- `laravel new --force` borraría el repositorio ([TECHNICAL-DESIGN.md §1](TECHNICAL-DESIGN.md)).
- Las versiones reales son las de `composer.lock` y `package-lock.json` (el kit se instaló desde su rama `main`).

# Roadmap — Food & Pantry Manager

> Fases del MVP. Una fase se cierra solo cuando se cumplen todos sus criterios. Estado actual en [PROJECT-STATUS.md](PROJECT-STATUS.md); criterios `CA-xx` en [PRD.md §7](PRD.md); decisiones `DT-xx` en [TECHNICAL-DESIGN.md §8](TECHNICAL-DESIGN.md).

| Fase | Nombre | Depende de | Estado |
|---|---|---|---|
| 0 | Preparación documental | — | Cerrada |
| 1 | Fundamentos | 0 | En curso |
| 2 | Ingredientes e inventario | 1 | Pendiente |
| 3 | Recetas | 2 | Pendiente |
| 4 | Menú semanal | 3 | Pendiente |
| 5 | Cierre de menú y lista de la compra | 4 | Pendiente |
| 6 | Recepción de compra | 5 | Pendiente |
| 7 | Publicación | 6 | Pendiente |

El estado de cada tarea se registra solo en [PROJECT-STATUS.md](PROJECT-STATUS.md); aquí solo cambia el estado de la fase al abrirla o cerrarla.

## Fase 0 — Preparación documental
**Tareas:** redactar los ocho documentos; validar stack en fuentes oficiales; incorporar decisiones funcionales.
**Criterios de cierre**
- Los ocho documentos existen, sin contradicciones ni reglas duplicadas.
- Compatibilidad de Laravel 13, starter kit Vue, Node y testing verificada y registrada con fuentes.
- Ninguna decisión de producto o arquitectura bloquea la Fase 1.

## Fase 1 — Fundamentos
**Tareas** (cada instalación o acción sobre la base de datos, con autorización previa)
- Inicializar Laravel con el kit Vue, Fortify y Pest, sin WorkOS, teams ni Boost (DT-08), con el procedimiento de [TECHNICAL-DESIGN.md §1](TECHNICAL-DESIGN.md); registrar las versiones instaladas en §2.1.
- Bases `food_pantry_manager` y `food_pantry_manager_test` con usuarios dedicados.
- Configuración de Claude Code del proyecto y commit inicial, según lo que apruebe el usuario.
- Conectar MySQL: `.env`, `.env.testing` y tests contra la base de pruebas (DT-01, DT-02).
- Verificación de email no bloqueante en desarrollo (DT-09).
- `APP_LOCALE=es` y textos del kit en español (DT-04); zona horaria UTC.
- `households`, `household_user`; creación del hogar en el registro; ámbito de hogar y policy base.
- Layout, navegación y componentes de estado (vacío, carga, error, confirmación).

**Criterios de cierre**
- Suite Pest en verde contra MySQL de pruebas: registro con hogar, CA-01 y CA-02 base.
- `composer ci:check` y `npm run build` sin errores; `npm audit` sin alertas que no estén documentadas.
- `.env` y `.env.testing` fuera de Git; `.env.example` sin secretos.
- Navegación usable a 360 px.

## Fase 2 — Ingredientes e inventario
**Tareas:** enum de unidades y conversión g↔kg / ml↔l; ingredientes normalizados y alias; inventario con fila única por ingrediente, ubicación y unidad.
**Criterios de cierre:** CA-02 (ingredientes, inventario), CA-03, CA-04 en verde; estados vacío y error en ambas pantallas.

## Fase 3 — Recetas
**Tareas:** CRUD de recetas e ingredientes; escalado; archivado y restauración.
**Criterios de cierre:** CA-05 en verde; el borrado de una receta referenciada se rechaza (test, completa CA-16 en Fase 4).

## Fase 4 — Menú semanal
**Tareas:** menú por semana (Europe/Madrid); rejilla de 14 espacios con un plato por espacio; recetas y platos puntuales; indicador de completitud.
**Criterios de cierre:** CA-15, CA-16, CA-17 en verde; rejilla usable en escritorio y móvil.

## Fase 5 — Cierre de menú y lista de la compra
**Tareas:** cálculo con `bcmath`; cierre transaccional con bloqueo; advertencia por espacios vacíos; reapertura; conciliación y estado de compra; pantalla de lista editable.
**Criterios de cierre:** CA-06 a CA-13 en verde, incluidas pruebas de integración contra MySQL para CA-10.

## Fase 6 — Recepción de compra
**Prerrequisitos:** decidir recepciones por lista ([DATA-MODEL.md §8](DATA-MODEL.md)) y herramienta E2E (DT-03).
**Tareas:** formulario de recepción desde la lista; productos no previstos; confirmación transaccional e idempotente.
**Criterios de cierre:** CA-14 en verde; flujo menú → lista → recepción → inventario verificado (E2E o prueba manual documentada).

## Fase 7 — Publicación
**Tareas:** resolver DT-05, DT-06 y DT-07; revisión de seguridad y accesibilidad (WCAG 2.2 AA); configuración de producción; despliegue con autorización explícita.
**Criterios de cierre:** todos los CA verificados (incluido CA-18); copia de seguridad y restauración probadas; aplicación accesible en el entorno acordado.

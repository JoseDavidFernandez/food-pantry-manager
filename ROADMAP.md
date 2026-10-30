# Roadmap — Food & Pantry Manager

> Fases del MVP. Una fase se cierra solo cuando se cumplen todos sus criterios. Estado actual en [PROJECT-STATUS.md](PROJECT-STATUS.md); criterios `CA-xx` en [PRD.md §7](PRD.md); decisiones `DT-xx` en [TECHNICAL-DESIGN.md §8](TECHNICAL-DESIGN.md).

| Fase | Nombre | Depende de | Estado |
|---|---|---|---|
| 0 | Preparación documental | — | Cerrada |
| 1 | Fundamentos | 0 | Cerrada (2026-10-21) |
| 2 | Ingredientes e inventario | 1 | Cerrada (2026-10-21) |
| 3 | Recetas | 2 | Cerrada (2026-10-21) |
| 4 | Menú semanal | 3 | Cerrada (2026-10-21) |
| 5 | Cierre de menú y lista de la compra | 4 | Cerrada (2026-10-20) |
| 6 | Recepción de compra | 5 | Cerrada (2026-10-21) |
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
**Cambio posterior (2026-10-22):** cada espacio admite varios platos ordenados (RN-16, CA-27) y mover o soltar añade sin intercambiar ni sustituir (RN-33, CA-21 revisado). Pruebas y E2E actualizados y en verde.

## Fase 5 — Cierre de menú y lista de la compra
**Tareas:** cálculo con `bcmath`; cierre transaccional con bloqueo; advertencia por espacios vacíos; reapertura; conciliación y estado de compra; pantalla de lista editable.
**Criterios de cierre:** CA-06 a CA-13 en verde, incluidas pruebas de integración contra MySQL para CA-10.
**Cambio posterior (2026-10-21):** el cierre y la reapertura del menú se sustituyeron por la lista persistente por periodo (RN-36).
- CA-06 y CA-07 se retiraron, y CA-10 se redefinió (una lista por hogar y periodo, también ante peticiones simultáneas).
- La conciliación de RN-27 y RN-28 se mantiene.
- Pruebas actualizadas y en verde.

## Fase 6 — Recepción de compra
**Prerrequisitos:** decidir recepciones por lista ([DATA-MODEL.md §8](DATA-MODEL.md)) y herramienta E2E (DT-03).
**Tareas:** formulario de recepción desde la lista; productos no previstos; confirmación transaccional e idempotente.
**Criterios de cierre:** CA-14 en verde; flujo menú → lista → recepción → inventario verificado (E2E o prueba manual documentada).

## Fase 7 — Publicación
**Prerrequisito: cambios de producto (2026-10-21) — cumplido.** No son tareas de publicación. El usuario los validó en el navegador: la prueba manual intensiva el 2026-10-28 y el rediseño (con el modo oscuro), las categorías y la búsqueda real de imágenes el 2026-10-29 (registro en [PROJECT-STATUS.md](PROJECT-STATUS.md)). Alcance validado:
- el rediseño, las categorías (RN-31) y las imágenes (RN-32);
- la reorganización: Inventario como único apartado de alimentos, el calendario con recetario y arrastrar y soltar (RN-33), e Inicio como panel de resumen (RN-34);
- la compra por periodo: lista persistente sin cierre del menú, historial, «No comido» y clasificación automática (RN-31, RN-36, RN-37).

CA-19 a CA-22 están cubiertos por tests automáticos y, salvo la búsqueda real de imágenes, por la sesión E2E.
**Tareas** (cada despliegue o cambio en servicios externos, con autorización explícita):
1. **Versión de MySQL de producción (DT-07):** elegirla con el proveedor de hosting si ya está decidido; si no, valorar MySQL 8.4 LTS. Registrar la decisión en [TECHNICAL-DESIGN.md §8](TECHNICAL-DESIGN.md). La compatibilidad no se da por supuesta hasta la tarea 2.
2. **Compatibilidad con la versión elegida:** migraciones y suite Pest contra una base de pruebas aislada de esa versión (autenticación con `pdo_mysql`, restricciones CHECK, intercalaciones y claves foráneas), sin tocar la instalación de MAMP. Instalar o arrancar otra versión de MySQL requiere autorización explícita.
3. **Alerta de `vite-plus` y política de `npm audit`:** decidir entre migrar a `vite-plus` ≥ 1.1.0 (propuesta en [TECHNICAL-DESIGN.md §7.1](TECHNICAL-DESIGN.md), junto con DT-10) o aceptar la alerta con una mitigación documentada y fecha de revisión. Fijar qué parte de `npm audit` bloquea la CI a partir de resultados verificados en ese momento (por ejemplo, dependencias de producción frente al árbol completo).
4. **CI con MySQL** (DT-06), tras las tareas 1–3:
   - servicio MySQL con la versión elegida en la tarea 1;
   - pasos explícitos (`composer install`, `npm ci`, generar `APP_KEY`, migraciones, `composer ci:check`, `npm run build` y `npm audit` según la política de la tarea 3), sin depender de `composer setup`, que copia `.env.example` (SQLite) y migra sobre esa conexión;
   - migraciones y tests solo contra `food_pantry_manager_test`, con el guardián de `tests/TestCase.php`; en la CI no existe base de desarrollo;
   - credenciales efímeras y exclusivas de la CI, como *secrets* o variables del repositorio, nunca las de MAMP ni las de producción;
   - `APP_KEY` generada en cada ejecución.
5. **Entorno y despliegue:** proveedor, `APP_ENV=production`, `APP_DEBUG=false`, HTTPS, colas y tareas programadas si hacen falta, `storage` persistente (imágenes de ingredientes) y procedimiento de despliegue (DT-06).
6. **Copias de seguridad y restauración** de la base y de `storage/app/private`, probadas con una restauración real.
7. **Correo:** proveedor, remitente, verificación de email y recuperación de contraseña en producción (DT-05).
8. **Autenticación en producción:** verificación en dos pasos y llaves de acceso (DT-05).
9. **Seguridad:** revisión antes de publicar (cabeceras, CSP con los orígenes de imágenes de búsqueda, límites de peticiones, dependencias, comprobación de lo decidido en la tarea 3 sobre `vite-plus`).
10. **Accesibilidad:** WCAG 2.2 AA en las pantallas principales, a 360 px y en modo oscuro (CA-18).
11. **Proveedores de imágenes:** `INGREDIENT_IMAGES_CONTACT` con un contacto del servicio y la decisión de mantener activada la búsqueda. Si algún día se usa un proveedor con clave, la clave va solo en el servidor (TECHNICAL-DESIGN §10).

**Criterios de cierre:** todos los CA verificados (incluidos CA-18, CA-19 y CA-20); copia de seguridad y restauración probadas; CI en verde contra la versión de MySQL de producción; aplicación accesible en el entorno acordado.

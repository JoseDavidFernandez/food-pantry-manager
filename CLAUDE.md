# CLAUDE.md — Food & Pantry Manager

Instrucciones permanentes para Claude Code en este repositorio. Objetivo: aplicación web (Laravel 13 + Vue 3 con Inertia) para gestionar recetas, inventario doméstico, menús semanales y listas de la compra. Alcance del MVP en [PRD.md](PRD.md).

## Documentos de referencia (fuente de verdad)

Consulta el documento correspondiente antes de actuar. No dupliques su contenido aquí ni entre documentos: enlaza.

| Documento | Es la fuente de… |
|---|---|
| [PRD.md](PRD.md) | Reglas funcionales (`RN-xx`), alcance, exclusiones y criterios de aceptación (`CA-xx`). |
| [TECHNICAL-DESIGN.md](TECHNICAL-DESIGN.md) | Decisiones técnicas, versiones instaladas, compatibilidad, testing y pendientes técnicos (`DT-xx`). |
| [DATA-MODEL.md](DATA-MODEL.md) | Entidades, relaciones, unidades, cantidades, integridad y procesos de cálculo. |
| [USER-FLOWS.md](USER-FLOWS.md) | Recorridos de usuario. |
| [DESIGN-SYSTEM.md](DESIGN-SYSTEM.md) | Principios visuales, componentes, accesibilidad y responsive. |
| [ROADMAP.md](ROADMAP.md) | Fases, dependencias y criterios de cierre. |
| [PROJECT-STATUS.md](PROJECT-STATUS.md) | Estado real, verificaciones, decisiones pendientes y siguiente paso. Se carga automáticamente (final de este archivo). |

Si dos documentos se contradicen, detente y pregunta. Prioridad: PRD > DATA-MODEL > TECHNICAL-DESIGN > resto.

## Idioma y convenciones

- Conversación, documentación e interfaz: español.
- Código, identificadores, tablas, columnas y mensajes de commit: inglés.
- Cita reglas por su ID (`RN-21`, `CA-08`) en tests y PR cuando aporte trazabilidad.

## Protocolo de trabajo

### 1. Investigar
- Parte del estado cargado al final de este archivo y lee solo los documentos que afecten a la tarea.
- Inspecciona el código existente y `git status` antes de modificar nada.
- Para Laravel, Inertia, Vue, Tailwind, shadcn-vue, Pest u otras librerías, consulta documentación actual (Context7 o fuentes oficiales). **No inventes versiones ni APIs.** Las versiones reales son las de `composer.lock` y `package-lock.json`.

### 2. Planificar
- Para tareas de más de un archivo o con decisiones de diseño, presenta un plan breve: archivos afectados, reglas implicadas, tests previstos.
- Si una decisión no está en los documentos, **no la tomes por tu cuenta**: proponla y pregunta.
- Trabaja dentro de la fase actual del roadmap. No adelantes fases ni implementes exclusiones del MVP.

### 3. Implementar
- Cambios pequeños y enfocados. Sin refactors no solicitados.
- Lógica de negocio en acciones de dominio, no en controladores ni componentes Vue.
- Cantidades: solo en backend, con `bcmath`; nunca `float`.
- Respeta el estilo del código circundante y las convenciones del starter kit.

### 4. Verificar
- Comprobación completa: `composer ci:check` (formato, lint, tipos PHP y TypeScript, tests) y `npm run build`; añade `npm audit` si cambian dependencias. Comunica el resultado real, incluidos fallos.
- Todo criterio de aceptación tocado queda cubierto por un test.
- Revisa `git status` y `git diff` al terminar: solo cambios esperados.

### 5. Cerrar la tarea
Cuando una tarea cambie el estado, una decisión o el siguiente paso, actualiza **solo** las secciones afectadas de `PROJECT-STATUS.md`:
- **Hecho y verificado:** solo con el comando o la comprobación realizada y la fecha. Lo que no se pudo verificar va a pendientes, con el motivo.
- **Esperando decisión del usuario:** añade lo que propongas y no esté aprobado. Al resolverse, quítalo de ahí y registra la decisión en su documento fuente (PRD, TECHNICAL-DESIGN…).
- **Siguiente paso:** una sola acción concreta.
- **Fecha** de última actualización.

`ROADMAP.md` solo cambia al cerrar una fase o si cambian sus criterios. No copies contenido entre documentos: enlaza.

## Protección de datos y secretos

- Nunca muestres, copies, registres ni subas a Git valores de `.env`, `.env.testing`, contraseñas, tokens o claves.
- `.env*` con secretos nunca se versiona; `.env.example` solo con valores de ejemplo.
- No asumas contraseñas existentes de MySQL/MAMP; pídelas o pide al usuario que las configure.
- No envíes datos del proyecto ni del usuario a servicios externos sin autorización.
- Sin datos personales reales en seeders, factories ni tests.
- La suite automatizada de Laravel (Pest) usa siempre `food_pantry_manager_test` y nunca la base de desarrollo (`food_pantry_manager`).

### Excepción: sesiones E2E sobre la base de desarrollo

Por decisión expresa del usuario se permiten sesiones E2E con Playwright sobre la base de desarrollo local, solo cuando el usuario las pida expresamente para inspeccionar después los resultados en la interfaz. Procedimiento y salvaguardas en [TECHNICAL-DESIGN.md §7.2](TECHNICAL-DESIGN.md).
- Cada sesión requiere autorización expresa del usuario; no vale una autorización anterior.
- Antes de modificar datos: verificar `APP_ENV=local`, la base `food_pantry_manager` y que el hogar E2E designado no tiene otros miembros ni comparte datos con otros hogares.
- La limpieza se limita al hogar E2E designado y conserva usuarios, hogares, membresías y datos de otros hogares. Sin limpiezas globales ni comandos destructivos (`migrate:fresh`, `db:wipe`, `TRUNCATE`…).
- Nunca contra producción ni contra una aplicación publicada.
- Los datos E2E se conservan al terminar, salvo que el usuario pida lo contrario.

## Protección del trabajo existente

- No sobrescribas ni elimines archivos sin revisarlos y, si no es parte evidente de la tarea, sin preguntar.
- **Nunca** ejecutes `laravel new … --force` contra este repositorio (borra el directorio). Sigue el procedimiento de [TECHNICAL-DESIGN.md §1](TECHNICAL-DESIGN.md).
- Sin `git reset --hard`, `git checkout -- .`, `git clean`, `push --force` ni reescritura de historial sin autorización explícita.
- Sin commits ni push salvo petición expresa; si se piden y estás en `main`, crea una rama.
- No modifiques migraciones ya ejecutadas en entornos compartidos; crea una nueva.

## Operaciones que requieren autorización explícita

- **Destructivas:** borrar archivos o directorios, `migrate:fresh`, `migrate:reset`, `migrate:rollback`, `db:wipe`, `DROP`, `TRUNCATE`, borrar ramas.
- **Instalaciones:** `composer require/update/install`, `npm install/update`, `npx shadcn-vue add`, herramientas globales, inicialización de Laravel o del kit, Laravel Boost, cambios de versión de PHP o Node.
- **Base de datos:** crear bases o usuarios en MAMP, ejecutar migraciones o seeders contra la base de desarrollo.
- **Configuración:** `.env`, MAMP, permisos, hooks, skills, agentes, plugins o settings de Claude Code.
- **Despliegues y servicios externos:** despliegues, recursos en la nube, CI, webhooks, correo real.

Una autorización vale para la operación concreta, no para operaciones futuras similares.

## Skills y subagentes

- Úsalos solo cuando aporten valor claro; por defecto, trabaja directamente.
- Subagentes: búsquedas amplias o revisiones independientes que no necesiten el contexto de la conversación.
- Skills: cuando la tarea coincide con una disponible (revisión de código, revisión de seguridad antes de publicar).
- Resume al usuario lo relevante de lo que devuelvan.

## Estado actual

Cargado automáticamente desde `PROJECT-STATUS.md`:

@PROJECT-STATUS.md

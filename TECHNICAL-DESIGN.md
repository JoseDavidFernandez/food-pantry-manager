# Diseño técnico — Food & Pantry Manager

> Fuente de **decisiones técnicas y compatibilidad**. Reglas funcionales en [PRD.md](PRD.md); entidades en [DATA-MODEL.md](DATA-MODEL.md).
> Compatibilidad verificada el **2026-10-20** contra las fuentes de §9. Las versiones **instaladas** (§2.1) proceden de `composer show` y `npm ls` tras la inicialización del 2026-10-20; la fuente de verdad sigue siendo `composer.lock` y `package-lock.json`.

## 1. Entorno local

PHP 8.3.30 (extensiones `bcmath`, `intl`, `mbstring`, `pdo_mysql`, `pdo_sqlite` presentes; `memory_limit` de CLI: 128M), Composer 2.9.4, Node.js 22.22.1, npm 10.9.4, Laravel Installer 5.32.0, Git. MySQL 8.0.44 de MAMP en `127.0.0.1:3306`.

### Inicialización segura en este repositorio
El directorio del proyecto ya contiene documentación y `.git`. El instalador rechaza directorios existentes, y con `--force` sobre un directorio con nombre ejecuta `rm -rf` sobre él ([S10]). Por tanto:
- **Nunca** usar `laravel new … --force` apuntando a este repositorio.
- Generar la aplicación en un directorio temporal fuera del repositorio. Revisar colisiones de nombres con los ocho documentos y con `.gitignore`, y copiarla al repositorio sin sobrescribir la documentación.
- No dejar que el instalador inicialice Git ni haga commits (el repositorio ya existe).

Procedimiento aplicado el 2026-10-20:
1. `laravel new fpm-scaffold --vue --pest --no-boost --no-node --no-interaction` en un directorio temporal. Con el instalador ≥ 5.28, el hook del kit (`install:features`) se ejecuta al final de la creación; se conservaron todas sus funciones de autenticación.
2. Copia con `rsync -a --ignore-existing` (simulación previa con `--dry-run`), excluyendo `.git`, `vendor`, `node_modules`, `.env`, `database/database.sqlite` y `public/build`.
3. `composer install` y `npm install` dentro del repositorio; `.env` desde `.env.example` y `php artisan key:generate`.

Con el instalador 5.25.3 el kit actual no funciona bien: no aplaza `install:features`.

## 2. Stack y compatibilidad verificada

| Componente | Decisión | Verificación | Fuente |
|---|---|---|---|
| Laravel | 13.x (`^13`) | Requiere PHP ≥ 8.3; admite 8.3–8.5. Última 13.x publicada: v13.37.0 (2026-10-16). Correcciones de errores hasta Q3 2027, de seguridad hasta 17-03-2028. ✔ PHP 8.3.30. | [S1], [S7] |
| Starter kit | Oficial **Vue** (`laravel new` → Vue) | Vue 3 Composition API, TypeScript, Tailwind, shadcn-vue, Inertia 3, Vite. Autenticación con Laravel Fortify. | [S2] |
| Autenticación | Fortify integrada en el kit, **sin WorkOS** | Login, registro, recuperación de contraseña, verificación de email, 2FA y passkeys vienen activadas en `config/fortify.php`. | [S2], [S4] |
| Inertia | 3.x (adaptador Laravel `^3.0`, `@inertiajs/vue3` `^3.0`) | El adaptador Laravel 3 requiere PHP ≥ 8.2 y Laravel ≥ 11. ✔ | [S4], [S5] |
| Tailwind CSS | 4.x (`^4.1`) | Incluido en el kit. | [S4] |
| shadcn-vue | Incluido (componentes sobre `reka-ui`; iconos `@lucide/vue`; toasts `vue-sonner`) | Componentes adicionales con `npx shadcn-vue@latest add …` (requiere autorización: modifica dependencias/archivos). | [S2], [S4] |
| Vite | 8.x (`^8.0`) + Vite+ (`vite-plus`) | Vite requiere Node 20.19+ o 22.12+. ✔ Node 22.22.1. No se encontró requisito publicado de versión de npm. | [S4], [S6] |
| MySQL (desarrollo local) | 8.0 de MAMP, **solo para desarrollo local** | Laravel 13 admite MySQL 5.7+. ✔ Aviso: Oracle pasó MySQL 8.0 a *Sustaining Support* el 21-04-2026 (sin nuevas correcciones). | [S3], [S8] |
| MySQL (producción) | **Pendiente** (DT-07) | Comprobar con el proveedor de hosting qué versiones ofrece; valorar MySQL 8.4 LTS antes de publicar. | [S8] |

El kit se instaló desde su rama `main` (`dev-main`): sus versiones etiquetadas en Packagist (v1.0.x) son de Laravel 12.

### 2.1 Versiones instaladas (2026-10-20)

| Backend (Composer) | Versión | Frontend (npm) | Versión |
|---|---|---|---|
| PHP (entorno; restricción `^8.3`) | 8.3.30 | vue | 3.5.44 |
| laravel/framework | 13.37.0 | @inertiajs/vue3 | 3.9.1 |
| inertiajs/inertia-laravel | 3.5.1 | @inertiajs/vite | 3.9.1 |
| laravel/fortify | 1.41.0 | tailwindcss / @tailwindcss/vite | 4.3.3 |
| laravel/wayfinder | 0.1.23 | vite | 8.3.4 (paquete `vite` real, sin alias) |
| laravel/tinker | 3.0.2 | @vitejs/plugin-vue | 6.0.10 |
| pestphp/pest | 4.7.8 | laravel-vite-plugin | 3.2.0 |
| pestphp/pest-plugin-laravel | 4.1.0 | @laravel/vite-plugin-wayfinder | 0.1.11 |
| phpunit/phpunit | 12.5.33 | vite-plus (Vite+) | 0.3.0 (fijada por el kit) |
| larastan/larastan | 3.13.0 | oxfmt / oxlint (vía vite-plus) | 0.64.0 / 1.79.0 |
| phpstan/phpstan | 2.3.1 | typescript | 5.9.3 |
| laravel/pint | 1.32.1 | vue-tsc | 2.2.12 |
| laravel/sail | 1.69.0 | reka-ui | 2.11.0 |
| laravel/pail | 1.2.7 | @lucide/vue | 1.60.0 |
| | | @playwright/test (añadido el 2026-10-21, §7.2) | 1.64.1 |

### 2.2 Ajustes locales respecto al kit

| Archivo | Ajuste | Motivo |
|---|---|---|
| `.gitignore` | Se añade `.env.testing`. Desde el 2026-10-25 también se ignoran `/CLAUDE.md`, `/.claude/` y `/.impeccable/`. | `.env.testing` contendrá credenciales de pruebas. La configuración de Claude Code e Impeccable se mantiene solo en local (decisión del usuario, 2026-10-25). |
| `composer.json` | `types:check` → `phpstan analyse --memory-limit=1G`. | Con el `memory_limit` de 128M del PHP CLI, PHPStan agota la memoria en sus procesos paralelos. No se modifica el `php.ini` global. |
| `vite.config.ts` | `'/*.md'` en `fmt.ignorePatterns`. | `vp check` (oxfmt) revisaba también los documentos Markdown, que no siguen el formato del código. El patrón es de estilo gitignore con raíz en `vite.config.ts`, así que solo excluye los Markdown de la raíz. |
| `package.json` | `"overrides": { "oxfmt": { "tinypool": "2.1.2" } }` (temporal). | Ver §2.3. |

### 2.3 Override temporal `oxfmt → tinypool 2.1.2`

**Motivo:**
- El kit fija `vite-plus@0.3.0` → `oxfmt@=0.64.0` → `tinypool@2.1.0`.
- `tinypool` 2.1.0 está afectado por GHSA-5gmw-xhrv-c9v3 (corregido en 2.1.1) y GHSA-85c8-ppgw-ccpr (corregido en 2.1.2), ambos críticos [S13], [S14].
- Es una dependencia de desarrollo (herramientas de formato) y no forma parte del bundle.

**Por qué no se actualizó `vite-plus`:**
- Todas las versiones de `vite-plus` que traen `tinypool` ≥ 2.1.2 (0.3.2 y 0.3.3) exigen sustituir el paquete `vite` por el alias `npm:@voidzero-dev/vite-plus-core` [S15].
- Con 0.3.3, `npm run build` falla si se mantiene Vite real. Adoptar el alias cambiaría el Vite del stack aprobado.

**Alcance verificado:**
- Un único override, limitado a `tinypool` dentro de `oxfmt`.
- En el lock solo cambia `tinypool` (2.1.0 → 2.1.2).
- `npm audit`: 0 vulnerabilidades.
- `npm run check`, que ejecuta `oxfmt` con ese `tinypool`, pasa.

**Retirada.** El override se elimina cuando se cumplan las dos condiciones:
1. El proyecto adopta una versión de `vite-plus` cuya cadena resuelva `tinypool` ≥ 2.1.2 sin override, y es compatible con el Vite del stack o con un cambio de stack aprobado expresamente.
2. Sin el override, `npm explain tinypool` muestra ≥ 2.1.2, `npm audit` no muestra esas alertas y las siete comprobaciones de §7.1 pasan.

Revisarlo en cada actualización del kit o de `vite-plus`.

## 3. Arquitectura

Monolito Laravel con Inertia: el servidor resuelve rutas, autorización y datos; Vue renderiza páginas sin API REST pública. El kit usa **Wayfinder** para rutas tipadas en el frontend.

```
Navegador (Vue 3 + TS, páginas Inertia)
        ▼
Rutas web → Middleware (auth, ámbito de hogar)
        ▼
Controladores finos → Form Requests (validación) → Policies (autorización)
        ▼
Acciones de dominio (cierre, conciliación, recepción, cálculo)
        ▼
Modelos Eloquent → MySQL
```

- Lógica de negocio en clases de dominio testeables sin HTTP; controladores y componentes Vue sin reglas de negocio.
- Operaciones críticas en transacción con bloqueo de fila: cierre, reapertura, confirmación de recepción ([DATA-MODEL.md §6](DATA-MODEL.md)).
- Cálculos de cantidades solo en backend con `bcmath`; prohibido `float` ([DATA-MODEL.md §5](DATA-MODEL.md)).
- Ámbito de hogar centralizado (scope/consulta base) además de policies.
- Estructura de carpetas: la del kit (`resources/js/{components,composables,layouts,lib,pages,types}`).

## 4. Autenticación

- **Decisión:** autenticación del kit oficial con Fortify; sin proveedores externos (no WorkOS ni Socialite).
- **Teams del kit:** no se activa (`--teams` no). Su gestión de equipos e invitaciones queda fuera del MVP; el hogar se modela con `household_user` propio ([DATA-MODEL.md §2](DATA-MODEL.md)).
- **Registro:** se personaliza `app/Actions/Fortify/CreateNewUser.php` para crear usuario, hogar y pertenencia en una transacción.
- **Verificación de email:**
  - **Decisión:** en desarrollo no debe bloquear por falta de correo; su activación y el proveedor de correo se revisan en la Fase 7 (DT-05).
  - **Estado real tras instalar:** el kit trae `Features::emailVerification()` activado, `User` **sí** implementa `MustVerifyEmail` y las rutas de `routes/web.php` y parte de `routes/settings.php` usan el middleware `verified`. Con `MAIL_MAILER=log`, el enlace de verificación se escribe en el log, sin enviarse.
  - **Aplicado (DT-09):** el listener `App\Listeners\MarkEmailAsVerifiedInLocal` marca el email como verificado al registrarse **solo cuando `APP_ENV=local`**. En `testing` y `production` el flujo estándar (`MustVerifyEmail` + middleware `verified`) queda intacto; está cubierto por tests.
- **2FA y passkeys:** vienen activadas en el kit; se conservan tal como vienen y se revisan en la Fase 7 (no bloqueante).
- **Rate limiting:** el de Fortify (`FortifyServiceProvider`).

## 5. Seguridad

- Autorización por hogar con policies; test de acceso cruzado por recurso (CA-02).
- **Hogares y roles (V1, 2026-10-22; RN-01, RN-38, RN-39).** Las rutas se organizan en tres grupos:
  - Sin hogar (`auth`, `verified`): crear el hogar, el enlace de invitación, aceptar y rechazar.
  - Gestión del hogar (`+ household`): la página la ve cualquier miembro; las acciones de administración llaman a `Gate::authorize('household.manage')`.
  - Datos del hogar (`+ household`, `household.write`): `EnsureUserHasHousehold` envía a quien no tiene hogar a crearlo. `EnsureHouseholdWriteAccess` exige la *gate* `household.write` en toda petición que no sea GET o HEAD de ese grupo; así ninguna ruta queda sin proteger. Desde Inertia responde con un aviso y por HTTP directo con 403. La búsqueda de imágenes (GET a servicios externos) también exige `household.write`.

  Las *gates* se definen en `AppServiceProvider` a partir del rol de la pertenencia autenticada. Las policies por recurso siguen comprobando el hogar. La interfaz recibe `auth.household` (rol y permisos) solo para ocultar lo que el servidor rechazaría.

  Las pruebas recorren todas las rutas de escritura del grupo con identificadores reales (`RolePermissionTest`).
- **Tokens de invitación:** `Str::random(64)` (CSPRNG). Solo se guarda su hash SHA-256 (único). Caducan a los 7 días. Las rutas de crear, aceptar y rechazar tienen límite de peticiones. Si el destinatario no coincide, la respuesta es la misma que para un token inexistente.
- **Correo de invitación:** notificación por el mailer configurado. En desarrollo es `log` (`MAIL_MAILER=log`): escribe el correo en el registro y no envía nada, la interfaz lo indica y muestra el enlace una vez. En producción hace falta un proveedor real (DT-05, Fase 7).
- Validación con Form Requests. `$fillable` explícito; `household_id` nunca llega desde el cliente.
- Protección CSRF/origen: middleware `PreventRequestForgery` de Laravel 13 ([S1]).
- Secretos solo en `.env` (no versionado). `.env.example` sin valores reales.
- Datos personales limitados a nombre y email.

## 6. Base de datos

| Aspecto | Decisión |
|---|---|
| Servidor | Desarrollo local: MySQL 8.0.44 de MAMP, `127.0.0.1:3306`. Producción: pendiente (DT-07). |
| Base de desarrollo | `food_pantry_manager`, usuario `food_pantry_app`. Creadas por el usuario; conexión verificada por el usuario. |
| Base de pruebas | `food_pantry_manager_test`, usuario `food_pantry_app_test`. Separada de la de desarrollo; creadas y verificadas por el usuario. |
| Credenciales | Cada usuario tiene privilegios solo sobre su base. Las contraseñas las escribe el usuario en `.env` / `.env.testing` (ambos ignorados por Git); nunca en documentación, comandos persistentes ni Git. No se usa `root`. |
| Estado de la aplicación | Laravel **todavía no está conectado a MySQL**: `.env` usa `DB_CONNECTION=sqlite` y no se ha ejecutado ninguna migración. |
| Charset / collation | `utf8mb4` / `utf8mb4_unicode_ci` (valor por defecto de `config/database.php` de Laravel [S3]). Pendiente comprobar los valores por defecto del servidor MAMP. |
| Zona horaria | `config/app.php` en `UTC`; timestamps en UTC. La interfaz usa `Europe/Madrid` para mostrar fechas y calcular la "semana actual". Las fechas del menú son `DATE` (calendario, sin hora). |
| Locale | `APP_LOCALE=es` (el kit trae `en`). |

- El instalador usa SQLite por defecto y ejecuta migraciones al crear el proyecto ([S2], [S7]). La configuración de MySQL y la primera migración contra MAMP se hacen después, con la base y el usuario creados y con autorización.
- `php artisan test --parallel` crea bases con sufijo (`…_test_1`, `…_test_2`) ([S9]); si se usa, el usuario necesita privilegios sobre ese patrón. No es necesario en el MVP.
- Migraciones reversibles. Nunca `migrate:fresh`, `migrate:reset` ni `db:wipe` sin autorización.

## 7. Testing

| Nivel | Herramienta | Decisión |
|---|---|---|
| Backend | **Pest 4** (instalado 4.7.8) | Instalado con `--pest`, que sustituye el PHPUnit directo del kit (PHPUnit 12.5.33 queda como dependencia de Pest). **Pest 5 requiere PHP ≥ 8.4** [S11], por eso con PHP 8.3.30 se resolvió Pest 4 [S12]. |
| Integración | Pest contra **MySQL** (`food_pantry_manager_test`) | Obligatorio para lo que dependa de MySQL: cierre concurrente, únicos, FK, `DECIMAL`, collation. El `phpunit.xml` del kit usa SQLite en memoria [S4]: se reconfigurará en la Fase 1. |
| Aislado | Pest unitario; SQLite en memoria solo si aporta | Conversión, escalado, redondeo, normalización y conciliación sin depender de MySQL. |
| Estático | Pint, Larastan (PHPStan, nivel 7), `vue-tsc`, `vp check` (Vite+) | Vienen en el kit (`composer lint:check`, `composer types:check`, `npm run types:check`, `npm run check`). `vp check` comprueba formato (oxfmt) y lint (oxlint). Ajustes locales en §2.2. |
| Frontend | Ninguna adicional | No se añade Vitest sin necesidad demostrada. |
| E2E | **Playwright** (`@playwright/test` 1.64.1, Chromium) | Flujos críticos por el navegador (§7.2). Resuelve DT-03 (2026-10-21). |

Reglas: cada `CA-xx` tiene al menos un test; los tests de Pest nunca usan la base de desarrollo; no se añaden dependencias de testing sin necesidad demostrada. Excepción decidida por el usuario (2026-10-21): la sesión E2E de §7.2 trabaja sobre la base de desarrollo para dejar datos inspeccionables.

### 7.1 Verificación de la inicialización (2026-10-20)

Siete comprobaciones satisfactorias:

| # | Comando | Resultado |
|---|---|---|
| 1 | `composer types:check` | 0 errores |
| 2 | `composer lint:check` | Correcto |
| 3 | `npm run types:check` | Correcto |
| 4 | `npm run check` | 68 archivos con formato correcto; 62 sin avisos de lint |
| 5 | `npm run build` | Correcto |
| 6 | `php artisan test` | 40 tests, 138 aserciones (tests del kit, SQLite en memoria según `phpunit.xml`) |
| 7 | `npm audit` | 0 vulnerabilidades conocidas en esa auditoría |

**Aviso de `npm audit` (revisado el 2026-10-21):** 1 alerta en `vite-plus` ≤ 1.0.0, que `npm audit` muestra como alta [S16][S17].
- **Origen:** `launch-editor` ≤ 2.14.1, que va empaquetado en el Vite interno de `@voidzero-dev/vite-plus-core` 0.3.0. No es un paquete independiente.
- **Avisos:** GHSA-698v-4r3x-3vcv (solo Windows, `cmd.exe`), GHSA-587h-7wmj-h5pm (solo Windows) y GHSA-r825-w8mm-5pxw (inyección de argumentos con nombres de archivo que empiezan por `+`/`-`; en Vite solo afecta a WSL). Severidad moderada en GitHub (CVSS v4 6.3).
- **Corrección:** `launch-editor` 2.14.2 y `vite-plus` ≥ 1.1.0. El `vite` real del proyecto (8.3.4) ya incluye la corrección (≥ 8.3.3).
- **Alcance:** solo el servidor de desarrollo (`vp dev`, middleware `/__open-in-editor`). El build y producción no lo incluyen; en este equipo (macOS) no se dan las condiciones de los avisos. No la introdujo Playwright.
- **Propuesta (no aplicada):** migrar a `vite-plus` 1.3.0 en una rama propia, junto con DT-10.
  - **Riesgos:** cambia la resolución de `vite` (alias a `vite-plus-core`); `oxfmt` 0.64 → 0.73 y `oxlint` 1.79 → 1.88 pueden reformatear y señalar archivos; `vitest` 4 → 5, que no se usa; hay que revisar el override de `tinypool`.
  - **Comprobaciones:** `npm run build`, `npm run check`, `vue-tsc`, `npm run test:e2e` y `npm audit`.
  - **Mitigación mientras tanto:** no exponer `vp dev` fuera de `localhost` y no usarlo en Windows ni en WSL.
- **Revisado de nuevo el 2026-10-22, sin cambios:**
  - `npm audit` da 1 vulnerabilidad alta: `vite-plus` 0.3.0 instalado, afectado ≤ 1.0.0, por GHSA-587h-7wmj-h5pm (alta) y otros dos avisos moderados. La corrección disponible es `vite-plus` 1.3.0 (cambio mayor).
  - `composer audit` no tiene avisos.
  - Las sesiones E2E usan el build (`global-setup` se detiene si hay `public/hot`), así que no exponen el servidor de desarrollo.
  - La migración sigue pendiente de decisión del usuario y no se aborda en la V1 multiusuario.

### 7.2 Sesión E2E con Playwright (2026-10-21)

- `npm run test:e2e` (`playwright.config.ts`, pruebas en `e2e/`). Arranca `php artisan serve` en `127.0.0.1:8000` (o reutiliza el que esté en marcha) y usa los assets compilados (`npm run build`; se detiene si hay `public/hot`).
- **Solo con autorización expresa del usuario en cada sesión**; una autorización anterior no vale para otra sesión. Nunca contra producción ni contra una aplicación publicada.
- **Base de desarrollo, a propósito:** los datos que crea se quedan para revisarlos en el navegador. No se limpia al terminar, salvo que el usuario lo pida.
- **Usuario dedicado** `e2e@food-pantry.test`, registrado por la interfaz en la primera ejecución. Su contraseña, exclusiva de desarrollo, se genera en `e2e/.auth/credentials.json` (fuera de Git).
- **Antes de cada ejecución** (`e2e/global-setup.ts`): comprueba `APP_ENV=local` y la base `food_pantry_manager` (si no, se detiene), y vacía solo el hogar del usuario E2E con `E2eHouseholdResetSeeder`. Primero enumera lo que se borrará; exige que el hogar no tenga otros miembros y que ningún otro hogar use sus ingredientes. Usuario, hogar y membresía se conservan.
- **Sin limpieza (2026-10-21):** `E2E_EMAIL=<otro correo> E2E_NO_RESET=1 npm run test:e2e` ejecuta la sesión con otro usuario dedicado, que se registra con un hogar nuevo y vacío. No se borra nada.
  - `global-setup` se detiene si ese usuario ya existe.
  - Cada usuario guarda sus credenciales en su propio archivo de `e2e/.auth/`.
  - Se usó con `e2e-periodo@food-pantry.test` (hogar 3) para no reiniciar el hogar 2, que guarda la revisión visual del usuario.
- Evidencias (capturas) en `e2e/evidence/`; informe HTML en `e2e/report/`; ambos fuera de Git.
- La suite de Pest sigue en `food_pantry_manager_test` (`phpunit.xml` y la salvaguarda de `tests/TestCase.php`).

## 8. Decisiones técnicas pendientes (no bloquean la Fase 1)

| ID | Decisión | Momento |
|---|---|---|
| DT-01 | Comprobar que el charset/collation de las bases ya creadas es `utf8mb4` / `utf8mb4_unicode_ci`. | Fase 1, al conectar MySQL. |
| DT-02 | Reconfigurar el entorno de tests (`phpunit.xml` / `.env.testing`) para MySQL de pruebas. | Fase 1. |
| DT-03 | E2E: **resuelta**, Playwright sobre la base de desarrollo con usuario dedicado (§7.2). | Cerrada (2026-10-21). |
| DT-04 | Traducción al español de los textos del kit y estrategia de i18n. | Fase 1 (textos) / posterior (i18n). |
| DT-05 | Verificación de email, proveedor de correo, 2FA y passkeys en producción. | Fase 7. |
| DT-06 | Destino de despliegue, CI y copias de seguridad. | Fase 7. |
| DT-07 | Versión de MySQL en producción: comprobar con el proveedor de hosting. MySQL 8.0 queda solo para desarrollo local (ya sin correcciones); valorar MySQL 8.4 LTS, recomendada por Oracle [S8], y verificar que los tests de integración pasan en esa versión. | Antes de publicar (Fase 7). |
| DT-08 | Laravel Boost: **resuelta**, no instalado (`--no-boost`). Solo se instalaría con autorización expresa. | Cerrada (2026-10-20). |
| DT-09 | Verificación de email no bloqueante en desarrollo: **resuelta**, listener solo para `APP_ENV=local` (§4). Requisito: producción nunca debe usar `APP_ENV=local`. | Cerrada (2026-10-20). |
| DT-10 | Retirar el override `oxfmt → tinypool` cuando se cumplan las condiciones de §2.3. | En cada actualización del kit o de `vite-plus`. |

## 9. Fuentes (consultadas el 2026-10-20)

- [S1] Laravel 13 — Release Notes: https://laravel.com/docs/13.x/releases
- [S2] Laravel 13 — Starter Kits: https://laravel.com/docs/13.x/starter-kits
- [S3] Laravel 13 — Database: https://laravel.com/docs/13.x/database
- [S4] Repositorio oficial del kit Vue (`composer.json`, `package.json`, `phpunit.xml`, `.env.example`, `config/fortify.php`, rama `main`): https://github.com/laravel/vue-starter-kit
- [S5] Inertia v3 — Upgrade guide: https://inertiajs.com/docs/v3/getting-started/upgrade-guide
- [S6] Vite — Getting Started (v8.3.4): https://vite.dev/guide/
- [S7] Laravel 13 — Installation: https://laravel.com/docs/13.x/installation
- [S8] MySQL — EOL notices: https://www.mysql.com/support/eol-notice.html
- [S9] Laravel 13 — Testing: https://laravel.com/docs/13.x/testing
- [S10] Laravel installer (`src/NewCommand.php`): https://github.com/laravel/installer
- [S11] Pest — Installation: https://pestphp.com/docs/installation
- [S12] Packagist (metadatos de `pestphp/pest`, `pestphp/pest-plugin-laravel`, `laravel/framework`): https://packagist.org
- [S13] GitHub Advisory GHSA-5gmw-xhrv-c9v3 (tinypool ≤ 2.1.0, corregido en 2.1.1): https://github.com/advisories/GHSA-5gmw-xhrv-c9v3
- [S14] GitHub Advisory GHSA-85c8-ppgw-ccpr (tinypool < 2.1.2, corregido en 2.1.2): https://github.com/advisories/GHSA-85c8-ppgw-ccpr
- [S15] Registro npm (metadatos de `vite-plus` 0.3.0–0.3.3, `oxfmt` y `tinypool`): https://www.npmjs.com/package/vite-plus
- [S16] GitHub Advisory GHSA-r825-w8mm-5pxw (launch-editor ≤ 2.14.1; vite-plus ≤ 1.0.0, corregido en 1.1.0): https://github.com/advisories/GHSA-r825-w8mm-5pxw
- [S17] GitHub Advisory GHSA-698v-4r3x-3vcv (solo Windows): https://github.com/advisories/GHSA-698v-4r3x-3vcv
- [S18] Open Food Facts — API (límites por IP, User-Agent obligatorio): https://openfoodfacts.github.io/documentation/docs/Product-Opener/api/
- [S19] Open Food Facts — Condiciones de uso (fotos CC BY-SA 3.0, atribución con enlace): https://world.openfoodfacts.org/terms-of-use
- [S20] Wikimedia Foundation — User-Agent Policy: https://foundation.wikimedia.org/wiki/Policy:Wikimedia_Foundation_User-Agent_Policy
- [S21] MediaWiki — Extension:CommonsMetadata (`imageinfo` + `extmetadata`: autor y licencia): https://www.mediawiki.org/wiki/Extension:CommonsMetadata
- [S22] Unsplash API Guidelines (clave, hotlinking obligatorio, 50 peticiones/h en demo): https://help.unsplash.com/api-guidelines/unsplash-api-guidelines
- [S23] Pixabay API (clave, sin hotlinking permanente, caché de 24 h): https://pixabay.com/api/docs/
- [S24] Pexels API (clave, 200 peticiones/h y 20 000/mes, atribución): https://www.pexels.com/api/documentation/

## 10. Imágenes de ingredientes (2026-10-21)

**Decisión.** Siempre hay un icono por categoría, local y sin dependencias. Además, una imagen opcional que el usuario busca y elige:

| Fuente | Uso | Clave | Límites | Licencia y atribución | Español y cobertura |
|---|---|---|---|---|---|
| **Wikimedia Commons** (elegida) | Fotos genéricas de ingredientes, por nombre | No | Sin cupo publicado; User-Agent identificativo obligatorio [S20] | Libres (CC BY, CC BY-SA, dominio público…), con autor y licencia por imagen [S21] | Busca en títulos y descripciones; calidad variable, por eso se elige entre resultados |
| **Open Food Facts** (elegida) | Productos envasados, por nombre o código de barras | No | 10 búsquedas/min y 15 lecturas de producto/min por IP [S18] | Fotos CC BY-SA 3.0; atribución con enlace al producto [S19] | Buena en productos españoles; no sirve para genéricos («arroz» devuelve marcas) |
| Unsplash | Fotos | Sí | 50/h en demo | Obliga a usar sus URL (hotlinking) [S22] | Descartada: cada visualización depende de su CDN |
| Pixabay | Fotos | Sí | 100/min | Prohíbe el hotlinking permanente; caché de 24 h [S23] | Viable con clave; no necesaria |
| Pexels | Fotos | Sí | 200/h, 20 000/mes | Atribución recomendada [S24] | Viable con clave; no necesaria |
| Spoonacular / TheMealDB | Ingredientes | Sí / limitada | Cupos diarios | Licencia de imágenes no explícita | Descartadas: nombres en inglés y licencia poco clara |

**Funcionamiento** (`App\Actions\Ingredients\Images`):
- **Búsqueda:** `GET ingredients/images/search?source=photos|products|barcode&q=…`, limitada a 20 peticiones/min por usuario. Solo se ejecuta cuando el usuario pulsa «Buscar» y envía solo el texto escrito. Un fallo del proveedor (tiempo de espera, error HTTP o respuesta inválida) se devuelve como mensaje y siempre se puede continuar sin imagen.
- **Elección:** solo cuenta lo que el usuario elige expresamente. El servidor vuelve a consultar al proveedor con la referencia (título del archivo o código de barras), nunca descarga una URL enviada por el navegador (sin SSRF) y solo descarga por HTTPS desde `upload.wikimedia.org`, `images.openfoodfacts.org` o `static.openfoodfacts.org`. Además limita el tamaño a 2 MB, comprueba los bytes (JPEG, PNG o WebP) y sigue sin redirecciones.
- **Almacenamiento:** una copia local en el disco privado (`storage/app/private/ingredient-images/{hogar}/…`), junto con el autor, la licencia y los enlaces que exige la licencia. Las licencias de Commons y Open Food Facts permiten copiar con atribución. La imagen se sirve desde `GET ingredients/{id}/image` solo a los miembros del hogar, con caché privada (la URL lleva versión). Si el proveedor retira la imagen, la copia sigue funcionando; si falla al cargar, la interfaz muestra el icono.
- **Configuración** (`config/services.php` › `ingredient_images`, sin secretos): `INGREDIENT_IMAGES_ENABLED` (por defecto `true`), `INGREDIENT_IMAGES_CONTACT` (contacto para el User-Agent; por defecto `APP_URL`, nunca datos personales) e `INGREDIENT_IMAGES_TIMEOUT` (segundos, por defecto 6). No hace falta ninguna clave de API. Si se añadiera un proveedor con clave (Pexels, Pixabay), iría solo en `.env` del servidor, nunca en el frontend ni en el repositorio.
- **Pruebas:** con `Http::fake()` y `Http::preventStrayRequests()`, así que la suite nunca llama a servicios reales. Las pruebas E2E no usan la búsqueda de imágenes, para no depender de terceros.

## 11. Mejoras funcionales de la V1 (2026-10-22)

### 11.1 Comparativa con aplicaciones similares
Consultada el 2026-10-22. «Oficial» = documentación, cadenas de la interfaz, changelog o ayuda del fabricante; «Tienda» = ficha de App Store o material de marketing; «—» = no se pudo verificar.

| Tema | Grocy | Mealie | AnyList | Paprika | KitchenPal | MealBoard |
|---|---|---|---|---|---|---|
| Varios platos por comida | Secciones del plan (oficial, cadenas) | Etiquetas desayuno/comida/cena/snack, varias entradas por día (oficial) | Varias recetas por día en el calendario (oficial, ayuda) | Planificador (tienda) | — | Por día y tipo de comida (tienda) |
| Inventario frente a lista | «Put missing products on shopping list» (oficial) | Lista desde el plan, escalada; sin despensa propia | Combina artículos iguales en la lista (oficial, notas de versión 2020) | Despensa; si se resta al generar la lista: fuentes contradictorias | Despensa y recetas por ingrediente (tienda) | Despensa y lista combinada (tienda) |
| Conversiones por producto | «QU conversions» por producto (oficial) | Conversión métrico/imperial, v3.29.0 (oficial) | — | — | — | — |
| Formato de compra | Unidad de compra y de stock por producto (oficial) | — | — | — | — | — |
| Alternativas | «Parent product» y desactivar la comprobación de stock por ingrediente (oficial) | «Substitutions» en ingredientes, v3.26.0 (oficial) | — | — | — | — |
| Subrecetas | «Included recipes» con raciones; corrección «a recipe cannot be included in itself» (oficial, changelog) | No nativas: enlaces en markdown (discusiones #1761 y #858) | — | — | — | — |
| Hogar compartido | Multiusuario en una instancia | Hogares con plan y listas; enlaces de invitación por hogar desde 2.3.0 (fuente secundaria) | Hogar compartido por invitación de correo (oficial) | Sincronización entre dispositivos (tienda) | Listas compartidas; plan familiar (tienda) | — |
| Invitado que ya tiene hogar | — | — | — | — | — | — |

**Conclusiones aplicadas:**
- **Conversiones y formato de compra:** como en Grocy, viven en cada producto y no en una tabla universal. Plan to Eat y RealPlans combinan solo unidades compatibles; esta app hace lo mismo hasta que el alimento define su equivalencia (RN-40, RN-41).
- **Subrecetas con raciones y rechazo de ciclos:** el modelo de «Included recipes» de Grocy es el más completo verificado (RN-43).
- **Alternativas:** Mealie las guarda como texto; aquí son líneas reales con elección por plato, para que la lista sea calculable (RN-42).
- **Invitación a quien ya tiene hogar:** ninguna fuente documenta este caso. Se eligió la opción más conservadora: explicar, no tocar nada y mantener la invitación pendiente.

Fuentes:
- Grocy: [github.com/grocy/grocy](https://github.com/grocy/grocy), [grocy.info](https://grocy.info), [strings.pot](https://raw.githubusercontent.com/grocy/grocy/master/localization/strings.pot), [changelog](https://grocy.info/changelog).
- Mealie: [funcionalidades](https://mealie.io/documentation/getting-started/features/) y [versiones](https://github.com/mealie-recipes/mealie/releases).
- AnyList: [ayuda](https://help.anylist.com).
- Paprika, KitchenPal y MealBoard: fichas de App Store.

### 11.3 Fase A: existencias previstas y «Guardar lo marcado» (2026-10-22)
- **Migraciones aditivas**, revisadas con `--pretend` y aplicadas en desarrollo:
  - `2026_10_23_100000_create_stock_events_table`, con un recuento inicial por cada una de las 105 filas existentes, verificado fila a fila;
  - `2026_10_23_100001_create_recipe_revisions_table`.
- **Componentes:**
  - `StockEstimator`: recorrido cronológico por fila.
  - `RecipeCompositions`: composición vigente en un momento.
  - `DishPlan`: previsto y límite por hora de referencia.
  - `StockManager`: registra los movimientos.
  - `ReceptionRecorder`: modo parcial.
- **Zona horaria:** `valid_until` y `occurred_at` se guardan en la zona de la aplicación. Al consultar se compara el mismo instante (`setTimezone(config('app.timezone'))`), nunca la hora local de Madrid como texto.
- **Rendimiento:** el recorrido parte del primer movimiento del hogar. Medido sobre los datos de desarrollo: de 0 a 9 ms por hogar. Si creciera, la optimización sería un punto de control interno sin tocar lo registrado.
- **Pruebas:** las factorías de existencias registran su recuento, como la migración. Las propiedades del modelo por fila se comprueban con secuencias aleatorias de semilla fija (generador propio, para no alterar los datos falsos de las factorías).

### 11.4 Fase B: lista más clara y básicos de casa (2026-10-23)
- **Migración aditiva** `2026_10_22_300000_add_restock_to_ingredients`: cuatro columnas nulas y el CHECK `ingredients_restock_check` (DATA-MODEL §2). Revisada con `--pretend` y aplicada en desarrollo: 243 ingredientes, ninguno con reposición; el resto de sus columnas, sin cambios.
- **`FoodRestock`**: mínimo, «Se acabó» (transacción con el alimento bloqueado; cada fila pasa por `StockManager::remove`, que registra el recuento a 0) y reposición activa. La única termina con un `add` posterior (comparación estricta del instante, como en §11.3).
- **`PeriodRequirements`** suma la reposición en la clave canónica del alimento (`FoodUnits`), de modo que comparte línea, conversiones, redondeo y envase con las recetas.
- **Rutas** `PUT ingredients/{ingredient}/minimum` y `POST ingredients/{ingredient}/depleted`, dentro del grupo `household.write` y con la policy del alimento.

### 11.2 Decisiones técnicas
- **Varios platos (RN-16):**
  - Migración `allow_several_dishes_per_menu_slot`: añade `position` y el índice (`menu_id`, `day`, `meal`, `position`) antes de retirar el único, para que la FK de `menu_id` conserve un índice.
  - Las rutas pasan a ser por plato (`menu/dishes/{dish}`), resuelto siempre dentro del hogar (404 si no).
  - `MenuPlanner` renumera dentro de una transacción con el menú bloqueado.
- **Un único modelo de unidades** para la receta, las existencias y la compra (DATA-MODEL §4.1): `FoodUnits` (grafo en árbol por alimento) y `FoodUnitsMap` (una consulta por cálculo).
  - Se descartó una tabla de equivalencias universal: un «paquete» o una «bolsa» no tienen el mismo contenido en dos alimentos.
  - Se descartó guardar todo en una unidad base: cambiaría las existencias que escribió el usuario.
- **Alternativas y preparaciones** en un único punto, `RecipeExpansion`, que usan el menú, la lista, el periodo y la página de la receta.
  - La elección se guarda por plato y por `recipe_id` + grupo, de modo que sobrevive a la edición de la receta, que borra y vuelve a crear sus líneas.
  - Se descartó guardar la elección en la receta: impediría elegir distinto en dos días.
  - Se descartó aplicar la propuesta sin decirlo, porque lo prohíbe el punto 5.
- **Sin ciclos:** se rechazan en la validación y otra vez dentro de la transacción de guardado, con las recetas del hogar bloqueadas (`RecipeCycle` → error de validación).
- **Migraciones** (todas aditivas, `--pretend` revisado y aplicadas en desarrollo con autorización de la tarea):
  - `2026_10_22_200000_allow_several_dishes_per_menu_slot`;
  - `2026_10_22_200001_add_conversions_and_purchase_unit_to_ingredients`;
  - `2026_10_22_200002_add_alternatives_and_components_to_recipes`.
- **Arrastre:** el asa del plato deja pasar el puntero. El E2E arrastra los platos con movimientos intermedios (`dragDish`), porque `dragTo` pulsa en el centro de la tarjeta, donde están sus botones.

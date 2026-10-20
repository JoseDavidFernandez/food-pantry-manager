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

### 2.2 Ajustes locales respecto al kit

| Archivo | Ajuste | Motivo |
|---|---|---|
| `.gitignore` | Se quita `/CLAUDE.md` y se añade `.env.testing`. | `CLAUDE.md` debe versionarse; `.env.testing` contendrá credenciales de pruebas. |
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
  - **Pendiente:** aplicar la decisión es una tarea de la Fase 1 (DT-09).
- **2FA y passkeys:** vienen activadas en el kit; se conservan tal como vienen y se revisan en la Fase 7 (no bloqueante).
- **Rate limiting:** el de Fortify (`FortifyServiceProvider`).

## 5. Seguridad

- Autorización por hogar con policies; test de acceso cruzado por recurso (CA-02).
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
| E2E | **Playwright: a evaluar** | Para los flujos críticos (menú → lista → recepción). Decidir antes de la Fase 6 (DT-03). |

Reglas: cada `CA-xx` tiene al menos un test; los tests nunca usan la base de desarrollo; no se añaden dependencias de testing sin necesidad demostrada.

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

## 8. Decisiones técnicas pendientes (no bloquean la Fase 1)

| ID | Decisión | Momento |
|---|---|---|
| DT-01 | Comprobar que el charset/collation de las bases ya creadas es `utf8mb4` / `utf8mb4_unicode_ci`. | Fase 1, al conectar MySQL. |
| DT-02 | Reconfigurar el entorno de tests (`phpunit.xml` / `.env.testing`) para MySQL de pruebas. | Fase 1. |
| DT-03 | Playwright (u otra opción) para E2E. | Antes de la Fase 6. |
| DT-04 | Traducción al español de los textos del kit y estrategia de i18n. | Fase 1 (textos) / posterior (i18n). |
| DT-05 | Verificación de email, proveedor de correo, 2FA y passkeys en producción. | Fase 7. |
| DT-06 | Destino de despliegue, CI y copias de seguridad. | Fase 7. |
| DT-07 | Versión de MySQL en producción: comprobar con el proveedor de hosting. MySQL 8.0 queda solo para desarrollo local (ya sin correcciones); valorar MySQL 8.4 LTS, recomendada por Oracle [S8], y verificar que los tests de integración pasan en esa versión. | Antes de publicar (Fase 7). |
| DT-08 | Laravel Boost: **resuelta**, no instalado (`--no-boost`). Solo se instalaría con autorización expresa. | Cerrada (2026-10-20). |
| DT-09 | Aplicar en el código que la verificación de email no bloquee el desarrollo (hoy `User` implementa `MustVerifyEmail` y hay rutas con `verified`; ver §4). | Fase 1. |
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

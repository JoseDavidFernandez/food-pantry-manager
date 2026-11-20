# Despliegue a producción

## Entorno
- Servidor: VPS de IONOS con Plesk.
- Dominio: foodpantry.josedavidfernandezcomino.com
- Base de datos: MariaDB 10.5.
- Correo: Brevo.
- PHP 8.3 y Node 26 (ver «La carpeta de la aplicación»).

En este documento, `<usuario>` es el usuario del sistema de la suscripción y
`<servidor>` la IP o el nombre del VPS. No se escriben aquí a propósito.

## La carpeta de la aplicación
- La app **no** está en `httpdocs`, sino en `~/food-pantry-manager` (dentro de
  la suscripción).
- **Todos los comandos por SSH se lanzan desde esa carpeta:**
  ```
  ssh <usuario>@<servidor>
  cd ~/food-pantry-manager
  ```
- Node 26 se fija con el archivo `.node-version` de esa carpeta. Fuera de ella,
  nodenv no sabe qué versión usar y `node` (y `npm`) fallan.
- PHP 8.3 llega por el shim de phpenv.
- `public/build` no está en el repositorio (`.gitignore`): los assets se
  compilan en el servidor con `npm run build`, que lanza `vp build`.

## Acceso SSH
- El usuario del sistema tiene acceso SSH con `/bin/bash`.
- Solo con clave **ed25519**, subida con `ssh-copy-id`. La contraseña del
  usuario es larga, aleatoria y no se usa.
- SSH tiene que estar activo porque el script de Deployment del Laravel Toolkit
  solo se puede editar con SSH activado.

## Fail2Ban
Instalado y activo en el servidor, con las jails:
- `ssh`
- `plesk-panel`
- `recidive`
- `plesk-apache`

## Cabeceras de seguridad
Las cabeceras **no están en el repositorio**: se ponen en Apache, en Plesk →
Websites & Domains → (el dominio) → Apache & nginx Settings → **Additional
directives for HTTPS**. Laravel no envía ninguna de estas cabeceras.

### Las directivas
```
Header always set Strict-Transport-Security "max-age=31536000"
Header always set X-Content-Type-Options "nosniff"
Header always set X-Frame-Options "DENY"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"
Header always unset X-Powered-By
Header unset X-Powered-By
Header always set Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: https://upload.wikimedia.org https://images.openfoodfacts.org https://static.openfoodfacts.org; font-src 'self'; connect-src 'self'; manifest-src 'self'; media-src 'none'; worker-src 'none'; frame-src 'none'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'"
```
- HSTS va sin `includeSubDomains`: solo protege este subdominio.
- `X-Frame-Options: DENY` y `frame-ancestors 'none'` dicen lo mismo (la app no
  se puede incrustar en ningún sitio); la primera es para navegadores antiguos.
- `X-Powered-By` se quita dos veces porque PHP-FPM la añade en respuestas que
  no pasan por `always`.

### La CSP, directiva a directiva
Sin `'unsafe-inline'`, `'unsafe-eval'` ni hashes: la app no tiene scripts ni
estilos inline.

| Directiva | Por qué |
|---|---|
| `script-src 'self'` | Los módulos de Vite (`/build/assets`) y `/theme-init.js`, que aplica el modo oscuro antes de pintar. |
| `style-src 'self'` | El CSS de Vite y `/errors.css` (páginas de error y de mantenimiento). La barra de progreso de Inertia va con `includeCSS: false` y sus estilos en `app.css`. Los `:style` de Vue y de los componentes se aplican por CSSOM, que la CSP permite. |
| `img-src` | Las imágenes de los alimentos se guardan y se sirven desde la app; los tres hosts externos son las **miniaturas de la búsqueda de imágenes** (Wikimedia Commons y Open Food Facts), que carga el navegador. `data:` como en el resto de proyectos. |
| `font-src 'self'` | Archivo, servida desde el build. |
| `connect-src 'self'` | Peticiones de Inertia. La búsqueda de imágenes pasa por el servidor. |
| `form-action 'self'` | Todos los formularios y sus redirecciones son del mismo origen. |
| `frame-src 'none'`, `frame-ancestors 'none'` | La app no incrusta ni se deja incrustar. |
| `manifest-src`, `media-src`, `worker-src`, `object-src`, `base-uri` | Cerradas o limitadas al propio origen: la app no usa vídeo, audio, workers ni plugins. |

**Hay que cambiarla si:**
- se añade un script, estilo, fuente o imagen de otro origen;
- la v2 quita la sección Imagen: entonces se retiran los tres hosts de `img-src`;
- se activa el SSR de Inertia: revisar el HTML que genera;
- se vuelve a escribir un `<script>`, un `<style>` o un `style="…"` en una
  plantilla Blade (`tests/Feature/ContentSecurityPolicyTest.php` lo detecta).

### Probar un cambio de la CSP
1. Poner la nueva política como
   `Header always set Content-Security-Policy-Report-Only "…"` (misma lista),
   sin quitar la que está en vigor. El navegador avisa en la consola, pero no
   bloquea nada.
2. Recorrer la app con la consola abierta, filtrando por «Content Security
   Policy»: login, 2FA y passkeys, Ajustes (Apariencia en claro, oscuro y
   sistema, recargando; Seguridad con el QR; Hogar e invitaciones), Inventario
   con la búsqueda de imágenes, Recetas, Menú, Lista de la compra, una URL
   inexistente y una navegación lenta (DevTools → «Slow 4G») para ver la barra
   de progreso.
3. Sin avisos, sustituir la política en vigor por la nueva y quitar la línea
   `Report-Only`.

### Comprobar que están puestas
```
curl -sI https://foodpantry.josedavidfernandezcomino.com/login
```
Deben salir las seis cabeceras de arriba y ningún `X-Powered-By`.

## Cómo llega el código
Plesk Git descarga el repositorio de GitHub por SSH usando una deploy key de solo lectura:
- La clave está en `/var/www/vhosts/foodpantry.josedavidfernandezcomino.com/.ssh/id_rsa`.
- En GitHub está registrada como «Plesk VPS - Food Pantry Manager».
- El repositorio que gestiona Plesk está en `.../git/food-pantry-manager`.

## Despliegue de un clic (Laravel Toolkit → Deployment)
El despliegue está en modo **Manual**: nada se despliega solo al hacer push;
hay que pulsar **Deploy**.

Pasos marcados en Laravel Toolkit → Deployment:
- Paso 1: activar el modo mantenimiento.
- Paso 4: `composer install`.
- Paso 5: `npm install`.
- Paso 6: script de despliegue (abajo).
- Paso 7: quitar el modo mantenimiento.

Script de despliegue (paso 6):
```
npm run build
php artisan migrate --force
php artisan optimize
```

### Para desplegar
1. Si el despliegue incluye una **migración**, comprobar antes que hay una copia
   de seguridad reciente de la base de datos (ver «Copias de seguridad»).
2. Hacer push a `main` en GitHub.
3. En Plesk: Laravel Toolkit → Deployment → **Deploy**.
4. Verificar el despliegue (siguiente apartado).

### Verificar un despliegue
Por SSH, desde `~/food-pantry-manager`:
```
cd ~/food-pantry-manager
ls -l public/build/manifest.json bootstrap/cache/config.php
php artisan migrate:status
```
- Las fechas de `manifest.json` y `config.php` deben ser de la hora del
  despliegue. **El servidor va en UTC.**
- `migrate:status` no debe mostrar migraciones pendientes.

## Copias de seguridad
Las copias se hacen con **Backup Manager** de Plesk, a nivel de servidor: cubren
esta suscripción y la de VyaMap. Probadas con una restauración real en
noviembre de 2026.

### Qué se copia
- Configuración, correo, archivos y bases de datos.
- Se excluyen los archivos de log («Exclude log files») y lo que se regenera en
  cada despliegue con `composer install` y `npm run build`. Las exclusiones van
  separadas por comas y son relativas a cada suscripción (las de `/httpdocs`
  son de VyaMap):
  ```
  /httpdocs/node_modules, /httpdocs/vendor, /httpdocs/public/build, /food-pantry-manager/node_modules, /food-pantry-manager/vendor, /food-pantry-manager/public/build
  ```

### Programación
- Diaria, de madrugada (hora del servidor, **UTC**).
- Incremental, con una copia completa cada semana.
- Se guardan como máximo **2 copias completas**, y las manuales también
  cuentan: una copia completa manual puede hacer que se borre la completa
  programada más antigua.
- Aviso por correo si una copia falla.

### Copia fuera del servidor
Backup Manager guarda las copias en el propio VPS: si se pierde el servidor, se
pierden con él. Por eso, una vez a la semana:
1. Backup Manager → copia completa más reciente → **Download**, protegida con
   contraseña. La contraseña está en el gestor de contraseñas: sin ella la copia
   no se puede restaurar.
2. Comprobar que el archivo pesa lo que indica Plesk. En macOS sale algo más,
   porque Plesk cuenta 1 MB = 1.048.576 bytes.
3. Guardarlo fuera del equipo (disco externo) con la fecha en el nombre y borrar
   las copias de hace más de un mes.

Más adelante, esta descarga puede sustituirse por un script que, por SSH, se
traiga los volcados de las bases de datos, el `.env` y `storage`.

### Antes de desplegar una migración
- En Backup Manager, comprobar que la última copia programada está en verde.
- Si desde esa copia hay datos que no se quieren perder, exportar un volcado
  solo de la base (**Databases → Export Dump**). No es una copia de Backup
  Manager, así que no cuenta para el máximo de 2 completas.

### Restaurar
- **Antes de restaurar**, hacer una copia del estado actual, por si el resultado
  no es el esperado.
- **Solo la base de datos** (lo habitual): Backup Manager → la copia →
  **Restore** → **Selected objects** → **Database** → la base de Food Pantry.
  Una suscripción cada vez. No hace falta volver a desplegar.
- **Subscription** restaura la suscripción entera, **también los archivos**
  (código, `.env` y `storage`). Solo si se ha perdido todo. Después hay que
  desplegar (**Deploy**), porque `vendor`, `node_modules` y `public/build` no
  están en la copia.
- Al terminar, entrar en la aplicación y comprobar que están los datos.

## Comprobar qué commit está desplegado
En el servidor:
```
cd /var/www/vhosts/foodpantry.josedavidfernandezcomino.com/git/food-pantry-manager
git -c safe.directory='*' log -1 --oneline
```
Debe coincidir con el último commit de GitHub.

## Problemas conocidos
- **«Permission denied (publickey)» al hacer Pull:** no crear un `/root/.ssh/config` para github.com. Ese archivo obliga a usar otra clave y Plesk deja de poder descargar.
- **«dubious ownership» al usar git como root:** usar `git -c safe.directory='*' …` en lugar de añadir una excepción global.
- **`node` falla por SSH:** se está fuera de `~/food-pantry-manager` y nodenv no
  encuentra `.node-version`. Hacer `cd ~/food-pantry-manager` primero.

## Plan B: despliegue manual (si el script falla)
Los pasos antiguos, por si falla el despliegue del Toolkit:
1. Si hay migración, comprobar que hay copia de seguridad reciente de la base de datos (ver «Copias de seguridad»).
2. En Plesk: Subscriptions → foodpantry… → Git → **Pull** y después **Deploy**.
3. Por SSH, desde `~/food-pantry-manager`:
   ```
   cd ~/food-pantry-manager
   npm install
   npm run build
   ```
   - Si cambia `composer.lock`: `composer install --no-dev --optimize-autoloader`.
   - Si hay migraciones: `php artisan migrate --force`.
4. En Laravel Toolkit: ejecutar **optimize** (`php artisan optimize`).
5. Verificar como en «Verificar un despliegue».

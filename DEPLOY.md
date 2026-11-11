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

## Cómo llega el código
Plesk Git descarga el repositorio privado de GitHub por SSH usando una deploy key de solo lectura:
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
   de seguridad reciente de la base de datos.
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
1. Si hay migración, comprobar que hay copia de seguridad reciente de la base de datos.
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

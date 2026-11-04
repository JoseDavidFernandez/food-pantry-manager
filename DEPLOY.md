# Despliegue a producción

## Entorno
- Servidor: VPS de IONOS con Plesk.
- Dominio: foodpantry.josedavidfernandezcomino.com
- Base de datos: MariaDB 10.5.
- Correo: Brevo.
- El código se despliega en la carpeta `food-pantry-manager/` de la suscripción (no en `httpdocs`).

## Cómo funciona
Plesk Git descarga el repositorio privado de GitHub por SSH usando una deploy key de solo lectura:
- La clave está en `/var/www/vhosts/foodpantry.josedavidfernandezcomino.com/.ssh/id_rsa`.
- En GitHub está registrada como «Plesk VPS - Food Pantry Manager».
- El repositorio que gestiona Plesk está en `.../git/food-pantry-manager`.
- El modo de despliegue es manual.

## Pasos para desplegar
1. Hacer push a `main` en GitHub.
2. En Plesk: Subscriptions → foodpantry… → Git → **Pull**.
3. Pulsar **Deploy**.
4. Acciones después del despliegue: <!-- COMPLETAR con lo que viste en Plesk o haces a mano -->

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
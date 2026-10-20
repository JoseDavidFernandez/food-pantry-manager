# PRD — Food & Pantry Manager

> Fuente principal de **reglas funcionales** (`RN-xx`) y **criterios de aceptación** (`CA-xx`). Decisiones técnicas en [TECHNICAL-DESIGN.md](TECHNICAL-DESIGN.md); entidades e integridad en [DATA-MODEL.md](DATA-MODEL.md); recorridos en [USER-FLOWS.md](USER-FLOWS.md).

## 1. Problema

Planificar las comidas de la semana y hacer la compra en casa suele implicar:

- Recetas dispersas, sin cantidades ni raciones claras.
- Desconocimiento de lo que ya hay en el frigorífico y la despensa, con compras duplicadas u olvidos.
- Listas de la compra hechas a mano, sin relación con el menú previsto.
- Ingredientes repetidos con nombres distintos ("tomate", "tomates"), que impiden sumar necesidades.

## 2. Objetivos

1. Mantener un recetario reutilizable con ingredientes, cantidades y raciones base.
2. Conocer el inventario doméstico (frigorífico y despensa) con unidades prácticas.
3. Planificar un menú semanal de 14 espacios (comida y cena, de lunes a domingo).
4. Generar, al cerrar el menú, una lista de la compra que sume las necesidades y descuente lo que ya hay en casa.
5. Registrar lo realmente comprado y actualizar el inventario con cantidades reales.

## 3. Usuarios

- **MVP:** una persona usuaria autenticada que gestiona **un único hogar**.
- **Futuro:** varios miembros por hogar con roles. El modelo lo prepara (ver [DATA-MODEL.md](DATA-MODEL.md)) sin implementarlo.

## 4. Alcance del MVP

| Módulo | Incluye |
|---|---|
| Autenticación | La del starter kit oficial (registro, login, logout, recuperación de contraseña). Sin proveedores externos. |
| Hogar | Un hogar creado automáticamente para cada usuario. |
| Ingredientes | Catálogo normalizado del hogar con alias explícitos. |
| Inventario | Productos en frigorífico y despensa con cantidad y unidad. |
| Recetas | Alta, edición, consulta, archivado y borrado (si no está referenciada). |
| Menú semanal | 14 espacios; receta guardada o plato puntual; cierre y reapertura. |
| Lista de la compra | Generada al cerrar el menú; editable; marcado de comprado. |
| Recepción de compra | Desde una lista: cantidades reales, productos no previstos, actualización del inventario. |
| Interfaz | Responsive, con estados vacíos, de carga, de error y confirmaciones. |

## 5. Fuera del MVP

- Macros e información nutricional.
- Integración con WhatsApp.
- Roles (administrador, editor, lector) y gestión de miembros e invitaciones.
- Fechas de caducidad y códigos de barras.
- Importaciones automáticas (recetas, tickets, webs).
- Descuento automático de inventario al cocinar.
- Funcionalidades de inteligencia artificial.
- Proveedores de autenticación externos (redes sociales, WorkOS).
- Recepciones de compra sin lista y recepciones en borrador.

## 6. Reglas de negocio

### Hogar y acceso
- **RN-01** Cada usuario pertenece a un único hogar en el MVP. Ingredientes, inventario, recetas, menús, listas y recepciones pertenecen al hogar, no al usuario.
- **RN-02** Un usuario solo puede ver y modificar datos de su hogar.

### Ingredientes y unidades
- **RN-03** Un ingrediente es único dentro del hogar por su nombre normalizado (sin distinguir mayúsculas, tildes ni espacios sobrantes).
- **RN-04** Los sinónimos y plurales se resuelven con **alias explícitos** creados por el usuario ("tomates" → "tomate"). Un alias apunta a un solo ingrediente y no puede coincidir con otro alias ni con el nombre de otro ingrediente. **Nunca** se fusionan ingredientes automáticamente por similitud.
- **RN-05** Unidades del MVP: pieza (unidad), bote, paquete, bandeja, g, kg, ml y l. Cada ingrediente usa las que correspondan.
- **RN-06** Solo se convierte automáticamente entre g↔kg y entre ml↔l, y únicamente al combinar cantidades de ese par (sumar o restar); en ningún otro caso se cambia la unidad elegida por el usuario.
  - Pieza, bote, paquete y bandeja conservan siempre su unidad. El MVP no define equivalencias entre ellas ni con peso o volumen (p. ej. bote↔g, pieza↔paquete); introducir una equivalencia explícita y fiable sería una decisión futura.
  - Al combinar g con kg (o ml con l), el resultado se expresa en kg (l) si es ≥ 1 kg (1 l) y en g (ml) si es menor. Si todas las cantidades combinadas usan la misma unidad, esa unidad se conserva.
- **RN-07** Las cantidades guardadas son siempre mayores que cero. Ningún cálculo produce cantidades negativas.

### Inventario
- **RN-08** Cada producto de inventario está en una ubicación: frigorífico o despensa. Existe una sola fila por ingrediente, ubicación y unidad compatible (g y kg comparten fila; ml y l también). Las cantidades con unidades incompatibles permanecen en filas separadas.
- **RN-09** El inventario solo cambia por edición directa del usuario o por la confirmación de una recepción (RN-30).
- **RN-10** Planificar recetas no reserva ni descuenta stock.
- **RN-11** Cocinar no descuenta inventario.

### Recetas
- **RN-12** Una receta tiene raciones base (entero ≥ 1) e ingredientes con cantidad y unidad. Una receta sin ingredientes se puede guardar, pero el plato que la use se considera incompleto (RN-18).
- **RN-13** Al planificar una receta con N raciones, cada cantidad se escala por `N / raciones_base`.
- **RN-14** Una receta referenciada por algún plato planificado no se puede eliminar; se puede **archivar**. Una receta archivada no se ofrece al planificar, sigue visible en los menús que la usan y se puede restaurar. Una receta sin referencias se puede eliminar con confirmación.

### Menú semanal
- **RN-15** Un menú corresponde a una semana de calendario de lunes a domingo y tiene 14 espacios: comida y cena de cada día. Solo existe un menú por hogar y semana.
- **RN-16** Cada espacio está vacío o contiene **un único** plato planificado: una receta guardada o un plato puntual.
- **RN-17** Un plato puntual tiene nombre, raciones y uno o varios ingredientes con cantidad y unidad.
- **RN-18** Cierre del menú:
  - Si algún plato está incompleto (sin ingredientes, o con un ingrediente sin cantidad o unidad), el cierre se **bloquea** indicando día, comida y dato que falta.
  - Si hay espacios vacíos, se muestra una **advertencia** con su número y el cierre requiere confirmación explícita.
- **RN-19** Un menú cerrado es de solo lectura y conserva su lista. Cerrar un menú ya cerrado no tiene efecto.

### Lista de la compra
- **RN-20** La lista se genera únicamente al cerrar explícitamente el menú. Cada menú tiene como máximo una lista; cerrar repetidamente no crea listas ni líneas duplicadas.
- **RN-21** Cálculo:
  1. Sumar las necesidades de todos los platos (escaladas según RN-13).
  2. Agrupar por ingrediente y unidad compatible.
  3. Restar el inventario compatible de ambas ubicaciones.
  4. Conservar solo los grupos con resultado mayor que cero.
- **RN-22** Si existe inventario del mismo ingrediente en una unidad incompatible, no se resta y la línea se marca para revisión.
- **RN-23** Las cantidades en unidades indivisibles (pieza, bote, paquete, bandeja) se redondean **hacia arriba** al entero en la lista, después de restar el inventario. g, kg, ml y l no se redondean. El redondeo no modifica recetas ni inventario.
- **RN-24** La lista es editable: añadir, modificar y eliminar líneas. Añadir un artículo cuyo ingrediente y unidad compatible ya existen en la lista modifica esa línea en lugar de crear otra. Se admiten líneas de texto libre sin ingrediente.
- **RN-25** Marcar una línea como comprada no modifica el inventario.

### Reapertura y regeneración
- **RN-26** Reabrir un menú cerrado requiere confirmación explícita. Mientras está reabierto, el menú es editable y la lista se conserva sin cambios (y sigue siendo editable).
- **RN-27** Al volver a cerrar, la lista se recalcula según RN-21 a RN-23 y se concilia línea a línea por ingrediente y unidad compatible:

| Línea existente | Nuevo cálculo | Resultado |
|---|---|---|
| Generada, sin cambios del usuario | Hay cantidad | Se actualiza la cantidad. |
| Generada, sin cambios del usuario | Ya no hace falta | Se elimina, salvo si estaba comprada (se conserva y se marca para revisión). |
| Generada, modificada por el usuario | Cambia respecto al cálculo anterior | Se conserva la cantidad del usuario y se marca para revisión mostrando el nuevo cálculo. |
| Generada, modificada por el usuario | No cambia | Sin cambios. |
| Generada, descartada por el usuario | Cambia respecto al cálculo anterior | Reaparece con la nueva cantidad, marcada para revisión. |
| Generada, descartada por el usuario | No cambia o ya no hace falta | Sigue descartada. |
| Manual | Hay cantidad para el mismo ingrediente y unidad | Se conserva intacta y se marca para revisión mostrando el cálculo; no se crea otra línea. |
| Manual | Cualquier otro caso | Sin cambios. |
| No existe | Hay cantidad | Se crea una línea generada. |

- **RN-28** Estado de compra tras recalcular: una línea comprada sigue comprada si su nueva cantidad es menor o igual que la que tenía. Si aumenta, se desmarca y se marca para revisión. La marca de revisión desaparece cuando el usuario edita la línea o la da por revisada.

### Recepción de compra
- **RN-29** Una recepción parte siempre de una lista existente y se registra y confirma en una sola operación (sin borradores).
  - La cantidad prevista de cada línea se muestra solo como **sugerencia editable**.
  - El usuario decide explícitamente, línea a línea, entre:
    - **Recibido:** confirma o corrige la cantidad realmente adquirida (igual, mayor o menor que la prevista), la unidad y la ubicación destino.
    - **No comprado.**
  - No se puede confirmar la recepción mientras quede alguna línea sin decidir. Marcar una línea como comprada en la lista no equivale a recibirla (RN-25).
  - Se pueden añadir productos no previstos con ingrediente, cantidad, unidad y ubicación.
- **RN-30** Al confirmar, solo las líneas recibidas y los productos no previstos se suman al inventario, respetando RN-06 y RN-08 (se agregan a la fila compatible existente o se crea una nueva). Las líneas no compradas no modifican el stock. La confirmación no se puede aplicar dos veces.

## 7. Criterios de aceptación

Verificables mediante tests automatizados o prueba manual documentada.

| ID | Criterio | Reglas |
|---|---|---|
| CA-01 | Un usuario no autenticado no accede a ninguna página salvo las de autenticación. | — |
| CA-02 | Un usuario no puede leer ni modificar recursos de otro hogar (403/404). | RN-02 |
| CA-03 | Crear "Tomate" cuando existe "tomate " se rechaza como duplicado. Con el alias "tomates", escribir "Tomates" resuelve a "tomate". "Tomate pera" se crea como ingrediente distinto. | RN-03, RN-04 |
| CA-04 | 500 g + 1 kg del mismo ingrediente = 1,5 kg en una línea; 200 g + 300 g = 500 g. 1 bote + 200 g = dos líneas, cada una en su unidad. 2 paquetes + 1 paquete = 3 paquetes (nunca se expresan en g). | RN-06, RN-21 |
| CA-05 | Una receta de 4 raciones con 200 g de arroz planificada para 6 raciones requiere 300 g. | RN-13 |
| CA-06 | No se puede cerrar un menú con un plato puntual sin cantidades; el mensaje identifica día, comida y dato. | RN-18 |
| CA-07 | Cerrar un menú con 3 espacios vacíos muestra la advertencia; solo se genera la lista tras confirmar. | RN-18 |
| CA-08 | Con 1 kg de arroz necesario y 400 g en despensa, la lista muestra 600 g. Con 2 kg, el arroz no aparece. | RN-21 |
| CA-09 | Necesidad de 1,5 botes sin inventario → la lista muestra 2 botes; receta e inventario no cambian. | RN-23 |
| CA-10 | Cerrar, reabrir y volver a cerrar deja una sola lista. Dos peticiones de cierre simultáneas generan una sola lista. | RN-20 |
| CA-11 | Tras reabrir y volver a cerrar: una línea manual persiste; una línea generada editada conserva la cantidad del usuario y se marca para revisión si el cálculo cambió. | RN-27 |
| CA-12 | Una línea comprada sigue comprada si el nuevo cálculo es igual o menor; se desmarca y se marca para revisión si aumenta. | RN-28 |
| CA-13 | Marcar una línea como comprada no cambia ninguna cantidad del inventario. | RN-25 |
| CA-14 | Confirmar una recepción con 3 botes recibidos (2 previstos) y un producto no previsto suma exactamente esas cantidades. 1 kg recibido sobre 500 g en la misma ubicación deja una fila de 1,5 kg. Una línea marcada "No comprado" no cambia el inventario. No se puede confirmar con líneas sin decidir. Reenviar la confirmación no suma de nuevo. | RN-29, RN-30 |
| CA-15 | Planificar o quitar una receta del menú no modifica el inventario. | RN-10 |
| CA-16 | Una receta usada en un menú no se puede eliminar pero sí archivar; archivada no aparece al planificar. | RN-14 |
| CA-17 | El lunes 26/10/2026 a las 00:30 (Europe/Madrid) la "semana actual" empieza el 26/10/2026, aunque en UTC aún sea domingo. | RN-15 |
| CA-18 | Las pantallas principales son usables a 360 px y muestran estados vacío, carga y error. | — |

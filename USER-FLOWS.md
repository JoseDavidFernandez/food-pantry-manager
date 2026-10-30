# Flujos de usuario — Food & Pantry Manager

> Recorridos del MVP. Las reglas se citan por ID ([PRD.md §6](PRD.md)) y no se redefinen aquí; procesos internos en [DATA-MODEL.md §6](DATA-MODEL.md); patrones visuales en [DESIGN-SYSTEM.md](DESIGN-SYSTEM.md).

Convenciones: **[C]** requiere confirmación · **[E]** estado vacío · **[X]** error posible.

## 0. Acceso
1. Registro → «Tu hogar» (RN-01): crear un hogar (nombre propuesto «Hogar de …»; se es su administrador) o unirse a una de las invitaciones dirigidas a ese correo. Hasta hacerlo, cualquier pantalla del hogar lleva aquí, también tras cerrar sesión y volver.
   - **Enlace de invitación** (RN-39): sin sesión, lleva a iniciar sesión o registrarse con el correo invitado y vuelve al enlace. Muestra quién invita, el hogar y el rol, y permite unirse o rechazar. Si no es para la cuenta, se responde como si no existiera; si está caducada, revocada o usada, se informa.
   - **Invitación a quien ya tiene hogar** (2026-10-22): la página explica que cada cuenta pertenece a un solo hogar y ofrece las opciones: salir antes de su hogar desde Ajustes › Hogar (si RN-38 lo permite; si no, por qué) o rechazar. Nada se acepta ni se abandona automáticamente y la invitación sigue pendiente; también aparece en Ajustes › Hogar, «Invitaciones a otros hogares».
   - **Ajustes › Hogar:** todos los miembros ven el nombre, los miembros y sus roles (el propio, como etiqueta) y pueden salir del hogar, salvo que RN-38 lo impida, en cuyo caso se explica el motivo. El único miembro de un hogar vacío puede salir y el hogar se elimina, tras confirmarlo. El administrador además renombra el hogar, cambia los roles de los demás, retira miembros e invita (correo y rol), ve el enlace una sola vez para copiarlo y revoca las invitaciones pendientes.
   - **Eliminar la cuenta** (Ajustes › Perfil, RN-38): si no se puede (único administrador con más miembros, o único miembro de un hogar con datos), la sección explica el motivo y qué hacer en lugar del botón. Si se puede, se confirma con la contraseña. Los datos del hogar se quedan en él, y un hogar vacío sin miembros se elimina.
   - **Cabecera:** en todas las páginas, el nombre del hogar real con enlace a Ajustes › Hogar; recortado con puntos suspensivos si no cabe.
   - **Lector:** aviso «Modo lectura» en todas las páginas y sin acciones de modificación.
2. Login, logout y recuperación de contraseña del starter kit. En desarrollo no se exige verificar el email.
3. Inicio (RN-34), por orden de prioridad:
   1. **Hoy:** comida y cena del día actual en un bloque destacado, con enlace a la receta. Si falta alguna, «Sin planificar» y «Planificar comida» o «Planificar cena».
   2. **Menú de la semana:** tabla compacta de los siete días con comida y cena; hoy resaltado; el recuento «X de 14» o, si no hay menú, «Sin planificar». El menú no tiene estado: se planifica de forma continua (desde el 2026-10-21).
   3. **Lista de la compra** de los próximos 7 días (RN-36): hasta seis líneas pendientes con su cantidad, «y N productos más» y «Ver la lista completa». Si no falta nada: «No te falta nada para las recetas de los próximos 7 días».
   4. Accesos a Inventario, Recetas y Planificar.

Navegación principal: Inicio, Menú, Lista de la compra, Inventario y Recetas. No hay un apartado «Ingredientes» (decisión del usuario del 2026-10-21): `/ingredients` redirige a Inventario.

## 1. Alimentos del catálogo y alias (dentro de otros flujos)
Se distinguen tres cosas: el **alimento** (su definición en el catálogo: nombre, categoría, imagen y alias), sus **existencias** (cantidad y unidad por ubicación, §3) y su uso como **ingrediente de una receta** (cantidad necesaria, §2). Todas comparten el mismo catálogo.
1. Al escribir un alimento (receta, inventario, plato puntual, lista, recepción) se resuelve por nombre normalizado y alias (RN-03, RN-04).
2. Parecidos → se sugieren, nunca se asignan solos: el usuario elige la sugerencia, corrige el nombre o pulsa "Crear «X»" para crearlo como alimento nuevo sin salir del formulario (recetas, platos sencillos, inventario, recepción). Sin parecidos → "Crear «X»" o corregir. Un alimento creado desde una receta aparece en Inventario › «Sin existencias».
3. **[X]** Nombre o alias ya existente → se ofrece usar el alimento existente o, al añadir existencias, guardar lo escrito como alias de un parecido.
4. Catálogo, desde Inventario (§3): ficha del alimento para renombrar, cambiar categoría, unidad habitual e imagen, gestionar alias y eliminar. **[X]** No se elimina si está en uso.
5. **Unidades y compra** (ficha del alimento, RN-40 y RN-41): «Nueva equivalencia» («1 [cabeza] = [10] [dientes]») y «Añadir equivalencia», con las existentes como etiquetas que se pueden quitar. «Se compra en» elige el envase (bolsa, caja, malla, cartón…) o «la cantidad que falta». **[X]** Equivalencias contradictorias o innecesarias (g/kg) y envases sin contenido conocido se rechazan con su motivo. Quitar la equivalencia que daba contenido al envase quita el formato de compra, con aviso.
6. **Mínimo en casa** (ficha del alimento, RN-47): «Al menos [cantidad] [unidad]» y «Guardar mínimo»; «Quitar el mínimo» si lo tiene. Si hay una reposición única pendiente, se muestra «Pendiente de reponer: X, hasta que registres su compra». **[X]** Cantidad no positiva o sin unidad.

## 2. Recetas
- **Crear / editar:** nombre, raciones base, instrucciones opcionales y filas de ingrediente (ingrediente, cantidad, unidad, nota). **[X]** Validación por campo.
  - **Alternativas** (RN-42): «Añadir una alternativa a «X»» inserta debajo una fila marcada «O bien: alternativa a «X»»; también se puede marcar o desmarcar en cualquier fila.
  - **Preparaciones incluidas** (RN-43): «Incluir una preparación» añade otra receta y las raciones que usa. No se ofrecen la propia receta ni las que la incluyen. **[X]** Un ciclo se rechaza con su motivo.
- **Detalle con preparaciones:** enlace a cada preparación con sus raciones escaladas y «Todo lo que necesitas» combinado; las alternativas se muestran como «X o Y». Una preparación muestra en qué recetas se usa.
- **Listar:** búsqueda; filtro para ver archivadas. **[E]** "Crea tu primera receta".
- **Detalle:** selector de raciones con cantidades escaladas (RN-13), solo visual.
- **Editar una receta que ya usaron comidas pasadas** (RN-45): al guardar, el aviso explica que los platos que ya han pasado conservan la receta anterior en el inventario previsto y que los próximos usan la nueva.
- **Eliminar [C]:** solo si no está referenciada. Si lo está, se ofrece **Archivar** (RN-14). Las archivadas se pueden restaurar.

## 3. Inventario
Único apartado para gestionar los alimentos.
1. Pestañas: Todo, Frigorífico, Congelador, Despensa y **Sin existencias** (alimentos del catálogo que no tienes en casa). Búsqueda (también por alias) y filtro de categoría opcionales. **[E]** por pestaña.
   - **Alimentos habituales** (RN-49, 2026-10-28): debajo de «Sin existencias», sugerencias del diccionario de categorías que el hogar aún no tiene, plegadas por categoría y filtradas por la búsqueda. Un toque crea el alimento en el catálogo, sin existencias; si se parece a otro, se pide confirmación («Crear «X» igualmente»). Sin catálogo, el estado vacío invita a empezar por ellas; si no queda ninguna o no coincide con la búsqueda, la sección no aparece. El lector no la ve.
2. «Añadir al inventario»: alimento, ubicación, cantidad, unidad y etiqueta opcional. Un alimento nuevo se crea marcando «Es un alimento nuevo», con categoría opcional. Si ya existe la fila compatible (RN-08), se suma a la existente según RN-06; una unidad de otro grupo (p. ej. botes frente a g) crea una fila separada. Desde «Sin existencias», «Añadir al inventario» abre el diálogo con el alimento ya escrito.
3. Existencias: ajuste rápido y edición completa (cantidad, unidad compatible, ubicación y etiqueta); al moverlas a una ubicación con fila compatible, se suman.
   - **Previsto** (RN-44, 2026-10-22): si las comidas pasadas del menú han usado parte de una fila, la tarjeta muestra «≈ X previstos · registrado Y» y un desplegable «≈ Es una estimación. ¿Por qué?» con las comidas que lo explican. No hay que hacer nada; si no coincide con lo que hay, se pulsa la cantidad y se corrige (es un recuento). El ajuste ± parte del previsto, y la edición viene prellenada con él.
   - Tras contar o borrar una ubicación, el aviso menciona una vez las otras ubicaciones del mismo alimento que siguen siendo una estimación.
   - **«Se acabó»** (RN-47, 2026-10-23) en el pie de la tarjeta **[C]**: el diálogo nombra todas las ubicaciones que se pondrán a 0. Al confirmar, el aviso dice si la lista lo pedirá por su mínimo o una vez («Se ha añadido a la lista (3 bolsas) hasta que registres su compra»). Se corrige añadiendo existencias.
4. Ficha del alimento (pulsando su nombre o su imagen): §1.4.
5. Eliminar existencias **[C]**: el alimento sigue en el catálogo y pasa a «Sin existencias».

## 4. Planificar el menú
1. Menú → semana actual (lunes en Europe/Madrid); navegación entre semanas. Si no existe: "Crear menú de esta semana".
2. Calendario de siete filas (lunes a domingo) y dos columnas, Comida y Cena (RN-15). En móvil, cada día muestra sus dos celdas una al lado de la otra. Espacio vacío → «Añadir»:
   - **Receta guardada:** elegir entre las no archivadas y las raciones.
   - **Plato puntual:** nombre, raciones e ingredientes (pueden completarse después).
3. **Recetario** junto al calendario (en escritorio, columna lateral; en móvil, botón «Recetario» que abre un panel), con buscador y aviso de recetas sin ingredientes (RN-33):
   - Arrastrar una receta a una celda: se añade con sus raciones base, al final de los platos que ya tenga (desde el 2026-10-22, sin sustituir).
   - Sin arrastrar (teclado y táctil): «Añadir … al menú» → día y comida (se propone el primer espacio vacío que no haya pasado) y raciones. Si el espacio ya tiene platos se indica «Se añadirá junto a…».
4. Cada comida o cena muestra sus platos en orden, con «Añadir» al pie (RN-16). Por plato: «Cambiar», subir y bajar, «Mover», «No comido» y retirar **[C]**:
   - Mover: arrastrar el plato a otra celda, o «Mover» con un selector de día y comida. Se añade al final del destino; nada se sustituye ni se intercambia (RN-33).
   - **Alternativas** (RN-42): bajo el plato, «Propuesta: X (la tienes)» con «Elegir», o «Con X» con «Cambiar». El diálogo muestra las opciones con su cantidad y «En casa».
   - Un día ya pasado se marca «Pasado»; planificar en él se avisa porque no genera compras (RN-36).
5. Indicador por espacio: vacío / completo / incompleto, y «No comido» (RN-37) con «Volver a pendiente». Todas las semanas se pueden editar: no hay cierre (2026-10-21).
6. Barra bajo la cabecera: «X de 14 comidas y cenas planificadas» y «Lista de la compra de esta semana», que abre la lista del periodo lunes–domingo de esa semana.

## 5. Cerrar el menú
*(Retirado el 2026-10-21.)* La lista ya no se genera cerrando el menú: se abre por periodo (§7) y sigue los cambios del menú, las recetas y el inventario.

## 6. Reabrir y volver a cerrar
*(Retirado el 2026-10-21.)* La conciliación que protegía los cambios manuales al volver a cerrar (RN-27, RN-28) se aplica ahora cada vez que se abre la lista.

## 7. Lista de la compra
1. «Lista de la compra» abre la lista de los próximos 7 días. Arriba, las fechas del periodo, el botón del periodo (diálogo con «Próximos 7/14/30 días» o «Desde / Hasta», RN-36), «Añadir» y el resumen «N por comprar · M en el carro · K para revisar» (RN-48). Cada periodo tiene su lista y al volver a él recupera los cambios y lo comprado.
2. Líneas por nombre (orden alfabético). Por cada una: el nombre, «Faltan X» y, si se compra en envases, «Para comprar: N envases (contenido)», insignias («Mínimo en casa», «Se acabó», «En el carro»…) y «Ver cálculo» plegado. **[E]** «No necesitas comprar nada en este periodo».
   - Al pie: «Registrar compra», «Ver menú», «Historial» y «Compras registradas (N)» plegado.
   - «Historial» lleva a todas las listas: actuales, terminadas y semanales anteriores. Las del historial se ven en solo lectura.
3. Por línea: marcar comprado (un toque, RN-25), editar cantidad o unidad, eliminar **[C]**, "Marcar como revisada" si está marcada.
4. "Añadir artículo": ingrediente o texto libre, cantidad, unidad. Si coincide con una línea existente, se edita esa (RN-24).
5. Indicadores: generada/manual, modificada, para revisar (con motivo y cálculo vigente).
6. Explicaciones (2026-10-22): con cuántos platos se ha calculado; aviso si hay platos de esta semana en días ya pasados (no cuentan) o platos con una alternativa solo propuesta (con enlace al menú). En las líneas con formato de compra: «Faltan X · se compra en envases enteros (1 bolsa = 500 g)».

## 8. Recibir la compra
0. **Lo habitual: «Guardar lo marcado»** (RN-46, 2026-10-22). En la tienda se marca cada línea al ponerla en el carro. Al volver aparece una barra fija «N en el carro · Guardar N en casa», que abre una hoja con solo lo marcado: cantidad que falta (en envases si los hay), unidad y ubicación sugeridas, todas editables. «Guardar» lo suma al inventario en una operación y lo demás de la lista sigue pendiente. El texto libre queda marcado y no va al inventario. **[X]** Si otra persona ya lo guardó, se avisa y no se suma dos veces.
1. Para casos especiales (productos no previstos, varias ubicaciones, decidir línea a línea): «Registrar compra». La lista se concilia antes con las necesidades actuales (RN-27). Las listas del historial no admiten recepciones.
2. Formulario con las líneas de la lista **aún pendientes** (puede haber varias recepciones por lista), cada una **sin decidir**. Cada línea muestra la cantidad y la unidad previstas como sugerencia editable, y una indicación si estaba marcada como comprada.
3. Por cada línea, el usuario elige (RN-29):
   - **Recibido:** confirma la sugerencia o corrige la cantidad real (más o menos), la unidad y la ubicación destino.
   - **No comprado:** no se pide cantidad y la línea no afectará al stock.
4. "Añadir producto no previsto": ingrediente, cantidad, unidad y ubicación.
5. Un contador muestra las líneas pendientes de decidir. **[X]** "Confirmar" queda desactivado mientras quede alguna; el servidor también lo rechaza.
6. "Confirmar y actualizar inventario" **[C]**: resumen solo de lo que cambia (recibidos y no previstos: cantidad actual → cantidad final). Las líneas no compradas se listan aparte, sin cambios.
7. Éxito → mensaje con enlace a Inventario. No hay borradores: abandonar el formulario descarta los datos (avisar al salir con cambios).
8. **[X]** Reenvío de una confirmación ya aplicada → no se suma de nuevo (RN-30).
9. Al volver a la lista, pide lo que falta con las nuevas existencias. Lo comprado y recibido desaparece, y lo recibido queda en «Compras registradas».

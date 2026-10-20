# Flujos de usuario — Food & Pantry Manager

> Recorridos del MVP. Las reglas se citan por ID ([PRD.md §6](PRD.md)) y no se redefinen aquí; procesos internos en [DATA-MODEL.md §6](DATA-MODEL.md); patrones visuales en [DESIGN-SYSTEM.md](DESIGN-SYSTEM.md).

Convenciones: **[C]** requiere confirmación · **[E]** estado vacío · **[X]** error posible.

## 0. Acceso
1. Registro → se crean usuario, hogar y pertenencia (RN-01) → Inicio.
2. Login, logout y recuperación de contraseña del starter kit. En desarrollo no se exige verificar el email.
3. Inicio: accesos a Menú de la semana actual, Lista activa, Inventario y Recetas.

## 1. Ingredientes y alias (dentro de otros flujos)
1. Al escribir un ingrediente (receta, inventario, plato puntual, lista, recepción) se resuelve por nombre normalizado y alias (RN-03, RN-04).
2. Coincidencias → se sugieren. Sin coincidencias → "Crear «X»".
3. **[X]** Nombre o alias ya existente → se ofrece usar el ingrediente existente.
4. Catálogo: listar, renombrar, gestionar alias y eliminar. **[X]** No se elimina si está en uso. **[E]** "Aún no hay ingredientes".

## 2. Recetas
- **Crear / editar:** nombre, raciones base, instrucciones opcionales y filas de ingrediente (ingrediente, cantidad, unidad, nota). **[X]** Validación por campo.
- **Listar:** búsqueda; filtro para ver archivadas. **[E]** "Crea tu primera receta".
- **Detalle:** selector de raciones con cantidades escaladas (RN-13), solo visual.
- **Editar una receta usada en menús en borrador:** aviso de que el cambio afectará al próximo cierre.
- **Eliminar [C]:** solo si no está referenciada. Si lo está, se ofrece **Archivar** (RN-14). Las archivadas se pueden restaurar.

## 3. Inventario
1. Vista por Frigorífico / Despensa / Todo. **[E]** por ubicación.
2. Añadir: ingrediente, ubicación, cantidad, unidad, etiqueta opcional. Si ya existe la fila compatible (RN-08), se ofrece sumar a la existente según RN-06. Una unidad de otro grupo (p. ej. botes frente a g) crea una fila separada.
3. Editar cantidad (ajuste rápido y edición completa); mover de ubicación (si existe fila compatible en destino, se suman).
4. Eliminar **[C]**.

## 4. Planificar el menú
1. Menú → semana actual (lunes en Europe/Madrid); navegación entre semanas. Si no existe: "Crear menú de esta semana".
2. Rejilla de 14 espacios (RN-15). Espacio vacío → "Añadir plato":
   - **Receta guardada:** buscar entre no archivadas; raciones (por defecto, las base).
   - **Plato puntual:** nombre, raciones e ingredientes (pueden completarse después).
3. Un espacio ocupado permite editar, sustituir o quitar el plato (RN-16).
4. Indicador por espacio: vacío / completo / incompleto.

## 5. Cerrar el menú
1. "Cerrar menú y generar lista".
2. **[X]** Platos incompletos → no se cierra; lista de problemas con enlace a cada espacio (RN-18).
3. **[C]** Confirmación que explica que se restará el inventario actual. Si hay espacios vacíos, incluye la advertencia con su número (RN-18).
4. Carga durante el cálculo; el botón se desactiva. Repetir el envío no duplica nada (RN-20).
5. Resultado: menú cerrado (solo lectura) → Lista, con resumen (líneas generadas, líneas para revisar).

## 6. Reabrir y volver a cerrar
1. Menú cerrado → "Reabrir menú" **[C]**: explica que la lista se conserva y que al volver a cerrar se recalculará protegiendo los cambios manuales (RN-26, RN-27).
2. Menú en borrador y editable; la lista sigue disponible.
3. Volver a cerrar (paso 5). El resumen indica líneas actualizadas, creadas, eliminadas y **para revisar**, con enlace directo a estas.

## 7. Lista de la compra
1. Cabecera: semana, enlace al menú y estado.
2. Líneas por nombre de ingrediente (orden alfabético). **[E]** "No necesitas comprar nada esta semana".
3. Por línea: marcar comprado (un toque, RN-25), editar cantidad o unidad, eliminar **[C]**, "Marcar como revisada" si está marcada.
4. "Añadir artículo": ingrediente o texto libre, cantidad, unidad. Si coincide con una línea existente, se edita esa (RN-24).
5. Indicadores: generada/manual, modificada, para revisar (con motivo y cálculo vigente).

## 8. Recibir la compra
1. Desde la lista: "Registrar compra".
2. Formulario con todas las líneas de la lista, cada una **sin decidir**. Cada línea muestra la cantidad y la unidad previstas como sugerencia editable, y una indicación si estaba marcada como comprada.
3. Por cada línea, el usuario elige (RN-29):
   - **Recibido:** confirma la sugerencia o corrige la cantidad real (más o menos), la unidad y la ubicación destino.
   - **No comprado:** no se pide cantidad y la línea no afectará al stock.
4. "Añadir producto no previsto": ingrediente, cantidad, unidad y ubicación.
5. Un contador muestra las líneas pendientes de decidir. **[X]** "Confirmar" queda desactivado mientras quede alguna; el servidor también lo rechaza.
6. "Confirmar y actualizar inventario" **[C]**: resumen solo de lo que cambia (recibidos y no previstos: cantidad actual → cantidad final). Las líneas no compradas se listan aparte, sin cambios.
7. Éxito → mensaje con enlace a Inventario. No hay borradores: abandonar el formulario descarta los datos (avisar al salir con cambios).
8. **[X]** Reenvío de una confirmación ya aplicada → no se suma de nuevo (RN-30).

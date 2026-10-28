# Modelo de datos — Food & Pantry Manager

> Entidades, relaciones e integridad. Las reglas funcionales (`RN-xx`) están en [PRD.md §6](PRD.md) y no se repiten aquí; este documento describe cómo el modelo las soporta.
> **Estado:** modelo validado a nivel funcional; nombres de tablas y columnas orientativos hasta las migraciones de cada fase.

## 1. Conceptos

| Concepto | Tabla | Qué es |
|---|---|---|
| Hogar | `households` | Propietario de todos los datos de dominio. |
| Pertenencia | `household_user` | Relación usuario–hogar, preparada para varios miembros. |
| Ingrediente normalizado | `ingredients` | Identidad única de un alimento en el hogar. Sin cantidad ni ubicación. |
| Alias | `ingredient_aliases` | Sinónimo o plural que resuelve a un ingrediente (RN-04). |
| Producto de inventario | `inventory_items` | Existencia física: ingrediente + ubicación + unidad + cantidad. |
| Receta | `recipes` | Plantilla reutilizable con raciones base; archivable. |
| Ingrediente de receta | `recipe_ingredients` | Cantidad y unidad para las raciones base. |
| Menú | `menus` | Semana de calendario con estado. |
| Plato planificado | `planned_dishes` | Contenido de un espacio: receta o plato puntual, con raciones. |
| Ingrediente de plato puntual | `planned_dish_ingredients` | Ingredientes de un plato puntual. |
| Lista de la compra | `shopping_lists`, `shopping_list_items` | Una por hogar y periodo de compra; se concilia al abrirla y es editable. |
| Recepción | `receptions`, `reception_items` | Registro confirmado de lo comprado. |

## 2. Entidades

Todas las tablas tienen `id`, `created_at` y `updated_at` (UTC). Las entidades de dominio cuelgan de `household_id` directa o indirectamente.

### households
`name`.

### household_user
`household_id` (FK), `user_id` (FK), `role` (`admin` | `editor` | `viewer`, con CHECK; por defecto `admin`; RN-38).
- Único (`household_id`, `user_id`) y único `user_id`: un hogar por usuario en la V1 (RN-01). Varios miembros comparten un hogar.
- Desde el 2026-10-22 el registro no crea el hogar: el usuario lo crea después, con su usuario bloqueado para que solo se cree una vez, o entra por invitación.
- Los miembros anteriores a la migración `2026_10_22_100000` (uno por hogar, quien lo creó) quedaron como `admin`.

### household_invitations
`household_id` (FK, en cascada), `email` (minúsculas), `role`, `token_hash` (SHA-256 del token, único), `invited_by` (FK `users`, nullable), `expires_at`, `accepted_at`, `accepted_by` (FK `users`, nullable), `declined_at`, `revoked_at` (RN-39).
- El estado se deduce de las fechas: pendiente, aceptada, rechazada, revocada o caducada. Se conservan como historial.
- Sin pendientes duplicadas por (hogar, correo): se comprueba con el hogar bloqueado.
- Al aceptar, se bloquea la invitación y la restricción única de `user_id` impide un segundo hogar ante peticiones simultáneas.

### users
Tabla del starter kit sin cambios de dominio.

### ingredients
`household_id`, `name` (como lo escribe el usuario), `normalized_name`, `default_unit` (opcional), `purchase_unit` (opcional; formato de compra, RN-41), `category` (opcional, RN-31), reposición opcional (RN-47, 2026-10-23): `restock_mode` (`minimum` | `once`), `restock_quantity` (`DECIMAL(12,3)`, > 0), `restock_unit` y `restock_since` (`TIMESTAMP(6)`, inicio de una reposición única); CHECK `ingredients_restock_check`: todas nulas, o modo válido con cantidad > 0 y unidad. Imagen opcional (RN-32): `image_path` (copia local en el disco privado `local`), `image_provider` (`commons` | `off`), `image_source_url`, `image_author`, `image_license`, `image_license_url`, `image_updated_at`.
Único: (`household_id`, `normalized_name`). Índice: (`household_id`, `category`).

**Categorías** (`App\Enums\IngredientCategory`, lista cerrada; nulo = «Sin clasificar»): `fruit` Frutas · `vegetables` Verduras y hortalizas · `meat` Carne y aves · `fish` Pescado y marisco · `dairy_eggs` Lácteos y huevos · `bakery_cereals` Panadería y cereales · `pasta_rice_legumes` Pasta, arroz y legumbres · `cold_cuts` Embutidos · `preserves` Conservas y envasados · `soups_broths` Caldos, cremas y sopas (2026-10-28) · `drinks` Bebidas · `sauces_oils` Salsas, aceites y condimentos · `other` Otros. «Congelados» no es una categoría: es un estado de conservación y se representa con la ubicación `freezer`. Las existencias no guardan la categoría: la toman de su ingrediente. El orden de la lista es el de los pasillos de la lista de la compra (el del enum y el de `CATEGORY_ICONS` en `resources/js/lib/visuals.ts`, comprobado por una prueba). Una categoría nueva no exige migración (`category` es `varchar(30)` sin CHECK; la valida `Rule::enum`) ni reclasifica alimentos existentes.

### ingredient_conversions (2026-10-22, RN-40)
`ingredient_id` (cascada), `unit`, `factor` (`DECIMAL(14,6)`, `CHECK > 0`), `to_unit`: 1 `unit` = `factor` `to_unit`, solo para ese alimento.
Único: (`ingredient_id`, `unit`, `to_unit`). Las equivalencias de un alimento forman un árbol entre grupos de unidades (§4.1).

### ingredient_aliases
`household_id`, `ingredient_id`, `alias`, `normalized_alias`.
Único: (`household_id`, `normalized_alias`). La aplicación valida además que ningún `normalized_alias` coincida con un `normalized_name` del hogar y viceversa (RN-04).
Resolución de un texto: normalizar → buscar en `ingredients.normalized_name` → buscar en `ingredient_aliases.normalized_alias` → si no hay coincidencia, ofrecer crear.

### inventory_items
`household_id`, `ingredient_id`, `location` (`fridge` | `freezer` | `pantry`), `unit` (la elegida por el usuario), `unit_group` (derivado de `unit`, §4), `quantity`, `label` (opcional, descriptivo).
Único: (`household_id`, `ingredient_id`, `location`, `unit_group`) → RN-08.
Es lo **registrado**: solo cambia por acciones del usuario o por recepciones, nunca por el paso del tiempo (RN-44).

### stock_events (2026-10-22, RN-44)
`household_id` y `ingredient_id` (cascada), `kind` (`add` | `count` | `move`, CHECK), `location` y `unit_group` (la fila), `to_location` (solo `move`), `quantity` (≥ 0; `add`: lo sumado; `count`: lo indicado, 0 al borrar; `move`: la cantidad nueva de la parte movida o nulo si solo cambió la ubicación), `unit`, `occurred_at` (precisión de microsegundos), `reception_item_id` (nulo, único, `set null`).
Los escribe `StockManager` en cada alta, compra, edición, ajuste, traslado o borrado. La migración creó un `count` por cada fila existente, igual a lo registrado, en el momento de migrar.

### recipe_revisions (2026-10-22, RN-45)
`recipe_id` (cascada), `valid_until` (momento de la edición, microsegundos), `base_servings`, `ingredients` (JSON: `ingredient_id`, `quantity`, `unit`, `alternative_group`, `position`), `components` (JSON: `component_recipe_id`, `servings`, `position`).
Se guarda al editar una receta, solo si su composición cambia y algún plato que la usa (directamente o como preparación) ya pasó su hora de referencia.

### recipes
`household_id`, `name`, `base_servings` (entero ≥ 1), `instructions` (opcional), `archived_at` (nullable).

### recipe_ingredients
`recipe_id`, `ingredient_id`, `quantity`, `unit` (tal como se introduce, p. ej. `kg`), `note` (opcional), `position`, `alternative_group` (nullable; las líneas de una receta con el mismo número son alternativas, RN-42).
Único: (`recipe_id`, `ingredient_id`, `unit`).

### recipe_components (2026-10-22, RN-43)
`recipe_id` (cascada), `component_recipe_id` (`restrict`: una preparación en uso no se borra, RN-14), `servings` (raciones de la preparación para las raciones base de la receta), `position`.
Único: (`recipe_id`, `component_recipe_id`). Sin ciclos: se comprueba al guardar, con la tabla de recetas del hogar bloqueada.

### planned_dish_choices (2026-10-22, RN-42)
`planned_dish_id` (cascada), `recipe_id` (la receta del grupo: la del plato o una de sus preparaciones; cascada), `alternative_group`, `ingredient_id` (cascada).
Único: (`planned_dish_id`, `recipe_id`, `alternative_group`). Sin fila, la opción en uso es la propuesta (§6.1).

### menus
`household_id`, `week_start` (`DATE`, siempre lunes), `status` (`draft` | `closed`), `closed_at`, `reopened_at`. Desde el 2026-10-21 no hay cierre: `status`, `closed_at` y `reopened_at` solo se conservan como historial y ya no limitan la edición. Los menús nuevos se crean como `draft`.
Único: (`household_id`, `week_start`).

### planned_dishes
`menu_id`, `day` (1–7, lunes = 1), `meal` (`lunch` | `dinner`), `position` (orden dentro del espacio, desde 0), `type` (`recipe` | `adhoc`), `recipe_id` (nullable), `name` (nullable), `servings` (entero ≥ 1), `not_eaten_at` (nullable; «No comido», RN-37).
Índice: (`menu_id`, `day`, `meal`, `position`). Desde el 2026-10-22 un espacio admite varios platos (RN-16): se retiró el único (`menu_id`, `day`, `meal`) y los platos existentes quedaron en la posición 0. Un espacio vacío no tiene filas.
Coherencia: `type = recipe` ⇒ `recipe_id` no nulo; `type = adhoc` ⇒ `name` no nulo.

### planned_dish_ingredients
`planned_dish_id`, `ingredient_id`, `quantity`, `unit`. Cantidades para las `servings` del plato; no se escalan.

### shopping_lists
`household_id`, `period_start`, `period_end` (`DATE`, nullable), `rolling_days` (7, 14 o 30, nullable; único por hogar), `menu_id` (nullable, único), `generated_at`, `regenerated_at`.
- **Lista móvil** («Próximos N días», `rolling_days`): una por hogar y duración. Al abrirla se actualizan `period_start` y `period_end` para que empiece hoy. Si ya existe una lista con fechas propias exactamente iguales, ese día se abre esa y la móvil se desplaza otro día.
- **Lista de periodo** (desde el 2026-10-21, RN-36): con `period_start` y `period_end` y sin menú. Es única por (`household_id`, `period_start`, `period_end`): una sola lista por hogar y periodo (RN-20).
- **Lista semanal anterior:** con `menu_id` y sin periodo; la generaba el cierre del menú. Se conserva como historial de solo lectura con sus líneas, comprados y recepciones.
- MySQL admite varios `NULL` en los índices únicos, así que los dos tipos conviven.

### shopping_list_items

| Campo | Uso |
|---|---|
| `shopping_list_id` | FK. |
| `origin` | `generated` \| `manual`. |
| `ingredient_id` | Nullable solo para texto libre. |
| `name` | Texto mostrado (nombre del ingrediente o texto libre). |
| `unit`, `quantity` | Cantidad vigente y su unidad, expresada según RN-06. |
| `match_key` | `"{ingredient_id}:{unit_group}"`; nulo en texto libre. |
| `calculated_quantity`, `calculated_unit` | Último valor calculado (tras redondeo) y su unidad; nulos si nunca se calculó o el menú ya no lo necesita. |
| `receptions_counted_after` | Id de la última recepción de la lista ya reflejada en el inventario cuando la cantidad se fijó desde el cálculo; solo las recepciones posteriores reducen lo pendiente (§6.4). Nulo: cuentan todas. |
| `is_user_modified` | El usuario cambió cantidad o unidad de una línea generada. |
| `is_dismissed` | El usuario eliminó una línea generada (borrado lógico, oculta). |
| `is_purchased` | Marca de comprado. |
| `needs_review`, `review_reason` | Marca y motivo (`incompatible_inventory`, `calculation_changed`, `no_longer_needed`, `quantity_increased`, `manual_with_calculation`). |

Único: (`shopping_list_id`, `match_key`). MySQL admite varios `NULL`, así que las líneas de texto libre no colisionan. Esto garantiza que no haya duplicados entre líneas generadas y manuales del mismo ingrediente y unidad (RN-24, RN-27).
Las líneas manuales se eliminan físicamente; las generadas, con `is_dismissed`.

### receptions
`household_id`, `shopping_list_id` (FK obligatoria, RN-29), `token` (UUID único del formulario, idempotencia RN-30), `confirmed_at`.
Se crea ya confirmada, en la misma transacción que actualiza el inventario. Una lista admite **varias recepciones parciales** (§9, Fase 6).

### reception_items
`reception_id`, `shopping_list_item_id` (nullable, `ON DELETE SET NULL`: el historial se conserva si la línea desaparece), `is_unforeseen`, `ingredient_id` (nullable solo en una línea de texto libre no comprada), `name` (copia para el historial), `suggested_quantity` y `suggested_unit` (lo pendiente de la línea en el momento de recibir; nulos en no previstos), `outcome` (`received` | `not_purchased`), `quantity_received`, `unit`, `unit_group`, `location`.
Coherencia (con `CHECK` en MySQL):
- `received` ⇒ `quantity_received > 0`, con `unit`, `location` e `ingredient_id` obligatorios.
- `not_purchased` ⇒ `quantity_received`, `unit` y `location` nulos.
- Un producto no previsto (`is_unforeseen`) siempre es `received`.
- La petición de confirmación debe incluir un `outcome` para **cada** línea pendiente de la lista; si falta alguno, se rechaza (RN-29).

## 3. Normalización

`normalized_name` / `normalized_alias`: recortar → colapsar espacios internos → minúsculas (`mb_strtolower`) → eliminar diacríticos (extensión `intl`, disponible en local). Sin singularizar automáticamente: los plurales se resuelven con alias.
La collation propuesta (`utf8mb4_unicode_ci`) también compara sin distinguir mayúsculas ni acentos; la unicidad depende de la normalización en aplicación, y el índice actúa como red de seguridad.

## 4. Unidades

| Código | Nombre | Grupo (`unit_group`) | Factor dentro del grupo | Indivisible |
|---|---|---|---|---|
| `g` | gramo | `mass` | 1 | No |
| `kg` | kilogramo | `mass` | 1000 | No |
| `ml` | mililitro | `volume` | 1 | No |
| `l` | litro | `volume` | 1000 | No |
| `piece` | pieza / unidad | `piece` | — | Sí |
| `can` | lata | `can` | — | Sí |
| `jar` | bote | `jar` | — | Sí |
| `pack` | paquete | `pack` | — | Sí |
| `slice` | rebanada | `slice` | — | Sí |
| `deli_slice` | loncha | `deli_slice` | — | Sí |
| `tray` | bandeja | `tray` | — | Sí |
| `clove` | diente | `clove` | — | Sí |
| `head` | cabeza | `head` | — | Sí |
| `bag` | bolsa | `bag` | — | Sí |
| `box` | caja | `box` | — | Sí |
| `net` | malla | `net` | — | Sí |
| `carton` | cartón | `carton` | — | Sí |

- Enum en código; no editable por el usuario. No hay equivalencias universales entre grupos (RN-06); cada alimento puede definir las suyas (§4.1).
- **Selectores (2026-10-28):** las unidades se agrupan en **Medidas** (g, kg, ml, l), **Piezas** (unidad, rebanada, loncha, diente, cabeza) y **Envases** (lata, bote, paquete, bolsa, cartón); al añadir existencias, las unidades del alimento (la habitual y las unidas por sus equivalencias) van primero. `tray`, `box` y `net` (bandeja, caja, malla) **ya no se ofrecen** para entradas nuevas, porque paquete y bolsa los cubren; siguen siendo válidas, así que los datos existentes no cambian y se pueden editar (`Unit::isOffered()`).
- Cada tabla guarda la **unidad elegida o resultante**; no se normaliza a g ni a ml al guardar.
- **Clave de compatibilidad** = (`ingredient_id`, `unit_group`). Solo los grupos `mass` y `volume` contienen más de una unidad.
- Combinar cantidades del mismo grupo con unidades distintas (g + kg, ml + l):
  1. Se opera internamente en g o ml con `bcmath`.
  2. El resultado se expresa según RN-06 (kg/l si es ≥ 1; si no, g/ml).
- Las unidades indivisibles nunca se convierten.
- Las comparaciones entre cantidades del mismo grupo (p. ej. "el cálculo cambió" en RN-27) se hacen sobre el valor equivalente, no sobre el texto de la unidad.

### 4.1 Unidades de un alimento (2026-10-22, RN-40 y RN-41), `App\Support\FoodUnits`
Un único modelo para la unidad de receta, la de existencias y la de compra:
- **Grafo:** los grupos de unidades son nodos y las equivalencias del alimento (`ingredient_conversions`) son aristas. Se admite una arista solo si sus dos grupos aún no están conectados, así que cada componente es un árbol: una conversión siempre da el mismo resultado y no puede haber contradicciones ni ciclos. g↔kg y ml↔l siguen siendo internos de su grupo.
- **Unidad común de un componente:** masa si la contiene, si no volumen, si no la unidad más pequeña. Necesidades y existencias se convierten a ella antes de compararse, con `bcmath` (escala 12 en las razones y resultado a 3 decimales, redondeado hacia arriba en la mitad).
- **Clave de compatibilidad:** (`ingredient_id`, grupo común). Sin equivalencias es la misma de siempre (`ingredient_id:unit_group`), así que las líneas de lista existentes no cambian.
- **Formato de compra:** si `purchase_unit` es convertible, lo que falta se pasa a esa unidad y se redondea hacia arriba a entero (primero a 6 decimales, para que 3 × ⅓ no dé 2). La lista guarda la línea en esa unidad y muestra lo que falta y el contenido del envase.
- **Filas y recepciones:** las existencias, las recetas y lo recibido se guardan en su unidad. Lo recibido en otra unidad convertible cuenta para la línea (1 kg para una línea en bolsas de 500 g), y al sumar a mano a una línea se convierte a su unidad.
- `FoodUnitsMap::forHousehold` carga una sola vez las equivalencias de los alimentos del hogar que tienen alguna o un formato de compra.
- **Contenido del envase al añadir existencias (2026-10-28):** si la unidad es un envase y el alimento aún no sabe cuánto trae, «Añadir al inventario» ofrece «¿Cuánto trae cada envase? (opcional)» (250 g, 500 g, 1 kg, 1 l o un valor propio en g, kg, ml o l). Se guarda en la misma transacción que la existencia como equivalencia del alimento («1 paquete = 1 kg») y, si no tenía formato de compra, ese envase pasa a serlo. Si ya lo sabe, se muestra («Cada envase trae 1 kg»). Sin contenido, nada se convierte (RN-06). Un contenido que contradice las equivalencias existentes se rechaza y no se guarda nada.

## 5. Cantidades

- Columnas `DECIMAL(12,3)`.
- Aritmética con `bcmath` (extensión disponible en local; sin dependencias nuevas) con escala intermedia suficiente y resultado a 3 decimales. Prohibido `float` en cálculos de cantidades.
- En el frontend las cantidades viajan como cadenas decimales y solo se formatean.

## 6. Procesos

### 6.0 Existencias previstas (2026-10-22, RN-44), `App\Actions\Inventory\StockEstimator`
- **Hora de referencia:** la fecha del plato a las 15:00 (comida) o a las 22:00 (cena), hora de Madrid (`PlannedDish::referenceTime`). Antes de ella el plato es pendiente; desde ella, consumido, salvo que esté marcado «No comido».
- **Previsto por fila** (alimento, ubicación y grupo), en la unidad común del alimento (§4.1). Se recorren en orden cronológico los `stock_events` y los platos consumidos; en un empate de hora, el plato va antes, después la posición y el `id`:
  - `add`: la fila suma;
  - `count`: la fila pasa a ser exactamente lo indicado (a 0, desaparece) y olvida su consumo anterior;
  - `move`: la fila y su previsto pasan a la otra ubicación (sumando si hay fila); con cantidad, la parte movida queda contada;
  - plato: cada ingrediente se toma de las filas del alimento en el orden frigorífico → despensa → congelador, cada una hasta 0; lo que no cabe se ignora.
- Los platos pasados usan la composición vigente en su hora de referencia (`RecipeCompositions`, revisiones de RN-45) y, en las alternativas, la elección o la propuesta que el previsto de ese momento daba (RN-42).
- **Propiedades** comprobadas en pruebas, también con secuencias aleatorias: 0 ≤ previsto ≤ registrado en cada fila; contar todas las filas, en cualquier orden, deja el previsto igual a lo registrado; un recuento no toca las demás filas; repetirlo no cambia nada; un traslado conserva previsto y registrado.
- **Dónde se usa:** `DishPlan` (lista por periodo, menú, Inicio y propuestas de alternativas) usa el previsto. El aviso «Revisar unidades» depende de lo registrado, no del previsto. El inventario muestra «≈ previsto · registrado» por fila con los platos que lo explican.
- **Correcciones desde la interfaz:** el ajuste rápido (escribir o ±) siempre es un recuento. En el diálogo de edición, la cantidad viene prellenada con lo mostrado (el previsto si difiere): guardarla sin cambiarla es un traslado o un cambio de etiqueta; otra cantidad es un recuento.

### 6.1 Necesidad de un plato
Receta: `cantidad × servings / base_servings` (RN-13). Plato puntual: cantidades tal cual.

Desde el 2026-10-22 (`App\Actions\Recipes\RecipeExpansion`):
- **Preparaciones (RN-43):** una receta suma también las líneas de sus `recipe_components`, a escala `servings_plato × servings_preparación / (base_servings × base_servings_preparación)`. El numerador y el denominador se acumulan como enteros y se escala una sola vez, sin redondeos intermedios. La expansión se detiene en una receta ya visitada y a 5 niveles, aunque los ciclos ya se rechazan al guardar.
- **Alternativas (RN-42):** de cada grupo cuenta una sola línea: la elegida en `planned_dish_choices` si sigue en el grupo; si no, la propuesta. La propuesta se marca como tal en el menú y la lista cuenta los platos que la usan (`coverage.proposals`). Una elección que ya no está en el grupo se devuelve como `stale` y se vuelve a proponer.
- **Reparto de existencias (`App\Actions\Menus\DishPlan`, 2026-10-22):**
  - `DishPlan::until` recorre los platos pendientes desde hoy hasta el final del periodo o de la semana mostrada, en orden (fecha, comida antes que cena, `position`, `id`), con un `StockAllocation`: las existencias aún no asignadas, en la unidad común.
  - Cada plato toma primero sus líneas fijas y sus elecciones confirmadas. Después, cada propuesta es la primera opción que cubre lo que queda, si no la primera con algo, y si no la primera, y toma su parte.
  - Así la propuesta de un plato solo depende de los anteriores: es la misma en el menú, en la lista de 7, 14 o 30 días y en fechas propias.
  - La lista resta las existencias como siempre (§6.2); el reparto solo decide qué opción cuenta.
  - Los platos pasados o «No comido» no participan; si se muestran, sus propuestas no consideran existencias.
- Varias líneas del mismo alimento (de la receta y de sus preparaciones) se suman por su clave de compatibilidad (§4.1), sin duplicar.

### 6.2 Lista de un periodo (RN-20 a RN-23, RN-27, RN-28, RN-36), `ShoppingListSync`
Sustituye al cierre del menú (retirado el 2026-10-21). Se ejecuta al abrir la lista y antes de registrar una compra:
1. Buscar la lista del hogar con ese periodo. Si no existe, crearla; si otra petición la crea a la vez, la restricción única gana y se reutiliza la suya. Al crearla, copiar de la lista vigente más reciente que se solape (también una semanal anterior) sus líneas aún pendientes y las quitadas, sin modificar la de origen.
2. En una transacción, bloquear la fila de la lista (`SELECT … FOR UPDATE`).
3. Calcular las necesidades del periodo (`PeriodRequirements`):
   - platos con fecha desde hoy (Europe/Madrid), excepto los «No comido»;
   - agrupadas por clave de compatibilidad (§4), menos las existencias compatibles que queden tras los platos pendientes anteriores al periodo;
   - más la reposición activa del alimento (RN-47, `FoodRestock`), sumada en la misma clave: el mínimo siempre; la única mientras no haya un movimiento `add` del alimento con `occurred_at` estrictamente posterior a `restock_since`. El detalle de la línea separa `recipes`, `restock` y `before` (lo que usan antes comidas pendientes fuera del periodo);
   - `max(0, …)` y redondeo hacia arriba de las unidades indivisibles;
   - marca `incompatible_inventory` si solo hay existencias en otro grupo de unidades.
4. Conciliar por `match_key` según la tabla de RN-27 y RN-28. Al comparar «cambia respecto al cálculo anterior» se usa `calculated_quantity`.
   - En las líneas modificadas por el usuario, `calculated_quantity` guarda la necesidad sin descontar lo recibido en esa línea, igual que la cantidad del usuario, contra la que cuentan sus recepciones. Se compara `calculated_quantity − recibido` con la necesidad actual: una recepción no es un cambio.
   - Al copiar líneas a un periodo nuevo se copia lo pendiente, porque las recepciones se quedan en la lista de origen.

Las listas de periodos terminados y las semanales anteriores no se concilian: son historial.

### 6.3 Reapertura
*(Retirada el 2026-10-21.)*

### 6.4 Confirmación de recepción (RN-29, RN-30)
1. En una transacción, bloquear la fila de la lista (`lockForUpdate`). Si ya existe una recepción con el `token` del formulario, devolverla sin aplicar nada (RN-30).
2. Validar que cada línea **pendiente** de la lista tiene `outcome` y que no llegan líneas que ya no lo están (formulario desactualizado).
3. Crear `receptions` y `reception_items`.
4. Por cada ítem `received` (líneas y productos no previstos), sumar en la fila de inventario con la misma clave de compatibilidad y ubicación, combinando según §4; si no existe, crearla con la unidad recibida.
5. Los ítems `not_purchased` no tocan el inventario y la línea sigue pendiente.

**Pendiente de una línea:** su cantidad menos la suma de lo recibido en el mismo grupo de unidades en las recepciones posteriores a `receptions_counted_after`. Al conciliar la lista (§6.2), una línea cuya cantidad sale del nuevo cálculo ya descuenta el inventario (y con él lo recibido antes), así que esas recepciones no se restan otra vez; en líneas manuales o modificadas por el usuario cuentan todas. Lo recibido en un grupo incompatible no se descuenta (como RN-22). Una línea de texto libre sin cantidad deja de estar pendiente al recibirse una vez. Las líneas quitadas de la lista no se ofrecen.

## 7. Integridad

- FK con `ON DELETE` explícito:
  - `households` → cascada sobre sus datos de dominio.
  - `ingredients` → `restrict` si está en uso.
  - `recipes` → `restrict` desde `planned_dishes` y desde `recipe_components.component_recipe_id` (RN-14, RN-43); cascada sobre sus propias `recipe_components` y sobre `planned_dish_choices`.
  - `ingredients` → cascada sobre `ingredient_conversions` y `planned_dish_choices`.
  - `users` → cascada solo sobre `household_user` (y `passkeys`); `household_invitations.invited_by` y `accepted_by` pasan a nulo. Borrar una cuenta no borra datos del hogar.
- **Ciclo de vida del hogar** (2026-10-22, RN-38):
  - Un hogar solo se elimina cuando queda sin miembros y vacío (`HouseholdMembership::isEmpty`: sin alimentos, recetas, existencias, platos, líneas de lista ni compras; las listas vacías abiertas automáticamente no cuentan), al salir su único miembro o al borrar su cuenta.
  - Con datos, ni salir ni borrar la cuenta del único miembro está permitido (`leaveBlocker`, `accountDeletionBlocker`), así que un hogar con datos no puede quedar sin miembros.
  - El hogar 9 de desarrollo, que quedó sin miembros antes de esta regla, se eliminó a mano el 2026-10-22 con autorización del usuario (estaba vacío).
  - `menus` → cascada sobre platos y lista.
- Únicos descritos en §2.
- Validación de dominio en Form Requests (cantidades > 0, raciones ≥ 1, `week_start` en lunes, unidades del enum) y, cuando aplique, `CHECK` de MySQL 8.0 (soportados desde 8.0.16).
- Ningún proceso modifica `inventory_items` salvo la edición directa y §6.4 (RN-09).

## 8. Pendiente (no bloqueante)

- ~~¿Una o varias recepciones por lista?~~ Resuelto el 2026-10-20: varias recepciones parciales (§9, Fase 6).

## 9. Decisiones de implementación (Fase 2, 2026-10-20)

- **Precisión al combinar g + kg (ml + l):** si el total en kg (l) necesitaría más de 3 decimales, se expresa en g (ml) para no perder exactitud.
- **Ingrediente desconocido al añadir existencias:** solo se crea si el usuario lo pide expresamente (casilla «Crear el ingrediente»); si no, error de validación. Evita duplicados por erratas.
- **Sugerencias por parecido (no son fusiones):** si el texto no coincide exactamente con un nombre o alias, se sugieren hasta 3 ingredientes parecidos comparando nombres y alias normalizados (uno contiene al otro, distancia de edición ≤ 2 o similitud ≥ 75 %). Nunca se asignan solos: el usuario elige la sugerencia, la añade como alias o confirma que es un ingrediente nuevo. Crear un ingrediente parecido a otro (desde el catálogo o el inventario) exige esa confirmación; renombrar no.
- **Edición de existencias:** la unidad solo cambia dentro de su grupo. Al mover a otra ubicación con fila compatible, se suman y se conserva la etiqueta de la fila de destino (o la de origen si aquella no tenía).
- **Longitudes:** nombre, alias y etiqueta, máximo 100 caracteres. Cantidades de hasta 999.999.999 con 3 decimales; se admite la coma decimal.

### Fases 3 y 4 (2026-10-20)

- **Líneas de ingredientes** (recetas y platos sencillos): solo ingredientes del catálogo, por nombre o alias; un desconocido se rechaza con sugerencias, nunca se crea desde aquí. Un ingrediente no puede repetirse con unidad compatible en el mismo plato; el índice único es (`…`, `ingredient_id`, `unit_group`) en lugar de `unit`, para impedir g y kg separados. `recipe_ingredients` y `planned_dish_ingredients` guardan también `unit_group` y `position`.
- **Plato sencillo** = plato puntual (RN-17): nombre y raciones obligatorios; ingredientes opcionales hasta el cierre (se marca «Sin ingredientes»).
- **Menú:** se crea al asignar el primer plato de la semana; cada cambio se guarda al momento. Raciones de 1 a 100.
- **Escalado (RN-13):** redondeo al alza de la mitad a 3 decimales, mínimo 0,001.
- **Necesidades del menú:** vista de solo lectura en la pantalla del menú (suma escalada, combinación RN-06 y comparación con ambas ubicaciones; estados suficiente, falta, sin existencias y revisar por unidad incompatible). No aplica el redondeo de compra de RN-23, que corresponde a la lista.
- **Zona horaria de la interfaz:** `config('app.display_timezone')`, por defecto `Europe/Madrid`.
- **Ingredientes en uso:** no se pueden eliminar si están en inventario, recetas, menús o listas de la compra.

### Fase 5 (2026-10-20)

- **Cálculo único:** la lista y la vista de necesidades del menú usan el mismo cálculo (`MenuRequirements::calculate`). Por línea: falta = necesidad − existencias compatibles, y después el redondeo al alza de RN-23. Con existencias solo incompatibles se compra la necesidad completa y la línea se marca `incompatible_inventory` (RN-22).
- **Cierre:** bloqueado si hay platos sin ingredientes (RN-18); con espacios vacíos exige confirmación explícita. Se bloquea la fila del menú (`lockForUpdate`) para que dos cierres simultáneos no creen dos listas; cerrar un menú ya cerrado no hace nada.
- **`calculated_unit`:** columna añadida para mostrar el nuevo cálculo en su unidad cuando el usuario cambió la unidad de la línea. La comparación de RN-27/RN-28 se hace en unidad base.
- **Conciliación (RN-27):** sin cambios en el cálculo, nada se toca. Líneas manuales: nunca se duplican; si el menú también las necesita, guardan el cálculo y se marcan `manual_with_calculation`. Líneas quitadas por el usuario: reaparecen solo si cambia el cálculo. Líneas modificadas: conservan la cantidad del usuario y se marcan `calculation_changed` o `no_longer_needed`. Líneas sin tocar que ya no hacen falta se borran, salvo que estén compradas (se marcan `no_longer_needed`).
- **Añadir a la lista (RN-24):** un ingrediente del catálogo exige cantidad y unidad; si ya hay línea compatible, se suma a ella (una línea quitada toma la cantidad nueva) y una generada pasa a «modificada». Un nombre que no está en el catálogo se añade como texto libre (cantidad opcional); si se parece a un ingrediente, se pide confirmación («Añadir como texto libre»).
- **Edición:** las líneas de ingrediente solo cambian de unidad dentro de su grupo. Editar o «dar por revisada» quita la marca de revisión (RN-28).
- **Comprado (RN-25):** solo cambia `is_purchased`; nunca toca el inventario.
- **Rutas:** `POST menu/{week}/close` y `menu/{week}/reopen` (con `confirm`); `GET shopping-list/{week?}`; líneas en `shopping-list/{week}/items` (`POST`, `PATCH {item}`, `DELETE {item}`, `PATCH {item}/purchased`, `PATCH {item}/reviewed`). Una línea de otro hogar o de otra semana responde 404.

### Fase 6 (2026-10-20)

- **Varias recepciones por lista (decisión del usuario):** cada recepción registra solo lo recibido en esa ocasión y se confirma de una vez. El formulario ofrece las líneas pendientes (§6.4) con lo pendiente como sugerencia editable; «No comprado» deja la línea pendiente para otra recepción. Recibir más de lo previsto completa la línea; recibir menos deja el resto pendiente.
- **Idempotencia (RN-30):** `receptions.token` es un UUID generado al abrir el formulario. Reenviar el mismo token no vuelve a sumar; un formulario antiguo con líneas ya completadas se rechaza.
- **Nada recibido:** una recepción sin ninguna línea recibida ni producto no previsto se rechaza.
- **Ingredientes (RN-03, RN-04):** las líneas de ingrediente usan el suyo. Las de texto libre y los productos no previstos se resuelven por nombre o alias; un nombre desconocido solo se crea con «Crear el ingrediente» y, si se parece a otro, además con la confirmación de que es nuevo (como al añadir existencias).
- **Unidad y ubicación:** la unidad recibida puede ser de otro grupo; entonces se crea una fila de inventario aparte (RN-08) y no reduce lo pendiente. Se sugiere la ubicación donde ya se guarda ese ingrediente.
- **Comprado y recibido son independientes (RN-25):** recibir no cambia `is_purchased`; una línea marcada como comprada sigue pendiente hasta recibirse.
- **Resumen antes de confirmar (USER-FLOWS §8):** muestra lo que se suma, la ubicación y lo que hay ahora en ella; la cantidad final no se calcula en el navegador porque las cantidades solo se calculan en el backend.
- **Rutas:** `GET shopping-list/{week}/reception` (formulario) y `POST shopping-list/{week}/receptions`. El historial se muestra en la página de la lista.
- **Ingredientes en uso:** tampoco se pueden eliminar si aparecen en una recepción.

### Correcciones tras las pruebas manuales (2026-10-20)

- **Ingredientes nuevos desde recetas y platos sencillos (RN-03, RN-04, USER-FLOWS §1):** un nombre que no está en el catálogo se rechaza con sus sugerencias, que nunca se aplican solas. Cada fila admite `create=1` («Crear «X»»): el ingrediente se crea con el nombre escrito al guardar, en la misma transacción que la receta o el plato; si la validación falla, no se crea nada. Si el nombre normalizado ya existe, o es un alias, se usa ese ingrediente y no se crea otro. Las sugerencias viajan al formulario como dato estructurado (flash `ingredientSuggestions`, por índice de fila) para ofrecerlas como botones, y el formulario conserva lo escrito.
- **Creación concurrente:** `IngredientCatalog::findOrCreate` se apoya en el índice único (`household_id`, `normalized_name`); si dos peticiones crean el mismo nombre a la vez, la segunda reutiliza la fila de la primera (lectura con bloqueo tras el conflicto). Lo usan recetas, platos, inventario y recepción.
- **Plato sin ingredientes ≠ ingrediente desconocido:** un plato sencillo puede guardarse sin ingredientes (queda incompleto y bloquea el cierre, RN-17/RN-18); una fila con un ingrediente desconocido impide guardar hasta que el usuario elija, corrija o cree.
- **Unidades lata (`can`), rebanada (`slice`) y loncha (`deli_slice`):** cada una forma su propio grupo, indivisible (RN-23) y sin equivalencias (RN-06). No requieren migración: las columnas de unidad son `varchar(10)` sin `CHECK` de valores, y los datos existentes no cambian.
- **Datos de prueba manual:** `Database\Seeders\ManualTestDataSeeder`, solo con `APP_ENV=local` y un hogar explícito (`SEED_HOUSEHOLD_ID`, con `SEED_DRY_RUN=1` para simular). Sustituye los datos funcionales de ese hogar en una transacción; usuarios, hogares, membresías y otros hogares no se tocan, y se detiene sin borrar si otro hogar referencia sus ingredientes.

### Revisión de las Fases 5 y 6 (2026-10-20)

- **Recepción y recálculo:** una recepción parcial seguida de reabrir y cerrar el menú restaba dos veces lo recibido. Corregido con `shopping_list_items.receptions_counted_after` (§2, §6.4; migración aditiva).
- **Marcas de revisión coherentes (RN-27):** una línea sin tocar que vuelve a hacer falta pierde la marca `no_longer_needed`; una línea manual que el menú deja de necesitar pierde el cálculo y la marca `manual_with_calculation`, sin cambiar su cantidad.
- **Ajuste rápido de existencias (USER-FLOWS §3):** `PATCH inventory/{id}/quantity` con una cantidad nueva en la unidad de la fila o `step` (`up`/`down`) de una unidad en unidades indivisibles; nunca deja 0 (RN-07) y conserva ubicación, unidad y etiqueta.

### Categorías, imágenes, congelador y concurrencia (2026-10-21)

- **Migración aditiva** `2026_10_21_100000_add_category_and_image_to_ingredients_table`: solo añade columnas nulas e índice. Los ingredientes existentes quedan «sin clasificar» y sin imagen; nada se asigna automáticamente (RN-31). La interfaz de Ingredientes señala cuántos quedan sin clasificar y los filtra.
- **Categoría al crear existencias:** si el ingrediente se crea desde «Añadir existencias», se puede elegir su categoría. Si ya existía, su categoría no cambia.
- **Imagen (RN-32):** búsqueda bajo demanda en Wikimedia Commons (fotos) u Open Food Facts (productos y código de barras), y selección explícita. El servidor resuelve de nuevo la imagen a partir de la referencia del proveedor (título del archivo o código de barras), nunca a partir de una URL del navegador. Solo descarga de los hosts del proveedor, con un máximo de 2 MB, y comprueba que el contenido es una imagen. La copia se sirve con autorización del hogar (`GET ingredients/{id}/image`). Al quitar la imagen o borrar el ingrediente se borra el archivo. Detalle en TECHNICAL-DESIGN §10.
- **Congelador:** `StorageLocation::Freezer` es una ubicación más. Las reglas RN-06 y RN-08 se aplican igual: una fila por ingrediente, ubicación y grupo compatible.
- **Ediciones simultáneas del menú:** `MenuPlanner` bloquea la fila del menú en cada cambio (asignar, mover, retirar, «No comido»). Desde el 2026-10-21 ya no se comprueba si está cerrado (RN-19 retirada). La carrera que se protege ahora es la creación simultánea de la lista de un periodo (§6.2).
- **Máximo almacenable:** las columnas `DECIMAL(12,3)` admiten hasta 999.999.999,999. Toda cantidad se comprueba antes de guardarse (`Quantity::storable()`). Una suma que lo supere se rechaza con un mensaje y deshace la transacción: inventario, cierre del menú y fusión en la lista.
- **Inventario como único apartado de alimentos (2026-10-21):** no cambia el modelo. `Ingredient` sigue siendo la definición del alimento en el catálogo, `InventoryItem` sus existencias y `RecipeIngredient` / `PlannedDishIngredient` su uso con la cantidad necesaria. Sin migraciones: solo cambian la navegación y las pantallas.
- **Mover e intercambiar platos (RN-33):** *(sustituido el 2026-10-22)* ya no hay intercambio ni valor provisional de `meal`: `MenuPlanner::move` añade el plato al final del destino y renumera el origen, con el menú bloqueado. Los platos conservan su `id`, sus ingredientes y sus elecciones.
- **Altas simultáneas en la lista (RN-24):** `ShoppingListEditor::add` bloquea la lista. Si aun así aparece una línea con la misma clave, la restricción única gana y el alta se fusiona con ella en lugar de fallar.


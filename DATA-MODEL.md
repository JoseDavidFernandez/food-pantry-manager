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
| Lista de la compra | `shopping_lists`, `shopping_list_items` | Resultado del cierre, editable. |
| Recepción | `receptions`, `reception_items` | Registro confirmado de lo comprado. |

## 2. Entidades

Todas las tablas tienen `id`, `created_at` y `updated_at` (UTC). Las entidades de dominio cuelgan de `household_id` directa o indirectamente.

### households
`name`.

### household_user
`household_id` (FK), `user_id` (FK). Único (`household_id`, `user_id`).
MVP: además único `user_id` (un hogar por usuario, RN-01). Para admitir miembros más adelante se elimina ese único y se añade el rol; no hay columna de rol en el MVP.
El hogar y su pertenencia se crean en la misma transacción que el usuario al registrarse.

### users
Tabla del starter kit sin cambios de dominio.

### ingredients
`household_id`, `name` (como lo escribe el usuario), `normalized_name`, `default_unit` (opcional).
Único: (`household_id`, `normalized_name`).

### ingredient_aliases
`household_id`, `ingredient_id`, `alias`, `normalized_alias`.
Único: (`household_id`, `normalized_alias`). La aplicación valida además que ningún `normalized_alias` coincida con un `normalized_name` del hogar y viceversa (RN-04).
Resolución de un texto: normalizar → buscar en `ingredients.normalized_name` → buscar en `ingredient_aliases.normalized_alias` → si no hay coincidencia, ofrecer crear.

### inventory_items
`household_id`, `ingredient_id`, `location` (`fridge` | `pantry`), `unit` (la elegida por el usuario), `unit_group` (derivado de `unit`, §4), `quantity`, `label` (opcional, descriptivo).
Único: (`household_id`, `ingredient_id`, `location`, `unit_group`) → RN-08.

### recipes
`household_id`, `name`, `base_servings` (entero ≥ 1), `instructions` (opcional), `archived_at` (nullable).

### recipe_ingredients
`recipe_id`, `ingredient_id`, `quantity`, `unit` (tal como se introduce, p. ej. `kg`), `note` (opcional).
Único: (`recipe_id`, `ingredient_id`, `unit`).

### menus
`household_id`, `week_start` (`DATE`, siempre lunes), `status` (`draft` | `closed`), `closed_at`, `reopened_at`.
Único: (`household_id`, `week_start`).

### planned_dishes
`menu_id`, `day` (1–7, lunes = 1), `meal` (`lunch` | `dinner`), `type` (`recipe` | `adhoc`), `recipe_id` (nullable), `name` (nullable), `servings` (entero ≥ 1).
Único: (`menu_id`, `day`, `meal`) → un plato por espacio (RN-16). Un espacio vacío no tiene fila.
Coherencia: `type = recipe` ⇒ `recipe_id` no nulo; `type = adhoc` ⇒ `name` no nulo.

### planned_dish_ingredients
`planned_dish_id`, `ingredient_id`, `quantity`, `unit`. Cantidades para las `servings` del plato; no se escalan.

### shopping_lists
`menu_id` (único → RN-20), `household_id`, `generated_at`, `regenerated_at`.

### shopping_list_items

| Campo | Uso |
|---|---|
| `shopping_list_id` | FK. |
| `origin` | `generated` \| `manual`. |
| `ingredient_id` | Nullable solo para texto libre. |
| `name` | Texto mostrado (nombre del ingrediente o texto libre). |
| `unit`, `quantity` | Cantidad vigente y su unidad, expresada según RN-06. |
| `match_key` | `"{ingredient_id}:{unit_group}"`; nulo en texto libre. |
| `calculated_quantity` | Último valor calculado (tras redondeo); nulo si nunca se calculó. |
| `is_user_modified` | El usuario cambió cantidad o unidad de una línea generada. |
| `is_dismissed` | El usuario eliminó una línea generada (borrado lógico, oculta). |
| `is_purchased` | Marca de comprado. |
| `needs_review`, `review_reason` | Marca y motivo (`incompatible_inventory`, `calculation_changed`, `no_longer_needed`, `quantity_increased`). |

Único: (`shopping_list_id`, `match_key`). MySQL admite varios `NULL`, así que las líneas de texto libre no colisionan. Esto garantiza que no haya duplicados entre líneas generadas y manuales del mismo ingrediente y unidad (RN-24, RN-27).
Las líneas manuales se eliminan físicamente; las generadas, con `is_dismissed`.

### receptions
`household_id`, `shopping_list_id` (FK obligatoria, RN-29), `confirmed_at`.
Se crea ya confirmada, en la misma transacción que actualiza el inventario.

### reception_items
`reception_id`, `shopping_list_item_id` (nullable → producto no previsto), `ingredient_id`, `suggested_quantity` y `suggested_unit` (copia de la línea en el momento de recibir; nulos en no previstos), `outcome` (`received` | `not_purchased`), `quantity_received`, `unit`, `location`.
Coherencia:
- `received` ⇒ `quantity_received > 0`, con `unit` y `location` obligatorios.
- `not_purchased` ⇒ `quantity_received`, `unit` y `location` nulos.
- Un producto no previsto siempre es `received`.
- La petición de confirmación debe incluir un `outcome` para **cada** línea de la lista; si falta alguno, se rechaza (RN-29).

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
| `jar` | bote | `jar` | — | Sí |
| `pack` | paquete | `pack` | — | Sí |
| `tray` | bandeja | `tray` | — | Sí |

- Enum en código; no editable por el usuario. No hay tabla de equivalencias entre grupos (RN-06).
- Cada tabla guarda la **unidad elegida o resultante**; no se normaliza a g ni a ml al guardar.
- **Clave de compatibilidad** = (`ingredient_id`, `unit_group`). Solo los grupos `mass` y `volume` contienen más de una unidad.
- Combinar cantidades del mismo grupo con unidades distintas (g + kg, ml + l):
  1. Se opera internamente en g o ml con `bcmath`.
  2. El resultado se expresa según RN-06 (kg/l si es ≥ 1; si no, g/ml).
- Las unidades indivisibles nunca se convierten.
- Las comparaciones entre cantidades del mismo grupo (p. ej. "el cálculo cambió" en RN-27) se hacen sobre el valor equivalente, no sobre el texto de la unidad.

## 5. Cantidades

- Columnas `DECIMAL(12,3)`.
- Aritmética con `bcmath` (extensión disponible en local; sin dependencias nuevas) con escala intermedia suficiente y resultado a 3 decimales. Prohibido `float` en cálculos de cantidades.
- En el frontend las cantidades viajan como cadenas decimales y solo se formatean.

## 6. Procesos

### 6.1 Necesidad de un plato
Receta: `cantidad × servings / base_servings` (RN-13). Plato puntual: cantidades tal cual.

### 6.2 Cierre (RN-18 a RN-23, RN-27, RN-28)
En una transacción, con bloqueo de la fila del menú (`SELECT … FOR UPDATE`):
1. Si `status = closed`, terminar sin cambios (RN-19).
2. Validar completitud; si falla, abortar con errores por (día, comida). La advertencia de espacios vacíos se resuelve antes, en la petición: el cierre exige un indicador de confirmación cuando hay huecos.
3. Calcular necesidades por plato y agrupar por clave de compatibilidad, combinando según §4.
4. Restar el inventario compatible de ambas ubicaciones; `max(0, …)`; marcar `incompatible_inventory` si hay inventario del ingrediente en otro grupo de unidades.
5. Redondear hacia arriba las unidades indivisibles; descartar resultados 0.
6. Si la lista no existe: crear líneas `generated` con `quantity = calculated_quantity`.
   Si existe (cierre tras reapertura): conciliar por `match_key` aplicando la tabla de RN-27 y RN-28. Al comparar "cambia respecto al cálculo anterior" se usa `calculated_quantity`.
7. `status = closed`, `closed_at = now()`.

Las cantidades se guardan en la lista: editar o archivar una receta no altera una lista ya generada hasta un nuevo cierre.

### 6.3 Reapertura (RN-26)
`status = draft`, `reopened_at = now()`. La lista no se toca.

### 6.4 Confirmación de recepción (RN-29, RN-30)
1. Validar que cada línea de la lista tiene `outcome`.
2. En una transacción, crear `receptions` y `reception_items`.
3. Por cada ítem `received` (líneas y productos no previstos), sumar en la fila de inventario con la misma clave de compatibilidad y ubicación, combinando según §4; si no existe, crearla con la unidad recibida.
4. Los ítems `not_purchased` no tocan el inventario.

La protección frente a doble envío se apoya en un token de idempotencia del formulario (detalle en Fase 6).

## 7. Integridad

- FK con `ON DELETE` explícito:
  - `households` → cascada sobre sus datos de dominio.
  - `ingredients` → `restrict` si está en uso.
  - `recipes` → `restrict` desde `planned_dishes` (RN-14).
  - `menus` → cascada sobre platos y lista.
- Únicos descritos en §2.
- Validación de dominio en Form Requests (cantidades > 0, raciones ≥ 1, `week_start` en lunes, unidades del enum) y, cuando aplique, `CHECK` de MySQL 8.0 (soportados desde 8.0.16).
- Ningún proceso modifica `inventory_items` salvo la edición directa y §6.4 (RN-09).

## 8. Pendiente (no bloqueante)

- ¿Una o varias recepciones por lista? Propuesta: varias permitidas (compras en varias tiendas), cada una confirmada de una vez. Decidir antes de la Fase 6.

# Sistema de diseño — Food & Pantry Manager

> **Identidad visual «Baldosa hidráulica»** (rediseño R1–R7, 2026-10-25) sobre los componentes del starter kit (shadcn-vue + Tailwind 4). Los tokens, la tipografía, la forma y el movimiento están en [DESIGN.md](DESIGN.md); este documento recoge principios, patrones por pantalla y accesibilidad. Recorridos en [USER-FLOWS.md](USER-FLOWS.md); componentes disponibles verificados en [TECHNICAL-DESIGN.md §2](TECHNICAL-DESIGN.md).

## 1. Principios
1. **Claridad antes que decoración.** Cantidades, unidades y estados se leen de un vistazo.
2. **Móvil primero en lista de la compra y recepción.** Se usan de pie, en la tienda o en la cocina, con una mano. Inventario también debe funcionar bien en móvil.
3. **Escritorio para planificar.** Menú semanal y edición de recetas aprovechan pantallas anchas, sin dejar de funcionar en móvil.
4. **Acciones seguras.** Lo que afecta al inventario o a la lista pide confirmación y muestra su efecto.
5. **Siempre un estado.** Vacío, carga, error o contenido; nunca una pantalla en blanco.

## 2. Jerarquía
- Un título de página (`h1`) y una acción principal visible por pantalla.
- Acciones secundarias agrupadas (menú de acciones o botones `outline`/`ghost`).
- En listas: nombre del ingrediente como texto principal; cantidad y unidad como dato destacado.
- El menú no tiene estado: se planifica de forma continua y no se cierra (desde el 2026-10-21). Los días pasados se atenúan con «Pasado» y la compra se calcula por periodo en la lista (RN-36).

## 3. Tipografía
- Archivo variable, servida desde la aplicación; títulos de página y de sección en anchura condensada y mayúsculas (`font-display`). Detalle en [DESIGN.md](DESIGN.md).
- Escala Tailwind: `text-sm` (metadatos), `text-base` (cuerpo), `text-lg`/`text-xl` (secciones), `text-2xl`/`text-3xl` (título).
- `tabular-nums` en cantidades.
- Formato español: coma decimal, sin ceros superfluos (1,5 kg; 200 g; 2 botes).
- **Totales legibles** (2026-10-21, `Quantity::readable()`, solo presentación):
  - Masa en kg desde 1000 g y en g por debajo; volumen en l desde 1000 ml y en ml por debajo. Ejemplos: 400 g + 600 g → «1 kg»; 750 g + 500 g → «1,25 kg».
  - Es exacto: hasta 6 decimales, sin redondeos.
  - Se aplica a los totales y cálculos de la lista de la compra («Necesitas», «Tienes · Comprometido · Disponible», «Recibido X · falta Y»), a la recepción, a «Ingredientes necesarios» del menú y a Inicio.
  - Las cantidades escritas por el usuario (líneas manuales o editadas y cada recepción del historial) se muestran como se escribieron.
  - Las unidades indivisibles nunca se convierten. El inventario muestra la unidad almacenada, que es también la de su edición.

## 4. Color
- Solo **tokens semánticos** (variables CSS en `resources/css/app.css`): `background`, `foreground`, `card`, `primary`, `muted`, `accent`, `destructive`, `border`, `ring`… Nunca colores literales en componentes.
- **Dirección visual (2026-10-25): «Baldosa hidráulica».** Fondo de cemento, almagre como color de acción y selección (`primary`), carbón para el texto y la barra lateral, cobalto para el foco (`ring`) y cuatro pigmentos (`--pigment-*`); la comida se pinta en ocre y la cena en cobalto. Radios casi rectos. Valores claro/oscuro en [DESIGN.md](DESIGN.md).
- Tokens de estado, ya en uso:
  - `success` y `success-soft`: completo, comprado.
  - `warning`, `warning-soft` y `warning-border`: incompleto, para revisar, sin clasificar.
- **Contraste AA:** el texto de cada token sobre su fondo cumple 4,5:1, medido en claro y oscuro en todas las páginas a 360 px (R1–R7).
- El color nunca es el único portador de significado: siempre con icono o texto.
- Frigorífico y despensa se distinguen por icono y etiqueta.

## 5. Componentes
| Necesidad | Patrón |
|---|---|
| Navegación | Barra lateral en carbón con la página activa en almagre; en móvil, panel lateral (§8). Primer elemento: enlace «Saltar al contenido», visible al recibir el foco. |
| Hogar y roles | «Tu hogar» e «Invitación» usan el diseño de autenticación (tarjeta centrada, con avisos), porque el usuario aún no está dentro de la app. Ajustes › Hogar: nombre, miembros con su rol (selector para el administrador en los demás miembros; el propio rol, siempre como etiqueta), invitaciones recibidas de otros hogares, invitar (correo y rol), enlace mostrado una vez con «Copiar», pendientes con «Revocar», historial plegado y «Salir del hogar» con confirmación. El lector ve un aviso «Modo lectura» bajo la cabecera de todas las páginas y no se le ofrecen acciones de modificación. |
| Formularios | Input, Select, Combobox (ingredientes, recetas), Label y error por campo. |
| Cantidad + unidad | Componente propio: input decimal + selector de unidad. |
| Listados | Tabla en escritorio; filas/tarjetas apiladas en móvil. |
| Menú semanal | Tablero de baldosas: siete filas (días) × dos columnas (Comida en ocre, Cena en cobalto; huecos en cemento), con juntas entre piezas, hoy marcado y los días pasados atenuados con «Pasado». En móvil, tira de 7 días (con marca de lo planificado) y las dos piezas del día elegido, hoy por defecto. Cada celda (grupo con nombre accesible «Martes 20, cena: Lentejas, Fruta» o «…: vacío») contiene tarjetas de plato (`PlannedDishCard`, «Lentejas, 4 raciones») con raciones, tipo y avisos (sin ingredientes, archivada, No comido, alternativa). Sus acciones (Cambiar, Mover a…, Subir/Bajar, No comido, Retirar) están en un menú «⋯» con texto, conservando sus nombres accesibles; «Añadir» al pie de la celda. |
| Cabecera | Píldora con el icono de casa y el nombre del hogar real, enlazada a Ajustes › Hogar, a la derecha de la cabecera; se recorta con puntos suspensivos (máx. 45 % del ancho en móvil) y el nombre completo está en `title` y en el nombre accesible. |
| Alternativas | Bajo el plato, texto con icono de cruce: en `warning` «Propuesta: X» y en `muted` «Con X», con enlace «Elegir/Cambiar». El diálogo usa filas de radio de 44 px con cantidad e insignia «En casa». En la receta, la alternativa se crea con «Añadir una alternativa a «X»»; la fila resultante va sobre fondo `muted`, pegada a la anterior, con la casilla «O bien» para deshacerla. |
| Unidades y compra | Sección de la ficha del alimento: equivalencias como insignias con «×», formulario «1 [unidad] = [cantidad] [unidad]» con «Añadir equivalencia», y «Se compra en» con el contenido del envase. En la lista, una línea secundaria «Faltan X · se compra en envases enteros (1 bolsa = 500 g)». |
| Recetario y arrastrar y soltar | En escritorio, columna lateral fija (`lg`) con buscador; en móvil, panel lateral abierto con «Recetario». Arrastre nativo de HTML5, sin librerías: icono de agarre, la celda de destino se resalta con `primary` y anillo. Siempre hay una alternativa con diálogo («Añadir … al menú» y «Mover»), operable con teclado. Soltar o mover añade al final del destino: nunca sustituye (RN-33). El asa de arrastre deja pasar el puntero (`pointer-events-none`) para que el arrastre empiece en la tarjeta. |
| Inicio | Si la semana no tiene comidas, un aviso en carbón con una sola acción («Planificar esta semana»; el domingo, «Planificar la semana que viene»). «Hoy» como dos baldosas grandes, Comida y Cena. Debajo, la semana como tablero de baldosas (sigue siendo una tabla, con hoy resaltado y `aria-current="date"`) y la lista de la compra de los próximos 7 días. Sin accesos duplicados de la navegación. |
| Lista de la compra | (RN-48, 2026-10-23.) Cabecera con las fechas en corto; barra con el botón del periodo (diálogo con botones de periodo y fechas en una columna en móvil) y «Añadir» (diálogo); resumen de recuentos. Fila con checkbox de 44 px (casilla visible de 28 px), el nombre, «Faltan **X**» y, si se compra en envases, «Para comprar: **N envases** (contenido)», insignias y un `<details>` «Ver cálculo» con chevron que gira al abrir. «En el carro»: fondo `muted` e insignia; el tachado solo en lo recibido. Avisos del cálculo y «Compras registradas» en `<details>` plegados; las acciones secundarias, al pie. Las listas del historial se muestran con un aviso y sin acciones. |
| Básicos (RN-47) | Ficha: sección «Mínimo en casa» con texto de ayuda, «Al menos» + unidad, «Guardar mínimo» y «Quitar el mínimo» (fantasma). Tarjeta de existencias: «Se acabó» en el menú «⋯», con confirmación que nombra las ubicaciones. En la lista, insignias «Mínimo en casa» y «Se acabó». |
| Recepción | Fila por línea con cantidad real editable y ubicación; acción principal fija al pie en móvil. |
| Confirmaciones | `Dialog` (`@/components/ui/dialog`): título y descripción que explican la consecuencia, y al pie «Cancelar» (`DialogClose`) y un botón con verbo concreto («Sí, se acabó» o «Salir del hogar», no «Aceptar»). Excepciones: el borrado del catálogo en la ficha del alimento se confirma en línea, y salir de «Registrar compra» con artículos marcados usa la confirmación nativa del navegador. |
| Feedback | Toast para éxito; Alert inline para errores. |
| Carga | Skeleton en listados; botón desactivado con indicador al enviar. |
| Vacíos | Icono opcional, frase breve y acción principal. |
| Insignias | Incompleto, No comido, Archivada, Manual, Modificada, Para revisar, Comprado, Recibido; en el historial: Actual, Terminada, Semanal (anterior). Borrador y Cerrado ya no se muestran: los menús no se cierran desde el 2026-10-21. |
| Encabezado de página | `Heading` (por defecto) o `PageHeader`: el único `h1` de la página, una descripción y las acciones a la derecha. Los títulos de sección son `h2` (`Heading variant="small"` o encabezados de sección). |
| Vacíos | `EmptyState`: icono en círculo `accent`, frase breve, explicación y, si existe, la acción que lo resuelve. Se distingue entre «no hay nada» y «ningún resultado con estos filtros» (con «Quitar filtros»). |
| Imagen de ingrediente | `IngredientThumb`: la copia local de la imagen o, si no hay (o falla al cargar), el icono de su categoría sobre `accent`. Es decorativa (`alt=""`): el nombre siempre está al lado. |
| Existencias previstas (RN-44) | Cuando difieren de lo registrado, la cifra principal de la tarjeta es «≈ X previstos», en negrita, con «registrado Y» debajo en `muted`. El nombre accesible del botón es «Corregir la cantidad de … ≈ X previstos, registrado Y». Encima del pie hay un `<details>` nativo («≈ Es una estimación. ¿Por qué?») con las comidas que lo explican y la regla de orden (frigorífico → despensa → congelador). Sin color de aviso ni insignias: es información, no una tarea. En la lista y en el menú: «Tienes ≈ X (previsto; registrado Y)». |
| Guardar lo marcado (RN-46) | Barra fija al pie de la lista («N en el carro» y el botón principal «Guardar N en casa», 44 px en móvil) que solo aparece si hay algo marcado. La hoja es un diálogo con una tarjeta por artículo: cantidad, unidad y «Dónde lo guardas», con controles de 44 px en móvil. |
| Inventario | Único apartado de alimentos. Pestañas (Todo, Frigorífico, Congelador, Despensa, Sin existencias) con recuento como navegación principal. Búsqueda instantánea (nombre y alias) y filtro opcional por categoría, con aviso de alimentos «Sin clasificar». Secciones por ubicación con rejilla de tarjetas (1 columna en móvil, 2–3 en escritorio): imagen o icono, nombre, categoría, menú «⋯» (Editar, Se acabó, Eliminar) y ajuste rápido (− cantidad +) en el pie. En «Sin existencias», tarjetas con categoría, uso en recetas y «Añadir al inventario»; debajo, «Alimentos habituales» (RN-49): grupos plegables por categoría (`FoldSection`) con botones «+ Nombre» de 44 px en móvil y el aviso de nombre parecido en línea, con «Crear igualmente». La acción principal, «Añadir al inventario», abre un diálogo. |
| Ficha del alimento | Diálogo que se abre al pulsar el nombre o la imagen de una tarjeta: nombre, categoría y unidad habitual; secciones plegables con su resumen (alias, unidades y compra, mínimo en casa, imagen), que se abren solas si tienen errores; selector de imagen; eliminar del catálogo con confirmación en línea (bloqueado si está en uso). Las existencias se editan aparte, en su tarjeta. |
| Selector de imagen | Fuentes: Fotos (Wikimedia Commons), Productos envasados o Código de barras (Open Food Facts). «Buscar» explícito, resultados en rejilla, elección marcada y «Usar esta imagen» o «No usar ninguna». Muestra la atribución (autor y licencia con enlaces) y los estados sin resultados y de error, que siempre permiten continuar sin imagen. |

Iconos: Lucide. Categorías (`resources/js/lib/visuals.ts`): manzana (frutas), zanahoria (verduras), muslo (carne), pez (pescado), botella de leche (lácteos y huevos), cruasán (panadería), espiga (pasta, arroz y legumbres), jamón (embutidos), paquete (conservas), cuenco de sopa (caldos, cremas y sopas), refresco (bebidas), gota (salsas y aceites), cesta (otros) e interrogación (sin clasificar). Ubicaciones: frigorífico, copo de nieve (congelador) y archivador (despensa). El icono siempre va acompañado de su texto.

## 6. Accesibilidad
- Objetivo **WCAG 2.2 AA**: contraste 4,5:1 en texto y 3:1 en componentes; foco visible; todo operable con teclado.
- Objetivos táctiles ≥ 44×44 px en móvil (área real medida con `elementFromPoint`, no solo el tamaño visible): botones de icono `size-11 sm:size-9`, botones de texto `min-h-11 sm:min-h-8/9`, campos `h-11 sm:h-10`. La × de los diálogos (`DialogContent`) tiene una zona de 44 × 44 en móvil y de 32 × 32 desde `sm`, con el icono siempre en la misma posición. En los diálogos que se abren desde la lista y el inventario, los campos, los botones y las etiquetas de las casillas también miden 44 px en móvil, sin cambiar el escritorio (`sm:min-h-0`, `sm:h-9`). Desde R1 los componentes base (botón, campo, selector, casilla, elemento de menú, enlace suelto `tap-target`) miden 44 px en móvil en toda la aplicación.
- Etiquetas asociadas; errores enlazados con `aria-describedby`.
- Celdas del menú con nombre accesible («Martes 20, cena: Lentejas, Fruta») y cada plato con el suyo («Lentejas, 4 raciones»).
- Cambios de estado anunciados con `aria-live`.
- `prefers-reduced-motion`: diálogos y paneles solo funden (150 ms), sin deslizarse ni ampliarse; los marcadores de carga no laten; los indicadores giratorios se mantienen.
- El foco nunca queda bajo una barra fija inferior (`scroll-padding-bottom`, WCAG 2.4.11).

## 7. Responsive
- Breakpoints por defecto de Tailwind; diseño base a 360 px.
- Sin scroll horizontal de página.
- Formularios de una columna en móvil.

## 8. Validación y mejoras futuras
- Identidad visual «Baldosa hidráulica» (rediseño R1–R7, con logotipo de baldosa y Archivo): validada por el usuario en el navegador (2026-10-29), tras su prueba manual intensiva (2026-10-28).
- Modo oscuro: medido pantalla a pantalla en el rediseño (contraste y controles en claro y oscuro) y validado por el usuario (2026-10-29).
- Accesibilidad: la revisión formal WCAG 2.2 AA (CA-18) sigue pendiente y es una tarea de la Fase 7 ([ROADMAP.md](ROADMAP.md)).
- Navegación en móvil: menú lateral desplegable del kit, que se cierra al empezar cualquier visita, también al pulsar la página actual (comprobado por E2E a 360 px). **Mejora futura:** una barra inferior.

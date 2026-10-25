---
name: Food & Pantry Manager
description: Planificación de comidas, inventario y compra para un hogar, sobre un suelo de baldosa hidráulica.
colors:
  almagre: "#a63f28"
  almagre-on: "#ffffff"
  carbon: "#1f1d1b"
  cemento: "#e4e3df"
  pieza: "#f7f7f4"
  popover: "#fbfbf9"
  cemento-junta: "#cbc8c0"
  cemento-liso: "#e0dfda"
  cemento-secundario: "#dbd9d3"
  cemento-hover: "#d6d4cd"
  texto-apagado: "#55524c"
  trazo-campo: "#969288"
  ocre: "#c98a1e"
  verde-botella: "#2f5d4a"
  verde-botella-suave: "#dce8e1"
  cobalto: "#2b4c8c"
  cobalto-suave: "#dce3f0"
  aviso: "#7d5210"
  aviso-suave: "#f5e6c8"
  aviso-borde: "#d9ae62"
  destructivo: "#a51c30"
  barra-lateral-texto: "#e4dfd7"
  barra-lateral-activo: "#34302b"
  dark-cemento: "#161412"
  dark-pieza: "#201d1a"
  dark-texto: "#ece8e1"
  dark-almagre: "#e0805f"
  dark-junta: "#3a342e"
  dark-texto-apagado: "#b5ab9e"
  dark-cobalto: "#8fb0e6"
  dark-barra-lateral: "#0f0e0d"
typography:
  display:
    fontFamily: "Archivo, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.875rem"
    fontWeight: 800
    lineHeight: 1
    letterSpacing: "-0.005em"
    fontVariation: "'wdth' 75"
  display-mobile:
    fontFamily: "Archivo, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.75rem"
    fontWeight: 800
    lineHeight: 1
    letterSpacing: "-0.005em"
    fontVariation: "'wdth' 75"
  title:
    fontFamily: "Archivo, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 700
    letterSpacing: "0.025em"
  body:
    fontFamily: "Archivo, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: 1.43
    fontFeature: "'tnum'"
  label:
    fontFamily: "Archivo, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 600
    fontFeature: "'tnum'"
rounded:
  sm: "2px"
  md: "4px"
  lg: "6px"
  xl: "8px"
spacing:
  junta: "6px"
  junta-movil: "2px"
  pieza: "12px"
  pagina-movil: "16px"
  pagina: "24px"
  toque: "44px"
components:
  button-primary:
    backgroundColor: "{colors.almagre}"
    textColor: "{colors.almagre-on}"
    rounded: "{rounded.md}"
    padding: "8px 16px"
    height: "36px"
  button-outline:
    backgroundColor: "{colors.pieza}"
    textColor: "{colors.carbon}"
    rounded: "{rounded.md}"
    padding: "8px 16px"
    height: "36px"
  button-outline-hover:
    backgroundColor: "{colors.cemento-hover}"
  button-ghost-hover:
    backgroundColor: "{colors.cemento-hover}"
    textColor: "{colors.carbon}"
  input:
    backgroundColor: "{colors.pieza}"
    textColor: "{colors.carbon}"
    rounded: "{rounded.md}"
    padding: "4px 12px"
    height: "36px"
  filter-tab:
    backgroundColor: "{colors.pieza}"
    textColor: "{colors.carbon}"
    rounded: "{rounded.md}"
    padding: "0 14px"
    height: "40px"
  filter-tab-active:
    backgroundColor: "{colors.carbon}"
    textColor: "{colors.cemento}"
  badge-success:
    backgroundColor: "{colors.verde-botella-suave}"
    textColor: "{colors.verde-botella}"
    rounded: "{rounded.sm}"
    padding: "2px 6px"
  badge-warning:
    backgroundColor: "{colors.aviso-suave}"
    textColor: "{colors.aviso}"
    rounded: "{rounded.sm}"
    padding: "2px 6px"
  badge-info:
    backgroundColor: "{colors.cobalto-suave}"
    textColor: "{colors.cobalto}"
    rounded: "{rounded.sm}"
    padding: "2px 6px"
  menu-tile-empty:
    backgroundColor: "{colors.cemento-liso}"
    rounded: "{rounded.sm}"
    padding: "12px"
  dialog:
    backgroundColor: "{colors.pieza}"
    textColor: "{colors.carbon}"
    rounded: "{rounded.lg}"
    padding: "24px"
    width: "512px"
  sidebar:
    backgroundColor: "{colors.carbon}"
    textColor: "{colors.barra-lateral-texto}"
---

# Design System: Food & Pantry Manager

> Este archivo registra el **sistema visual** construido (mundo, tokens, tipografía, forma, movimiento y reglas con nombre). Los principios funcionales, los patrones por pantalla y los requisitos de accesibilidad siguen en [DESIGN-SYSTEM.md](DESIGN-SYSTEM.md), que no se duplica aquí. Fuente normativa de los valores: `resources/css/app.css`.

## Overview

**Creative North Star: "Baldosa hidráulica"**

La aplicación es un suelo de baldosas hidráulicas: un fondo de cemento gris, piezas claras asentadas sobre él y juntas finas entre pieza y pieza. Cada comida planificada es una baldosa que toma el pigmento de su franja (ocre para la comida, cobalto para la cena); un hueco vacío es cemento liso. Rechaza el calendario de tarjetas blancas con borde de las aplicaciones del sector: aquí la estructura la dan las juntas y el tono, no las sombras.

La densidad es de herramienta doméstica: mucha información por pantalla, texto a 14 px, cifras tabulares en toda la interfaz y títulos de página en una grotesca condensada en mayúsculas (Archivo al 75 % de anchura) que da la voz de rótulo. El almagre es el único color de acción y selección; el carbón sostiene el texto y la barra lateral; el cobalto marca el foco del teclado. Ocre, verde botella y cobalto (más el almagre) son pigmentos de categoría y estado, nunca decoración.

El sistema funciona en claro y en oscuro con los mismos papeles, mantiene contraste WCAG 2.2 AA en todos los pares de texto y garantiza áreas táctiles de 44 × 44 px por debajo de 640 px desde los componentes base.

**Key Characteristics:**
- Fondo de cemento, piezas claras, juntas como bordes o como huecos de 6 px sobre el color de junta.
- Almagre solo para acción primaria, selección y sección activa.
- Títulos en Archivo condensada (anchura 75 %, peso 800, mayúsculas); cifras tabulares en todo el `body`.
- Radios casi rectos (2–8 px).
- Plano: profundidad por tono y junta; sombra solo en lo que flota (diálogos, menús).
- 44 px de área táctil en móvil y movimiento reducido a un fundido de 150 ms.

## Colors

Paleta mineral de cemento y carbón con un acento de almagre y tres pigmentos de baldosa que solo significan algo.

### Primary
- **Almagre** (`almagre`): botón principal, enlaces de texto (subrayado al 40 %), sección activa de la barra lateral, barra de progreso de Inertia, anillo de destino al arrastrar. En oscuro se aclara a **almagre tostado** (`dark-almagre`) con texto sobre él en cemento oscuro.

### Secondary
- **Ocre** (`ocre`): pigmento de la comida del mediodía. Se usa como tinte de la baldosa (`--tile-lunch`: `color-mix(in oklab, ocre 26%, pieza)`; 22 % en oscuro) y como cuarto de disco en la esquina de la pieza.
- **Cobalto** (`cobalto`): pigmento de la cena (`--tile-dinner`: 16 % sobre la pieza; 22 % en oscuro), color del foco (`--ring`) y de la información (`--info`, con `cobalto-suave` de fondo). En oscuro el foco pasa a `dark-cobalto`.

### Tertiary
- **Verde botella** (`verde-botella`): éxito y estado «suficiente» (`--success`, fondo `verde-botella-suave`).
- **Aviso** (`aviso` sobre `aviso-suave`, borde `aviso-borde`): avisos de revisión y recuadros de advertencia.
- **Destructivo** (`destructivo`): solo acciones irreversibles y errores de validación.

### Neutral
- **Cemento** (`cemento`): fondo de la aplicación.
- **Pieza** (`pieza`): tarjetas, diálogos, campos y botones de contorno. `popover` para menús desplegables.
- **Carbón** (`carbon`): texto, barra lateral, pestaña de filtro activa y anillo de «hoy».
- **Junta** (`cemento-junta`): bordes y fondo de la rejilla del menú, que se ve entre las piezas.
- **Cemento liso** (`cemento-liso`): hueco vacío del menú y superficies apagadas; **secundario** (`cemento-secundario`) y **hover** (`cemento-hover`) para botones secundarios y estados de paso.
- **Texto apagado** (`texto-apagado`): descripciones y metadatos (AA sobre cemento y pieza).
- **Trazo de campo** (`trazo-campo`): borde de los campos de formulario, más oscuro que la junta para que el campo se distinga.
- **Superposición**: `rgb(31 29 27 / 0.6)` en claro, `rgb(0 0 0 / 0.7)` en oscuro.

### Named Rules
**The Pigment Means Something Rule.** Ocre, cobalto, verde botella y almagre solo codifican franja, categoría, selección o estado. Una superficie no se tiñe por adorno.

**The One Action Colour Rule.** El almagre es el único color de acción. El foco es cobalto, nunca almagre, para que «seleccionado» y «con foco» no se confundan.

**The Tint, Not Fill Rule.** Las baldosas planificadas llevan el pigmento mezclado con la pieza (16–26 %) para que el texto en carbón mantenga AA; el pigmento puro solo aparece en marcas pequeñas (esquina, punto de leyenda).

## Typography

**Display Font:** Archivo variable, autoalojada en `resources/fonts/archivo` (OFL), peso 100–900 y anchura 62–125 %, con `ui-sans-serif, system-ui, sans-serif` de reserva.
**Body Font:** la misma Archivo a anchura normal.

**Character:** Una sola familia con dos voces: la condensada en mayúsculas es el rótulo de la pieza; la normal es la letra de trabajo. Las cifras son tabulares en todo el documento (`body { tabular-nums }`), para que cantidades y recuentos se alineen.

### Hierarchy
- **Display** (800, 1.75rem en móvil / 1.875rem desde 640 px, interlineado 1, anchura 75 %, mayúsculas, −0.005em): el único `h1` de cada página (`PageHeader`, `Heading`), el número del día en el menú (1.125rem) y la cifra destacada de la semana (1.5rem). Utilidad `font-display`.
- **Title** (700, 0.875rem, +0.025em, mayúsculas): los `h2` de sección («Comidas y cenas», «Recetario», «Hoy…»), que encabezan contenido real; en pasillos de la lista baja a 0.75rem.
- **Body** (400, 0.875rem; 1rem en campos en móvil para evitar el zoom): texto general; párrafos largos limitados a `max-w-prose`.
- **Label** (600, 0.75rem): insignias, etiquetas de franja (Comida/Cena), resúmenes de secciones plegadas.

### Named Rules
**The One Display Voice Rule.** La condensada en mayúsculas es solo para el `h1` de página, el logotipo y las cifras de la semana. No se usa en botones, cuerpos ni etiquetas.

**The Tabular Figures Rule.** Toda cifra se compone en tabulares; no se desactiva localmente.

## Layout

Columna única centrada con anchura según la tarea, dentro del área a la derecha de la barra lateral. Márgenes de página de 16 px en móvil y 24 px desde 640 px; separación entre bloques de 12–24 px. El único punto de corte de comportamiento es 640 px (`sm`): por debajo, diálogos como hoja inferior, áreas táctiles de 44 px y tira de días; por encima, rejilla completa. A partir de `lg` el menú añade el recetario en una columna fija de 17rem.

La rejilla del menú es el patrón espacial del mundo: un contenedor con fondo de junta, 6 px de relleno y 6 px de hueco, columnas `6.5rem | 1fr | 1fr` (día, comida, cena) en escritorio con `subgrid`; en móvil, una tira de 7 baldosas de día con 2 px de junta y, debajo, las dos piezas del día elegido. Cada pieza mide al menos 96 px de alto con 10–12 px de relleno.

Con barras inferiores fijas (`[data-checked-bar]`, `[data-bottom-bar]`), el documento reserva 5.5rem de `scroll-padding-bottom` para que el foco nunca quede debajo.

## Elevation & Depth

Plano por defecto. La profundidad se expresa con tono (cemento < pieza < popover) y con juntas, no con sombras. Las piezas de la rejilla no llevan sombra ni borde: la junta es el hueco. Las sombras quedan para lo que flota sobre la página.

### Shadow Vocabulary
- **Asiento** (`shadow-xs`, `0 1px 2px 0 rgb(0 0 0 / 0.05)`): botones principales y de contorno, campos, píldora del hogar. Apenas perceptible.
- **Flotante** (`shadow-md`): menús desplegables y selectores.
- **Diálogo** (`shadow-lg`): diálogos y hojas, siempre con la superposición detrás.

### Named Rules
**The Joint Not Shadow Rule.** Para separar piezas vecinas se usa la junta (hueco sobre `cemento-junta` o borde de 1 px), nunca una sombra.

## Shapes

Radios casi rectos derivados de `--radius: 0.375rem`: 2 px (`sm`) en baldosas del menú, insignias y marcas; 4 px (`md`) en botones, campos, pestañas y contenedores de rejilla; 6 px (`lg`) en diálogos de escritorio y tarjetas; 8 px (`xl`) en el borde superior de la hoja inferior móvil. Bordes de 1 px en color de junta; los campos usan el trazo más oscuro. La forma firma del sistema es el **cuarto de disco** de 16 px en la esquina inferior derecha de una baldosa planificada, en el pigmento de su franja, y el logotipo: una baldosa de 32 px con esquinas de almagre, rombo ocre y núcleo carbón.

## Components

### Buttons
Firmes y sobrios: un rectángulo casi recto que no se anima más allá del color.
- **Shape:** esquinas de 4 px; 36 px de alto (32 px `sm`, 40 px `lg`); en móvil, mínimo 44 px de alto y, si solo llevan icono, 44 px de ancho.
- **Primary:** almagre con texto blanco, peso 500 a 14 px, `shadow-xs`; hover al 85 % de opacidad.
- **Hover / Focus:** transición de color; foco con anillo de 2 px en cobalto y 2 px de separación sobre el fondo.
- **Outline:** pieza con borde de junta; hover a `cemento-hover`. **Secondary:** cemento secundario. **Ghost:** solo hover. **Link:** almagre en 600 con subrayado de 2 px al 40 %, completo en hover. **Destructive:** fondo destructivo, solo para acciones irreversibles.

### Chips (insignias y pestañas de filtro)
- **Insignias:** esquinas de 2 px, 12 px en 600, interlineado 1, mínimo 20 px de alto; variantes de estado en par suave/intenso (verde botella, aviso con borde, cobalto).
- **Pestañas de filtro:** 44 px en móvil y 40 px desde 640 px, esquinas de 4 px, 600; inactiva en pieza con borde, activa en carbón con texto cemento y `aria-current="page"`; recuento tabular. En móvil desplazan en horizontal con un fundido de 2rem en el borde.

### Cards / Containers
- **Corner Style:** 6 px (`lg`) para tarjetas; 2 px para baldosas.
- **Background:** pieza sobre cemento.
- **Shadow Strategy:** ninguna en reposo (ver Elevation & Depth).
- **Border:** 1 px de junta, o ninguno cuando la junta es el hueco de la rejilla.
- **Internal Padding:** 12–24 px.

### Inputs / Fields
- **Style:** pieza con trazo de campo de 1 px, esquinas de 4 px, 36 px de alto (44 px en móvil, 16 px de texto para evitar el zoom), `shadow-xs`.
- **Focus:** borde cobalto y anillo de 2 px de cobalto al 40 %.
- **Error / Disabled:** borde destructivo con anillo al 20 % (40 % en oscuro); desactivado al 50 %.

### Navigation
Barra lateral carbón (`#0f0e0d` en oscuro) con texto en cemento claro; el elemento activo en almagre. Cabecera de 64 px con borde inferior, botón de barra lateral de 44 px en móvil y la píldora del hogar (pieza, borde, icono en almagre). En móvil la barra lateral es un panel que se cierra al navegar.

### Dialogs and Sheets
Por debajo de 640 px, todo diálogo es una hoja inferior a ancho completo (máx. 92svh, esquinas superiores de 8 px, asa de 40 × 4 px, relleno inferior que respeta la zona segura) que entra deslizando desde abajo. Desde 640 px, diálogo centrado de 512 px, esquinas de 6 px y 24 px de relleno, con fundido y zoom al 95 % en 200 ms. El botón «Cerrar» mide 44 × 44 px en móvil y 32 × 32 px en escritorio, con el icono en la misma posición.

### Menu Tile (signature)
La baldosa de una franja del menú: mínimo 96 px de alto, esquinas de 2 px, sin borde. Vacía: cemento liso con «+». Planificada: tinte de su pigmento (`--tile-lunch` o `--tile-dinner`) con el cuarto de disco en la esquina y los platos separados por líneas de carbón al 10 %. **Hoy**: anillo interior de 2 px en carbón (la cenefa). **Pasado**: saturación al 35 %. **Destino al arrastrar**: `cemento-hover` con anillo interior de 2 px en almagre.

### Folded Section
Una sección plegable (`details`) con borde superior de junta, cabecera de 48 px de alto con título en 600 y resumen de una línea en texto apagado, y un chevrón que gira 180°. Sirve para que una ficha larga se lea de un vistazo.

### Motion
Transiciones de color en controles; diálogos y hojas con fundido más zoom o deslizamiento (200 ms). Con `prefers-reduced-motion: reduce`, diálogos y hojas solo funden en 150 ms (sin traslación, escala ni giro), los marcadores de carga dejan de pulsar y el desplazamiento suave se desactiva; los indicadores de giro se conservan porque son la única señal de progreso.

## Do's and Don'ts

### Do:
- **Do** usar los tokens de `app.css` (`bg-primary`, `bg-tile-lunch`, `bg-pigment-*`, `text-muted-foreground`…) y nunca hex literales en componentes.
- **Do** dar a cada control 44 × 44 px por debajo de 640 px: los componentes base ya lo hacen; para enlaces de texto sueltos, la utilidad `tap-target`.
- **Do** separar piezas con junta (hueco de 6 px sobre `cemento-junta`, 2 px en la tira móvil) y dejar los huecos vacíos en cemento liso.
- **Do** componer el `h1` de cada página con `font-display` a través de `PageHeader` o `Heading`, uno por página.
- **Do** comprobar cada pareja de texto y fondo nueva en claro y en oscuro con AA.
- **Do** mantener el foco en cobalto, 2 px y 2 px de separación.

### Don't:
- **Don't** usar almagre ni ningún pigmento como relleno decorativo de superficies.
- **Don't** separar piezas con sombras ni construir la semana con tarjetas blancas con borde.
- **Don't** redondear por encima de 8 px las piezas, botones o pestañas.
- **Don't** usar la voz condensada en mayúsculas para botones, etiquetas o cuerpo de texto.
- **Don't** usar el rojo destructivo para estados que no son error ni acción irreversible.
- **Don't** añadir animaciones que se trasladen o escalen sin la alternativa de movimiento reducido.

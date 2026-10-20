# Sistema de diseño — Food & Pantry Manager

> **Identidad visual provisional.** No hay marca, paleta ni tipografía definitivas; se parte de los tokens y componentes del starter kit (shadcn-vue + Tailwind 4) hasta validarlas. Recorridos en [USER-FLOWS.md](USER-FLOWS.md); componentes disponibles verificados en [TECHNICAL-DESIGN.md §2](TECHNICAL-DESIGN.md).

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
- Estado del menú (borrador/cerrado) siempre visible en su cabecera.

## 3. Tipografía (provisional)
- La del starter kit hasta decidir.
- Escala Tailwind: `text-sm` (metadatos), `text-base` (cuerpo), `text-lg`/`text-xl` (secciones), `text-2xl` (título).
- `tabular-nums` en cantidades.
- Formato español: coma decimal, sin ceros superfluos (1,5 kg; 200 g; 2 botes).

## 4. Color (provisional)
- Solo **tokens semánticos** del kit (variables CSS de shadcn-vue: `background`, `foreground`, `primary`, `muted`, `accent`, `destructive`, `border`, `ring`…); nunca colores literales en componentes.
- Tokens adicionales propuestos: `success` (completo, comprado) y `warning` (incompleto, para revisar).
- El color nunca es el único portador de significado: siempre con icono o texto.
- Frigorífico y despensa se distinguen por icono y etiqueta.

## 5. Componentes
| Necesidad | Patrón |
|---|---|
| Navegación | Layout de barra lateral del kit en escritorio; patrón móvil pendiente (§8). |
| Formularios | Input, Select, Combobox (ingredientes, recetas), Label y error por campo. |
| Cantidad + unidad | Componente propio: input decimal + selector de unidad. |
| Listados | Tabla en escritorio; filas/tarjetas apiladas en móvil. |
| Menú semanal | Rejilla 7×2 en escritorio; lista por día en móvil. Celda con estado vacío/completo/incompleto. |
| Lista de la compra | Fila táctil con checkbox grande, cantidad e indicadores (manual, modificada, para revisar). |
| Recepción | Fila por línea con cantidad real editable y ubicación; acción principal fija al pie en móvil. |
| Confirmaciones | AlertDialog que describe la consecuencia; botón con verbo concreto ("Cerrar menú", no "Aceptar"). |
| Feedback | Toast para éxito; Alert inline para errores. |
| Carga | Skeleton en listados; botón desactivado con indicador al enviar. |
| Vacíos | Icono opcional, frase breve y acción principal. |
| Insignias | Borrador, Cerrado, Incompleto, Archivada, Manual, Modificada, Para revisar, Comprado. |

Iconos: los del kit (Lucide).

## 6. Accesibilidad
- Objetivo **WCAG 2.2 AA**: contraste 4,5:1 en texto y 3:1 en componentes; foco visible; todo operable con teclado.
- Objetivos táctiles ≥ 44×44 px en móvil.
- Etiquetas asociadas; errores enlazados con `aria-describedby`.
- Celdas del menú con nombre accesible ("Martes, cena: Lentejas, 4 raciones").
- Cambios de estado anunciados con `aria-live`.
- Respetar `prefers-reduced-motion`.

## 7. Responsive
- Breakpoints por defecto de Tailwind; diseño base a 360 px.
- Sin scroll horizontal de página.
- Formularios de una columna en móvil.

## 8. Pendiente de validar (no bloqueante)
- Identidad visual: nombre visible, logotipo, paleta, tipografía.
- Modo oscuro.
- Navegación en móvil (menú lateral desplegable del kit o barra inferior).

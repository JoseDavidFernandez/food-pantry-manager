# Product

<!-- impeccable:product-schema 1 -->

> Contexto de producto para el trabajo de diseño con Impeccable. Las reglas funcionales y el alcance están en [PRD.md](PRD.md), que prevalece si hay diferencias.

## Platform

web

## Users

Un hogar familiar: una o varias personas con cuenta propia que comparten el mismo hogar con un rol (administrador, editor o lector). Planifican las comidas de la semana en el ordenador o en el móvil, compran con el móvil en la tienda y consultan la despensa desde la cocina.

## Product Purpose

Food & Pantry Manager reúne recetario, inventario de casa, menú semanal y lista de la compra para que planificar y comprar lleve menos tiempo y esfuerzo. Objetivo del usuario: «Food & Pantry Manager debe ahorrar tiempo y esfuerzo. No quiero una aplicación que me obligue a dedicar tiempo todos los días a mantenerla actualizada.» El éxito es una lista de la compra correcta sin trabajo diario de mantenimiento.

## Positioning

La lista de la compra se deduce sola del menú, de las recetas y de lo que hay en casa, y explica cada cantidad en el lenguaje de la compra real («Faltan 85 unidades · Para comprar: 5 bolsas, 1 bolsa = 20 unidades»). Las existencias previstas descuentan lo que el menú ya ha usado sin pedir confirmaciones diarias.

## Operating Context

- Planificar: la semana de comidas y cenas (7 × 2), con varios platos por comida, recetas guardadas o platos sencillos.
- Comprar: en la tienda, con el móvil, marcando lo que va al carro y guardándolo en casa al volver.
- Despensa: frigorífico, congelador y despensa; «Se acabó» y «Mínimo en casa» para los básicos.
- Uso compartido por los miembros del hogar.

## Capabilities and Constraints

- Alcance, reglas (`RN-xx`) y criterios (`CA-xx`) en [PRD.md](PRD.md); recorridos en [USER-FLOWS.md](USER-FLOWS.md).
- Aplicación web responsive (escritorio y móvil) sobre Laravel 13, Inertia 3, Vue 3, Tailwind CSS 4 y shadcn-vue.
- Las cantidades y los cálculos viven en el servidor; la interfaz no los recalcula.
- Fuera del MVP: nutrición, IA, caducidades, importaciones y descuento automático al cocinar ([PRD.md §5](PRD.md)).

## Brand Commitments

- Nombre: «Food & Pantry Manager».
- Interfaz solo en español de España (fechas, coma decimal, zona Europe/Madrid).
- Ningún elemento visual heredado es un compromiso: el logotipo del starter kit y la paleta verde actual se pueden sustituir.

## Evidence on Hand

- Imágenes de alimentos opcionales, elegidas por el usuario desde Wikimedia Commons u Open Food Facts (RN-32); la mayoría de los alimentos no tendrá foto y usa el icono de su categoría.
- Sin fotos propias, testimonios, clientes ni cifras de uso: no se deben inventar.

## Product Principles

1. Ahorrar tiempo: cada pantalla reduce trabajo; nada exige mantenimiento diario.
2. La cantidad es la protagonista: clara, en unidades de compra y explicable bajo demanda.
3. Confirmar solo lo que cambia datos de forma importante; el resto, directo y reversible.
4. Lo mismo se ve y se comporta igual en todas las pantallas.

## Accessibility & Inclusion

WCAG 2.2 AA y objetivos táctiles de al menos 44 × 44 px en móvil ([DESIGN-SYSTEM.md §6](DESIGN-SYSTEM.md), CA-18).

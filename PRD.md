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

- **V1 (decisión del usuario del 2026-10-22):** varias personas con cuenta propia comparten un hogar con un rol: administrador, editor o lector (RN-38). Se unen por invitación (RN-39). Cada persona pertenece a un único hogar (RN-01).
- **V2:** kcal, macronutrientes, IA y mejoras derivadas del uso real.

## 4. Alcance del MVP

| Módulo | Incluye |
|---|---|
| Autenticación | La del starter kit oficial (registro, login, logout, recuperación de contraseña). Sin proveedores externos. |
| Hogar | Tras registrarse, cada persona crea su hogar o acepta una invitación. Gestión de miembros, roles e invitaciones en Ajustes › Hogar (RN-01, RN-38, RN-39). |
| Inventario | Único apartado para gestionar los alimentos (decisión del usuario del 2026-10-21). **Catálogo** normalizado del hogar (la definición de cada alimento) con alias explícitos, categoría general (RN-31) e imagen opcional (RN-32), y **existencias** en frigorífico, congelador y despensa con cantidad y unidad. Las recetas usan los alimentos del mismo catálogo. |
| Recetas | Alta, edición, consulta, archivado y borrado (si no está referenciada). |
| Menú semanal | 14 espacios (7 días × comida y cena), cada uno con uno o varios platos ordenados (RN-16): recetas guardadas o platos puntuales. Recetario junto al calendario con arrastrar y soltar y alternativa sin arrastrar (RN-33). |
| Inicio | Panel de resumen con datos reales: la comida y la cena de hoy, el menú de la semana y lo que queda por comprar (RN-34). |
| Lista de la compra | Una lista persistente por periodo de compra (RN-36): se actualiza sola con el menú, las recetas y el inventario. Es editable, permite marcar lo comprado y conserva un historial de listas. |
| Recepción de compra | Desde una lista: cantidades reales, productos no previstos, actualización del inventario. |
| Interfaz | Responsive, con estados vacíos, de carga, de error y confirmaciones. |

## 5. Fuera del MVP

- Macros e información nutricional.
- Integración con WhatsApp.
- Eliminar un hogar y transferirlo como operación propia: no forman parte de la V1. Un administrador puede nombrar a otro administrador y después salir (RN-38).
- Varios hogares por persona.
- Fechas de caducidad y gestión de existencias por código de barras. El código de barras solo se usa para buscar la imagen de un producto envasado (RN-32, decisión del usuario del 2026-10-21).
- Importaciones automáticas (recetas, tickets, webs).
- Descuento automático de inventario al cocinar.
- Funcionalidades de inteligencia artificial.
- Proveedores de autenticación externos (redes sociales, WorkOS).
- Recepciones de compra sin lista y recepciones en borrador.

## 6. Reglas de negocio

### Hogar y acceso
- **RN-01** Cada usuario pertenece como máximo a un hogar. Ingredientes, inventario, recetas, menús, listas y recepciones pertenecen al hogar, no al usuario.
  - Tras registrarse, el usuario crea un hogar, del que es administrador, o acepta una invitación. Hasta entonces solo accede a esa pantalla, también si cierra sesión o interrumpe el proceso.
  - Crear el hogar es idempotente: repetir la petición no crea otro.
  - Un usuario sin hogar (porque salió de él o lo retiraron) vuelve a esa pantalla.
- **RN-02** Un usuario solo puede ver y modificar datos de su hogar. El hogar se deduce siempre de su pertenencia autenticada, nunca de un identificador enviado por el cliente.
- **RN-38** Roles del hogar (V1), comprobados en el servidor para cada petición:
  - **Administrador:** todo lo del editor, además de invitar, cambiar roles, retirar miembros y cambiar el nombre del hogar.
  - **Editor:** crear, editar y borrar datos del hogar (recetas, menú, inventario y alimentos, listas, recepciones) y consultar el historial.
  - **Lector:** consultar todas las pantallas del hogar; no puede ejecutar ninguna acción que modifique datos y la interfaz lo indica («Modo lectura»).
  - **Último administrador:** el hogar conserva siempre al menos uno. No se le puede degradar ni retirar, no puede salir hasta nombrar a otro y no puede borrar su cuenta si hay más miembros.
  - **Eliminar la cuenta** (2026-10-22): solo elimina la cuenta y su pertenencia; los datos del hogar se quedan en él. No se permite si dejaría el hogar sin administrador (regla anterior) o si es el único miembro de un hogar con datos, porque nadie podría volver a acceder a ellos y en esta versión no se eliminan hogares con datos. Ajustes › Perfil explica el motivo antes de intentarlo: invitar a otra persona y nombrarla administradora. El único miembro de un hogar vacío sí puede: el hogar se elimina con la cuenta. Se comprueba con el hogar bloqueado.
  - **Salir del hogar:** cualquier miembro puede. Los datos se quedan en el hogar. El único miembro solo puede salir si el hogar está vacío (sin alimentos, recetas, existencias, platos, listas ni compras), y entonces el hogar se elimina; con datos no puede, porque borrar hogares con datos no forma parte de la V1. Lo mismo al borrar la cuenta: un hogar vacío que se queda sin miembros se elimina (2026-10-22).
- **RN-39** Invitaciones (V1):
  - El administrador invita a un correo con un rol. Se envía un correo con el enlace, por el mailer configurado; en desarrollo es el de log, que no envía nada y la interfaz lo indica. El enlace se muestra una vez para compartirlo.
  - El token es aleatorio (64 caracteres) y solo se guarda su hash SHA-256. Caduca a los 7 días.
  - Solo puede aceptarla, una vez y si aún no pertenece a un hogar, el usuario cuyo correo coincide. Con cualquier otro correo se responde como si no existiera.
  - Puede rechazarla el destinatario y revocarla un administrador. Las caducadas, revocadas, rechazadas o usadas no se aceptan.
  - No hay dos invitaciones pendientes para el mismo correo y hogar, ni invitaciones para quien ya es miembro.
  - Quien no tiene cuenta se registra con ese correo y encuentra la invitación al entrar.
  - **Destinatario que ya tiene hogar** (2026-10-22): nunca se acepta, se sale ni se borra nada automáticamente. La página de la invitación y Ajustes › Hogar explican el conflicto y las opciones (salir antes del hogar actual, si RN-38 lo permite, o rechazar). La invitación sigue pendiente mientras tanto. Cada usuario sigue perteneciendo a un solo hogar.

### Ingredientes y unidades
- **RN-03** Un ingrediente es único dentro del hogar por su nombre normalizado (sin distinguir mayúsculas, tildes ni espacios sobrantes).
- **RN-04** Los sinónimos y plurales se resuelven con **alias explícitos** creados por el usuario ("tomates" → "tomate"). Un alias apunta a un solo ingrediente y no puede coincidir con otro alias ni con el nombre de otro ingrediente. **Nunca** se fusionan ingredientes automáticamente por similitud.
- **RN-05** Unidades: pieza (unidad), lata, bote, paquete, rebanada, loncha, bandeja, diente, cabeza, bolsa, caja, malla, cartón, g, kg, ml y l. Cada ingrediente usa las que correspondan. Lata, rebanada y loncha se añadieron el 2026-10-20; diente, cabeza, bolsa, caja, malla y cartón el 2026-10-22. Ninguna tiene equivalencia universal con g, ml ni con otras unidades (RN-06); solo las que defina cada alimento (RN-40).
- **RN-06** Solo se convierte automáticamente entre g↔kg y entre ml↔l, y únicamente al combinar cantidades de ese par (sumar o restar); en ningún otro caso se cambia la unidad elegida por el usuario.
  - Las demás unidades no tienen equivalencias universales entre ellas ni con peso o volumen (p. ej. bote↔g, pieza↔paquete). Desde el 2026-10-22 cada alimento puede definir las suyas (RN-40).
  - Al combinar g con kg (o ml con l), el resultado se expresa en kg (l) si es ≥ 1 kg (1 l) y en g (ml) si es menor. Si todas las cantidades combinadas usan la misma unidad, esa unidad se conserva.
- **RN-07** Las cantidades guardadas son siempre mayores que cero. Ningún cálculo produce cantidades negativas.
- **RN-40** Equivalencias de un alimento (2026-10-22). Un alimento puede definir sus propias equivalencias entre unidades que no se convierten solas («1 cabeza = 10 dientes» para el ajo, «1 bolsa = 500 g» para las patatas, «1 cartón = 12 unidades» para los huevos). Solo se aplican a ese alimento.
  - Distinguen la unidad de la receta, la de las existencias y la de compra sin cambiar ninguna: las recetas y las existencias conservan su unidad, y la comparación se hace en la unidad común del alimento (peso, volumen o la más pequeña).
  - Nunca se contradicen: no se admite una equivalencia entre unidades que ya se convierten, directamente o a través de otras, ni dentro de g/kg o ml/l, ni con un factor ≤ 0.
  - Sin equivalencia, las unidades siguen siendo incompatibles (RN-22).
- **RN-41** Formato de compra (2026-10-22). Un alimento puede indicar en qué envase o pieza se compra (bolsa, caja, malla, cartón, paquete, cabeza…), siempre que sepa cuánto contiene (RN-40). La lista pide entonces envases enteros, redondeando hacia arriba lo que falta después de restar el inventario, y explica cuánto falta de verdad y qué contiene cada envase («Faltan 600 g · 1 bolsa = 500 g» → 2 bolsas). Sin formato de compra se aplica RN-23. Quitar la equivalencia que lo hace calculable quita también el formato de compra, con aviso.
- **RN-31** Cada ingrediente puede tener una categoría general de una lista cerrada (DATA-MODEL §2). La categoría clasifica qué es el producto, no dónde está: es independiente de la ubicación. Las existencias la heredan de su ingrediente. Un ingrediente puede quedar «sin clasificar». Al **crear** un alimento sin elegir categoría, se asigna automáticamente solo si su nombre está en un diccionario local (`config/ingredient_categories.php`). La coincidencia es exacta, sin distinguir mayúsculas, tildes ni espacios y admitiendo el plural; no hay coincidencias parciales ni servicios externos. Si no hay coincidencia, queda sin clasificar. La categoría elegida por el usuario nunca se sustituye y al editar nunca se deduce (decisión del usuario del 2026-10-21; antes nunca se asignaba).
- **RN-32** Un ingrediente puede tener una imagen. Solo se busca cuando el usuario lo pide, por nombre o por código de barras, y nunca se asigna sin que la elija. Se guarda una copia local con la atribución que exige su licencia. Sin imagen, se muestra el icono de su categoría. Mostrar el inventario no depende de servicios externos.

### Inventario
- **RN-08** Cada producto de inventario está en una ubicación: frigorífico, congelador o despensa (el congelador se añadió el 2026-10-21). Existe una sola fila por ingrediente, ubicación y unidad compatible (g y kg comparten fila; ml y l también). Las cantidades con unidades incompatibles permanecen en filas separadas.
- **RN-09** El inventario solo cambia por edición directa del usuario o por la confirmación de una recepción (RN-30).
- **RN-10** Planificar recetas no reserva ni descuenta stock.
- **RN-11** Cocinar no modifica las existencias registradas y nunca se pide confirmar que se ha cocinado o comido. Desde el 2026-10-22, la lista usa las existencias previstas (RN-44), que descuentan lo que planificaste.
- **RN-44** Existencias previstas (Fase A, 2026-10-22):
  - Cada fila del inventario tiene lo **registrado** (lo que introdujo el usuario; nunca cambia solo con el tiempo) y lo **previsto**: lo registrado menos lo que usan las comidas del menú cuya **hora de referencia** ya ha pasado (comida 15:00, cena 22:00, hora de Madrid) y que no están marcadas «No comido».
  - El plan es la referencia: no hay confirmaciones diarias. Una comida es pendiente (cuenta para la lista) hasta su hora de referencia y consumida (cuenta en el previsto) desde entonces; nunca las dos cosas.
  - Las compras suman a la fila; un **recuento** (editar la cantidad, ± o eliminar la fila) fija la fila en lo que dice el usuario y reinicia su consumo previsto; mover una fila lleva consigo su previsto.
  - El consumo de un alimento se toma de sus filas en el orden frigorífico → despensa → congelador, sin bajar de cero; lo que no cabe se ignora.
  - Las comidas pasadas usan la receta vigente en su hora de referencia (RN-45) y, en las alternativas, la opción elegida o la que el previsto de ese momento proponía (RN-42).
  - La interfaz muestra «≈ X previstos · registrado Y» con una explicación desplegable y permite corregirlo cuando se quiera, sin exigirlo.
- **RN-45** Revisiones de recetas (2026-10-22): editar una receta que ya usaron comidas pasadas guarda su composición anterior; esas comidas siguen usándola para el previsto. Solo cambian las comidas futuras. Corregir un plato pasado concreto (raciones, sustituirlo, borrarlo o «No comido») sí cambia su consumo.
- **RN-47** Básicos de casa (Fase B, decisión del usuario del 2026-10-23):
  - **«Mínimo en casa»** (ficha del alimento): una cantidad y unidad opcionales. La lista pide la diferencia cuando, tras las comidas del menú anteriores y del periodo, vayan a quedar menos. Se quita dejando la cantidad vacía. No existe «Siempre en la lista».
  - **«Se acabó»** (tarjeta de existencias, con confirmación): pone a 0 **todas** las ubicaciones del alimento en una sola operación, registrada como recuentos (RN-44), y se corrige después con el flujo habitual de existencias.
  - Si el alimento no tiene mínimo, «Se acabó» lo pide **una sola vez**: la cantidad de su última compra recibida o, si no la hay, un envase de su formato de compra, lo que había o 1 de su unidad habitual. Deja de pedirse con la primera entrada de existencias posterior (compra o alta).
  - La reposición se suma a la **misma línea** que las recetas de ese alimento: (recetas + reposición) − existencias previstas compatibles, con las equivalencias del alimento (RN-40), el redondeo de RN-23 y su formato de compra (RN-41). Nunca crea una segunda línea.

### Recetas
- **RN-12** Una receta tiene raciones base (entero ≥ 1) e ingredientes con cantidad y unidad. Una receta sin ingredientes se puede guardar, pero el plato que la use se considera incompleto (RN-18).
- **RN-13** Al planificar una receta con N raciones, cada cantidad se escala por `N / raciones_base`.
- **RN-14** Una receta referenciada por algún plato planificado, o incluida como preparación en otra receta (RN-43), no se puede eliminar; se puede **archivar**. Una receta archivada no se ofrece al planificar, sigue visible en los menús que la usan y se puede restaurar. Una receta sin referencias se puede eliminar con confirmación.
- **RN-42** Ingredientes alternativos (2026-10-22). Varias líneas de una receta pueden formar un grupo de alternativas («nata **o bien** leche evaporada»): se usa solo una.
  - Cada plato planificado guarda la opción elegida para cada grupo. Mientras el usuario no elige, se **propone** la primera opción que cubre el inventario **aún no asignado**, si no la primera con algo de existencias sin asignar y si no la primera. La propuesta se muestra siempre como tal en el plato, y la lista avisa de cuántos platos usan una propuesta; el usuario la confirma o la cambia.
  - **Reparto de existencias** (2026-10-22): los platos pendientes, desde hoy, se recorren en el orden en que se comen (fecha, comida antes que cena, posición). Cada plato toma primero lo que necesitan sus líneas fijas y sus elecciones confirmadas, y después se deciden sus propuestas con lo que quede. Una misma cantidad nunca cuenta para dos platos, un plato posterior no cambia la propuesta de uno anterior, y el menú y todas las listas ven la misma propuesta. Todo se recalcula al cambiar una elección, una receta, las raciones, el menú o el inventario.
  - La lista solo incluye la opción en uso, sin duplicar líneas. La elección se conserva al recalcular, en todos los periodos y al editar la receta mientras la opción siga en el grupo. Si la receta deja de ofrecerla, el plato lo indica («Elegiste X, que ya no está en la receta») y vuelve a proponer.
- **RN-43** Preparaciones (2026-10-22). Una receta puede incluir raciones de otra receta, por ejemplo una salsa boloñesa en unos macarrones o en unos espaguetis.
  - Al planificar, sus ingredientes se suman a escala: raciones del plato / raciones base de la receta × raciones de la preparación / raciones base de la preparación, en un solo paso y sin redondeos intermedios.
  - Una preparación se escribe una sola vez: si cambia, cambian todas las recetas que la usan.
  - Una receta no puede incluirse a sí misma, ni directamente ni a través de otras (se rechaza al guardar). Una preparación en uso solo se puede archivar (RN-14). Las alternativas de una preparación se eligen en cada plato que la usa (RN-42).

### Menú semanal
- **RN-15** Un menú corresponde a una semana de calendario de lunes a domingo y tiene 14 espacios: comida y cena de cada día. Solo existe un menú por hogar y semana.
- **RN-16** Cada espacio está vacío o contiene **uno o varios** platos planificados en orden (por ejemplo, primero, segundo y postre): recetas guardadas o platos puntuales. Cada plato se añade, cambia, reordena, mueve, marca como «No comido» o retira por separado, y las necesidades suman todos (cambio del 2026-10-22; antes, un único plato por espacio).
- **RN-17** Un plato puntual tiene nombre, raciones y uno o varios ingredientes con cantidad y unidad.
- **RN-18** *(Retirada el 2026-10-21 junto con el cierre del menú, RN-36.)*
  - Un plato incompleto (sin ingredientes) no añade nada a la lista y se señala en el menú, en «Ingredientes necesarios».
  - Los espacios vacíos no requieren confirmación.
- **RN-19** *(Retirada el 2026-10-21.)* Los menús ya no se cierran: todas las semanas se pueden editar. Los menús cerrados antes de ese día conservan su estado como dato histórico, pero también se pueden editar.
- **RN-33** Asignar y mover platos (decisiones del usuario del 2026-10-21). Arrastrar y soltar complementa a los diálogos y nunca es la única forma de hacerlo:
  - Una receta añadida desde el recetario se planifica con sus raciones base; las raciones se pueden cambiar después.
  - Poner una receta en un espacio ocupado la añade al final, junto a los platos que ya tiene (desde el 2026-10-22, RN-16; antes se sustituía tras confirmarlo).
  - Un plato se puede mover a otro espacio de la misma semana: se añade al final del destino, sin sustituir ni intercambiar nada, y conserva raciones, ingredientes y elecciones.
  - Planificar en un día ya pasado se permite, pero se avisa: se conserva como historial y no genera compras (RN-36).
  - Todo se rige por RN-16 y no modifica el inventario (RN-10).

### Inicio
- **RN-34** Inicio responde a tres preguntas con los datos reales del hogar: qué se come hoy (comida y cena del día actual en Europe/Madrid, como RN-15), qué se come esta semana (los siete días) y qué hay que comprar para los próximos 7 días (RN-36), con su cantidad. Es de solo lectura.

### Periodo de compra y «No comido» (decisiones del usuario del 2026-10-21)
- **RN-36** Lista por periodo:
  - La «Lista de la compra» calcula lo que hay que comprar para un periodo elegido: los próximos 7, 14 o 30 días o unas fechas propias (Europe/Madrid; hasta 92 días).
  - Cuentan los platos planificados de cualquier semana cuya hora de referencia aún no ha llegado (RN-44), que no estén marcados como «No comido» (RN-37). Los pasados no generan necesidades: ya cuentan en las existencias previstas, y siguen en el menú como historial.
  - Por alimento y grupo de unidades compatible se calculan:
    - las **existencias previstas** (RN-44), mostrando también las registradas si difieren;
    - lo **comprometido** por los platos pendientes;
    - lo **disponible** (existencias − comprometido, nunca negativo);
    - lo que hay que **comprar**: lo que necesitan los platos del periodo menos lo que quede tras los platos pendientes anteriores al periodo, con el redondeo de RN-23 y sin restar unidades incompatibles (RN-22).
  - No reserva ni descuenta existencias (RN-10).
  - **Persistencia** (2026-10-21, sustituye al cierre del menú):
    - Cada hogar tiene una lista por periodo (inicio y fin). Volver al mismo periodo abre la misma lista, con sus líneas manuales, las cantidades editadas y lo marcado como comprado.
    - «Próximos 7/14/30 días» tiene una sola lista por hogar y duración. Su ventana se desplaza para empezar hoy cada vez que se abre, desde la lista o desde Inicio, y conserva sus líneas, comprados y recepciones; no se crea una lista nueva cada día. Las fechas propias siguen teniendo una lista fija por periodo exacto (decisión del usuario del 2026-10-21).
    - La primera vez que se abre un periodo nuevo, parte de la lista vigente más reciente que se solapa con él (también una lista semanal antigua): copia sus líneas manuales, cantidades editadas, líneas quitadas y comprados aún no recibidos. La lista de origen no se modifica.
    - Cada vez que se abre la lista, y antes de registrar una compra, se concilia con las necesidades actuales según RN-27 y RN-28.
    - Solo se pueden editar y recibir las listas de periodos que no han terminado. Las de periodos terminados y las listas semanales generadas al cerrar menús antes del 2026-10-21 son historial de solo lectura y conservan sus líneas, comprados y recepciones.
    - Un periodo que ya ha terminado no crea ninguna lista.
- **RN-37** «No comido»:
  - Un plato planificado se puede marcar como «No comido» y volver a «pendiente».
  - Sigue visible en el calendario con ese estado, pero sus ingredientes dejan de contar en RN-36 y en las necesidades del menú. Las existencias no cambian.
  - Marcar dos veces no tiene más efecto que una.
  - Cambiar el plato de ese espacio lo vuelve a dejar pendiente.

### Lista de la compra
- **RN-20** *(Actualizada el 2026-10-21.)* La lista de un periodo se crea la primera vez que se abre: no hay que cerrar el menú. Hay como máximo una lista por hogar y periodo, también ante dos peticiones simultáneas, y abrirla varias veces no duplica líneas.
- **RN-35** La lista sigue al inventario, a las recetas y al menú (decisión del usuario del 2026-10-21). Al abrir la lista se recalcula según RN-21 a RN-23 y se concilia según RN-27 y RN-28:
  - las líneas manuales y las cantidades cambiadas por el usuario se conservan;
  - los cambios del cálculo se marcan para revisión;
  - una línea comprada se desmarca si la necesidad aumenta.

  Las listas del historial no se recalculan. El inventario nunca cambia por la lista (RN-09, RN-25).
- **RN-21** Cálculo:
  1. Sumar las necesidades de todos los platos (escaladas según RN-13).
  2. Agrupar por ingrediente y unidad compatible.
  3. Restar el inventario compatible de ambas ubicaciones.
  4. Conservar solo los grupos con resultado mayor que cero.
- **RN-22** Si existe inventario del mismo ingrediente en una unidad incompatible, no se resta y la línea se marca para revisión.
- **RN-23** Las cantidades en unidades indivisibles (pieza, lata, bote, paquete, rebanada, loncha, bandeja) se redondean **hacia arriba** al entero en la lista, después de restar el inventario. g, kg, ml y l no se redondean. El redondeo no modifica recetas ni inventario. Si el alimento tiene formato de compra, se redondea a envases enteros (RN-41).
- **RN-24** La lista es editable: añadir, modificar y eliminar líneas. Añadir un artículo cuyo ingrediente y unidad compatible ya existen en la lista modifica esa línea en lugar de crear otra. Se admiten líneas de texto libre sin ingrediente.
- **RN-25** Marcar una línea como comprada («en el carro») no modifica el inventario.
- **RN-48** Presentación de la lista (Fase B, 2026-10-23): cada línea dice «Comprar X» (con el envase si lo hay). El cálculo —para las comidas, mínimo o reposición, lo que usan antes comidas más próximas, lo que hay en casa y lo que falta exactamente— está plegado en «Ver cálculo». Arriba, el periodo (que se cambia en un diálogo), un resumen «N por comprar · M en el carro · K para revisar» y «Añadir». Los avisos del cálculo y las compras registradas van plegados. En móvil (360 px) el primer producto se ve sin desplazarse, y todos los controles táctiles miden al menos 44 × 44 px, con foco visible y nombre accesible.
- **RN-46** «Guardar lo marcado» (2026-10-22): desde la lista, las líneas marcadas se guardan en casa en una sola operación, con la cantidad que falta (en envases si hay formato de compra) y la ubicación habitual del alimento (o según su categoría), ambas editables. Lo no marcado sigue pendiente sin decidir nada. Es una recepción con historial y token de un solo uso: reenviarla no suma de nuevo; si otro miembro ya guardó una línea, se omite y se avisa. El texto libre no va al inventario y queda marcado.

### Recálculo de la lista
- **RN-26** *(Retirada el 2026-10-21: ya no hay cierre ni reapertura del menú.)*
- **RN-27** Al abrir la lista de un periodo vigente (RN-35), y antes de registrar una compra, se recalcula según RN-21 a RN-23 y se concilia línea a línea por ingrediente y unidad compatible:

| Línea existente | Nuevo cálculo | Resultado |
|---|---|---|
| Generada, sin cambios del usuario | Hay cantidad | Se actualiza la cantidad. |
| Generada, sin cambios del usuario | Ya no hace falta | Se elimina, salvo si estaba comprada y aún no se ha recibido: entonces se conserva y se marca para revisión. Comprada y recibida, se elimina (la recepción la conserva en el historial). |
| Generada, modificada por el usuario | Cambia respecto al cálculo anterior menos lo recibido en esa línea | Se conserva la cantidad del usuario y se marca para revisión mostrando el nuevo cálculo. Lo recibido no cuenta como cambio: una línea recibida por completo queda «Recibido», sin aviso. |
| Generada, modificada por el usuario | No cambia | Sin cambios. |
| Generada, descartada por el usuario | Cambia respecto al cálculo anterior | Reaparece con la nueva cantidad, marcada para revisión. |
| Generada, descartada por el usuario | No cambia o ya no hace falta | Sigue descartada. |
| Manual | Hay cantidad para el mismo ingrediente y unidad | Se conserva intacta y se marca para revisión mostrando el cálculo; no se crea otra línea. |
| Manual | Cualquier otro caso | Sin cambios. |
| No existe | Hay cantidad | Se crea una línea generada. |

- **RN-28** Estado de compra tras recalcular: una línea comprada sigue comprada si su nueva cantidad es menor o igual que la que tenía. Si aumenta, se desmarca y se marca para revisión. La marca de revisión desaparece cuando el usuario edita la línea o la da por revisada.

### Recepción de compra
- **RN-29** Una recepción parte siempre de la lista vigente de un periodo (RN-36) y se registra y confirma en una sola operación (sin borradores). La pantalla «Registrar compra» pide decidir cada línea; «Guardar lo marcado» (RN-46) solo incluye las marcadas. Las listas del historial no admiten recepciones. Tras una recepción, la lista pide lo que falta con las nuevas existencias. Cada línea muestra lo recibido y lo pendiente («Recibido 250 g · falta 150 g») sin contar nada dos veces, y lo recibido queda también en el historial de compras de la lista.
  - La cantidad prevista de cada línea se muestra solo como **sugerencia editable**.
  - El usuario decide explícitamente, línea a línea, entre:
    - **Recibido:** confirma o corrige la cantidad realmente adquirida (igual, mayor o menor que la prevista), la unidad y la ubicación destino.
    - **No comprado.**
  - No se puede confirmar la recepción mientras quede alguna línea sin decidir. Marcar una línea como comprada en la lista no equivale a recibirla (RN-25).
  - Se pueden añadir productos no previstos con ingrediente, cantidad, unidad y ubicación.
  - Una lista admite varias recepciones parciales (decisión del 2026-10-20). Cada una incluye solo las líneas aún pendientes y registra lo recibido en esa ocasión; lo no comprado sigue pendiente. Detalle en [DATA-MODEL.md §6.4](DATA-MODEL.md).
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
| CA-06 | *(Retirado el 2026-10-21, junto con el cierre.)* Un plato sin ingredientes no añade nada a la lista y se señala en el menú. | RN-18 |
| CA-07 | *(Retirado el 2026-10-21.)* Los espacios vacíos no piden confirmación: la lista se abre sin cerrar el menú. | RN-18 |
| CA-08 | Con 1 kg de arroz necesario y 400 g en despensa, la lista muestra 600 g. Con 2 kg, el arroz no aparece. | RN-21 |
| CA-09 | Necesidad de 1,5 botes sin inventario → la lista muestra 2 botes; receta e inventario no cambian. | RN-23 |
| CA-10 | Abrir varias veces el mismo periodo, o cambiar a otro y volver, deja una sola lista para ese periodo con sus cambios y comprados. Dos peticiones simultáneas que abren un periodo nuevo generan una sola lista. | RN-20, RN-36 |
| CA-11 | Al recalcular la lista: una línea manual persiste; una línea generada editada conserva la cantidad del usuario y se marca para revisión si el cálculo cambió. | RN-27 |
| CA-12 | Una línea comprada sigue comprada si el nuevo cálculo es igual o menor; se desmarca y se marca para revisión si aumenta. | RN-28 |
| CA-13 | Marcar una línea como comprada no cambia ninguna cantidad del inventario. | RN-25 |
| CA-14 | Confirmar una recepción con 3 botes recibidos (2 previstos) y un producto no previsto suma exactamente esas cantidades. 1 kg recibido sobre 500 g en la misma ubicación deja una fila de 1,5 kg. Una línea marcada "No comprado" no cambia el inventario. No se puede confirmar con líneas sin decidir. Reenviar la confirmación no suma de nuevo. | RN-29, RN-30 |
| CA-15 | Planificar o quitar una receta del menú no modifica el inventario. | RN-10 |
| CA-16 | Una receta usada en un menú no se puede eliminar pero sí archivar; archivada no aparece al planificar. | RN-14 |
| CA-17 | El lunes 26/10/2026 a las 00:30 (Europe/Madrid) la "semana actual" empieza el 26/10/2026, aunque en UTC aún sea domingo. | RN-15 |
| CA-18 | Las pantallas principales son usables a 360 px y muestran estados vacío, carga y error. | — |
| CA-19 | Un ingrediente se crea o edita con o sin categoría. Sus existencias muestran esa categoría sin guardarla otra vez, y al cambiarla en el ingrediente cambia en todas. Crear «Pollo» (o «pollo», «Merluzas») le asigna su categoría del diccionario; un nombre desconocido o parcial («Pollo al curry precocinado») queda sin clasificar, y una categoría elegida se respeta y se puede corregir. Las categorías se pueden filtrar, incluido «Sin clasificar». | RN-31 |
| CA-20 | Buscar una imagen no asigna nada. Elegir un resultado guarda una copia local con su atribución, y mostrar el inventario no hace llamadas externas. Si no hay resultados o el proveedor falla, se puede continuar sin imagen. Sin imagen se ve el icono de la categoría. Otro hogar no puede ver, poner ni quitar la imagen. | RN-02, RN-32 |
| CA-21 | Mover un plato a otra comida lo añade al final de esa comida, conservando raciones e ingredientes, y deja los demás platos donde estaban. Una receta soltada en un espacio, vacío u ocupado, se añade con sus raciones base. Nada de esto cambia el inventario. *(Revisado el 2026-10-22: antes se intercambiaba y se pedía confirmación.)* | RN-10, RN-16, RN-33 |
| CA-22 | Con la hora de Madrid ya en jueves (aunque en UTC sea miércoles), Inicio muestra la comida y la cena del jueves del menú real, los siete días de la semana y solo las líneas de la lista aún pendientes, con su cantidad. | RN-15, RN-34 |
| CA-23 | Un usuario recién registrado no accede a Inicio ni a ninguna pantalla del hogar hasta crear uno, que se crea una sola vez aunque se repita la petición, o aceptar una invitación. Al volver a iniciar sesión continúa donde lo dejó. | RN-01 |
| CA-24 | Un lector recibe 403 en todas las rutas que modifican datos (y un aviso desde la app) y puede consultar todas las páginas. Un editor modifica datos, pero recibe 403 en las acciones de administración. El último administrador no puede ser degradado, retirado ni salir. | RN-38 |
| CA-25 | Una invitación solo la acepta, una vez, el destinatario del correo, con el rol previsto. Las caducadas, revocadas, rechazadas o usadas se rechazan, y otra cuenta no ve sus datos. | RN-39 |
| CA-26 | Lo que crea un miembro lo ven los demás del mismo hogar (inventario, recetas, menú, lista y recepciones). Otro hogar no lo ve ni lo modifica con identificadores adivinados ni con un `household_id` en el payload. | RN-02, RN-38 |
| CA-27 | Una comida con tortilla de patatas, pisto y fruta los muestra en orden; se reordenan, cambian, mueven y retiran uno a uno, «No comido» solo libera ese plato y las necesidades suman todos (4 + 4 huevos, 500 + 100 g de patatas). Un plato en un día pasado se avisa y la lista explica por qué no cuenta. | RN-16, RN-33, RN-36, RN-37 |
| CA-28 | 3 dientes de ajo con 1 cabeza en casa: sin equivalencia se pide revisar; con «1 cabeza = 10 dientes» no hay nada que comprar y la existencia sigue en cabezas. Las equivalencias contradictorias, dentro de g/kg o con factor ≤ 0 se rechazan, y las de un alimento no se aplican a otro. | RN-22, RN-40 |
| CA-29 | Patatas en bolsas de 500 g: faltan 600 g → 2 bolsas, explicando «Faltan 600 g · 1 bolsa = 500 g»; con 1 bolsa en casa, 1 bolsa; con 1 kg más, nada. 25 croquetas en bolsas de 20 → 2 bolsas; faltan 2 huevos en cartones de 12 → 1 cartón. Recibir 1 kg cuenta para una línea en bolsas. | RN-23, RN-41 |
| CA-30 | Nata o leche evaporada: sin nada en casa se propone nata, con leche evaporada en casa se propone esta, y la lista avisa de la propuesta. Elegir otra opción cambia la lista sin duplicar líneas y se conserva en todos los periodos y al editar la receta. Solo se aceptan opciones de la receta del plato, de un miembro que pueda escribir. | RN-38, RN-42 |
| CA-31 | Una salsa boloñesa incluida en macarrones (2 de 4 raciones) y en espaguetis se suma a escala y sin duplicar; cambiar la salsa cambia ambas recetas. Una receta no puede incluirse a sí misma ni a través de otras, una preparación de otro hogar no se acepta y una en uso solo se archiva. | RN-14, RN-43 |
| CA-33 | Con 150 ml de leche evaporada y dos platos de salsa (martes y miércoles), al del martes se le propone la leche y al del miércoles la nata, y la lista compra 200 ml de nata. Una línea fija o una elección confirmada de un plato anterior se queda con su parte antes de las propuestas posteriores. Los grupos distintos se reparten por separado, un periodo que empieza más tarde deja las existencias a los platos anteriores, y una elección que la receta ya no ofrece se señala y se vuelve a proponer. | RN-42 |
| CA-34 | El único miembro de un hogar con datos no puede eliminar su cuenta (se le explica antes) y no cambia nada. El de un hogar vacío sí, y el hogar se elimina. Un editor puede irse y los datos quedan para los demás, y el último administrador solo después de nombrar a otro. | RN-38 |
| CA-35 | Con 18 huevos el lunes y una tortilla de 4 de lunes a viernes, el jueves a las 10:00 hay ≈ 6 previstos (registrados 18) y la lista compra 2, sin confirmar nada. Un recuento fija un nuevo punto de partida (también el mismo día de una comida); una compra suma sin reiniciar el consumo; «No comido» días después devuelve los huevos y deshacerlo los quita; el previsto nunca baja de 0; a las 15:00 una comida pasa de la lista al previsto sin contarse dos veces; el menú solo compara los platos pendientes. | RN-11, RN-36, RN-44 |
| CA-36 | Varias ubicaciones (frigorífico 6, despensa 12, tres comidas de 4): el previsto es frigorífico 0 y despensa 6; contar una fila no cambia las demás; contar todas en cualquier orden deja el previsto igual a lo registrado; mover lleva el previsto consigo; borrar una fila elimina solo su previsto. Se cumple también en secuencias aleatorias. | RN-44 |
| CA-37 | Editar una receta el jueves no cambia lo consumido de lunes a miércoles; una edición antes de la comida del miércoles sí se aplica a ella; una cadena de ediciones usa la versión de cada momento, también en preparaciones y alternativas. | RN-45 |
| CA-38 | Marcar huevos y patatas y pulsar «Guardar 2 en casa» suma 12 huevos (en lugar de 8) y 2 bolsas en una operación; el arroz sigue pendiente. Reenviar no suma de nuevo; una compra parcial deja «Recibido 4 · falta 4»; si otro miembro guardó los huevos a la vez, se omiten y se avisa; el texto libre no va al inventario; el lector recibe 403 y otro hogar 404. | RN-46 |
| CA-39 | Leche con mínimo de 2 l y 3 l en casa: no se pide. Tras «Se acabó» se pide 2 l con «Mínimo en casa»; al añadir 2 l deja de pedirse. Quitar el mínimo deja de pedirla. |
| CA-40 | Huevos con mínimo 6, 12 en casa y una tortilla de 4: nada que comprar. Tras «Se acabó» (frigorífico y despensa a 0, dos recuentos) se pide **una** línea de 10 (4 recetas + 6 mínimo); con cartones de 12, 1 cartón. Se pueden volver a añadir existencias. |
| CA-41 | Sin mínimo, «Se acabó» pide una vez lo que había (6 huevos) en las listas de 7 y 14 días; tras recibir 12 deja de pedirse, y un nuevo «Se acabó» pide 12. Un lector u otro hogar recibe 403 y no cambia nada. |
| CA-42 | En la lista a 360 px, el primer producto se ve sin desplazarse, dice «Comprar X» y el cálculo está plegado; el checkbox, Editar, Quitar, Periodo y Añadir, los controles de la hoja «Guardar en casa» (también Guardar, Cancelar y cerrar), las tarjetas de inventario y la navegación del menú miden ≥ 44 px, sin desplazamiento horizontal y con foco visible. |
| CA-32 | Una invitación a quien ya tiene hogar no se acepta ni saca a nadie de su hogar: se explica el conflicto y la invitación sigue pendiente. El único miembro de un hogar vacío puede salir (el hogar se elimina); con datos, no. Todas las páginas muestran el nombre del hogar real, también tras renombrarlo, y cada miembro ve su propio rol como etiqueta. | RN-01, RN-38, RN-39 |

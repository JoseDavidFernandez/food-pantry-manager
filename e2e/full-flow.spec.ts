import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';
import { credentials, navigate, row, screenshot, toast } from './support';

/**
 * Phases 2 to 6 through the browser, on the development database, as the dedicated E2E user.
 * The data is left in place at the end for manual inspection.
 */
test.describe.configure({ mode: 'serial' });

async function signIn(page: Page): Promise<void> {
    const c = credentials();

    await page.goto('/login');
    await page.getByLabel('Correo electrónico').fill(c.email);
    await page.getByLabel('Contraseña', { exact: true }).fill(c.password);
    await page.getByRole('button', { name: 'Iniciar sesión' }).click();

    const signedIn = await page
        .waitForURL('**/dashboard', { timeout: 8_000 })
        .then(() => true)
        .catch(() => false);

    if (!signedIn) {
        // First session: the E2E user is registered through the interface (RN-01 creates its household).
        await page.goto('/register');
        await page.getByLabel('Nombre').fill(c.name);
        await page.getByLabel('Correo electrónico').fill(c.email);
        await page.getByLabel('Contraseña', { exact: true }).fill(c.password);
        await page.getByLabel('Confirmar contraseña').fill(c.password);
        await page.getByRole('button', { name: 'Crear cuenta' }).click();

        // RN-01: a new account creates its household (or accepts an invitation) first.
        await page.waitForURL('**/household/setup');
        await page.getByLabel('Nombre del hogar').fill('Hogar E2E');
        await page.getByRole('button', { name: 'Crear hogar' }).click();
        await page.waitForURL('**/dashboard');
    }

    await expect(page.getByRole('heading', { name: 'Inicio' })).toBeVisible();
}

async function openSlot(page: Page, day: string, meal: 'Comida' | 'Cena') {
    await page
        .getByRole('button', {
            name: new RegExp(`Añadir plato: ${day} \\d+ · ${meal}`),
        })
        .click();

    return page.getByRole('dialog');
}

/**
 * Opens the actions menu of a stock card («⋯», R4) and chooses one of its options, named as the
 * buttons were before: «Editar las existencias de Arroz (Despensa)», «Se acabó: Croquetas»…
 */
async function stockAction(
    page: Page,
    card: RegExp,
    option: string,
): Promise<void> {
    await page.getByRole('button', { name: card }).first().click();
    await page.getByRole('menuitem', { name: option, exact: true }).click();
}

/** Opens a folded section of the food sheet (R4) when it is closed. */
async function openFold(sheet: Locator, title: string): Promise<void> {
    const fold = sheet.locator('[data-fold]', {
        has: sheet.page().locator('summary', { hasText: title }),
    });

    if ((await fold.getAttribute('open')) === null) {
        await fold.locator('summary').click();
    }
}

/**
 * Opens the actions menu of a planned dish in one lunch or dinner («⋯», R2) and chooses one of
 * its options, named as the buttons were before: «Mover Arroz con huevo: Miércoles 28 · Comida».
 */
async function dishAction(
    page: Page,
    verb: string,
    dish: string,
    slot: string,
): Promise<void> {
    await page
        .getByRole('button', {
            name: new RegExp(`^Acciones de ${dish}: ${slot}$`),
        })
        .click();
    await page
        .getByRole('menuitem', {
            name: new RegExp(`^${verb} ${dish}: ${slot}$`),
        })
        .click();
}

/**
 * Drags a planned dish by its name, step by step, as a person does: `dragTo` presses on the
 * centre of the card (its action buttons) and moves in one jump, so the drag never starts.
 */
async function dragDish(
    page: Page,
    dish: Locator,
    target: Locator,
): Promise<void> {
    await target.scrollIntoViewIfNeeded();
    const from = await dish.boundingBox();
    const to = await target.boundingBox();

    if (from === null || to === null) {
        throw new Error('El plato o la comida de destino no están visibles.');
    }

    await page.mouse.move(from.x + 30, from.y + 12);
    await page.mouse.down();
    await page.mouse.move(from.x + 40, from.y + 20, { steps: 5 });
    await page.mouse.move(to.x + to.width / 2, to.y + to.height / 2, {
        steps: 10,
    });
    await page.mouse.up();
}

/**
 * The size of the area that really answers to a tap on an element (its box plus any enlarged hit
 * area), measured with elementFromPoint up to 22 px around its centre.
 */
async function touchSize(
    element: Locator,
): Promise<{ width: number; height: number }> {
    return element.evaluate((el) => {
        const r = el.getBoundingClientRect();
        const [cx, cy] = [r.left + r.width / 2, r.top + r.height / 2];
        const hits = (x: number, y: number) => {
            const found = document.elementFromPoint(x, y);

            return found !== null && (found === el || el.contains(found));
        };
        const reach = (dx: number, dy: number) => {
            let step = 0;

            while (
                step < 22 &&
                hits(cx + dx * (step + 1), cy + dy * (step + 1))
            ) {
                step++;
            }

            return step;
        };

        return {
            width: Math.min(44, reach(-1, 0) + reach(1, 0) + 1),
            height: Math.min(44, reach(0, -1) + reach(0, 1) + 1),
        };
    });
}

/** Elements that stick out of the given container (or of the viewport). */
async function outside(page: Page, selector: string): Promise<string[]> {
    return page.evaluate((sel) => {
        const root = document.querySelector(sel);

        if (root === null) {
            return ['sin contenedor'];
        }

        const r = root.getBoundingClientRect();

        return [...root.querySelectorAll('*')]
            .filter((element) => {
                const box = element.getBoundingClientRect();

                return (
                    box.width > 1 &&
                    (box.right > Math.min(r.right, innerWidth) + 1 ||
                        box.left < Math.max(r.left, 0) - 1)
                );
            })
            .map(
                (element) =>
                    element.textContent?.trim().slice(0, 30) ?? element.tagName,
            );
    }, selector);
}

/** Goes to the next week of the menu and waits for it, so the next click is on that week. */
async function nextWeek(page: Page): Promise<void> {
    const before = page.url();
    await page.getByRole('link', { name: 'Semana siguiente' }).click();
    await page.waitForURL(
        (url) =>
            url.toString() !== before &&
            /\/menu\/\d{4}-\d{2}-\d{2}/.test(url.toString()),
    );
}

/** RN-36: no closing; the list of the week is opened from the menu and follows it. */
async function openWeekList(page: Page): Promise<void> {
    await page
        .getByRole('link', { name: 'Lista de la compra de esta semana' })
        .click();
    await page.waitForURL(/shopping-list\?/);
    await expect(
        page.getByRole('heading', { name: 'Lista de la compra', level: 1 }),
    ).toBeVisible();
}

async function decide(
    page: Page,
    name: string,
    outcome: 'Recibido' | 'No comprado',
) {
    const line = row(page, name);
    await line.getByText(outcome, { exact: true }).click();

    return line;
}

async function confirmReception(page: Page): Promise<void> {
    await expect(page.getByText('Todo decidido')).toBeVisible();
    await page.getByRole('button', { name: 'Confirmar compra' }).click();
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Confirmar y actualizar inventario' })
        .click();
}

function stockButton(page: Page, ingredient: string, amount: string) {
    return page.getByRole('button', {
        name: `Ajustar la cantidad de ${ingredient}: ${amount}`,
        exact: true,
    });
}

test('fases 2 a 6 en el navegador sobre desarrollo', async ({ page }) => {
    await test.step('1. Inicio de sesión (registro del usuario E2E la primera vez)', async () => {
        await signIn(page);
        await screenshot(page, '01-inicio');
    });

    await test.step('2a. Inventario: alimentos nuevos, ficha (nombre, categoría, alias) y «Sin existencias»', async () => {
        await expect(
            page.getByRole('link', { name: 'Ingredientes', exact: true }),
        ).toHaveCount(0);
        await navigate(page, 'Inventario');
        await expect(page.getByText('Tu inventario está vacío.')).toBeVisible();

        // Error: an unknown food without asking to create it. The dialog stays open.
        await page
            .getByRole('button', { name: 'Añadir al inventario' })
            .click();
        const add = page.getByRole('dialog');
        await add.getByLabel('Alimento', { exact: true }).fill('Lentejas');
        await add.getByLabel('Cantidad', { exact: true }).fill('500');
        await add.getByRole('button', { name: 'Añadir al inventario' }).click();
        await expect(
            add.getByText(/«Lentejas» no está en tu catálogo/),
        ).toBeVisible();

        // New foods straight into the fridge, freezer or pantry, with their category.
        const newFoods: [string, string, string, string, string][] = [
            ['Arros', 'pasta_rice_legumes', '500', 'g', 'pantry'],
            ['Huevos', 'dairy_eggs', '3', 'piece', 'fridge'],
            ['Pan', '', '1', 'piece', 'pantry'],
            ['Merluza', 'fish', '2', 'piece', 'freezer'],
        ];

        for (const [name, category, quantity, unit, location] of newFoods) {
            if (!(await add.isVisible())) {
                await page
                    .getByRole('button', { name: 'Añadir al inventario' })
                    .click();
            }

            await add.getByLabel('Alimento', { exact: true }).fill(name);
            await add
                .getByLabel('Es un alimento nuevo: crearlo en el catálogo')
                .check();
            await add
                .getByLabel('Categoría del nuevo alimento')
                .selectOption(category);
            await add.getByLabel('Cantidad', { exact: true }).fill(quantity);
            await add.getByLabel('Unidad', { exact: true }).selectOption(unit);
            await add
                .getByLabel('Ubicación', { exact: true })
                .selectOption(location);
            await add
                .getByRole('button', { name: 'Añadir al inventario' })
                .click();
            await expect(add).toBeHidden();
        }

        await toast(page, 'Merluza: ahora hay 2 unidades en Congelador.');

        // «Pan» was created without choosing a category: the local dictionary gives it one (RN-31).
        await expect(row(page, 'Pan')).toContainText('Panadería y cereales');
        const freezer = page.getByRole('region', { name: /Congelador/ });
        await expect(freezer).toContainText('Merluza');
        await expect(freezer).toContainText('Pescado y marisco');

        // Without stock, a food stays in the catalog and moves to «Sin existencias».
        await stockAction(
            page,
            /^Acciones de Pan \(Despensa\)$/,
            'Eliminar las existencias de Pan (Despensa)',
        );
        await page
            .getByRole('dialog')
            .getByRole('button', { name: 'Eliminar existencias' })
            .click();
        await toast(page, 'Existencias eliminadas.');

        // The food sheet, from its card: fix the typo in the name.
        await page.getByRole('button', { name: 'Ficha de Arros' }).click();
        const sheet = page.getByRole('dialog');
        await sheet.getByLabel('Nombre', { exact: true }).fill('Arroz');
        await sheet.getByRole('button', { name: 'Guardar cambios' }).click();
        await toast(page, 'Alimento actualizado.');
        await page.keyboard.press('Escape');
        await expect(sheet).toBeHidden();
        await expect(row(page, 'Arroz')).toContainText(
            'Pasta, arroz y legumbres',
        );
        await expect(page.getByText('Arros', { exact: true })).toHaveCount(0);

        // Explicit alias «Huevo» → Huevos, and a duplicate rejected (RN-03).
        await page.getByRole('button', { name: 'Ficha de Huevos' }).click();
        await openFold(sheet, 'Otros nombres');
        await sheet.getByLabel('Nuevo alias de Huevos').fill('Huevo');
        await sheet
            .getByRole('button', { name: 'Añadir', exact: true })
            .click();
        // The alias shows as a chip and in the summary of its folded section.
        await expect(
            sheet.getByText('Huevo', { exact: true }).first(),
        ).toBeVisible();
        await sheet.getByLabel('Nombre', { exact: true }).fill('  ARRÓZ ');
        await sheet.getByRole('button', { name: 'Guardar cambios' }).click();
        await expect(
            sheet.getByText('Ya existe el alimento «Arroz».'),
        ).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(sheet).toBeHidden();
        await screenshot(page, '02-inventario-ficha');

        // «Sin existencias»: Pan, already classified, with no stock.
        await expect(page.getByText(/sin clasificar\./)).toHaveCount(0);
        await page.getByRole('link', { name: /Sin existencias/ }).click();
        await expect(row(page, 'Pan')).toContainText('Panadería y cereales');

        // Adding stock from there opens the dialog with the food filled in (not saved here).
        await page
            .getByRole('button', { name: 'Añadir Pan al inventario' })
            .click();
        await expect(add.getByLabel('Alimento', { exact: true })).toHaveValue(
            'Pan',
        );
        await page.keyboard.press('Escape');
        await expect(add).toBeHidden();
        await screenshot(page, '02-sin-existencias');
    });

    await test.step('2b. Existencias: alta por alias, búsqueda, edición y ajuste rápido', async () => {
        await page.getByRole('link', { name: /^Todo/ }).click();
        // The tab is a visit: let it finish before opening a dialog, or it closes it.
        await page.waitForURL((url) => !url.search.includes('location='));
        await expect(page.getByRole('link', { name: /^Todo/ })).toHaveAttribute(
            'aria-current',
            'page',
        );

        // The alias resolves to «Huevos»: 3 + 3.
        await page
            .getByRole('button', { name: 'Añadir al inventario' })
            .click();
        const add = page.getByRole('dialog');
        await add.getByLabel('Alimento', { exact: true }).fill('huevo');
        await add.getByLabel('Cantidad', { exact: true }).fill('3');
        await add.getByLabel('Unidad', { exact: true }).selectOption('piece');
        await add
            .getByLabel('Ubicación', { exact: true })
            .selectOption('fridge');
        await add.getByRole('button', { name: 'Añadir al inventario' }).click();
        await toast(page, 'Huevos: ahora hay 6 unidades en Frigorífico.');

        // Optional search and category filter.
        await page.getByLabel('Buscar en el inventario').fill('merl');
        await expect(page.getByText('Arroz', { exact: true })).toHaveCount(0);
        await expect(page.getByText('Merluza', { exact: true })).toBeVisible();
        await page.getByLabel('Buscar en el inventario').fill('zzz');
        await expect(
            page.getByText('Ningún alimento coincide con la búsqueda.'),
        ).toBeVisible();
        await page.getByRole('button', { name: 'Quitar filtros' }).click();
        await page
            .getByLabel('Filtrar por categoría')
            .selectOption('dairy_eggs');
        await expect(page.getByText('Huevos', { exact: true })).toBeVisible();
        await expect(page.getByText('Arroz', { exact: true })).toHaveCount(0);
        await page.getByLabel('Filtrar por categoría').selectOption('');

        // Full edit of the stock: 500 g → 200 g.
        await stockAction(
            page,
            /^Acciones de Arroz \(Despensa\)$/,
            'Editar las existencias de Arroz (Despensa)',
        );
        const dialog = page.getByRole('dialog');
        await dialog.getByLabel('Cantidad', { exact: true }).fill('200');
        await dialog.getByRole('button', { name: 'Guardar cambios' }).click();
        await expect(stockButton(page, 'Arroz', '200 g')).toBeVisible();

        // Quick adjustment: + / − for whole units and the inline amount.
        await page
            .getByRole('button', { name: 'Añadir una de Huevos' })
            .click();
        await expect(stockButton(page, 'Huevos', '7 unidades')).toBeVisible();
        await page
            .getByRole('button', { name: 'Quitar una de Huevos' })
            .click();
        await expect(stockButton(page, 'Huevos', '6 unidades')).toBeVisible();
        await stockButton(page, 'Huevos', '6 unidades').click();
        await page.getByLabel('Cantidad de Huevos en unidades').fill('5');
        await page
            .getByRole('button', { name: 'Guardar la cantidad de Huevos' })
            .click();
        await expect(stockButton(page, 'Huevos', '5 unidades')).toBeVisible();
        await page
            .getByRole('button', { name: 'Añadir una de Huevos' })
            .click();
        await expect(stockButton(page, 'Huevos', '6 unidades')).toBeVisible();
        await screenshot(page, '03-inventario');
    });

    await test.step('3. Receta: alta con ingrediente nuevo creado desde el formulario y edición', async () => {
        await navigate(page, 'Recetas');
        await page.getByRole('link', { name: 'Nueva receta' }).click();
        await page
            .getByLabel('Nombre', { exact: true })
            .fill('Arroz con huevo');
        await page.getByLabel('Raciones', { exact: true }).fill('2');

        const rows: [string, string, string][] = [
            ['Arroz', '400', 'g'],
            ['Huevos', '2', 'piece'],
            ['Tomate frito', '1', 'jar'],
        ];

        for (const [index, [ingredient, quantity, unit]] of rows.entries()) {
            if (index > 0) {
                await page
                    .getByRole('button', { name: 'Añadir ingrediente' })
                    .click();
            }

            await page
                .getByLabel('Ingrediente', { exact: true })
                .nth(index)
                .fill(ingredient);
            await page
                .getByLabel('Cantidad', { exact: true })
                .nth(index)
                .fill(quantity);
            await page
                .getByLabel('Unidad', { exact: true })
                .nth(index)
                .selectOption(unit);
        }

        await page.getByRole('button', { name: 'Guardar receta' }).click();
        await expect(
            page.getByText(/«Tomate frito» no está en tu catálogo/),
        ).toBeVisible();
        // The rest of the form is kept while the name is resolved.
        await expect(
            page.getByLabel('Ingrediente', { exact: true }).nth(0),
        ).toHaveValue('Arroz');
        await page
            .getByRole('button', { name: 'Crear «Tomate frito»' })
            .click();
        await expect(
            page.getByText(
                'Se creará «Tomate frito» como ingrediente nuevo al guardar.',
            ),
        ).toBeVisible();
        await page.getByRole('button', { name: 'Guardar receta' }).click();
        await toast(page, 'Receta «Arroz con huevo» guardada.');

        // Edit: 2 → 3 eggs.
        await page.getByRole('link', { name: 'Editar' }).click();
        await page.getByLabel('Cantidad', { exact: true }).nth(1).fill('3');
        await page.getByRole('button', { name: 'Guardar receta' }).click();
        await toast(page, 'Receta actualizada.');
        await expect(page.getByText(/Huevos/).first()).toBeVisible();
        await expect(page.getByText(/3 unidades/).first()).toBeVisible();
        await screenshot(page, '04-receta');

        // The food created from the recipe is in the shared catalog, once, without stock.
        await navigate(page, 'Inventario');
        await page.getByRole('link', { name: /Sin existencias/ }).click();
        await expect(row(page, 'Tomate frito')).toContainText('En 1 receta');
        await expect(
            page.getByText('Tomate frito', { exact: true }),
        ).toHaveCount(1);
    });

    await test.step('4 y 5. Plato sencillo con ingredientes nuevos y receta en el menú', async () => {
        await navigate(page, 'Menú');
        // The next week: its days are always ahead, whatever day the session runs (RN-36).
        await nextWeek(page);
        await expect(
            page.getByRole('heading', { name: /Semana del/ }),
        ).toBeVisible();

        const dish = await openSlot(page, 'Martes', 'Cena');
        await dish.getByRole('tab', { name: 'Plato sencillo' }).click();
        await dish.getByLabel('Nombre del plato').fill('Sandwich Mixto');
        await dish.getByLabel('Raciones').fill('2');

        const lines: [string, string, string][] = [
            ['Pan de molde', '4', 'slice'],
            ['Jamón de York', '2', 'deli_slice'],
            ['Pan bimbo', '1', 'piece'],
        ];

        for (const [index, [ingredient, quantity, unit]] of lines.entries()) {
            if (index > 0) {
                await dish
                    .getByRole('button', { name: 'Añadir ingrediente' })
                    .click();
            }

            await dish
                .getByLabel('Ingrediente', { exact: true })
                .nth(index)
                .fill(ingredient);
            await dish
                .getByLabel('Cantidad', { exact: true })
                .nth(index)
                .fill(quantity);
            await dish
                .getByLabel('Unidad', { exact: true })
                .nth(index)
                .selectOption(unit);
        }

        await dish
            .getByRole('button', { name: 'Añadir a esta comida' })
            .click();
        await expect(
            dish.getByText(
                /«Pan de molde» no está en tu catálogo. ¿Querías decir «Pan»\?/,
            ),
        ).toBeVisible();
        await screenshot(page, '05-sugerencias');

        // Explicit resolution: create the two new ones, use the suggestion for «Pan bimbo».
        await dish
            .getByRole('button', { name: 'Crear «Pan de molde»' })
            .click();
        await dish
            .getByRole('button', { name: 'Crear «Jamón de York»' })
            .click();
        await dish.getByRole('button', { name: 'Usar «Pan»' }).click();
        await expect(
            dish.getByLabel('Ingrediente', { exact: true }).nth(2),
        ).toHaveValue('Pan');
        await dish
            .getByRole('button', { name: 'Añadir a esta comida' })
            .click();
        await toast(page, '«Sandwich Mixto» guardado en el menú.');

        // The grid: seven days with a lunch and a dinner each.
        await expect(
            page.getByRole('group', { name: /, comida: / }),
        ).toHaveCount(7);
        await expect(page.getByRole('group', { name: /, cena: / })).toHaveCount(
            7,
        );

        // Recipe book next to the calendar, keyboard alternative: «Añadir al menú».
        const book = page.getByRole('complementary', { name: 'Recetario' });
        await book.getByLabel('Buscar en el recetario').fill('arroz');
        await book
            .getByRole('button', { name: 'Añadir Arroz con huevo al menú' })
            .click();
        const plan = page.getByRole('dialog');
        await plan.getByLabel('Día y comida').selectOption('1-lunch');
        await plan.getByLabel('Raciones').fill('3');
        await plan.getByRole('button', { name: 'Añadir al menú' }).click();
        await toast(page, '«Arroz con huevo» guardado en el menú.');

        const slot = (day: string, meal: string) =>
            page.getByRole('group', {
                name: new RegExp(`^${day} \\d+, ${meal}: `),
            });
        await expect(slot('Lunes', 'comida')).toHaveAccessibleName(
            /: Arroz con huevo$/,
        );
        await expect(
            slot('Lunes', 'comida').getByRole('listitem', {
                name: 'Arroz con huevo, 3 raciones',
            }),
        ).toBeVisible();

        // Point 1 (RN-16): a lunch or dinner holds several dishes. Dropping a recipe on a
        // taken slot adds it after the dish already there, with its base servings.
        await book
            .getByText('Arroz con huevo', { exact: true })
            .dragTo(slot('Martes', 'cena'));
        await toast(page, '«Arroz con huevo» guardado en el menú.');
        await expect(slot('Martes', 'cena')).toHaveAccessibleName(
            /: Sandwich Mixto, Arroz con huevo$/,
        );

        // Reorder within the dinner.
        await slot('Martes', 'cena')
            .getByRole('button', { name: /^Acciones de Arroz con huevo: / })
            .click();
        await page
            .getByRole('menuitem', { name: 'Subir Arroz con huevo' })
            .click();
        await expect(slot('Martes', 'cena')).toHaveAccessibleName(
            /: Arroz con huevo, Sandwich Mixto$/,
        );
        await screenshot(page, '06a-menu-varios-platos');

        // Dragging one dish moves only that dish; the other stays.
        await dragDish(
            page,
            slot('Martes', 'cena').getByRole('listitem', {
                name: /^Arroz con huevo, 2 raciones/,
            }),
            slot('Miércoles', 'comida'),
        );
        await toast(page, '«Arroz con huevo» movido.');
        await expect(slot('Martes', 'cena')).toHaveAccessibleName(
            /: Sandwich Mixto$/,
        );
        await expect(slot('Miércoles', 'comida')).toHaveAccessibleName(
            /: Arroz con huevo$/,
        );

        // «Mover» does the same without dragging.
        await dishAction(
            page,
            'Mover',
            'Arroz con huevo',
            'Miércoles \\d+ · Comida',
        );
        const moveDialog = page.getByRole('dialog');
        await moveDialog.getByLabel('Mover a').selectOption('5-dinner');
        await moveDialog.getByRole('button', { name: 'Mover' }).click();
        await toast(page, '«Arroz con huevo» movido.');
        await expect(slot('Miércoles', 'comida')).toHaveAccessibleName(
            /vacío$/,
        );

        // Remove it so the totals below stay as planned.
        await dishAction(
            page,
            'Retirar',
            'Arroz con huevo',
            'Viernes \\d+ · Cena',
        );
        await page
            .getByRole('dialog')
            .getByRole('button', { name: 'Retirar plato' })
            .click();
        await toast(page, '«Arroz con huevo» retirado del menú.');
        await expect(slot('Viernes', 'cena')).toHaveAccessibleName(/vacío$/);

        // Needs: 400 g × 3/2 = 600 g of rice, with 200 g in stock.
        const rice = row(page, 'Arroz');
        await expect(rice).toContainText('Necesitas 600 g');
        await expect(rice).toContainText('Faltan 400 g');
        await screenshot(page, '06-menu');
    });

    await test.step('6. Lista de la compra de la semana, sin cerrar el menú', async () => {
        await openWeekList(page);
        // B1 and M4: what is missing is the main figure; the calculation is folded.
        await expect(row(page, 'Arroz')).toContainText('Faltan');

        await expect(row(page, 'Arroz')).toContainText(/400\s*g/);
        // 1 jar × 3/2 = 1,5 → rounded up to 2 jars (RN-23).
        await expect(row(page, 'Tomate frito')).toContainText(/2\s*botes/);
        await expect(row(page, 'Pan de molde')).toContainText(/4\s*rebanadas/);
        await expect(row(page, 'Jamón de York')).toContainText(/2\s*lonchas/);
        await expect(row(page, 'Pan')).toContainText(/1\s*unidad/);
        // 4,5 eggs needed, 6 in stock: not on the list.
        await expect(page.getByText('Huevos', { exact: true })).toHaveCount(0);
        await screenshot(page, '07-lista');
    });

    await test.step('7. Edición de cantidad, línea manual y estado de compra', async () => {
        await page.getByRole('button', { name: 'Editar Pan de molde' }).click();
        const dialog = page.getByRole('dialog');
        await dialog.getByLabel('Cantidad', { exact: true }).fill('6');
        await dialog.getByRole('button', { name: 'Guardar' }).click();
        await expect(row(page, 'Pan de molde')).toContainText(/6\s*rebanadas/);
        await expect(row(page, 'Pan de molde')).toContainText('Modificada');

        // B2: adding opens a dialog, so the products stay first.
        await page.getByRole('button', { name: 'Añadir', exact: true }).click();
        await page.getByLabel('Artículo').fill('Papel de cocina');
        await page.getByRole('button', { name: 'Añadir a la lista' }).click();
        await toast(page, '«Papel de cocina» añadido a la lista.');
        await expect(row(page, 'Papel de cocina')).toContainText('Texto libre');

        await page
            .getByRole('checkbox', {
                name: 'Marcar Jamón de York como comprado',
            })
            .click();
        await expect(
            page.getByRole('checkbox', {
                name: 'Desmarcar Jamón de York como comprado',
            }),
        ).toHaveAttribute('aria-checked', 'true');
        await screenshot(page, '08-lista-editada');
    });

    await test.step('8. Primera recepción parcial', async () => {
        await page.getByRole('link', { name: 'Registrar compra' }).click();
        await expect(
            page.getByRole('heading', { name: 'Registrar compra' }),
        ).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'Confirmar compra' }),
        ).toBeDisabled();

        const rice = await decide(page, 'Arroz', 'Recibido');
        await expect(rice.getByLabel('Cantidad recibida')).toHaveValue('400');
        await rice.getByLabel('Cantidad recibida').fill('250');
        await expect(rice.getByLabel('Dónde lo guardas')).toHaveValue('pantry');

        const bread = await decide(page, 'Pan de molde', 'Recibido');
        await bread.getByLabel('Dónde lo guardas').selectOption('pantry');

        for (const name of [
            'Jamón de York',
            'Pan',
            'Papel de cocina',
            'Tomate frito',
        ]) {
            await decide(page, name, 'No comprado');
        }

        await screenshot(page, '09-recepcion-1');
        await confirmReception(page);
        await page.waitForURL(/shopping-list\?/);
        await toast(
            page,
            'Compra registrada: 2 productos añadidos al inventario.',
        );

        // The list now asks for what is missing with the new stock: 600 − (200 + 250) g.
        await expect(row(page, 'Arroz')).toContainText('Faltan 150 g');
        await expect(row(page, 'Arroz')).toContainText(
            'Recibido 250 g · falta 150 g',
        );
        await expect(
            row(page, 'Pan de molde').getByText('Recibido', { exact: true }),
        ).toBeVisible();
    });

    await test.step('9. Abrir la lista de nuevo la recalcula sin perder nada', async () => {
        await page.reload();
        await expect(row(page, 'Arroz')).toContainText(/150\s*g/);
        await expect(
            page.getByText('Papel de cocina', { exact: true }),
        ).toHaveCount(1);
        await expect(
            page.getByRole('checkbox', {
                name: 'Desmarcar Jamón de York como comprado',
            }),
        ).toHaveAttribute('aria-checked', 'true');
    });

    await test.step('10. Lo recibido no se cuenta dos veces', async () => {
        // 600 g − (200 + 250) g in stock = 150 g, never counted twice.
        await expect(row(page, 'Arroz')).toContainText(/150\s*g/);
        // What arrived for the line is shown, with what is still missing.
        await expect(row(page, 'Arroz')).toContainText(
            'Recibido 250 g · falta 150 g',
        );

        await page.getByRole('link', { name: 'Registrar compra' }).click();
        await expect(
            page.getByText('Ya has registrado 1 compra de esta lista'),
        ).toBeVisible();
        await expect(row(page, 'Arroz')).toContainText('Pendiente: 150 g');
        await expect(
            page.getByText('Pan de molde', { exact: true }),
        ).toHaveCount(0);
        await screenshot(page, '10-recepcion-2-pendiente');
    });

    await test.step('11. Segunda recepción con lo pendiente', async () => {
        const rice = await decide(page, 'Arroz', 'Recibido');
        await expect(rice.getByLabel('Cantidad recibida')).toHaveValue('150');

        const ham = await decide(page, 'Jamón de York', 'Recibido');
        await ham.getByLabel('Dónde lo guardas').selectOption('fridge');
        const sauce = await decide(page, 'Tomate frito', 'Recibido');
        await sauce.getByLabel('Dónde lo guardas').selectOption('pantry');
        const loaf = await decide(page, 'Pan', 'Recibido');
        await loaf.getByLabel('Dónde lo guardas').selectOption('pantry');
        await decide(page, 'Papel de cocina', 'No comprado');

        await confirmReception(page);
        await page.waitForURL(/shopping-list\?/);
        await toast(
            page,
            'Compra registrada: 4 productos añadidos al inventario.',
        );
    });

    await test.step('13. Reenviar la recepción anterior no suma de nuevo', async () => {
        // Back to the form already sent (same token), filled in again and confirmed again.
        await page.goBack();
        await expect(
            page.getByRole('heading', { name: 'Registrar compra' }),
        ).toBeVisible();
        await expect(row(page, 'Arroz')).toContainText('Pendiente: 150 g');

        await decide(page, 'Arroz', 'Recibido');
        await row(page, 'Jamón de York')
            .getByText('Recibido', { exact: true })
            .click();
        await row(page, 'Jamón de York')
            .getByLabel('Dónde lo guardas')
            .selectOption('fridge');
        await row(page, 'Tomate frito')
            .getByText('Recibido', { exact: true })
            .click();
        await row(page, 'Tomate frito')
            .getByLabel('Dónde lo guardas')
            .selectOption('pantry');
        await row(page, 'Pan').getByText('Recibido', { exact: true }).click();
        await row(page, 'Pan')
            .getByLabel('Dónde lo guardas')
            .selectOption('pantry');
        await decide(page, 'Papel de cocina', 'No comprado');

        await confirmReception(page);
        await toast(
            page,
            'Esta compra ya estaba registrada: no se ha vuelto a sumar al inventario.',
        );
    });

    await test.step('12. Inventario e historial', async () => {
        await navigate(page, 'Inventario');

        // 200 g + 250 g + 150 g in one row.
        await expect(stockButton(page, 'Arroz', '600 g')).toBeVisible();
        await expect(page.getByText('Arroz', { exact: true })).toHaveCount(1);
        await expect(
            stockButton(page, 'Pan de molde', '6 rebanadas'),
        ).toBeVisible();
        await expect(
            stockButton(page, 'Jamón de York', '2 lonchas'),
        ).toBeVisible();
        await expect(
            stockButton(page, 'Tomate frito', '2 botes'),
        ).toBeVisible();
        await expect(stockButton(page, 'Pan', '1 unidad')).toBeVisible();
        await expect(stockButton(page, 'Huevos', '6 unidades')).toBeVisible();
        await screenshot(page, '11-inventario-final');

        // «Lista de la compra» opens the list of the next days (RN-36).
        await navigate(page, 'Lista de la compra');
        await page
            .getByRole('button', {
                name: 'Periodo de compra: Próximos 7 días. Cambiar',
            })
            .click();
        await expect(
            page.getByRole('link', { name: 'Próximos 7 días' }),
        ).toHaveAttribute('aria-current', 'page');
        await page.keyboard.press('Escape');

        // The purchases are kept in the list of the planned week.
        await navigate(page, 'Menú');
        await nextWeek(page);
        await openWeekList(page);
        // B2: the purchases are folded below the list.
        const history = page.locator('[data-receptions]');
        await history.getByText('Compras registradas (2)').click();
        await expect(history.locator(':scope > ul > li')).toHaveCount(2);
        await expect(history).toContainText('Arroz: 250 g en Despensa');
        await expect(history).toContainText('Arroz: 150 g en Despensa');
        await expect(history).toContainText(
            'Jamón de York: 2 lonchas en Frigorífico',
        );
        await screenshot(page, '12-historial');
    });

    await test.step('12b. Inicio con los datos reales: hoy, la semana y la compra', async () => {
        await navigate(page, 'Inicio');

        const weekday = new Intl.DateTimeFormat('es-ES', {
            weekday: 'long',
            timeZone: 'Europe/Madrid',
        }).format(new Date());
        const today = page.getByRole('region', { name: /^Hoy, / });
        await expect(today).toContainText(weekday);
        // Everything was planned for next week: nothing today.
        await expect(
            today.getByRole('article', { name: 'Comida de hoy' }),
        ).toContainText('Sin planificar');
        await expect(
            today.getByRole('article', { name: 'Cena de hoy' }),
        ).toContainText('Sin planificar');

        const week = page.getByRole('region', { name: /Menú de la semana/ });
        await expect(week.getByRole('row')).toHaveCount(8);
        await expect(week.locator('tr[aria-current="date"]')).toHaveCount(1);

        // The summary is the persistent list of the next 7 days, with a link to it.
        const shopping = page.getByRole('region', {
            name: /Lista de la compra/,
        });
        await expect(shopping).toContainText('Próximos 7 días');
        await shopping
            .getByRole('link', { name: 'Ver la lista completa' })
            .click();
        await page.waitForURL(/\/shopping-list/);
        await navigate(page, 'Inicio');
        await screenshot(page, '12b-inicio');
    });

    await test.step('12c. Preparación incluida, ingredientes alternativos y compra en bolsas', async () => {
        const fillRow = async (
            index: number,
            ingredient: string,
            quantity: string,
            unit: string,
        ) => {
            await page
                .getByLabel('Ingrediente', { exact: true })
                .nth(index)
                .fill(ingredient);
            await page
                .getByLabel('Cantidad', { exact: true })
                .nth(index)
                .fill(quantity);
            await page
                .getByLabel('Unidad', { exact: true })
                .nth(index)
                .selectOption(unit);
        };

        // A preparation with two alternative ingredients (RN-42).
        await navigate(page, 'Recetas');
        await page.getByRole('link', { name: 'Nueva receta' }).click();
        await page.getByLabel('Nombre', { exact: true }).fill('Salsa base');
        await page.getByLabel('Raciones', { exact: true }).fill('2');
        await fillRow(0, 'Nata', '200', 'ml');
        await page
            .getByRole('button', { name: 'Añadir una alternativa a «Nata»' })
            .click();
        await fillRow(1, 'Leche evaporada', '150', 'ml');
        await page.getByRole('button', { name: 'Guardar receta' }).click();
        await page.getByRole('button', { name: 'Crear «Nata»' }).click();
        await page
            .getByRole('button', { name: 'Crear «Leche evaporada»' })
            .click();
        await page.getByRole('button', { name: 'Guardar receta' }).click();
        await toast(page, 'Receta «Salsa base» guardada.');
        await expect(page.getByText('o bien', { exact: true })).toBeVisible();

        // A recipe that includes it (RN-43).
        await navigate(page, 'Recetas');
        await page.getByRole('link', { name: 'Nueva receta' }).click();
        await page
            .getByLabel('Nombre', { exact: true })
            .fill('Croquetas con salsa');
        await page.getByLabel('Raciones', { exact: true }).fill('2');
        await fillRow(0, 'Croquetas', '25', 'piece');
        await page
            .getByRole('button', { name: 'Incluir una preparación' })
            .click();
        await page
            .getByLabel('Preparación', { exact: true })
            .selectOption({ label: 'Salsa base (2 rac.)' });
        await page.getByLabel('Raciones que usa').fill('2');
        await page.getByRole('button', { name: 'Guardar receta' }).click();
        await page.getByRole('button', { name: 'Crear «Croquetas»' }).click();
        await page.getByRole('button', { name: 'Guardar receta' }).click();
        await toast(page, 'Receta «Croquetas con salsa» guardada.');
        await expect(
            page.getByRole('link', { name: 'Salsa base' }),
        ).toBeVisible();
        await screenshot(page, '12c-receta-preparacion');

        // Croquetas are bought in bags of 20 (RN-40, RN-41).
        await navigate(page, 'Inventario');
        await page.getByRole('link', { name: /Sin existencias/ }).click();
        await page.getByRole('button', { name: 'Ficha de Croquetas' }).click();
        const sheet = page.getByRole('dialog');
        await openFold(sheet, 'Unidades y compra');
        await sheet
            .getByRole('group', { name: 'Nueva equivalencia' })
            .getByLabel('Unidad', { exact: true })
            .selectOption('bag');
        await sheet.getByLabel('Cantidad equivalente').fill('20');
        await sheet.getByLabel('Unidad equivalente').selectOption('piece');
        await sheet
            .getByRole('button', { name: 'Añadir equivalencia' })
            .click();
        await toast(page, /Equivalencia añadida a «Croquetas»/);
        await sheet.getByLabel('Se compra en').selectOption('bag');
        await sheet
            .getByRole('button', { name: 'Guardar', exact: true })
            .click();
        await toast(page, '«Croquetas» se comprará en bolsas enteras.');
        await expect(sheet).toContainText('1 bolsa = 20 unidades');
        await screenshot(page, '12c-ficha-unidades');
        await page.keyboard.press('Escape');

        // Planned next Thursday: the alternative is a proposal until chosen.
        await navigate(page, 'Menú');
        await nextWeek(page);
        const book = page.getByRole('complementary', { name: 'Recetario' });
        await book.getByLabel('Buscar en el recetario').fill('croquetas');
        await book
            .getByRole('button', { name: 'Añadir Croquetas con salsa al menú' })
            .click();
        const plan = page.getByRole('dialog');
        await plan.getByLabel('Día y comida').selectOption('4-dinner');
        await plan.getByRole('button', { name: 'Añadir al menú' }).click();
        await toast(page, '«Croquetas con salsa» guardado en el menú.');
        await expect(page.getByText('Propuesta: Nata')).toBeVisible();
        await page
            .getByRole('button', {
                name: 'Elegir entre Nata o Leche evaporada: Croquetas con salsa',
            })
            .click();
        await page
            .getByRole('dialog')
            .getByRole('radio', { name: /Leche evaporada/ })
            .check();
        await page
            .getByRole('dialog')
            .getByRole('button', { name: 'Usar esta' })
            .click();
        await toast(
            page,
            '«Croquetas con salsa» se preparará con Leche evaporada.',
        );
        await expect(
            page.getByText('Con Leche evaporada', { exact: true }),
        ).toBeVisible();
        await screenshot(page, '12c-menu-alternativa');

        // The list: 25 croquetas in whole bags, the chosen option only.
        await openWeekList(page);
        await expect(row(page, 'Croquetas')).toContainText(
            'Faltan 25 unidades',
        );
        await expect(row(page, 'Croquetas')).toContainText(
            /Para comprar:\s*2\s*bolsas\s*\(1 bolsa = 20 unidades\)/,
        );
        await expect(row(page, 'Leche evaporada')).toContainText(/150\s*ml/);
        await expect(page.getByText('Nata', { exact: true })).toHaveCount(0);
        await screenshot(page, '12c-lista-bolsas');

        // RN-46: «Guardar lo marcado». In the cart, then home in one operation, with the
        // suggested quantities (one corrected); nothing else of the list is touched.
        await page
            .getByRole('checkbox', { name: 'Marcar Croquetas como comprado' })
            .click();
        await page
            .getByRole('checkbox', {
                name: 'Marcar Leche evaporada como comprado',
            })
            .click();
        await expect(page.locator('[data-checked-bar]')).toContainText(
            '2 en el carro',
        );
        await page.getByRole('button', { name: 'Guardar 2 en casa' }).click();
        const cart = page.getByRole('dialog', { name: 'Guardar en casa' });
        await expect(cart.getByLabel('Cantidad').first()).toHaveValue('2');
        await cart.getByLabel('Cantidad').first().fill('3');
        await screenshot(page, '12d-guardar-lo-marcado');
        await cart
            .getByRole('button', { name: 'Guardar', exact: true })
            .click();
        await toast(page, 'Guardado en casa: 2 productos.');
        await expect(row(page, 'Croquetas')).toHaveCount(0);
        await expect(page.locator('[data-checked-bar]')).toHaveCount(0);

        await navigate(page, 'Inventario');
        await expect(
            page.getByRole('button', {
                name: 'Ajustar la cantidad de Croquetas: 3 bolsas',
            }),
        ).toBeVisible();

        // B4 (RN-47): a minimum at home for the evaporated milk (150 ml for the recipe + 300 ml
        // to keep − 150 ml at home = 300 ml) …
        await page
            .getByRole('button', { name: 'Ficha de Leche evaporada' })
            .click();
        const food = page.getByRole('dialog');
        await openFold(food, 'Mínimo en casa');
        await food.getByLabel('Al menos').fill('300');
        await food
            .locator('[data-restock]')
            .getByLabel('Unidad', { exact: true })
            .selectOption('ml');
        await food.getByRole('button', { name: 'Guardar mínimo' }).click();
        await toast(page, /«Leche evaporada»: se pedirá en la lista/);
        await page.keyboard.press('Escape');

        // … and «Se acabó» for the croquetas: every location to zero, after confirming; asked
        // for once (the last purchase, 3 bags = 60) with the 25 of the recipe: 85 → 5 bags.
        await stockAction(
            page,
            /^Acciones de Croquetas /,
            'Se acabó: Croquetas',
        );
        await page
            .getByRole('dialog')
            .getByRole('button', { name: 'Sí, se acabó' })
            .click();
        await toast(page, /«Croquetas»: 0 en casa\. Se ha añadido a la lista/);

        await navigate(page, 'Menú');
        await nextWeek(page);
        await openWeekList(page);
        await expect(row(page, 'Leche evaporada')).toContainText(/300\s*ml/);
        await expect(row(page, 'Leche evaporada')).toContainText(
            'Mínimo en casa',
        );
        await expect(row(page, 'Croquetas')).toContainText(/5\s*bolsas/);
        await expect(row(page, 'Croquetas')).toContainText('Se acabó');
        await screenshot(page, '12e-basicos');
    });

    await test.step('12d. Hallazgos de la prueba manual: habituales (M1, M3), unidad sugerida (E1), unidades (E2) y contenido del envase (M2)', async () => {
        await navigate(page, 'Inventario');
        await page.getByRole('link', { name: /Sin existencias/ }).click();
        await page.waitForURL((url) => url.search.includes('location=none'));
        const basics = page.locator('[data-basic-foods]');
        await expect(
            basics.getByRole('heading', { name: 'Alimentos habituales' }),
        ).toBeVisible();

        // RN-49: what the household already has is not offered again, by name, alias
        // («Huevo» is an alias of Huevos) or plural.
        for (const name of [
            'Arroz',
            'Huevo',
            'Huevos',
            'Pan',
            'Merluza',
            'Tomate frito',
        ]) {
            await expect(
                basics.getByRole('button', {
                    name: `Añadir ${name} al catálogo`,
                    exact: true,
                }),
            ).toHaveCount(0);
        }

        // The page search filters the suggestions too, with their groups open.
        const search = page.getByLabel('Buscar en el inventario');
        await search.fill('crema');
        await expect(
            basics.getByRole('button', {
                name: 'Añadir Crema de calabaza al catálogo',
            }),
        ).toBeVisible();
        await search.fill('zzzz');
        await expect(basics).toHaveCount(0);
        await search.fill('');

        // M1 + M3: one touch creates the food in the catalog, classified, with no stock.
        await openFold(basics, 'Caldos, cremas y sopas');
        await basics
            .getByRole('button', {
                name: 'Añadir Caldo de pollo al catálogo',
                exact: true,
            })
            .click();
        await toast(page, 'Alimento «Caldo de pollo» creado.');
        await expect(row(page, 'Caldo de pollo')).toContainText(
            'Caldos, cremas y sopas',
        );
        await expect(
            basics.getByRole('button', {
                name: 'Añadir Caldo de pollo al catálogo',
                exact: true,
            }),
        ).toHaveCount(0);

        // A name that looks like an existing food asks for the usual confirmation (RN-04).
        await openFold(basics, 'Panadería y cereales');
        await basics
            .getByRole('button', {
                name: 'Añadir Pan rallado al catálogo',
                exact: true,
            })
            .click();
        await expect(basics.getByRole('alert')).toContainText(
            'Se parece a «Pan»',
        );
        await expect(
            page.getByText('Pan rallado', { exact: true }),
        ).toHaveCount(1);
        await basics
            .getByRole('button', { name: 'Crear «Pan rallado» igualmente' })
            .click();
        await toast(page, 'Alimento «Pan rallado» creado.');
        await expect(basics.getByRole('alert')).toHaveCount(0);
        await expect(row(page, 'Pan rallado')).toContainText(
            'Panadería y cereales',
        );

        // E1: a food counted in cans. Its usual unit is chosen in its sheet.
        await openFold(basics, 'Conservas y envasados');
        await basics
            .getByRole('button', {
                name: 'Añadir Atún en lata al catálogo',
                exact: true,
            })
            .click();
        await toast(page, 'Alimento «Atún en lata» creado.');
        await page
            .getByRole('button', { name: 'Ficha de Atún en lata' })
            .click();
        const sheet = page.getByRole('dialog');
        await sheet
            .getByLabel('Unidad habitual (opcional)')
            .selectOption('can');
        await sheet.getByRole('button', { name: 'Guardar cambios' }).click();
        await toast(page, 'Alimento actualizado.');
        await page.keyboard.press('Escape');
        await expect(sheet).toBeHidden();

        // Adding stock suggests the unit of the food, however the name is typed.
        await page
            .getByRole('button', { name: 'Añadir al inventario' })
            .first()
            .click();
        const add = page.getByRole('dialog');
        // By id: with the pack content open there is a second «Unidad» field (of the content).
        const unit = add.locator('#unit');
        await add.getByLabel('Alimento', { exact: true }).fill('ATÚN EN LATA ');
        await expect(unit).toHaveValue('can');
        const warning = add.locator('#unit-warning');
        await expect(warning).toHaveCount(0);

        // E2: units in groups, the food's own first; tray, box and net are no longer offered.
        expect(
            await unit
                .locator('optgroup')
                .evaluateAll((groups) =>
                    groups.map((group) => group.getAttribute('label')),
                ),
        ).toEqual(['De este alimento', 'Medidas', 'Piezas', 'Envases']);
        await expect(
            unit.locator('optgroup[label="De este alimento"] option'),
        ).toHaveText(['latas']);
        for (const hidden of ['tray', 'box', 'net']) {
            await expect(unit.locator(`option[value="${hidden}"]`)).toHaveCount(
                0,
            );
        }

        // An incompatible unit warns, and says how to make it comparable.
        await unit.selectOption('g');
        await expect(warning).toContainText(
            'salvo que añadas una equivalencia en su ficha',
        );
        await expect(unit).toHaveAttribute('aria-describedby', 'unit-warning');
        await unit.selectOption('pack');
        await expect(warning).toContainText(
            'salvo que indiques cuánto trae cada envase',
        );
        await unit.selectOption('can');
        await expect(warning).toHaveCount(0);
        await add.getByLabel('Cantidad', { exact: true }).fill('3');
        await add
            .getByLabel('Ubicación', { exact: true })
            .selectOption('pantry');
        await add.getByRole('button', { name: 'Añadir al inventario' }).click();
        await expect(add).toBeHidden();
        await toast(page, 'Atún en lata: ahora hay 3 latas en Despensa.');

        // M2: the content of a pack, with a quick button, saved once in the food sheet.
        await page
            .getByRole('button', { name: 'Añadir al inventario' })
            .first()
            .click();
        await add.getByLabel('Alimento', { exact: true }).fill('Fideos finos');
        await add
            .getByLabel('Es un alimento nuevo: crearlo en el catálogo')
            .check();
        await unit.selectOption('pack');
        const content = add.getByRole('group', {
            name: '¿Cuánto trae cada envase? (opcional)',
        });
        await expect(content).toBeVisible();
        await content.getByRole('button', { name: '500 g' }).click();
        await expect(
            content.getByRole('button', { name: '500 g' }),
        ).toHaveAttribute('aria-pressed', 'true');
        await expect(add.getByLabel('Contenido')).toHaveValue('500');
        await add.getByLabel('Cantidad', { exact: true }).fill('2');
        await add
            .getByLabel('Ubicación', { exact: true })
            .selectOption('pantry');
        await add.getByRole('button', { name: 'Añadir al inventario' }).click();
        await expect(add).toBeHidden();
        await toast(
            page,
            'Fideos finos: ahora hay 2 paquetes en Despensa. Guardado en su ficha: 1 paquete = 500 g.',
        );

        // The sheet keeps the equivalence and the purchase format.
        await page.getByRole('link', { name: /^Todo/ }).click();
        await page.waitForURL((url) => !url.search.includes('location='));
        await page
            .getByRole('button', { name: 'Ficha de Fideos finos' })
            .click();
        await openFold(sheet, 'Unidades y compra');
        await expect(sheet).toContainText('1 paquete = 500 g');
        await expect(sheet.getByLabel('Se compra en')).toHaveValue('pack');
        await page.keyboard.press('Escape');
        await expect(sheet).toBeHidden();

        // Next time the content is already known: no question, only what the sheet says.
        await page
            .getByRole('button', { name: 'Añadir al inventario' })
            .first()
            .click();
        await add.getByLabel('Alimento', { exact: true }).fill('Fideos finos');
        await unit.selectOption('pack');
        await expect(add.locator('[data-pack-content]')).toContainText(
            'Cada envase trae 500 g (de su ficha).',
        );
        await expect(content).toHaveCount(0);
        await expect(warning).toHaveCount(0);
        await screenshot(page, '12d-envase-conocido');
        await page.keyboard.press('Escape');
        await expect(add).toBeHidden();
    });

    await test.step('14. Interfaz a 360 px', async () => {
        await page.setViewportSize({ width: 360, height: 780 });

        for (const [title, heading, file] of [
            ['Inicio', 'Inicio', '13-movil-inicio'],
            ['Menú', /Semana del/, '14-movil-menu'],
            ['Lista de la compra', 'Lista de la compra', '15-movil-lista'],
            ['Inventario', 'Inventario', '16-movil-inventario'],
        ] as const) {
            await navigate(page, title);
            await expect(
                page.getByRole('heading', { name: heading }).first(),
            ).toBeVisible();
            const overflow = await page.evaluate(
                () => document.documentElement.scrollWidth - window.innerWidth,
            );
            expect(
                overflow,
                `desbordamiento horizontal en ${title}`,
            ).toBeLessThanOrEqual(0);

            // Nothing visible sticks out of its list or card (e.g. a row wider than its card).
            const outside = await page.evaluate(() => {
                return [...document.querySelectorAll('main ul *')]
                    .filter((element) => {
                        const box = element.getBoundingClientRect();
                        const list = element.closest('ul');
                        const limit =
                            (list?.getBoundingClientRect().right ??
                                window.innerWidth) + 1;

                        return box.width > 1 && box.right > limit;
                    })
                    .map(
                        (element) =>
                            element.textContent?.trim().slice(0, 40) ||
                            element.tagName,
                    );
            });
            expect(
                outside,
                `elementos fuera del contenido en ${title}`,
            ).toEqual([]);

            // A badge never touches the quantity of its row (regression at 360 px).
            const touching = await page.evaluate(() =>
                [...document.querySelectorAll('main li')].flatMap((item) => {
                    const quantity = item.querySelector('[data-quantity]');

                    if (!quantity) {
                        return [];
                    }

                    const q = quantity.getBoundingClientRect();

                    return [...item.querySelectorAll('[data-slot="badge"]')]
                        .filter((badge) => {
                            const b = badge.getBoundingClientRect();

                            return (
                                b.right > q.left - 4 &&
                                b.left < q.right + 4 &&
                                b.bottom > q.top &&
                                b.top < q.bottom
                            );
                        })
                        .map((badge) => badge.textContent?.trim());
                }),
            );
            expect(
                touching,
                `insignias pegadas a la cantidad en ${title}`,
            ).toEqual([]);
            await screenshot(page, file);
        }

        // RN-49 at 360 px: the usual foods fit the page and every one answers to a 44 px touch.
        await navigate(page, 'Inventario');
        await page.getByRole('link', { name: /Sin existencias/ }).click();
        await page.waitForURL((url) => url.search.includes('location=none'));
        const basics = page.locator('[data-basic-foods]');
        await openFold(basics, 'Frutas');
        const chips = basics.locator('details[open] li button');
        await expect(chips.first()).toBeVisible();
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth - window.innerWidth,
            ),
            'desbordamiento horizontal en «Alimentos habituales»',
        ).toBeLessThanOrEqual(0);
        const chipSizes = await chips.evaluateAll((buttons) =>
            buttons.map((button) => {
                const box = button.getBoundingClientRect();

                return {
                    text: button.textContent?.trim(),
                    height: Math.round(box.height),
                    width: Math.round(box.width),
                    outside: box.right > window.innerWidth,
                };
            }),
        );
        expect(
            chipSizes.filter(
                (chip) => chip.height < 44 || chip.width < 44 || chip.outside,
            ),
            'botones de alimentos habituales pequeños o fuera de la pantalla',
        ).toEqual([]);
        await chips.first().scrollIntoViewIfNeeded();
        expect(await touchSize(chips.first())).toMatchObject({ height: 44 });
        await screenshot(page, '16b-movil-habituales');

        // B2 and B3 (Phase B): in the shop the products come first, and every control of a row
        // answers to a 44 px touch.
        await navigate(page, 'Menú');
        await nextWeek(page);
        await openWeekList(page);
        const firstRow = page.locator('main li:has([role="checkbox"])').first();
        await expect(firstRow).toBeVisible();
        expect(
            (await firstRow.boundingBox())?.y ?? 9999,
            'primer artículo de la lista',
        ).toBeLessThanOrEqual(300);

        for (const control of [
            firstRow.getByRole('checkbox'),
            firstRow.getByRole('button', { name: /^Editar / }),
            firstRow.getByRole('button', { name: /^Quitar / }),
            page.getByRole('button', { name: /^Periodo de compra: / }),
            page.getByRole('button', { name: 'Añadir', exact: true }),
        ]) {
            expect(await touchSize(control)).toMatchObject({
                height: 44,
                width: 44,
            });
        }

        await screenshot(page, '15b-movil-lista-arriba');

        // The weekly grid of the planned week shows its dishes.
        await navigate(page, 'Menú');
        await nextWeek(page);
        // On mobile the week is a strip of days with one day open (R2): Monday, then Tuesday.
        await expect(page.getByText('Arroz con huevo').first()).toBeVisible();
        await page
            .getByRole('navigation', { name: 'Elegir día' })
            .getByRole('button', { name: /^Martes / })
            .click();
        await expect(page.getByText('Sandwich Mixto').first()).toBeVisible();

        // The grid is also usable to plan on mobile: the week after (the planned one stays for
        // inspection), assign a dish, check it, and take it out again to leave that week empty.
        // On mobile the recipe book is a panel: choose the recipe, then the day and meal.
        await nextWeek(page);
        await page.getByRole('button', { name: 'Recetario' }).click();
        await page
            .getByRole('button', { name: 'Añadir Arroz con huevo al menú' })
            .click();
        const plan = page.getByRole('dialog', {
            name: 'Añadir «Arroz con huevo» al menú',
        });
        await expect(plan.getByLabel('Día y comida')).toHaveValue('1-lunch');
        await plan.getByRole('button', { name: 'Añadir al menú' }).click();
        await toast(page, '«Arroz con huevo» guardado en el menú.');
        await expect(page.getByRole('dialog')).toHaveCount(0);
        await expect(
            page.getByRole('button', {
                name: /Acciones de Arroz con huevo: Lunes \d+ · Comida/,
            }),
        ).toBeVisible();
        // Nothing sticks out of its calendar cell (e.g. the actions of a planned dish).
        const outsideCells = await page.evaluate(() =>
            [...document.querySelectorAll('[data-slot-key]')].flatMap(
                (cell) => {
                    const c = cell.getBoundingClientRect();

                    return [...cell.querySelectorAll('*')]
                        .filter((element) => {
                            const box = element.getBoundingClientRect();

                            return (
                                box.width > 1 &&
                                (box.right > c.right + 1 ||
                                    box.left < c.left - 1)
                            );
                        })
                        .map(
                            (element) =>
                                `${cell.getAttribute('data-slot-key')}: ${element.textContent?.trim().slice(0, 30) || element.tagName}`,
                        );
                },
            ),
        );
        expect(outsideCells, 'elementos fuera de su celda del menú').toEqual(
            [],
        );
        await screenshot(page, '17-movil-menu-planificar');

        await dishAction(
            page,
            'Retirar',
            'Arroz con huevo',
            'Lunes \\d+ · Comida',
        );
        await page
            .getByRole('dialog')
            .getByRole('button', { name: 'Retirar plato' })
            .click();
        await toast(page, '«Arroz con huevo» retirado del menú.');
        await expect(
            page.getByRole('button', {
                name: /Añadir plato: Lunes \d+ · Comida/,
            }),
        ).toBeVisible();

        // Point 4 (2026-10-22): the household name, the food sheet with its units and the
        // alternatives, at 360 px: nothing outside its box and 44 px touch targets (DESIGN-SYSTEM §6).
        expect(
            await touchSize(page.getByRole('link', { name: /^Hogar: / })),
        ).toMatchObject({ height: 44 });

        await navigate(page, 'Inventario');
        await page.getByRole('link', { name: /Sin existencias/ }).click();
        await page.waitForLoadState('networkidle');
        await page.getByRole('button', { name: 'Ficha de Croquetas' }).click();
        const sheet = page.getByRole('dialog');
        await openFold(sheet, 'Unidades y compra');
        const remove = sheet.getByRole('button', {
            name: 'Quitar la equivalencia 1 bolsa = 20 unidades',
        });
        await remove.scrollIntoViewIfNeeded();
        expect(await touchSize(remove)).toMatchObject({
            height: 44,
            width: 44,
        });

        for (const name of ['Añadir equivalencia', 'Guardar']) {
            const button = sheet.getByRole('button', { name, exact: true });
            await button.scrollIntoViewIfNeeded();
            expect(await touchSize(button)).toMatchObject({ height: 44 });
        }

        expect(await outside(page, '[role="dialog"]')).toEqual([]);
        await screenshot(page, '18-movil-ficha-unidades');
        await page.keyboard.press('Escape');

        await navigate(page, 'Menú');
        await nextWeek(page);
        // The croquetas are on Thursday's dinner: open that day in the strip (R2).
        await page
            .getByRole('navigation', { name: 'Elegir día' })
            .getByRole('button', { name: /^Jueves / })
            .click();
        const choose = page.getByRole('button', {
            name: /entre Nata o Leche evaporada: Croquetas con salsa/,
        });
        // Centred, so a toast at the bottom edge never covers the area being measured.
        await choose.evaluate((element) =>
            element.scrollIntoView({ block: 'center' }),
        );
        expect(await touchSize(choose)).toMatchObject({ height: 44 });
        await choose.click();
        await expect(page.getByRole('dialog')).toBeVisible();
        expect(await outside(page, '[role="dialog"]')).toEqual([]);
        await screenshot(page, '19-movil-alternativas');
        await page.keyboard.press('Escape');
    });

    await test.step('15. Multiusuario: invitar a un lector, que se registra, acepta y solo puede leer', async () => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto('/settings/household');
        await expect(
            page.getByRole('heading', { name: 'Tu hogar' }),
        ).toBeVisible();

        const invitee = `lector-${Date.now()}@food-pantry.test`;
        await page.getByLabel('Correo electrónico').fill(invitee);
        await page.getByLabel('Rol', { exact: true }).selectOption('viewer');
        await page.getByRole('button', { name: 'Invitar' }).click();
        await toast(page, `Invitación creada para ${invitee}`);
        const url = await page
            .getByLabel('Enlace de la invitación')
            .inputValue();
        expect(url).toMatch(/\/invitations\/[A-Za-z0-9]{64}$/);
        // The host comes from APP_URL; the session cookie belongs to the E2E server's host.
        const link = new URL(url).pathname;
        await expect(
            page
                .getByRole('listitem')
                .filter({ hasText: invitee })
                .getByRole('button', {
                    name: `Revocar la invitación de ${invitee}`,
                }),
        ).toBeVisible();
        await screenshot(page, '18-hogar-invitacion');

        // The invitee opens the link without an account: log in or register, then join.
        await page.context().clearCookies();
        await page.goto(link);
        await page.waitForURL(/\/login/);
        await page.goto('/register');
        await page.getByLabel('Nombre').fill('Lectora E2E');
        await page.getByLabel('Correo electrónico').fill(invitee);
        await page
            .getByLabel('Contraseña', { exact: true })
            .fill('Dev-lectora-e2e-1234');
        await page
            .getByLabel('Confirmar contraseña')
            .fill('Dev-lectora-e2e-1234');
        await page.getByRole('button', { name: 'Crear cuenta' }).click();
        await page.waitForURL(/\/(household\/setup|invitations\/)/);
        await page.goto(link);
        await expect(page.getByText(/te\s+invita a unirte/)).toBeVisible();
        await page.getByRole('button', { name: /Unirme a/ }).click();
        await page.waitForURL('**/dashboard');
        await toast(page, /Te has unido al hogar .* como Lector/);

        // Read only: the same data, a clear notice and no actions; the server refuses anyway.
        await expect(page.getByText(/Modo lectura/)).toBeVisible();
        await navigate(page, 'Inventario');
        await expect(stockButton(page, 'Arroz', '600 g')).toHaveCount(0);
        await expect(
            page.getByText('Arroz', { exact: true }).first(),
        ).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'Añadir al inventario' }),
        ).toHaveCount(0);
        const status = await page.evaluate(async () => {
            const token = decodeURIComponent(
                document.cookie
                    .split('; ')
                    .find((c) => c.startsWith('XSRF-TOKEN='))
                    ?.split('=')[1] ?? '',
            );
            const response = await fetch('/inventory', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'text/html',
                    'X-XSRF-TOKEN': token,
                },
                body: JSON.stringify({
                    ingredient: 'Arroz',
                    location: 'pantry',
                    quantity: '1',
                    unit: 'kg',
                }),
            });

            return response.status;
        });
        expect(status).toBe(403);
        await screenshot(page, '19-lector-inventario');

        // RN-49: the viewer sees the foods without stock, but no usual foods to add.
        await page.getByRole('link', { name: /Sin existencias/ }).click();
        await page.waitForURL((url) => url.search.includes('location=none'));
        await expect(row(page, 'Caldo de pollo')).toBeVisible();
        await expect(page.locator('[data-basic-foods]')).toHaveCount(0);
    });
});

import { readFileSync } from 'node:fs';
import type { Locator, Page } from '@playwright/test';
import { expect } from '@playwright/test';

/**
 * Dedicated E2E user. Another one can be chosen with E2E_EMAIL (e.g. to run on a new, empty
 * household without cleaning the usual one); each user keeps its own credentials file.
 */
export const E2E_EMAIL = process.env.E2E_EMAIL ?? 'e2e@food-pantry.test';
export const CREDENTIALS_FILE =
    E2E_EMAIL === 'e2e@food-pantry.test'
        ? 'e2e/.auth/credentials.json'
        : `e2e/.auth/credentials-${E2E_EMAIL.replace(/[^a-z0-9]+/gi, '-')}.json`;

export type Credentials = { email: string; password: string; name: string };

export function credentials(): Credentials {
    return JSON.parse(readFileSync(CREDENTIALS_FILE, 'utf8')) as Credentials;
}

/** Waits for the toast with this text (flash messages of the app). */
export async function toast(page: Page, text: string | RegExp): Promise<void> {
    await expect(
        page.locator('[data-sonner-toast]').filter({ hasText: text }).first(),
    ).toBeVisible();
}

/** A list item that contains exactly this name as one of its texts. */
export function row(scope: Page | Locator, name: string): Locator {
    return scope
        .locator('li')
        .filter({ has: scope.getByText(name, { exact: true }) })
        .first();
}

const PATHS: Record<string, string> = {
    Inicio: '/dashboard',
    Menú: '/menu',
    'Lista de la compra': '/shopping-list',
    Inventario: '/inventory',
    Recetas: '/recipes',
};

/**
 * Opens a page from the main navigation (sidebar on desktop, sheet on mobile) and waits until it
 * has really changed: the URL, and on mobile the sheet closed so the page is not hidden behind it.
 */
export async function navigate(page: Page, title: string): Promise<void> {
    const mobile = (page.viewportSize()?.width ?? 1280) < 768;

    if (mobile) {
        await page
            .getByRole('button', { name: 'Mostrar u ocultar el menú' })
            .click();
    }

    await page.getByRole('link', { name: title, exact: true }).first().click();
    await page.waitForURL((url) => url.pathname.startsWith(PATHS[title]));

    if (mobile) {
        await expect(page.getByRole('dialog', { name: 'Menú' })).toBeHidden();
    }
}

export async function screenshot(page: Page, name: string): Promise<void> {
    await page.screenshot({ path: `e2e/evidence/${name}.png`, fullPage: true });
}

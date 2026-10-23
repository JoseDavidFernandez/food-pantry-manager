import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { CREDENTIALS_FILE, E2E_EMAIL } from './support';

function artisan(args: string[], env: Record<string, string> = {}): string {
    return execFileSync('php', ['artisan', ...args], {
        encoding: 'utf8',
        env: { ...process.env, ...env },
    });
}

/**
 * Runs once before the E2E session. Stops unless the app is local and connected to the
 * development database, creates the dev-only credentials of the E2E user the first time, and
 * empties only that user's household (never at the end: the data is left for inspection).
 */
export default function globalSetup(): void {
    const [environment, database] = artisan([
        'tinker',
        '--execute',
        'echo app()->environment()."|".Illuminate\\Support\\Facades\\DB::connection()->getDatabaseName();',
    ])
        .trim()
        .split('|');

    if (environment !== 'local' || database !== 'food_pantry_manager') {
        throw new Error(
            `E2E detenido: se esperaba local + food_pantry_manager y hay ${environment} + ${database}.`,
        );
    }

    if (existsSync('public/hot')) {
        throw new Error(
            'E2E detenido: hay un servidor de Vite activo (public/hot). Usa el build (npm run build).',
        );
    }

    if (!existsSync(CREDENTIALS_FILE)) {
        mkdirSync('e2e/.auth', { recursive: true });
        // Exclusive to this local development user; never reused anywhere else.
        const password = `Dev-${randomBytes(12).toString('base64url')}-e2e`;
        writeFileSync(
            CREDENTIALS_FILE,
            JSON.stringify(
                { email: E2E_EMAIL, password, name: 'Pruebas E2E' },
                null,
                2,
            ),
        );
    }

    JSON.parse(readFileSync(CREDENTIALS_FILE, 'utf8'));

    // E2E_NO_RESET=1: nothing is cleaned. Only allowed for a user that does not exist yet, whose
    // household is created empty by the registration step of the session.
    if (process.env.E2E_NO_RESET === '1') {
        const exists = artisan([
            'tinker',
            '--execute',
            `echo App\\Models\\User::where('email', '${E2E_EMAIL}')->exists() ? 'yes' : 'no';`,
        ]).trim();

        if (exists !== 'no') {
            throw new Error(
                `E2E detenido: E2E_NO_RESET exige un usuario nuevo y ${E2E_EMAIL} ya existe.`,
            );
        }

        console.log(
            `E2E sin limpieza: se registrará ${E2E_EMAIL} con un hogar vacío.`,
        );

        return;
    }

    const env = { E2E_USER_EMAIL: E2E_EMAIL };
    console.log(
        artisan(
            ['db:seed', '--class=E2eHouseholdResetSeeder', '--no-interaction'],
            { ...env, E2E_DRY_RUN: '1' },
        ),
    );
    console.log(
        artisan(
            ['db:seed', '--class=E2eHouseholdResetSeeder', '--no-interaction'],
            env,
        ),
    );
}

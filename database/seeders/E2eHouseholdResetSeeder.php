<?php

namespace Database\Seeders;

use App\Models\Household;
use App\Models\User;
use Database\Seeders\Concerns\CleansHouseholdData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Development only, run by the Playwright global setup before each E2E session: empties the
 * functional data of the household of the dedicated E2E user, so the browser flow starts clean.
 * The user, the household and the membership are kept. It never runs at the end of the session:
 * the data the E2E tests create stays in the development database for manual inspection.
 *
 *   E2E_USER_EMAIL=e2e@food-pantry.test E2E_DRY_RUN=1 php artisan db:seed --class=E2eHouseholdResetSeeder
 *
 * Safeguards: APP_ENV=local, database food_pantry_manager, an e2e address, a household whose only
 * member is that user, and no rows of other households referencing its ingredients.
 */
class E2eHouseholdResetSeeder extends Seeder
{
    use CleansHouseholdData;

    public const DATABASE = 'food_pantry_manager';

    public function run(): void
    {
        if (! app()->environment('local') || DB::connection()->getDatabaseName() !== self::DATABASE) {
            throw new RuntimeException('E2eHouseholdResetSeeder solo se ejecuta en local contra '.self::DATABASE.'.');
        }

        $email = (string) getenv('E2E_USER_EMAIL');

        if (preg_match('/^e2e[^@]*@food-pantry\.test$/', $email) !== 1) {
            throw new RuntimeException('E2E_USER_EMAIL debe ser una dirección de pruebas e2e…@food-pantry.test.');
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->command->info("No existe {$email}: nada que limpiar (la prueba lo registrará).");

            return;
        }

        $household = $user->currentHousehold();
        $members = DB::table('household_user')->where('household_id', $household->id)->pluck('user_id')->all();

        if ($members !== [$user->id]) {
            throw new RuntimeException("El hogar {$household->id} tiene otros miembros. No se borra nada.");
        }

        DB::beginTransaction();

        try {
            Household::query()->whereKey($household->id)->lockForUpdate()->first();
            $this->ensureNothingOutsideReferencesTheHousehold($household);

            $this->report("Se borrará (solo hogar {$household->id}, de {$email})", $this->householdDataCounts($household));

            if (getenv('E2E_DRY_RUN') === '1') {
                DB::rollBack();
                $this->command->warn('E2E_DRY_RUN: no se ha borrado nada.');

                return;
            }

            $this->report('Borrado', $this->clean($household));
            DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function report(string $title, array $counts): void
    {
        $this->command->info($title.': '.implode(', ', array_map(fn (string $table, int $count) => "{$table}={$count}", array_keys($counts), $counts)));
    }
}

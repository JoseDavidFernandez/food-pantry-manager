<?php

namespace App\Actions\Ingredients\Images;

use App\Models\Ingredient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Images of catalog ingredients (TECHNICAL-DESIGN §10). Searching is always an explicit user
 * action and never assigns anything; choosing a result keeps a local copy with the attribution
 * its licence requires, so showing the inventory never calls an external service.
 */
class IngredientImages
{
    public const DISK = 'local';

    /**
     * Only these hosts are ever downloaded from (no URL comes from the browser).
     */
    private const ALLOWED_HOSTS = ['upload.wikimedia.org', 'images.openfoodfacts.org', 'static.openfoodfacts.org'];

    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(
        private readonly CommonsImages $commons,
        private readonly OpenFoodFactsImages $openFoodFacts,
    ) {}

    /**
     * @param  'photos'|'products'|'barcode'  $source
     * @return list<ImageCandidate>
     *
     * @throws ImageProviderUnavailable
     */
    public function search(string $source, string $text): array
    {
        return match ($source) {
            'photos' => $this->commons->search($text),
            'products' => $this->openFoodFacts->search($text),
            'barcode' => ($product = $this->openFoodFacts->find($text)) === null ? [] : [$product],
        };
    }

    /**
     * Keep a local copy of the chosen image, resolved again from the provider's reference.
     *
     * @throws ImageProviderUnavailable
     * @throws InvalidArgumentException when the reference does not identify a usable image
     */
    public function attach(Ingredient $ingredient, string $provider, string $reference): Ingredient
    {
        $candidate = match ($provider) {
            CommonsImages::NAME => $this->commons->find($reference),
            OpenFoodFactsImages::NAME => $this->openFoodFacts->find($reference),
            default => null,
        } ?? throw new InvalidArgumentException('La imagen elegida ya no está disponible. Busca de nuevo.');

        [$contents, $extension] = $this->download($candidate->downloadUrl);
        $path = sprintf('ingredient-images/%d/%d-%s.%s', $ingredient->household_id, $ingredient->id, substr(hash('sha256', $contents), 0, 16), $extension);
        $previous = $ingredient->image_path;

        Storage::disk(self::DISK)->put($path, $contents);

        DB::transaction(fn () => $ingredient->update([
            'image_path' => $path,
            'image_provider' => $candidate->provider,
            'image_source_url' => mb_substr($candidate->sourceUrl, 0, 500),
            'image_author' => $candidate->author,
            'image_license' => $candidate->license === null ? null : mb_substr($candidate->license, 0, 100),
            'image_license_url' => $candidate->licenseUrl === null ? null : mb_substr($candidate->licenseUrl, 0, 500),
            'image_updated_at' => now(),
        ]));

        if ($previous !== null && $previous !== $path) {
            Storage::disk(self::DISK)->delete($previous);
        }

        return $ingredient;
    }

    public function remove(Ingredient $ingredient): void
    {
        $path = $ingredient->image_path;

        $ingredient->update([
            'image_path' => null, 'image_provider' => null, 'image_source_url' => null, 'image_author' => null,
            'image_license' => null, 'image_license_url' => null, 'image_updated_at' => now(),
        ]);

        if ($path !== null) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /**
     * @return array{0: string, 1: string} contents and file extension
     *
     * @throws ImageProviderUnavailable
     */
    private function download(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! in_array($host, self::ALLOWED_HOSTS, true)) {
            throw new ImageProviderUnavailable('origen no permitido');
        }

        /** @var string $contact */
        $contact = config('services.ingredient_images.contact');
        /** @var int $timeout */
        $timeout = config('services.ingredient_images.timeout');
        /** @var int $maxBytes */
        $maxBytes = config('services.ingredient_images.max_bytes');

        try {
            $response = Http::withUserAgent("FoodPantryManager/1.0 (+{$contact})")
                ->timeout($timeout)
                ->connectTimeout($timeout)
                ->withoutRedirecting()
                ->get($url);
        } catch (ConnectionException) {
            throw new ImageProviderUnavailable('descarga');
        }

        $contents = $response->body();

        if (! $response->successful() || $contents === '' || strlen($contents) > $maxBytes) {
            throw new ImageProviderUnavailable('descarga');
        }

        // Trust the bytes, not the headers.
        $info = @getimagesizefromstring($contents);
        $mime = is_array($info) ? $info['mime'] : null;

        if ($mime === null || ! isset(self::TYPES[$mime])) {
            throw new ImageProviderUnavailable('formato no admitido');
        }

        return [$contents, self::TYPES[$mime]];
    }
}

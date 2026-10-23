<?php

namespace App\Actions\Ingredients\Images;

/**
 * Packaged products from Open Food Facts, by name or barcode: no API key; product photos are
 * CC BY-SA 3.0 and must credit Open Food Facts with a link to the product page.
 * Limits (per IP): 10 searches/min and 15 product reads/min, so searches only run on demand.
 */
class OpenFoodFactsImages extends ImageProvider
{
    public const NAME = 'off';

    private const SEARCH = 'https://es.openfoodfacts.org/cgi/search.pl';

    private const PRODUCT = 'https://world.openfoodfacts.org/api/v2/product/';

    private const FIELDS = 'code,product_name,product_name_es,brands,image_front_url,image_front_small_url';

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * @return list<ImageCandidate>
     *
     * @throws ImageProviderUnavailable
     */
    public function search(string $text): array
    {
        $data = $this->getJson(self::SEARCH, [
            'search_terms' => $text,
            'search_simple' => 1,
            'action' => 'process',
            'json' => 1,
            'page_size' => 12,
            'fields' => self::FIELDS,
        ]);

        $products = data_get($data, 'products', []);

        return array_values(array_filter(array_map(
            fn (mixed $product) => is_array($product) ? $this->candidate($product) : null,
            is_array($products) ? $products : [],
        )));
    }

    /**
     * @throws ImageProviderUnavailable
     */
    public function find(string $barcode): ?ImageCandidate
    {
        if (preg_match('/^\d{8,14}$/', $barcode) !== 1) {
            return null;
        }

        $data = $this->getJson(self::PRODUCT.$barcode, ['fields' => self::FIELDS]);
        $product = data_get($data, 'product');

        return (int) data_get($data, 'status') === 1 && is_array($product) ? $this->candidate($product) : null;
    }

    /**
     * @param  array<mixed>  $product
     */
    private function candidate(array $product): ?ImageCandidate
    {
        $code = (string) data_get($product, 'code', '');
        $image = data_get($product, 'image_front_url') ?? data_get($product, 'image_front_small_url');

        if (preg_match('/^\d{8,14}$/', $code) !== 1 || ! is_string($image)) {
            return null;
        }

        $name = trim((string) (data_get($product, 'product_name_es') ?: data_get($product, 'product_name') ?: $code));
        $brand = trim((string) data_get($product, 'brands', ''));

        return new ImageCandidate(
            provider: self::NAME,
            reference: $code,
            title: $brand === '' ? $name : "{$name} · {$brand}",
            thumbnailUrl: (string) (data_get($product, 'image_front_small_url') ?? $image),
            author: 'Colaboradores de Open Food Facts',
            license: 'CC BY-SA 3.0',
            licenseUrl: 'https://creativecommons.org/licenses/by-sa/3.0/deed.es',
            sourceUrl: "https://world.openfoodfacts.org/product/{$code}",
            downloadUrl: $image,
        );
    }
}

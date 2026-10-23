<?php

namespace App\Actions\Ingredients\Images;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Shared HTTP client for the image providers: identified User-Agent, short timeout and every
 * failure turned into ImageProviderUnavailable, so a provider outage never breaks a page.
 */
abstract class ImageProvider
{
    abstract public function name(): string;

    protected function http(): PendingRequest
    {
        /** @var string $contact */
        $contact = config('services.ingredient_images.contact');
        /** @var int $timeout */
        $timeout = config('services.ingredient_images.timeout');

        return Http::withUserAgent("FoodPantryManager/1.0 (+{$contact})")
            ->acceptJson()
            ->timeout($timeout)
            ->connectTimeout($timeout);
    }

    /**
     * @param  array<string, string|int>  $query
     * @return array<string, mixed>
     *
     * @throws ImageProviderUnavailable
     */
    protected function getJson(string $url, array $query = []): array
    {
        try {
            $response = $this->http()->get($url, $query);
        } catch (ConnectionException) {
            throw new ImageProviderUnavailable($this->name());
        }

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ImageProviderUnavailable
     */
    private function decode(Response $response): array
    {
        $data = $response->successful() ? $response->json() : null;

        if (! is_array($data)) {
            throw new ImageProviderUnavailable($this->name());
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}

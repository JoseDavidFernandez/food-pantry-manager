<?php

namespace App\Actions\Ingredients\Images;

/**
 * An image a provider offers for an ingredient, with what its licence requires to show.
 * `reference` is the provider's own identifier (a Commons file title, a barcode): the image is
 * always resolved again on the server from it, never from a URL sent by the browser.
 */
final readonly class ImageCandidate
{
    public function __construct(
        public string $provider,
        public string $reference,
        public string $title,
        public string $thumbnailUrl,
        public ?string $author,
        public ?string $license,
        public ?string $licenseUrl,
        public string $sourceUrl,
        // The version kept locally when the user chooses it (a reasonable size, from the provider).
        public string $downloadUrl,
    ) {}

    /**
     * @return array{provider: string, reference: string, title: string, thumbnail_url: string, author: string|null, license: string|null, license_url: string|null, source_url: string}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'reference' => $this->reference,
            'title' => $this->title,
            'thumbnail_url' => $this->thumbnailUrl,
            'author' => $this->author,
            'license' => $this->license,
            'license_url' => $this->licenseUrl,
            'source_url' => $this->sourceUrl,
        ];
    }
}

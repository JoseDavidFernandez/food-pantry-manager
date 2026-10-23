<?php

namespace App\Actions\Ingredients\Images;

/**
 * Generic photos from Wikimedia Commons: no API key, free licences (CC BY, CC BY-SA, public
 * domain…) that require crediting the author and the licence, which is stored with the image.
 */
class CommonsImages extends ImageProvider
{
    public const NAME = 'commons';

    private const API = 'https://commons.wikimedia.org/w/api.php';

    private const THUMB_WIDTH = 320;

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
        $data = $this->getJson(self::API, [
            ...$this->imageInfoQuery(),
            'generator' => 'search',
            'gsrsearch' => $text.' filetype:bitmap',
            'gsrnamespace' => 6,
            'gsrlimit' => 12,
        ]);

        return $this->candidates($data);
    }

    /**
     * @throws ImageProviderUnavailable
     */
    public function find(string $fileTitle): ?ImageCandidate
    {
        if (! str_starts_with($fileTitle, 'File:')) {
            return null;
        }

        return $this->candidates($this->getJson(self::API, [...$this->imageInfoQuery(), 'titles' => $fileTitle]))[0] ?? null;
    }

    /**
     * @return array<string, string|int>
     */
    private function imageInfoQuery(): array
    {
        return [
            'action' => 'query',
            'format' => 'json',
            'formatversion' => 2,
            'prop' => 'imageinfo',
            'iiprop' => 'url|mime|extmetadata',
            'iiurlwidth' => self::THUMB_WIDTH,
            'iiextmetadatafilter' => 'Artist|LicenseShortName|LicenseUrl|ObjectName',
            'iiextmetadatalanguage' => 'es',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<ImageCandidate>
     */
    private function candidates(array $data): array
    {
        $pages = data_get($data, 'query.pages', []);
        $candidates = [];

        foreach (is_array($pages) ? $pages : [] as $page) {
            $info = data_get($page, 'imageinfo.0');
            $thumb = data_get($info, 'thumburl');
            $mime = data_get($info, 'mime');

            if (! is_string($thumb) || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                continue;
            }

            $title = (string) data_get($page, 'title');

            $candidates[] = new ImageCandidate(
                provider: self::NAME,
                reference: $title,
                title: $this->text(data_get($info, 'extmetadata.ObjectName.value')) ?? preg_replace('/^File:|\.\w+$/', '', $title) ?? $title,
                thumbnailUrl: $thumb,
                author: $this->text(data_get($info, 'extmetadata.Artist.value')),
                license: $this->text(data_get($info, 'extmetadata.LicenseShortName.value')),
                licenseUrl: $this->text(data_get($info, 'extmetadata.LicenseUrl.value')),
                sourceUrl: (string) data_get($info, 'descriptionurl', 'https://commons.wikimedia.org/wiki/'.rawurlencode($title)),
                downloadUrl: $thumb,
            );
        }

        return $candidates;
    }

    /**
     * Commons metadata may contain HTML (links to the author's page): keep the plain text.
     */
    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5));

        return $text === '' ? null : mb_substr($text, 0, 255);
    }
}

<?php

namespace App\Actions\Ingredients\Images;

use RuntimeException;

/**
 * The provider did not answer, answered with an error or with something unusable.
 */
class ImageProviderUnavailable extends RuntimeException
{
    public function __construct(string $detail = '')
    {
        parent::__construct('El servicio de imágenes no responde ahora mismo. Inténtalo más tarde o continúa sin imagen.'.($detail === '' ? '' : " ({$detail})"));
    }
}

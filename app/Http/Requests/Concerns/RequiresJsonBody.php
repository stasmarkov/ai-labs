<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

/**
 * Only a JSON body is accepted, which keeps plain forms and links from writing or starting a model run. Another site's
 * script is refused by the route (RefuseCrossSiteRequests).
 */
trait RequiresJsonBody
{
    protected function prepareForValidation(): void
    {
        if (! $this->isJson()) {
            throw new UnsupportedMediaTypeHttpException('Send the request as a JSON body.');
        }
    }
}

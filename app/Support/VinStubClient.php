<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\VinReportUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

final readonly class VinStubClient
{
    /**
     * @return array<array-key, mixed>
     *
     * @throws VinReportUnavailableException when the VIN service fails or times out.
     */
    public function fetch(string $vin): array
    {
        try {
            $report = Http::baseUrl((string) config('services.vinstub.url'))
                ->connectTimeout((int) config('services.vinstub.connect_timeout'))
                ->timeout((int) config('services.vinstub.timeout'))
                ->acceptJson()
                ->get('/vin/'.$vin)
                ->throw()
                ->json();
        } catch (ConnectionException|RequestException $e) {
            throw VinReportUnavailableException::because($e);
        }

        if (! is_array($report)) {
            throw VinReportUnavailableException::because();
        }

        return $report;
    }
}

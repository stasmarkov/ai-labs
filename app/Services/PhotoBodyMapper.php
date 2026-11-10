<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Maps the body words of a Stanford class ("Sedan", "Extended Cab") to a catalog body type.
 */
final readonly class PhotoBodyMapper
{
    private const BODIES = [
        'sedan' => 'saloon',
        'hatchback' => 'hatchback',
        'wagon' => 'estate',
        'suv' => 'SUV',
        'minivan' => 'MPV',
        'coupe' => 'coupe',
        'convertible' => 'convertible',
    ];

    public function __construct(private StanfordCarsClassParser $parser) {}

    /**
     * The catalog body type of the class, or null when the class does not parse or its body has no mapping.
     */
    public function forClass(string $className): ?string
    {
        $body = mb_strtolower(trim($this->parser->parse($className)->body ?? ''));

        if ($body === '') {
            return null;
        }

        if (isset(self::BODIES[$body])) {
            return self::BODIES[$body];
        }

        return match (true) {
            preg_match('/\bcab\b|supercab/', $body) === 1 => 'pickup',
            preg_match('/\bvan\b/', $body) === 1 => 'van',
            default => null,
        };
    }
}

<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $path
 * @property int $bytes
 * @property string $sha256
 * @property int $rows
 * @property CarbonImmutable $generated_at
 */
class Export extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'rows' => 'integer',
            'generated_at' => 'immutable_datetime',
        ];
    }
}

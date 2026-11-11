<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $feature
 * @property string $provider
 * @property string $model
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class AiSetting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'feature';

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}

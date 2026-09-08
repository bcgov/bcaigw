<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ModelAliasTargetVersion extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Alias target versions are append-only.');
        });
        static::deleting(function (): never {
            throw new LogicException('Alias target versions are append-only.');
        });
    }
}

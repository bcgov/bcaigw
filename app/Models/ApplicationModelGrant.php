<?php

namespace App\Models;

use Database\Factories\ApplicationModelGrantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

class ApplicationModelGrant extends Model
{
    /** @use HasFactory<ApplicationModelGrantFactory> */
    use HasFactory;

    protected $guarded = ['id', 'public_id'];

    protected static function booted(): void
    {
        static::creating(function (self $grant): void {
            $grant->public_id ??= (string) Str::ulid();
        });
        static::deleting(function (): never {
            throw new LogicException('Application model grants must be disabled, not deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'enabled' => 'boolean',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function alias(): BelongsTo
    {
        return $this->belongsTo(PublicModelAlias::class, 'public_model_alias_id');
    }
}

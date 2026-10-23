<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class License extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'license_key',
        'user_id',
        'license_provider',
        'status',
        'meta',
    ];

    protected function casts()
    {
        return [
            'meta' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isActive()
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function getMaxFileSizeAttribute(): int
    {
        return 200000000;
    }

    public function getCustomDomainLimitCountAttribute(): ?int
    {
        return [
            1 => 1,
            2 => 10,
            3 => null,
        ][$this->meta['tier']];
    }

    public function getMaxUsersLimitCountAttribute(): ?int
    {
        return [
            1 => 1,
            2 => 5,
            3 => 20,
        ][$this->meta['tier']];
    }

    public static function booted(): void
    {
        static::saved(function (License $license) {
            if ($license->user) {
                $license->user->flushCache();
            }
        });
        static::deleted(function (License $license) {
            if ($license->user) {
                $license->user->flushCache();
            }
        });
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class YandexSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'url',
        'organization_name',
        'rating',
        'total_reviews',
        'last_synced_at',
    ];

    protected $casts = [
        'rating' => 'float',
        'total_reviews' => 'integer',
        'last_synced_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }
}

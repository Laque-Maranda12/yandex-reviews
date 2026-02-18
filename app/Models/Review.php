<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    use HasFactory;

    protected $fillable = [
        'yandex_source_id',
        'author_name',
        'author_phone',
        'rating',
        'text',
        'branch_name',
        'published_at',
        'yandex_id',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public function source()
    {
        return $this->belongsTo(YandexSource::class, 'yandex_source_id');
    }
}

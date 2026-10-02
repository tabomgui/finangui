<?php

namespace App\Domain\Tags\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    use BelongsToUser;

    /** @use HasFactory<TagFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'name', 'color'];

    protected static function newFactory(): TagFactory
    {
        return TagFactory::new();
    }
}

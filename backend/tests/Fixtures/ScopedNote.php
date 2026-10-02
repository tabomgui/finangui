<?php

namespace Tests\Fixtures;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;

class ScopedNote extends Model
{
    use BelongsToUser;

    protected $table = 'scoped_notes';

    protected $fillable = ['user_id', 'body'];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DelistingReason extends Model
{
    protected $fillable = ['name', 'normalized_name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}

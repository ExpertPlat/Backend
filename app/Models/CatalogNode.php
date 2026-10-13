<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogNode extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['labels' => 'array', 'rules' => 'array', 'regulated' => 'boolean'];
    }
}

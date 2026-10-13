<?php

namespace App\Services\Catalog;

use Illuminate\Support\Facades\DB;

class CatalogMutex
{
    public static function lock(): void
    {
        DB::table('catalog_locks')->where('id', 1)->lockForUpdate()->firstOrFail();
    }

    public static function changed(): void
    {
        DB::table('catalog_locks')->where('id', 1)->increment('revision');
    }
}

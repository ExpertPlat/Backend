<?php

namespace App\Console\Commands;

use App\Services\Catalog\CatalogReview;
use Illuminate\Console\Command;

class ReviewCatalogCases extends Command
{
    protected $signature = 'catalog:review-cases';

    protected $description = 'Flag submitted cases requiring explicit professional coverage review without rewriting pinned policies.';

    public function handle(CatalogReview $review): int
    {
        $this->info('Cases flagged: '.$review->run());

        return self::SUCCESS;
    }
}

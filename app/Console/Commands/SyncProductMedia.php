<?php

namespace App\Console\Commands;

use App\Support\ProductMediaPublisher;
use Illuminate\Console\Command;

final class SyncProductMedia extends Command
{
    protected $signature = 'novinpay:sync-product-media';

    protected $description = 'Publish static & responsive WebP derivatives for public products while excluding private test products';

    public function handle(): int
    {
        $count = ProductMediaPublisher::syncAllPublished(true);
        $this->info("PRODUCT_MEDIA_SYNCED count={$count}");

        return self::SUCCESS;
    }
}

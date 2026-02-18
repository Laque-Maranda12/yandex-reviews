<?php

namespace App\Console\Commands;

use App\Services\YandexReviewsService;
use Illuminate\Console\Command;

class SyncReviews extends Command
{
    protected $signature = 'reviews:sync
        {--full : Perform full sync (delete and re-fetch all reviews)}
        {--source= : Sync specific source ID only}';

    protected $description = 'Sync reviews from Yandex Maps for all sources';

    public function handle(YandexReviewsService $service): int
    {
        $sourceId = $this->option('source');
        $incremental = !$this->option('full');

        $mode = $incremental ? 'incremental' : 'full';
        $this->info("Starting {$mode} review sync...");

        if ($sourceId) {
            $source = \App\Models\YandexSource::find($sourceId);
            if (!$source) {
                $this->error("Source #{$sourceId} not found");
                return self::FAILURE;
            }

            try {
                if ($incremental) {
                    $result = $service->syncNewReviews($source);
                } else {
                    $result = $service->syncReviews($source);
                }

                $this->info("Synced: {$result->organization_name} — rating {$result->rating}, {$result->total_reviews} reviews");
            } catch (\Exception $e) {
                $this->error("Failed: {$e->getMessage()}");
                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        $results = $service->syncAllSources($incremental);

        $successCount = 0;
        $errorCount = 0;

        foreach ($results as $result) {
            if ($result['status'] === 'ok') {
                $this->info("  OK: {$result['organization']} — rating {$result['rating']}, {$result['total_reviews']} reviews");
                $successCount++;
            } else {
                $this->error("  FAIL: {$result['organization']} — {$result['error']}");
                $errorCount++;
            }
        }

        $this->newLine();
        $this->info("Sync complete: {$successCount} OK, {$errorCount} failed, " . count($results) . " total");

        return $errorCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\CaseDocumentVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupCaseDocuments extends Command
{
    protected $signature = 'case-documents:cleanup';

    protected $description = 'Remove unreferenced encrypted upload leftovers and stale scan scratch files only';

    public function handle(): int
    {
        $disk = (string) config('case_intake.disk');
        $storage = Storage::disk($disk);
        $cutoff = now()->subHours(max(1, (int) config('case_intake.orphan_grace_hours')))->timestamp;
        $count = 0;
        foreach ($storage->allFiles('documents') as $path) {
            if ($storage->lastModified($path) < $cutoff && ! CaseDocumentVersion::query()->where('disk', $disk)->where('path', $path)->exists()) {
                $storage->delete($path);
                $count++;
            }
        }
        foreach (glob(storage_path('app/private/case-scan/*.tmp')) ?: [] as $path) {
            if (filemtime($path) < $cutoff) {
                unlink($path);
                $count++;
            }
        }
        $this->info('Removed '.$count.' unreferenced temporary files.');

        return self::SUCCESS;
    }
}

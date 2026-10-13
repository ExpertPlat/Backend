<?php

namespace App\Services\Privacy;

use App\Enums\DataRequestStatus;
use App\Enums\DataRequestType;
use App\Exceptions\PrivacyException;
use App\Models\DataRightsRequest;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ExportDownload
{
    public function __construct(private readonly AuditWriter $audit) {}

    public function download(User $user, DataRightsRequest $record): Response
    {
        abort_unless((int) $record->user_id === (int) $user->id, 404);
        if ($record->type !== DataRequestType::Export || $record->status !== DataRequestStatus::Completed
            || ! $record->artifact_expires_at?->isFuture() || ! $record->artifact_path) {
            $this->audit->write($user, 'user.export_download_denied', 'data_request', $record->id, reason: 'EXPORT_NOT_AVAILABLE');
            throw new PrivacyException('EXPORT_NOT_AVAILABLE', 'This export is not available. Request a new export if it has expired.');
        }
        try {
            $json = Crypt::decryptString(Storage::disk($record->artifact_disk)->get($record->artifact_path));
            if (! hash_equals((string) $record->artifact_checksum, hash('sha256', $json))) {
                throw new \RuntimeException('Integrity check failed.');
            }
        } catch (Throwable) {
            $this->audit->write($user, 'user.export_download_denied', 'data_request', $record->id, reason: 'EXPORT_UNREADABLE');
            throw new PrivacyException('EXPORT_NOT_AVAILABLE', 'This export cannot be downloaded. Request a new export.');
        }
        $this->audit->write($user, 'user.export_downloaded', 'data_request', $record->id);

        return response($json, 200, ['Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="solveit-export-'.$record->reference.'.json"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}

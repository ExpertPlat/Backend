<?php

namespace App\Services\Cases;

use App\Exceptions\PrivacyException;
use App\Models\CaseDocumentVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CaseDocumentStorage
{
    private array $staged = [];

    public function store(UploadedFile $file): array
    {
        $disk = (string) config('case_intake.disk');
        $path = 'documents/'.Str::uuid().'.enc';
        $bytes = file_get_contents($file->getRealPath());
        if ($bytes === false) {
            throw new PrivacyException('DOCUMENT_STORAGE_FAILED', 'The document could not be stored.', 503);
        }
        $this->staged[] = [$disk, $path];
        Storage::disk($disk)->put($path, Crypt::encryptString($bytes));

        return ['disk' => $disk, 'path' => $path, 'checksum' => hash('sha256', $bytes), 'size' => strlen($bytes), 'mime_type' => (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()), 'extension' => strtolower($file->getClientOriginalExtension())];
    }

    public function cleanupUncommitted(): void
    {
        foreach ($this->staged as [$disk,$path]) {
            if (! CaseDocumentVersion::query()->where('disk', $disk)->where('path', $path)->exists()) {
                Storage::disk($disk)->delete($path);
            }
        }
        $this->staged = [];
    }

    public function bytes(CaseDocumentVersion $version): string
    {
        try {
            $contents = Storage::disk($version->disk)->get($version->path);
            $plain = Crypt::decryptString($contents);
            if (! hash_equals($version->checksum, hash('sha256', $plain))) {
                throw new \RuntimeException('Integrity check failed.');
            }

            return $plain;
        } catch (\Throwable) {
            throw new PrivacyException('DOCUMENT_NOT_READY', 'The document is unavailable or failed integrity verification.');
        }
    }
}

<?php

namespace App\Http\Controllers\Api\PublicApi;

use App\Http\Controllers\Controller;
use App\Models\ExpertProfile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams an expert's profile photo from whatever disk it lives on (local `public`, or a private bucket on
 * Laravel Cloud). The file name segment must match the current avatar, so an old URL stops working after a change.
 */
class ExpertAvatarController extends Controller
{
    public function show(int $profile, string $file): Response
    {
        $p = ExpertProfile::query()->whereKey($profile)->first(['id', 'avatar_disk', 'avatar_path', 'avatar_mime_type']);
        abort_unless($p && $p->avatar_path && basename($p->avatar_path) === $file, 404);
        $disk = Storage::disk($p->avatar_disk ?: ExpertProfile::avatarDisk());
        abort_unless($disk->exists($p->avatar_path), 404);

        return response($disk->get($p->avatar_path), 200, [
            'Content-Type' => in_array($p->avatar_mime_type, ['image/jpeg', 'image/png', 'image/webp'], true) ? $p->avatar_mime_type : 'image/jpeg',
            'Cache-Control' => 'public, max-age=86400, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

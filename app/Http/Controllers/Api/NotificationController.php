<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\MatchingResponses;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** In-app notifications for the authenticated account only (User or Expert realm, never both). */
class NotificationController extends Controller
{
    use MatchingResponses;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'perPage' => ['sometimes', 'integer', 'min:1', 'max:100'], 'unread' => ['sometimes', 'boolean']]);
        $q = $request->boolean('unread') ? $request->user()->unreadNotifications() : $request->user()->notifications();

        return $this->page($q->getQuery(), $data, fn ($n) => ['id' => $n->id, 'type' => $n->data['type'] ?? null, 'data' => $n->data, 'readAt' => $n->read_at?->toISOString(), 'createdAt' => $n->created_at->toISOString()]);
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $n = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $n->markAsRead();

        return $this->item(['id' => $n->id, 'readAt' => $n->fresh()->read_at?->toISOString()], 'Notification marked as read.');
    }
}

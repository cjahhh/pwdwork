<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PaginatesCollections;
use App\Models\UserNotification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    use PaginatesCollections;

    public function index(Request $request)
    {
        return $this->collectionResponse($request, $request->user()->userNotifications()->orderByDesc('id'), 'notifications', fn ($notice) => [
            'id' => $notice->id, 'title' => $notice->title, 'body' => $notice->body, 'actionUrl' => $notice->action_url,
            'readAt' => $notice->read_at?->toIso8601String(), 'createdAt' => $notice->created_at?->toIso8601String(),
        ]);
    }

    public function read(Request $request, UserNotification $userNotification)
    {
        abort_unless($userNotification->user_id === $request->user()->id, 403);
        if (! $userNotification->read_at) {
            $userNotification->update(['read_at' => now()]);
        }

        return response()->json(['message' => 'Notification marked as read.']);
    }
}

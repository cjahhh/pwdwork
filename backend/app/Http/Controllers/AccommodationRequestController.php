<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PaginatesCollections;
use App\Http\Requests\AccommodationWorkflowRequest;
use App\Models\AccommodationRequest;
use App\Services\AccommodationRequestService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccommodationRequestController extends Controller
{
    use PaginatesCollections;

    private const CONTEXT = ['application.user', 'application.jobListing.employer'];

    public function catalog()
    {
        return response()->json(['categories' => AccommodationRequest::categories(), 'stages' => AccommodationRequest::STAGES, 'statuses' => AccommodationRequest::STATUSES]);
    }

    public function index(Request $request, AccommodationRequestService $service)
    {
        $filters = $request->validate(['status' => ['sometimes', Rule::in(AccommodationRequest::STATUSES)],
            'application_id' => ['sometimes', 'integer', 'min:1'], 'query' => ['nullable', 'string', 'max:255']]);
        $query = AccommodationRequest::forParticipant($request->user())->with(self::CONTEXT);
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['application_id'])) {
            $query->where('application_id', $filters['application_id']);
        }
        if (! empty($filters['query'])) {
            $term = '%'.$filters['query'].'%';
            $query->where(fn ($q) => $q->whereHas('application.jobListing', fn ($job) => $job->where('title', 'like', $term))
                ->orWhereHas('application.user', fn ($user) => $user->where('name', 'like', $term)));
        }

        return $this->collectionResponse($request, $query->orderByDesc('id'), 'requests', fn ($record) => $service->payload($record))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, int $id, AccommodationRequestService $service)
    {
        $record = AccommodationRequest::forParticipant($request->user())->with(self::CONTEXT)->findOrFail($id);

        return response()->json(['request' => $service->payload($record)])->header('Cache-Control', 'private, no-store');
    }

    public function store(AccommodationWorkflowRequest $request, AccommodationRequestService $service)
    {
        $record = $service->create($request->user(), $request->validated());

        return response()->json(['request' => $service->payload($record->load(self::CONTEXT))], $record->wasRecentlyCreated ? 201 : 200)->header('Cache-Control', 'private, no-store');
    }

    public function action(AccommodationWorkflowRequest $request, int $id, AccommodationRequestService $service)
    {
        $record = $service->act($request->user(), $id, $request->validated());

        return response()->json(['request' => $service->payload($record->load(self::CONTEXT))])->header('Cache-Control', 'private, no-store');
    }

    public function messages(Request $request, int $id)
    {
        $record = AccommodationRequest::forParticipant($request->user())->findOrFail($id);
        $query = $record->messages()->whereHas('accommodationRequest', fn ($q) => $q->forParticipant($request->user()))->orderByDesc('id');

        return $this->collectionResponse($request, $query, 'messages', fn ($message) => [
            'id' => $message->id, 'sender' => $message->sender_role, 'body' => $message->body, 'createdAt' => $message->created_at->toIso8601String(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function events(Request $request, int $id)
    {
        $record = AccommodationRequest::forParticipant($request->user())->findOrFail($id);
        $query = $record->events()->whereHas('accommodationRequest', fn ($q) => $q->forParticipant($request->user()))->orderByDesc('id');

        return $this->collectionResponse($request, $query, 'events', fn ($event) => [
            'id' => $event->id, 'actor' => $event->actor_role, 'action' => $event->action, 'beforeStatus' => $event->before_status,
            'status' => $event->after_status, 'explanation' => $event->explanation, 'snapshot' => $event->snapshot,
            'version' => $event->version, 'createdAt' => $event->created_at->toIso8601String(),
        ])->header('Cache-Control', 'private, no-store');
    }
}

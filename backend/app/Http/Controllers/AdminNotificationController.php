<?php

namespace App\Http\Controllers;

use App\Models\Calendar;
use App\Notifications\InAppNotification;
use App\Support\CalendarNotificationRecipients;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminNotificationController extends Controller
{
    private const CATEGORY_LABELS = [
        'calendar' => 'Calendario',
        'shares' => 'Condivisioni',
        'feedback360' => 'Feedback 360',
        'gamification' => 'Gamification',
        'hobby' => 'Hobby',
        'goals' => 'Obiettivi',
        'projects' => 'Progetti',
        'surveys' => 'Sondaggi',
        'review' => 'Review',
        'sbi' => 'SBI',
        'suggestions' => 'Suggerimenti',
        'wiki' => 'Wiki',
        'timesheet' => 'Timesheet',
    ];

    public function index(Request $request): JsonResponse
    {
        $notifications = $this->notifications($request);
        $filters = $request->validate([
            ...$this->filterRules(),
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $unreadCounts = (clone $notifications)->whereNull('read_at')
            ->select('data->category as category', DB::raw('count(*) as total'))
            ->groupBy('data->category')
            ->pluck('total', 'category')
            ->map(fn ($total) => (int) $total);

        $page = $this->applyFilters($notifications, $filters)
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        $requestIds = $page->getCollection()->pluck('data.calendar_request_id')->filter()->unique();
        $statuses = Calendar::query()->whereKey($requestIds->all())->pluck('status', 'id');
        $page->through(function ($notification) use ($statuses) {
            $item = $notification->only(['id', 'data', 'read_at', 'created_at']);
            if (isset($item['data']['calendar_request_id'])) {
                $item['data']['calendar_status'] = $statuses->get($item['data']['calendar_request_id']);
            }

            return $item;
        });

        return response()->json([
            ...$page->toArray(),
            'unread_count' => $unreadCounts->sum(),
            'unread_by_category' => (object) $unreadCounts->all(),
            'categories' => collect(self::CATEGORY_LABELS)
                ->map(fn ($label, $key) => [
                    'key' => $key,
                    'label' => $label,
                    'unread_count' => $unreadCounts->get($key, 0),
                ])
                ->sortBy([['unread_count', 'desc'], ['label', 'asc']])
                ->values(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $notifications = $this->notifications($request);
        $data = $request->validate([
            ...$this->selectionRules($request),
            'read' => ['required', 'boolean'],
        ]);

        $selected = $this->selection($notifications, $data);
        $read = $request->boolean('read');
        $affected = $read
            ? $selected->whereNull('read_at')->update(['read_at' => now()])
            : $selected->whereNotNull('read_at')->update(['read_at' => null]);

        return response()->json(['affected' => $affected]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $notifications = $this->notifications($request);
        $data = $request->validate($this->selectionRules($request));

        return response()->json(['affected' => $this->selection($notifications, $data)->delete()]);
    }

    public function decide(Request $request, string $notification): JsonResponse
    {
        $item = $this->notifications($request)->whereKey($notification)->firstOrFail();
        $data = $request->validate(['status' => ['required', 'in:approved,rejected']]);
        $calendarRequestId = $item->data['calendar_request_id'] ?? null;
        abort_unless($calendarRequestId !== null && $item->data['category'] === 'calendar', 404);

        $entry = Calendar::query()->findOrFail($calendarRequestId);
        abort_unless(CalendarNotificationRecipients::forRequester($entry->user_id)
            ->whereKey($request->user()->id)->exists(), 403);

        $updated = Calendar::query()->whereKey($entry->id)->where('status', 'pending')
            ->update(['status' => $data['status']]);
        abort_unless($updated === 1, 409, 'La richiesta è già stata gestita.');

        return response()->json(['id' => $entry->id, 'status' => $data['status']]);
    }

    private function notifications(Request $request): MorphMany
    {
        $user = $request->user();
        if (! $request->routeIs('notifications.personal.*')) {
            abort_unless($user->roles()->whereIn('alias', ['Admin', ...CalendarNotificationRecipients::LEADERS])->exists(), 403);
        }

        return $user->notifications()->where('type', InAppNotification::class);
    }

    private function filterRules(): array
    {
        return [
            'category' => ['sometimes', 'string', 'max:100'],
            'categories' => ['sometimes', 'array', 'list', 'min:1', 'max:20'],
            'categories.*' => ['required', 'string', 'max:100', 'distinct'],
            'unread' => ['sometimes', 'boolean'],
        ];
    }

    private function applyFilters(MorphMany $notifications, array $filters): MorphMany
    {
        $categories = $filters['categories'] ?? (isset($filters['category']) ? [$filters['category']] : []);

        return $notifications
            ->when($categories !== [], fn ($query) => $query->whereIn('data->category', $categories))
            ->when((bool) ($filters['unread'] ?? false), fn ($query) => $query->whereNull('read_at'));
    }

    private function selection(MorphMany $notifications, array $data): MorphMany
    {
        if (isset($data['ids'])) {
            return $notifications->whereKey($data['ids']);
        }

        return $this->applyFilters($notifications, $data['filters'] ?? []);
    }

    private function selectionRules(Request $request): array
    {
        $rules = [
            'all' => ['required_without:ids', Rule::when($request->has('all'), ['accepted']), Rule::prohibitedIf($request->has('ids'))],
            'ids' => ['required_without:all', 'array', 'list', 'min:1', 'max:100'],
            'ids.*' => ['required', 'uuid', 'distinct'],
            'filters' => ['sometimes', 'array:category,categories,unread', Rule::prohibitedIf(! $request->has('all'))],
        ];

        foreach ($this->filterRules() as $field => $constraints) {
            $rules['filters.'.$field] = $constraints;
        }

        return $rules;
    }
}

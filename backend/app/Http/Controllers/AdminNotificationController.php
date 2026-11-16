<?php

namespace App\Http\Controllers;

use App\Models\Calendar;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Support\CalendarLeave;
use App\Support\CalendarNotificationRecipients;
use App\Support\ProjectParticipantAvatar;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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

        $unreadCounts = (clone $notifications)->reorder()->whereNull('read_at')
            ->select('data->category as category', DB::raw('count(*) as total'))
            ->groupBy('data->category')
            ->pluck('total', 'category')
            ->map(fn ($total) => (int) $total);

        $page = $this->applyFilters($notifications, $filters)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        $requestIds = $page->getCollection()->pluck('data.calendar_request_id')->filter()->unique();
        $entries = Calendar::query()->with(['user', 'typology'])->whereKey($requestIds->all())->get()->keyBy('id');
        $actorIds = $page->getCollection()->pluck('data.actor_id')->filter()->unique();
        $actors = User::query()->with('filestorage')->whereKey($actorIds->all())->get()->keyBy('id');

        $page->through(fn (DatabaseNotification $notification) => $this->notificationItem(
            $notification,
            $entries->get($notification->data['calendar_request_id'] ?? null),
            $actors->get($notification->data['actor_id'] ?? null),
            $request->user(),
        ));

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

    public function show(Request $request, string $notification): JsonResponse
    {
        $item = $this->notifications($request)->whereKey($notification)->firstOrFail();
        $calendarId = $item->data['calendar_request_id'] ?? null;
        $entry = $calendarId === null ? null : Calendar::with(['user', 'typology'])->find($calendarId);

        return response()->json($this->notificationItem($item, $entry, viewer: $request->user()));
    }

    public function avatar(Request $request, string $notification): BinaryFileResponse
    {
        $item = $this->notifications($request)->whereKey($notification)->firstOrFail();
        $actor = User::query()->with('filestorage')->findOrFail($item->data['actor_id'] ?? null);
        $path = ProjectParticipantAvatar::privatePath($actor);
        abort_if($path === null, 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function notificationItem(DatabaseNotification $notification, ?Calendar $entry, ?User $actor = null, ?User $viewer = null): array
    {
        $item = $notification->only(['id', 'data', 'read_at', 'created_at']);
        $item['category_label'] = self::CATEGORY_LABELS[$item['data']['category'] ?? ''] ?? null;
        if (isset($item['data']['calendar_request_id'])) {
            $item['data']['calendar_status'] = $entry?->status;
            if ($entry !== null && $viewer !== null) {
                $item['calendar_request'] = $this->calendarDetails($entry, $viewer);
            }
        }

        if ($actor !== null) {
            $item['actor'] = [
                'avatar_url' => ProjectParticipantAvatar::privatePath($actor) === null
                    ? null
                    : route('notifications.personal.avatar', ['notification' => $notification->id, 'v' => $actor->filestorage_id], false),
            ];
        }

        return $item;
    }

    private function calendarDetails(Calendar $entry, User $viewer): array
    {
        $user = $entry->user;
        $approver = CalendarNotificationRecipients::canApprove($viewer);
        $canDecide = $approver && $user !== null
            && CalendarNotificationRecipients::forRequester($entry->user_id)->whereKey($viewer->id)->exists();
        $hours = null;
        $calculationError = null;
        if ($user !== null) {
            try {
                $hours = CalendarLeave::hours($user, $entry->typology->alias,
                    $entry->start_date->toDateString(), $entry->end_date->toDateString(), $entry->start_time, $entry->end_time);
            } catch (ValidationException $exception) {
                $calculationError = collect($exception->errors())->flatten()->first();
            }
        }
        $balance = $approver && $user !== null ? $user->leaveBalances()
            ->where('leave_type', $entry->typology->alias === 'Ferie' ? 'ferie' : 'permesso')
            ->orderByDesc('reference_date')->orderByDesc('reference_month')->orderByDesc('id')->first() : null;

        return [
            'requester' => $user === null ? 'Utente non più attivo' : trim($user->first_name.' '.$user->last_name),
            'type' => $entry->typology->alias,
            'start_date' => $entry->start_date->toDateString(),
            'end_date' => $entry->end_date->toDateString(),
            'start_time' => $entry->start_time === null ? null : substr($entry->start_time, 0, 5),
            'end_time' => $entry->end_time === null ? null : substr($entry->end_time, 0, 5),
            'requested_hours' => $hours,
            'balance_hours' => $balance === null ? null : (float) $balance->remaining_hours,
            'notes' => $approver ? $entry->notes : null,
            'can_decide' => $canDecide && $entry->status === 'pending',
            'calculation_error' => $calculationError,
        ];
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
        abort_unless(CalendarNotificationRecipients::canApprove($request->user()), 403);
        abort_unless(CalendarNotificationRecipients::forRequester($entry->user_id)
            ->whereKey($request->user()->id)->exists(), 403);

        DB::transaction(function () use ($entry, $data) {
            $locked = Calendar::query()->with('typology')->lockForUpdate()->findOrFail($entry->id);
            abort_unless($locked->status === 'pending', 409, 'La richiesta è già stata gestita.');

            if ($data['status'] === 'approved' && in_array($locked->typology?->alias, ['Ferie', 'Permesso'], true)) {
                $user = \App\Models\User::query()->lockForUpdate()->findOrFail($locked->user_id);
                $type = $locked->typology->alias;
                $hours = CalendarLeave::hours($user, $type, $locked->start_date->toDateString(),
                    $locked->end_date->toDateString(), $locked->start_time, $locked->end_time);
                $balance = CalendarLeave::availableBalance($user, $type, $hours, lock: true);
                $balance->decrement('remaining_hours', $hours);
            }

            $locked->update(['status' => $data['status']]);
        }, 3);

        return response()->json(['id' => $entry->id, 'status' => $data['status']]);
    }

    private function notifications(Request $request): MorphMany
    {
        $user = $request->user();
        if (! $request->routeIs('notifications.personal.*')) {
            abort_unless($user->roles()->whereIn('alias', ['Admin', ...CalendarNotificationRecipients::LEADERS])->exists()
                || CalendarNotificationRecipients::canApprove($user), 403);
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

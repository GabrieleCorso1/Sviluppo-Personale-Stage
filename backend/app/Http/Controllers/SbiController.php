<?php

namespace App\Http\Controllers;

use App\Http\Resources\SbiResource;
use App\Models\Sbi;
use App\Models\SbiReply;
use App\Models\SbiRevision;
use App\Models\User;
use App\Policies\SbiPolicy;
use App\Support\ProjectCalendar;
use App\Support\ProjectFiles;
use App\Support\ProjectParticipantAvatar;
use App\Support\SbiText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SbiController extends Controller
{
    public function __construct(private SbiPolicy $policy) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Sbi::class);
        $filters = $request->validate([
            'user_id' => ['sometimes', 'integer', 'min:1'],
            'direction' => ['sometimes', Rule::in(['sent', 'received'])],
            'project_id' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'project_search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_positive' => ['sometimes', 'boolean'],
            'response_status' => ['sometimes', Rule::in(['answered', 'pending'])],
            'view_status' => ['sometimes', Rule::in(['viewed', 'unviewed'])],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $subject = (int) ($filters['user_id'] ?? $request->user()->id);
        $base = $this->policy->visibleQuery($request->user())->whereHas('latestRevision');
        if ($subject !== $request->user()->id) {
            abort_unless($this->policy->create($request->user()), 403);
            User::withTrashed()->findOrFail($subject);
        }
        $counts = [
            'sent' => (clone $base)->where('writer_id', $subject)->count(),
            'received' => (clone $base)->where('receiver_id', $subject)->count(),
            'unviewed' => (clone $base)->where('receiver_id', $subject)
                ->whereHas('latestRevision', fn ($query) => $query->whereNull('viewed_at'))->count(),
        ];
        $direction = $filters['direction'] ?? 'received';
        $query = $base->where($direction === 'sent' ? 'writer_id' : 'receiver_id', $subject);
        if (isset($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }
        if (filled($filters['project_search'] ?? null)) {
            $query->whereHas('project', fn ($project) => $project->where('alias', 'like', '%'.$filters['project_search'].'%'));
        }
        if (filled($filters['search'] ?? null)) {
            $relation = $direction === 'sent' ? 'receiver' : 'writer';
            foreach (preg_split('/\s+/u', trim($filters['search']), -1, PREG_SPLIT_NO_EMPTY) as $word) {
                $query->whereHas($relation, fn ($user) => $user->where(fn ($name) => $name
                    ->where('first_name', 'like', '%'.$word.'%')->orWhere('last_name', 'like', '%'.$word.'%')));
            }
        }
        $query->whereHas('latestRevision', function ($revision) use ($filters) {
            if (array_key_exists('is_positive', $filters)) {
                $revision->where('is_positive', $filters['is_positive']);
            }
            if (isset($filters['date_from'])) {
                $revision->whereDate('event_date', '>=', $filters['date_from']);
            }
            if (isset($filters['date_to'])) {
                $revision->whereDate('event_date', '<=', $filters['date_to']);
            }
            if (isset($filters['response_status'])) {
                $filters['response_status'] === 'answered' ? $revision->has('reply') : $revision->doesntHave('reply');
            }
            if (isset($filters['view_status'])) {
                $filters['view_status'] === 'viewed' ? $revision->whereNotNull('viewed_at') : $revision->whereNull('viewed_at');
            }
        });

        return SbiResource::collection($query->with(['writer.filestorage', 'receiver.filestorage', 'project', 'latestRevision.reply'])
            ->withCount('revisions')->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 10)->withQueryString())->additional(['counts' => $counts]);
    }

    public function users(Request $request)
    {
        $this->authorize('create', Sbi::class);
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = User::query();
        if (! $this->policy->isAdmin($request->user())) {
            $query->whereHas('projectUsers', fn ($assignment) => $assignment
                ->whereIn('project_id', $this->policy->managedProjects($request->user())->select('id'))
                ->whereDate('startwork_date', '<=', ProjectCalendar::today())
                ->where(fn ($period) => $period->whereNull('endwork_date')->orWhereDate('endwork_date', '>=', ProjectCalendar::today())));
        }
        foreach (preg_split('/\s+/u', trim($filters['search'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $query->where(fn ($name) => $name->where('first_name', 'like', '%'.$word.'%')->orWhere('last_name', 'like', '%'.$word.'%'));
        }

        return response()->json(['data' => $query->select(['id', 'first_name', 'last_name'])
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id')
            ->paginate($filters['per_page'] ?? 10)->withQueryString()]);
    }

    public function options(Request $request)
    {
        $this->authorize('create', Sbi::class);
        $data = $request->validate([
            'project_id' => ['sometimes', 'integer'], 'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'event_date' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:'.ProjectCalendar::today()],
        ]);
        $projects = $this->policy->managedProjects($request->user())->orderBy('alias')->get(['id', 'alias']);
        $users = collect();
        if (isset($data['project_id'])) {
            abort_unless($projects->contains('id', $data['project_id']), 403);
            $date = $data['event_date'] ?? ProjectCalendar::today();
            $users = User::query()->whereKeyNot($request->user()->id)
                ->whereHas('projectUsers', fn ($assignment) => $assignment->where('project_id', $data['project_id'])
                    ->whereDate('startwork_date', '<=', $date)
                    ->where(fn ($period) => $period->whereNull('endwork_date')->orWhereDate('endwork_date', '>=', $date)))
                ->when(filled($data['search'] ?? null), fn ($query) => $query->where(fn ($name) => $name
                    ->where('first_name', 'like', '%'.$data['search'].'%')->orWhere('last_name', 'like', '%'.$data['search'].'%')))
                ->orderBy('last_name')->orderBy('first_name')->get(['id', 'first_name', 'last_name']);
        }

        return response()->json(['data' => ['projects' => $projects, 'receivers' => $users]]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Sbi::class);
        $data = $this->revisionInput($request, true);
        $sbi = DB::transaction(function () use ($request, $data) {
            $project = $this->policy->managedProjects($request->user())->lockForUpdate()->find($data['project_id']);
            abort_unless($project, 403);
            $receiver = User::query()->lockForUpdate()->findOrFail($data['receiver_id']);
            if ($receiver->id === $request->user()->id || ! $receiver->projectUsers()
                ->where('project_id', $project->id)->whereDate('startwork_date', '<=', $data['event_date'])
                ->where(fn ($query) => $query->whereNull('endwork_date')->orWhereDate('endwork_date', '>=', $data['event_date']))->exists()) {
                throw ValidationException::withMessages(['receiver_id' => 'Seleziona un altro utente assegnato al progetto alla data indicata.']);
            }
            $sbi = Sbi::create(['writer_id' => $request->user()->id, 'receiver_id' => $receiver->id, 'project_id' => $project->id]);
            $sbi->revisions()->create($this->revisionFields($data) + ['revision_number' => 1]);

            return $sbi;
        });

        return $this->resource($sbi)->response()->setStatusCode(201);
    }

    public function show(Request $request, Sbi $sbi)
    {
        $this->authorize('view', $sbi);
        $data = $request->validate(['revision_id' => ['sometimes', 'integer']]);
        DB::transaction(function () use ($request, $sbi, $data) {
            $locked = Sbi::query()->lockForUpdate()->findOrFail($sbi->id);
            $this->authorize('view', $locked);
            $query = $locked->revisions();
            $revision = isset($data['revision_id']) ? $query->findOrFail($data['revision_id']) : $query->reorder()->orderByDesc('revision_number')->firstOrFail();
            if ($request->user()->id === $locked->receiver_id && $revision->viewed_at === null) {
                $revision->update(['viewed_at' => now()]);
            }
        });

        return $this->resource($sbi, true);
    }

    public function update(Request $request, Sbi $sbi)
    {
        $this->authorize('update', $sbi);
        $data = $this->revisionInput($request, false);
        DB::transaction(function () use ($sbi, $data) {
            $locked = Sbi::query()->lockForUpdate()->findOrFail($sbi->id);
            $this->authorize('update', $locked);
            $assigned = $locked->receiver->projectUsers()->where('project_id', $locked->project_id)
                ->whereDate('startwork_date', '<=', $data['event_date'])
                ->where(fn ($query) => $query->whereNull('endwork_date')->orWhereDate('endwork_date', '>=', $data['event_date']))
                ->exists();
            if (! $assigned) {
                throw ValidationException::withMessages(['event_date' => 'Il destinatario deve essere assegnato al progetto alla data dell’evento.']);
            }
            $number = (int) $locked->revisions()->withTrashed()->reorder()->max('revision_number') + 1;
            $locked->revisions()->create($this->revisionFields($data) + ['revision_number' => $number]);
            $locked->touch();
        });

        return $this->resource($sbi->refresh(), true);
    }

    public function reply(Request $request, Sbi $sbi, SbiRevision $revision)
    {
        $this->authorize('reply', $sbi);
        abort_unless($revision->sbi_id === $sbi->id, 404);
        $data = $request->validate(['body' => ['required', 'string', 'max:20000']]);
        $body = SbiText::clean($data['body']);
        $this->requireContent('body', $body);
        DB::transaction(function () use ($sbi, $revision, $body) {
            $locked = Sbi::query()->lockForUpdate()->findOrFail($sbi->id);
            $this->authorize('reply', $locked);
            $version = $locked->revisions()->findOrFail($revision->id);
            SbiReply::updateOrCreate(['sbi_revision_id' => $version->id], ['body' => $body]);
            if ($version->viewed_at === null) {
                $version->update(['viewed_at' => now()]);
            }
            $locked->touch();
        });

        return $this->resource($sbi->refresh(), true);
    }

    public function destroyRevision(Sbi $sbi, SbiRevision $revision)
    {
        $this->authorize('delete', $sbi);
        abort_unless($revision->sbi_id === $sbi->id, 404);
        DB::transaction(function () use ($sbi, $revision) {
            $locked = Sbi::query()->lockForUpdate()->findOrFail($sbi->id);
            $this->authorize('delete', $locked);
            $version = $locked->revisions()->findOrFail($revision->id);
            if ($locked->revisions()->count() === 1) {
                throw ValidationException::withMessages(['revision' => 'Per rimuovere l’ultima revisione elimina l’intero SBI.']);
            }
            $version->delete();
            $locked->touch();
        });

        return $this->resource($sbi->refresh(), true);
    }

    public function destroy(Sbi $sbi)
    {
        $this->authorize('delete', $sbi);
        DB::transaction(function () use ($sbi) {
            $locked = Sbi::query()->lockForUpdate()->findOrFail($sbi->id);
            $this->authorize('delete', $locked);
            $locked->revisions()->delete();
            $locked->delete();
        });

        return response()->json(['message' => 'SBI eliminato.']);
    }

    public function avatar(Sbi $sbi, int $user)
    {
        $this->authorize('view', $sbi);
        abort_unless(in_array($user, [$sbi->writer_id, $sbi->receiver_id], true), 404);
        $person = User::withTrashed()->with('filestorage')->findOrFail($user);
        $path = ProjectParticipantAvatar::privatePath($person);

        return response()->file($path ? Storage::disk('local')->path($path) : public_path('images/defaults/default-avatar.svg'), [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function logo(Sbi $sbi)
    {
        $this->authorize('view', $sbi);
        $path = ProjectFiles::logoPath($sbi->project->filestorage);

        return response()->file($path ? Storage::disk('local')->path($path) : public_path('images/defaults/default-project.svg'), [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function resource(Sbi $sbi, bool $history = false): SbiResource
    {
        $sbi->load(['writer.filestorage', 'receiver.filestorage', 'project', 'latestRevision.reply'])->loadCount('revisions');
        if ($history) {
            $sbi->load('revisions.reply');
        }

        return new SbiResource($sbi);
    }

    private function revisionInput(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'project_id' => $creating ? ['required', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')] : ['missing'],
            'receiver_id' => $creating ? ['required', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')] : ['missing'],
            'writer_id' => ['missing'], 'revision_number' => ['missing'], 'viewed_at' => ['missing'],
            'is_positive' => ['required', 'boolean'],
            'event_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.ProjectCalendar::today()],
            'situation' => ['required', 'string', 'max:20000'],
            'behavior' => ['required', 'string', 'max:20000'],
            'impact' => ['required', 'string', 'max:20000'],
            'suggestion' => ['nullable', 'string', 'max:20000'],
            'personal_notes' => ['nullable', 'string', 'max:20000'],
        ]);
        foreach (['situation', 'behavior', 'impact', 'suggestion', 'personal_notes'] as $field) {
            $data[$field] = isset($data[$field]) ? SbiText::clean($data[$field]) : null;
        }
        foreach (['situation', 'behavior', 'impact'] as $field) {
            $this->requireContent($field, $data[$field]);
        }

        return $data;
    }

    private function revisionFields(array $data): array
    {
        unset($data['project_id'], $data['receiver_id']);

        return $data;
    }

    private function requireContent(string $field, string $value): void
    {
        if (trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \n\r\t\v\0\xc2\xa0") === '') {
            throw ValidationException::withMessages([$field => 'Inserisci un testo.']);
        }
    }
}

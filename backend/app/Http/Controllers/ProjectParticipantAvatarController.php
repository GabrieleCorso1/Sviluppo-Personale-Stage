<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShowProjectRequest;
use App\Models\Project;
use App\Models\User;
use App\Support\ProjectCalendar;
use App\Support\ProjectParticipantAvatar;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProjectParticipantAvatarController extends Controller
{
    public function show(ShowProjectRequest $request, Project $project, User $user): BinaryFileResponse
    {
        $today = ProjectCalendar::today();
        $current = $project->projectUsers()->where('user_id', $user->id)
            ->whereDate('startwork_date', '<=', $today)
            ->where(fn ($query) => $query->whereNull('endwork_date')->orWhereDate('endwork_date', '>=', $today))
            ->exists();
        abort_unless($current, 404);
        $path = ProjectParticipantAvatar::privatePath($user);

        return response()->file($path ? Storage::disk('local')->path($path) : public_path('images/defaults/default-avatar.svg'), [
            'Cache-Control' => 'private, no-store',
        ]);
    }
}

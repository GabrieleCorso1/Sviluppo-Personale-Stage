<?php

namespace App\Support;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class ProjectParticipantAvatar
{
    public static function privatePath(User $user): ?string
    {
        $file = $user->filestorage;
        if (! $file || $file->name === 'default-avatar' || $file->type !== 'image') {
            return null;
        }
        $path = $file->path.'/'.$file->name.'.'.$file->extension;
        if (! str_starts_with($path, 'users/avatars/') || str_contains($path, '..')
            || ! in_array(strtolower($file->extension), ['png', 'jpg', 'jpeg', 'webp'], true)) {
            return null;
        }

        return Storage::disk('local')->exists($path) ? $path : null;
    }

    public static function fields(User $user, Project $project): array
    {
        $private = self::privatePath($user) !== null;

        return [
            'avatar_url' => $private
                ? route('projects.users.avatar', [$project, $user])
                : url('/images/defaults/default-avatar.svg'),
            'avatar_requires_auth' => $private,
        ];
    }
}

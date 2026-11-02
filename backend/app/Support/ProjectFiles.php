<?php

namespace App\Support;

use App\Models\FileStorage;
use Illuminate\Support\Facades\Storage;

class ProjectFiles
{
    public static function exists(?string $path, string $directory): bool
    {
        return $path !== null && str_starts_with($path, $directory.'/')
            && ! str_contains($path, '..') && ! str_contains($path, '\\')
            && Storage::disk('local')->exists($path);
    }

    public static function logoPath(?FileStorage $file): ?string
    {
        if (! $file || $file->name === 'default-project' || $file->type !== 'image'
            || ! in_array(strtolower($file->extension), ['png', 'jpg', 'jpeg', 'webp'], true)) {
            return null;
        }
        $path = $file->path.'/'.$file->name.'.'.$file->extension;

        return self::exists($path, 'projects/logos') ? $path : null;
    }
}

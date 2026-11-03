<?php

namespace App\Http\Controllers;

use App\Models\FileStorage;
use App\Models\User;
use App\Support\ProjectParticipantAvatar;
use App\Support\UserPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->refresh()->load(['roles', 'assignmentRoles', 'filestorage']);
        $private = ProjectParticipantAvatar::privatePath($user) !== null;
        // Keep the existing profile response and add the fields needed by the UI.
        $data = $user->toArray();
        unset($data['filestorage']);
        $data['hiring_date'] = $user->hiring_date?->toDateString();
        $data['avatar_url'] = $private ? route('user.avatar') : url('/images/defaults/default-avatar.svg');
        $data['avatar_requires_auth'] = $private;

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function password(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => UserPassword::rules(),
        ]);
        DB::transaction(function () use ($request, $data) {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            if (! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'La password attuale non è corretta.']);
            }
            if (Hash::check($data['password'], $user->password)) {
                throw ValidationException::withMessages(['password' => 'La nuova password deve essere diversa da quella attuale.']);
            }
            UserPassword::update($user, $data['password']);
        });

        return response()->json(['message' => 'Password aggiornata. Accedi con la nuova password.']);
    }

    public function avatar(Request $request): BinaryFileResponse
    {
        $path = ProjectParticipantAvatar::privatePath($request->user()->refresh()->load('filestorage'));

        return response()->file($path ? Storage::disk('local')->path($path) : public_path('images/defaults/default-avatar.svg'), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate(['avatar' => ['required', 'file', 'image', 'dimensions:min_width=1,min_height=1', 'mimes:jpg,jpeg,png,webp', 'max:2048']]);
        $path = $request->file('avatar')->store('users/avatars/'.$request->user()->id, 'local');
        abort_unless($path, 500, 'Impossibile salvare la foto.');
        try {
            $old = DB::transaction(function () use ($request, $path) {
                $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
                $old = $user->filestorage;
                $file = FileStorage::create([
                    'path' => dirname($path), 'name' => pathinfo($path, PATHINFO_FILENAME),
                    'extension' => pathinfo($path, PATHINFO_EXTENSION), 'type' => 'image',
                ]);
                $user->update(['filestorage_id' => $file->id]);
                return $old;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
        $this->removeUnusedAvatar($old, $request->user()->id);

        return $this->show($request);
    }

    public function removeAvatar(Request $request): JsonResponse
    {
        $old = DB::transaction(function () use ($request) {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $old = $user->filestorage;
            $default = FileStorage::firstOrCreate([
                'path' => 'images/defaults', 'name' => 'default-avatar', 'extension' => 'svg', 'type' => 'image',
            ]);
            $user->update(['filestorage_id' => $default->id]);
            return $old;
        });
        $this->removeUnusedAvatar($old, $request->user()->id);

        return $this->show($request);
    }

    private function removeUnusedAvatar(?FileStorage $file, int $userId): void
    {
        if (! $file || $file->path !== 'users/avatars/'.$userId
            || ! preg_match('/^[a-zA-Z0-9]+$/', $file->name)
            || ! in_array($file->extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return;
        }
        // Never delete defaults or an image still referenced by another entity.
        if (DB::table('users')->where('filestorage_id', $file->id)->exists()
            || DB::table('projects')->where('filestorage_id', $file->id)->exists()
            || DB::table('teamcategories')->where('filestorage_id', $file->id)->exists()) {
            return;
        }
        Storage::disk('local')->delete($file->path.'/'.$file->name.'.'.$file->extension);
    }
}

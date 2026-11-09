<?php

namespace App\Http\Controllers;

use App\Models\CalendarTypology;
use App\Notifications\InAppNotification;
use App\Support\CalendarNotificationRecipients;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CalendarRequestController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['Ferie', 'Permesso'])],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'start_time' => ['nullable', 'required_with:end_time', 'date_format:H:i'],
            'end_time' => ['nullable', 'required_with:start_time', 'date_format:H:i', 'after:start_time'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $type = CalendarTypology::query()->where('alias', $data['type'])->firstOrFail();
        unset($data['type']);
        $user = $request->user();

        $entry = DB::transaction(function () use ($data, $type, $user) {
            $entry = $user->calendarEntries()->create([
                ...$data,
                'typology_id' => $type->id,
                'status' => 'pending',
            ]);

            $message = 'Nuova richiesta di '.strtolower($type->alias);
            foreach (CalendarNotificationRecipients::forRequester($user->id)->lazyById() as $admin) {
                $admin->notify(new InAppNotification('calendar', $message, $user->id, $entry->id));
            }

            return $entry;
        });

        return response()->json(['id' => $entry->id, 'status' => $entry->status], 201);
    }
}

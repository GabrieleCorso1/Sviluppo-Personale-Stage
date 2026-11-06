<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class InAppNotification extends Notification
{
    public function __construct(
        private string $category,
        private string $message,
        private ?int $actorId = null,
        private ?int $calendarRequestId = null,
        private ?int $projectId = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'category' => $this->category,
            'message' => $this->message,
            'actor_id' => $this->actorId,
            ...($this->projectId === null ? [] : ['project_id' => $this->projectId]),
            ...($this->calendarRequestId === null ? [] : ['calendar_request_id' => $this->calendarRequestId]),
        ];
    }
}

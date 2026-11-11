<?php

namespace App\Http\Resources;

use App\Support\ProjectParticipantAvatar;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SbiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $actor = $request->user();
        $owner = $actor->id === $this->writer_id;
        $recipient = $actor->id === $this->receiver_id;
        $result = [
            'id' => $this->id,
            'writer' => $this->person($this->writer),
            'receiver' => $this->person($this->receiver),
            'project' => ['id' => $this->project_id, 'alias' => $this->project->alias,
                'logo_url' => route('sbis.logo', $this->resource), 'logo_requires_auth' => true],
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
            'revisions_count' => $this->revisions_count,
            'latest_revision' => $this->revision($this->latestRevision, $owner),
            'can_update' => $owner,
            'can_delete' => $owner,
            'can_reply' => $recipient,
        ];
        if ($this->resource->relationLoaded('revisions')) {
            $result['revisions'] = $this->revisions->map(fn ($revision) => $this->revision($revision, $owner));
        }

        return $result;
    }

    private function person($user): array
    {
        $private = ProjectParticipantAvatar::privatePath($user) !== null;

        return [
            'id' => $user->id, 'first_name' => $user->first_name, 'last_name' => $user->last_name,
            'avatar_url' => $private ? route('sbis.avatar', [$this->resource, $user]) : url('/images/defaults/default-avatar.svg'),
            'avatar_requires_auth' => $private,
        ];
    }

    private function revision($revision, bool $owner): ?array
    {
        if ($revision === null) {
            return null;
        }
        $result = [
            'id' => $revision->id, 'revision_number' => $revision->revision_number,
            'is_positive' => $revision->is_positive, 'event_date' => $revision->event_date->toDateString(),
            'situation' => $revision->situation, 'behavior' => $revision->behavior, 'impact' => $revision->impact,
            'suggestion' => ($owner || $revision->reply !== null) ? $revision->suggestion : null, 'viewed_at' => $revision->viewed_at?->toIso8601String(),
            'created_at' => $revision->created_at->toIso8601String(),
            'has_personal_notes' => $owner && filled($revision->personal_notes),
            'reply' => $revision->reply ? [
                'id' => $revision->reply->id, 'body' => $revision->reply->body,
                'created_at' => $revision->reply->created_at->toIso8601String(),
                'updated_at' => $revision->reply->updated_at->toIso8601String(),
            ] : null,
        ];
        if ($owner) {
            $result['personal_notes'] = $revision->personal_notes;
        }

        return $result;
    }
}

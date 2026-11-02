<?php

namespace App\Http\Resources;

use App\Support\ProjectFiles;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['currentProjectManagerAssignments.user:id,first_name,last_name', 'currentTeamLeaderAssignment.user:id,first_name,last_name', 'currentScrumMasterAssignment.user:id,first_name,last_name']);
        $data = parent::toArray($request);
        $managers = $this->resource->is_timesheet
            ? collect()
            : $this->resource->currentProjectManagerAssignments->pluck('user')->filter()->unique('id')->values();
        $data['project_managers'] = $managers->map(fn ($user) => [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
        ])->all();
        unset($data['project_manager_id'], $data['project_manager'], $data['current_project_manager_assignments']);
        $leader = $this->resource->is_timesheet
            ? null
            : $this->resource->currentTeamLeaderAssignment?->user;

        // Keep the API fields compatible while deriving them from current assignments.
        $data['team_leader_id'] = $leader?->id;
        $data['team_leader'] = $leader ? [
            'id' => $leader->id,
            'first_name' => $leader->first_name,
            'last_name' => $leader->last_name,
        ] : null;
        unset($data['current_team_leader_assignment']);
        $scrumMaster = $this->resource->is_timesheet
            ? null
            : $this->resource->currentScrumMasterAssignment?->user;
        $data['scrum_master_id'] = $scrumMaster?->id;
        $data['scrum_master'] = $scrumMaster ? [
            'id' => $scrumMaster->id,
            'first_name' => $scrumMaster->first_name,
            'last_name' => $scrumMaster->last_name,
        ] : null;
        unset($data['current_scrum_master_assignment']);
        $file = $this->resource->filestorage;
        $requiresAuth = ProjectFiles::logoPath($file) !== null;

        unset($data['filestorage']);

        $data['logo_url'] = $requiresAuth
            ? route('projects.logo', $this->resource)
            : url('/images/defaults/default-project.svg');
        $data['logo_requires_auth'] = $requiresAuth;

        $hasDeliveryPlan = ! $this->resource->is_timesheet
            && ProjectFiles::exists($this->resource->delivery_plan, 'projects/delivery-plans');
        $data['delivery_plan_url'] = $hasDeliveryPlan ? route('projects.delivery-plan', $this->resource) : null;
        $data['delivery_plan_requires_auth'] = $hasDeliveryPlan;

        if (! array_key_exists('next_sprint_review_date', $this->resource->getAttributes())) {
            $this->resource->loadMin('upcomingReviewSprints as next_sprint_review_date', 'end_date');
        }
        $data['next_sprint_review_date'] = $this->resource->is_timesheet
            ? null
            : $this->resource->getAttribute('next_sprint_review_date')?->toDateString();

        if ($this->resource->is_timesheet) {
            $data['missing_csv_count'] = 0;
        } else {
            $this->resource->loadMissing('sprintsRequiringCsv:id,project_id,csv_path,is_concluded,is_billing');
            $data['missing_csv_count'] = $this->resource->sprintsRequiringCsv
                ->filter(fn ($sprint) => ! $sprint->hasCsvFile())->count();
        }
        unset($data['sprints_requiring_csv']);

        return $data;
    }
}

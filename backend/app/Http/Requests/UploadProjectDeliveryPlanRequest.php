<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadProjectDeliveryPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->roles()
            ->where('alias', 'Admin')
            ->exists() ?? false;
    }

    public function rules(): array
    {
        return [
            'delivery_plan' => $this->route('project')->is_timesheet
                ? ['missing']
                : ['required', 'file', 'mimes:pdf', 'max:10240'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadSprintCsvRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', $this->route('project')) ?? false;
    }

    public function rules(): array
    {
        return [
            'csv' => ['required', 'file', 'extensions:csv', 'mimetypes:text/plain,text/csv,application/csv,text/comma-separated-values,application/vnd.ms-excel', 'max:10240'],
        ];
    }
}

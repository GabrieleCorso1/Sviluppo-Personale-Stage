<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

class UpdateUserLeavesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageLeaves', $this->route('user')) ?? false;
    }

    public function rules(): array
    {
        $amount = ['sometimes', 'required', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'];

        return [
            'leaves' => ['required', 'array', 'min:1', 'max:36'],
            'leaves.*' => ['required', 'array:leave_type,reference_month,reference_date,remaining_hours,accrued_hours,monthly_accrual'],
            'leaves.*.leave_type' => ['required', 'in:ferie,permesso,rol'],
            'leaves.*.reference_month' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.Carbon::now('Europe/Rome')->toDateString()],
            'leaves.*.reference_date' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.Carbon::now('Europe/Rome')->toDateString()],
            'leaves.*.remaining_hours' => $amount,
            'leaves.*.accrued_hours' => $amount,
            'leaves.*.monthly_accrual' => $amount,
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $seen = [];
            foreach ($this->input('leaves') as $index => $entry) {
                $key = $entry['leave_type'].'|'.substr($entry['reference_month'], 0, 7);
                if (isset($seen[$key])) {
                    $validator->errors()->add('leaves.'.$index.'.reference_month', 'Ogni tipologia può comparire una sola volta per mese.');
                }
                $seen[$key] = true;
                if (array_key_exists('reference_date', $entry)) {
                    if ($entry['leave_type'] === 'ferie') {
                        $validator->errors()->add('leaves.'.$index.'.reference_date', 'Le ferie usano solo il mese di riferimento.');
                    } elseif (substr($entry['reference_date'], 0, 7) !== substr($entry['reference_month'], 0, 7)) {
                        $validator->errors()->add('leaves.'.$index.'.reference_date', 'La data deve appartenere al mese di riferimento.');
                    }
                }
                if (array_intersect_key($entry, array_flip(['remaining_hours', 'accrued_hours', 'monthly_accrual'])) === []) {
                    $validator->errors()->add('leaves.'.$index, 'Inserisci almeno un contatore da aggiornare.');
                }
            }
        }];
    }
}

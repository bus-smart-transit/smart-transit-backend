<?php

namespace App\Http\Requests;

use App\Services\FinancialReportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FinancialFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role and ownership are enforced by the route group and the controller
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'route_id' => ['nullable', 'integer', 'min:1'],
            'fleet_id' => ['nullable', 'integer', 'min:1'],
            'trip_id' => ['nullable', 'integer', 'min:1'],
            'payment_method' => ['nullable', Rule::in(['cash', 'online'])],
            'channel' => ['nullable', 'string', 'max:40'],
            'sort' => ['nullable', Rule::in(FinancialReportService::SORTS)],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('financial.max_per_page')],
            'type' => ['nullable', Rule::in(['transactions', 'daily', 'refunds'])],
            'refund_basis' => ['nullable', Rule::in(['completed', 'sale'])],
        ];
    }
}

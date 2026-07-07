<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class AssignFleetRouteRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'route_id'   => 'required|integer|exists:routes,route_id',
            'start_time' => 'required|date_format:H:i',
            'end_time'   => 'required|date_format:H:i',
        ];
    }
}

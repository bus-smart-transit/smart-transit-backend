<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class StoreRouteRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'origin'     => 'required|string|max:255',
            'destination'=> 'required|string|max:255',
            'route_name' => 'required|string|max:255',
        ];
    }
}

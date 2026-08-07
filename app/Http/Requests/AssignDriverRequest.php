<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class AssignDriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
    public function rules(): array
    {
        return [
            'driver_id' => 'required|integer|exists:company_users,company_user_id',
        ];
    }
}

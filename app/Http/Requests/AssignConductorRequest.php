<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class AssignConductorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
    public function rules(): array
    {
        return [
            'conductor_id' => 'required|integer|exists:company_users,company_user_id',
        ];
    }
}

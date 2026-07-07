<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class CreateStaffAccountRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'name'      => 'required|string|max:255',
            'email'     => 'required|email|unique:users,email',
            'password'  => 'required|string|min:8',
            'phone_num' => 'required|string',
            'address'   => 'nullable|string',
            'username'  => 'nullable|string|unique:users,username',
            'role'      => 'required|string|in:operator,driver,conductor',
        ];
    }
}

<?php

namespace App\Http\Requests;

/**
 * Batch 48 Phase 3: the Super Admin adds a Company Admin to a company. Same fields and rules as Add Staff (the email
 * saved lowercased, the phone in E.164, a taken email or username gets one plain answer), without role or company:
 * the role is Operator and the company is the one in the address.
 */
class AddCompanyAdminRequest extends CreateStaffAccountRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['role'], $rules['company_name']);
        $rules['birth_date'] = 'nullable|date|before:today';

        return $rules;
    }
}

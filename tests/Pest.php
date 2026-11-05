<?php

use App\Models\StaffUser;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Batch 46 (least privilege): a Driver or Chauffeur belongs to one Operator. Older tests build one Operator and its crew
 * in any order, so their staff helpers call this: a new Driver or Chauffeur joins the first Operator, and a new
 * Operator takes on every Driver and Chauffeur that has no Operator yet. Tests about two companies set the link
 * themselves (see CrewCompanyScopeTest).
 */
function joinTestCompany(StaffUser $staff): StaffUser
{
    $role = $staff->user()->value('role');
    $crew = fn () => StaffUser::whereNull('operator_company_user_id')
        ->whereHas('user', fn ($q) => $q->whereIn('role', ['driver', 'conductor']));

    if ($role === 'operator') {
        if (! StaffUser::where('company_user_id', '!=', $staff->company_user_id)->whereHas('user', fn ($q) => $q->where('role', 'operator'))->exists()) {
            $crew()->update(['operator_company_user_id' => $staff->company_user_id]);
        }
    } elseif (in_array($role, ['driver', 'conductor'], true) && $staff->operator_company_user_id === null) {
        $operatorId = StaffUser::whereHas('user', fn ($q) => $q->where('role', 'operator'))->orderBy('company_user_id')->value('company_user_id');
        if ($operatorId) {
            $staff->update(['operator_company_user_id' => $operatorId]);
        }
    }

    return $staff;
}

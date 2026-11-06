<?php

use App\Models\Company;
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
 * Batch 46 (least privilege): staff work for a company. Older tests build one Operator and its crew in any order, so
 * their staff helpers call this: each new Operator gets its own test company, the first Operator's company takes on
 * every Driver and Chauffeur that has none yet, and a new Driver or Chauffeur joins the first Operator's company. Tests
 * about two companies set company_id themselves (see CrewCompanyScopeTest).
 */
function joinTestCompany(StaffUser $staff): StaffUser
{
    $role = $staff->user()->value('role');

    // Batch 46: a crew member starts Available (as a newly added one does), unless the test set it on purpose.
    if (in_array($role, ['driver', 'conductor'], true) && ! array_key_exists('is_available', $staff->getAttributes())) {
        $staff->forceFill(['is_available' => true])->save();
    }

    $operators = fn () => StaffUser::whereHas('user', fn ($q) => $q->where('role', 'operator'));

    if ($role === 'operator' && $staff->company_id === null) {
        $first = ! $operators()->where('company_user_id', '!=', $staff->company_user_id)->exists();
        $company = Company::create(['company_name' => 'Test Bus Co. '.$staff->company_user_id]);
        $staff->forceFill(['company_id' => $company->company_id])->save();
        if ($first) {
            StaffUser::whereNull('company_id')
                ->whereHas('user', fn ($q) => $q->whereIn('role', ['driver', 'conductor']))
                ->update(['company_id' => $company->company_id]);
        }
    } elseif (in_array($role, ['driver', 'conductor'], true) && $staff->company_id === null) {
        $companyId = $operators()->whereNotNull('company_id')->orderBy('company_user_id')->value('company_id');
        if ($companyId) {
            $staff->forceFill(['company_id' => $companyId])->save();
        }
    }

    return $staff;
}

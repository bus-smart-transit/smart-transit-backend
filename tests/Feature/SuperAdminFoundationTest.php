<?php

use App\Models\Company;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 48 Phase 1: the Super Admin (the `admin` role, the developers) is the only one who may use /api/admin. Every
// successful change there is recorded in the audit log, and an idle Super Admin session ends after 15 minutes.

function safUser(string $role, bool $companyAdmin = false): User
{
    $user = User::create(['username' => 'saf'.Str::random(8), 'email' => 'saf.'.Str::lower(Str::random(8)).'@example.com', 'password' => 'Right-password-1', 'role' => $role]);
    if ($role !== 'passenger') {
        StaffUser::create([
            'user_id' => $user->user_id,
            'company_user_uuid' => (string) Str::uuid(),
            'name' => 'Saf '.ucfirst($role),
            'phone_num' => '+639171234567',
            'address' => 'Sample',
            'company_id' => $role === 'admin' ? null : Company::firstOrCreate(['company_name' => 'Saf Bus Co'])->company_id,
            'is_company_admin' => $companyAdmin,
        ]);
    }

    return $user->fresh();
}

/** Every /api/admin route as [method, uri with each {id} filled in]. */
function safAdminRoutes(): array
{
    return collect(Router::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/admin/'))
        ->flatMap(fn ($route) => collect($route->methods())
            ->reject(fn ($method) => $method === 'HEAD')
            ->map(fn ($method) => [$method, '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri())]))
        ->values()
        ->all();
}

it('refuses every Super Admin route to Company Admins, Operators, Drivers, Chauffeurs and Passengers, and to no sign-in', function () {
    $routes = safAdminRoutes();
    expect(count($routes))->toBeGreaterThan(40);

    $others = [safUser('operator', true), safUser('operator'), safUser('driver'), safUser('conductor'), safUser('passenger')];
    foreach ($others as $user) {
        app('auth')->forgetGuards();
        $this->actingAs($user, 'sanctum');
        foreach ($routes as [$method, $uri]) {
            $status = $this->json($method, $uri)->getStatusCode();
            expect($status)->toBe(403, "{$user->role} got {$status} on {$method} {$uri}");
        }
    }

    app('auth')->forgetGuards();
    foreach ($routes as [$method, $uri]) {
        expect($this->json($method, $uri)->getStatusCode())->toBe(401, "no sign-in on {$method} {$uri}");
    }
});

it('records each successful Super Admin change in the audit log without the request body, and not reads or refusals', function () {
    $this->actingAs(safUser('admin'), 'sanctum');

    $this->getJson('/api/admin/profile')->assertOk();
    $this->postJson('/api/admin/routes', ['route_name' => 'Saf Route', 'origin' => 'A', 'destination' => 'B'])->assertSuccessful();
    $this->postJson('/api/admin/accounts', ['email' => 'not-an-email'])->assertUnprocessable();

    $entries = DB::table('audit_logs')->where('action', 'like', 'admin.%')->get();
    expect($entries)->toHaveCount(1)
        ->and($entries[0]->action)->toBe('admin.post')
        ->and(json_decode((string) $entries[0]->context, true))->toMatchArray(['route' => 'api/admin/routes'])
        ->and((string) $entries[0]->context)->not->toContain('Saf Route');
});

it('ends a Super Admin session idle for more than 15 minutes, and keeps one used recently', function () {
    $admin = safUser('admin');
    $token = function (int $minutesAgo) use ($admin): string {
        $made = $admin->createToken('test');
        DB::table('personal_access_tokens')->where('id', $made->accessToken->id)->update(['last_used_at' => now()->subMinutes($minutesAgo), 'created_at' => now()->subMinutes($minutesAgo + 1)]);

        return $made->plainTextToken;
    };

    $idle = $token(16);
    $recent = $token(5);
    app('auth')->forgetGuards();
    $this->withToken($idle)->getJson('/api/admin/profile')->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->withToken($recent)->getJson('/api/admin/profile')->assertOk();
    expect(DB::table('personal_access_tokens')->where('tokenable_id', $admin->user_id)->count())->toBe(1);
});

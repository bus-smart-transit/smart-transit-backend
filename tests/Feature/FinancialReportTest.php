<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 29, Stage 2: the financial page. Paid tickets only, refunds subtracted separately, Manila days and hours with
// the mixed timestamp convention (UTC on onsite_payment.issued_at and ticket_resolutions.completed_at, Manila elsewhere).
// Self-contained fixtures with made-up names.

function fnUser(string $role): User
{
    $user = User::create(['username' => $role.Str::random(6), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    if ($role !== 'admin') {
        StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => ucfirst($role).' '.Str::random(3), 'phone_num' => '0917', 'address' => 'x']);
    }

    return $user->fresh();
}

function fnCid(User $user): int
{
    return (int) $user->companyProfile->company_user_id;
}

/** An operator's fleet on a route with one trip. @return array{operator: User, fleet: Fleet, route: Route, fleetRoute: FleetRoute, trip: Trip} */
function fnWorld(string $routeName = 'Test Route', string $plate = 'FN-0001', ?User $operator = null, string $tripDate = '2026-10-05', string $time = '08:00', string $direction = 'outbound'): array
{
    $operator ??= fnUser('operator');
    $route = Route::create(['route_name' => $routeName, 'origin' => 'Alpha', 'destination' => 'Omega']);
    $fleet = Fleet::create([
        'company_user_id' => fnCid($operator), 'plate_number' => $plate, 'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active',
        'bus_company_name' => 'Test Lines', 'body_no' => 'B-'.Str::random(3),
    ]);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => fnCid($operator), 'trip_date' => $tripDate, 'departure_time' => $time,
        'trip_type' => 'one_way', 'leg_direction' => $direction, 'status' => 'completed',
        'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);

    return compact('operator', 'fleet', 'route', 'fleetRoute', 'trip');
}

/**
 * One payment with its tickets. $created is the Manila wall-clock time of the payment (and of the tickets, which an
 * online payment mints when the webhook confirms it); $issuedUtc is onsite_payment.issued_at for a cash ticket (UTC).
 *
 * @param  array<int, float>  $amounts  one ticket per amount
 * @return array{payment_id: int, ticket_ids: array<int, int>}
 */
function fnSale(array $world, array $amounts, string $created, string $method = 'online', string $channel = 'gcash', string $status = 'paid', ?string $issuedUtc = null, bool $valid = true, string $ticketStatus = 'valid'): array
{
    $paymentId = DB::table('payments')->insertGetId([
        'amount' => array_sum($amounts), 'payment_created' => $created, 'transaction_reference' => 'TX-'.Str::upper(Str::random(8)), 'payment_method' => $method,
        'payment_channel' => $channel, 'status' => $status, 'payment_uuid' => (string) Str::uuid(), 'is_valid' => $valid, 'created_at' => $created, 'updated_at' => $created,
    ]);
    if ($issuedUtc) {
        DB::table('onsite_payment')->insert(['payment_id' => $paymentId, 'conductor_id' => fnCid(fnUser('conductor')), 'issued_at' => $issuedUtc, 'created_at' => $created, 'updated_at' => $created]);
    }
    $ids = [];
    foreach ($amounts as $amount) {
        $ids[] = DB::table('tickets')->insertGetId([
            'fleet_route_id' => $world['fleetRoute']->fleet_route_id, 'trip_id' => $world['trip']->trip_id, 'payment_id' => $paymentId, 'ticket_uuid' => (string) Str::uuid(),
            'status' => $ticketStatus, 'seat_type' => 'seated', 'amount' => $amount, 'created_at' => $created, 'updated_at' => $created,
        ]);
    }

    return ['payment_id' => $paymentId, 'ticket_ids' => $ids];
}

function fnRefund(int $ticketId, int $tripId, float $amount, string $completedUtc, string $status = 'succeeded', string $method = 'gateway'): void
{
    $cancellationId = DB::table('trip_cancellations')->where('trip_id', $tripId)->value('id')
        ?? DB::table('trip_cancellations')->insertGetId(['trip_id' => $tripId, 'stage' => 'before_departure', 'cancelled_at' => '2026-10-05 07:00:00', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('ticket_resolutions')->insert([
        'ticket_id' => $ticketId, 'trip_cancellation_id' => $cancellationId, 'resolution' => 'refund', 'status' => $status, 'method' => $method, 'amount' => $amount,
        'requested_at' => $completedUtc, 'completed_at' => $status === 'succeeded' ? $completedUtc : null, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function fnGet($test, User $user, string $path, array $query = [])
{
    $role = $user->role === 'admin' ? 'admin' : 'operator';

    return $test->actingAs($user)->getJson("/api/$role/financials$path".($query ? '?'.http_build_query($query) : ''));
}

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00', 'Asia/Manila')));
afterEach(fn () => Carbon::setTestNow());

it('totals exactly the stored ticket amounts of paid payments and leaves out everything unpaid', function () {
    $w = fnWorld();
    fnSale($w, [100.50, 49.25], '2026-10-05 09:00:00');                                              // paid online: 149.75
    fnSale($w, [90.00], '2026-10-05 09:30:00', 'cash', 'onsite', 'paid', '2026-10-05 01:30:00');       // paid cash: 90.00
    fnSale($w, [500.00], '2026-10-05 09:40:00', status: 'pending');                                  // not paid
    fnSale($w, [500.00], '2026-10-05 09:41:00', status: 'failed');
    fnSale($w, [500.00], '2026-10-05 09:42:00', status: 'expired');
    fnSale($w, [500.00], '2026-10-05 09:43:00', valid: false);                                       // a released hold

    $kpis = fnGet($this, $w['operator'], '', ['from' => '2026-10-05'])->assertSuccessful()->json('data.kpis');

    expect($kpis['gross'])->toEqual(239.75);
    expect($kpis['refunds'])->toEqual(0.0);
    expect($kpis['net'])->toEqual(239.75);
    expect($kpis['tickets'])->toEqual(3);
    expect($kpis['average_fare'])->toEqual(79.92); // 239.75 / 3, from the stored amounts only
});

it('subtracts succeeded refunds as their own figure and shows partial and pending ones in the log', function () {
    $w = fnWorld();
    $a = fnSale($w, [100.00], '2026-10-05 09:00:00');
    $b = fnSale($w, [80.00], '2026-10-05 09:05:00');
    $c = fnSale($w, [60.00], '2026-10-05 09:10:00');
    fnRefund($a['ticket_ids'][0], $w['trip']->trip_id, 100.00, '2026-10-05 02:00:00');                 // full, completed 10:00 Manila
    fnRefund($b['ticket_ids'][0], $w['trip']->trip_id, 30.00, '2026-10-05 02:05:00');                  // partial
    fnRefund($c['ticket_ids'][0], $w['trip']->trip_id, 60.00, '2026-10-05 02:10:00', 'pending');     // not done yet: not subtracted

    $data = fnGet($this, $w['operator'], '', ['from' => '2026-10-05'])->assertSuccessful()->json('data');

    expect($data['kpis']['gross'])->toEqual(240.0);
    expect($data['kpis']['refunds'])->toEqual(130.0);
    expect($data['kpis']['net'])->toEqual(110.0);

    $log = collect(fnGet($this, $w['operator'], '/transactions', ['from' => '2026-10-05'])->assertSuccessful()->json('data.data'))->keyBy('amount');
    expect($log[100.0]['status'])->toEqual('refunded');
    expect($log[80.0]['status'])->toEqual('partially_refunded');
    expect($log[80.0]['refunded'])->toEqual(30.0);
    expect($log[60.0]['status'])->toEqual('refund_pending');
});

it('counts a rebooked journey once, through its replacement ticket', function () {
    $w = fnWorld();
    $sale = fnSale($w, [75.00], '2026-10-05 09:00:00', ticketStatus: 'rebooked');
    DB::table('tickets')->insert([
        'fleet_route_id' => $w['fleetRoute']->fleet_route_id, 'trip_id' => $w['trip']->trip_id, 'payment_id' => $sale['payment_id'], 'ticket_uuid' => (string) Str::uuid(),
        'status' => 'valid', 'seat_type' => 'seated', 'amount' => 75.00, 'created_at' => '2026-10-05 09:20:00', 'updated_at' => '2026-10-05 09:20:00',
    ]);

    $kpis = fnGet($this, $w['operator'], '', ['from' => '2026-10-05'])->json('data.kpis');

    expect($kpis['gross'])->toEqual(75.0);
    expect($kpis['tickets'])->toEqual(1);
});

it('splits cash from online with percentages, and lists only the channels that have data', function () {
    $w = fnWorld();
    fnSale($w, [300.00], '2026-10-05 08:00:00');                                                       // gcash
    fnSale($w, [100.00], '2026-10-05 08:10:00', channel: 'maya');
    fnSale($w, [100.00], '2026-10-05 08:20:00', 'cash', 'onsite', 'paid', '2026-10-05 00:20:00');

    $data = fnGet($this, $w['operator'], '', ['from' => '2026-10-05'])->json('data');

    expect($data['kpis']['cash'])->toMatchArray(['gross' => 100.0, 'tickets' => 1, 'percent' => 20.0]);
    expect($data['kpis']['online'])->toMatchArray(['gross' => 400.0, 'tickets' => 2, 'percent' => 80.0]);
    expect(collect($data['channels'])->pluck('label')->sort()->values()->all())->toEqual(['Cash', 'GCash', 'Maya']); // only what has data: no Card
    expect(collect($data['channels'])->pluck('percent')->sum())->toEqual(100.0);

    $onlineOnly = fnGet($this, $w['operator'], '', ['from' => '2026-10-05', 'payment_method' => 'online'])->json('data.kpis');
    expect($onlineOnly['gross'])->toEqual(400.0);
    $maya = fnGet($this, $w['operator'], '', ['from' => '2026-10-05', 'channel' => 'maya'])->json('data.kpis');
    expect($maya['gross'])->toEqual(100.0);
});

it('breaks revenue down by route, bus, trip and hour from the stored amounts', function () {
    $a = fnWorld('Route A', 'AA-1');
    $b = fnWorld('Route B', 'BB-1', $a['operator']);
    fnSale($a, [100.00], '2026-10-05 08:15:00');
    fnSale($a, [50.00], '2026-10-05 08:45:00');
    fnSale($b, [200.00], '2026-10-05 17:05:00');

    $data = fnGet($this, $a['operator'], '', ['from' => '2026-10-05'])->json('data');

    expect(collect($data['by_route'])->mapWithKeys(fn ($r) => [$r['route_name'] => $r['gross']])->all())->toEqual(['Route B' => 200.0, 'Route A' => 150.0]);
    expect(collect($data['by_bus'])->mapWithKeys(fn ($r) => [$r['plate_number'] => $r['gross']])->all())->toEqual(['BB-1' => 200.0, 'AA-1' => 150.0]);
    expect(collect($data['by_bus'])->firstWhere('plate_number', 'AA-1')['bus_label'])->toStartWith('Test Lines - B-');
    expect($data['by_trip'])->toHaveCount(2);
    expect($data['by_hour'])->toHaveCount(24);
    expect($data['by_hour'][8])->toMatchArray(['hour' => 8, 'tickets' => 2, 'gross' => 150.0]);
    expect($data['by_hour'][17]['gross'])->toEqual(200.0);
    expect($data['by_hour'][9]['gross'])->toEqual(0.0);

    $one = fnGet($this, $a['operator'], '', ['from' => '2026-10-05', 'route_id' => $a['route']->route_id])->json('data.kpis');
    expect($one['gross'])->toEqual(150.0);
    $trip = fnGet($this, $a['operator'], '', ['from' => '2026-10-05', 'trip_id' => $b['trip']->trip_id])->json('data.kpis');
    expect($trip['gross'])->toEqual(200.0);
    $fleet = fnGet($this, $a['operator'], '', ['from' => '2026-10-05', 'fleet_id' => $a['fleet']->fleet_id])->json('data.kpis');
    expect($fleet['gross'])->toEqual(150.0);
});

it('shows an Operator only their own fleets and routes, while an Admin sees everything', function () {
    $mine = fnWorld('Mine', 'MY-1');
    $theirs = fnWorld('Theirs', 'TH-1');
    fnSale($mine, [100.00], '2026-10-05 09:00:00');
    fnSale($theirs, [900.00], '2026-10-05 09:00:00');

    $data = fnGet($this, $mine['operator'], '', ['from' => '2026-10-05'])->assertSuccessful()->json('data');
    expect($data['kpis']['gross'])->toEqual(100.0);
    expect(collect($data['options']['routes'])->pluck('route_name')->all())->toEqual(['Mine']);
    expect(collect($data['options']['fleets'])->pluck('plate_number')->all())->toEqual(['MY-1']);

    // Asking for another operator's route, fleet or trip by id returns nothing: no leak, no error that confirms it exists.
    foreach (['route_id' => $theirs['route']->route_id, 'fleet_id' => $theirs['fleet']->fleet_id, 'trip_id' => $theirs['trip']->trip_id] as $key => $id) {
        expect(fnGet($this, $mine['operator'], '', ['from' => '2026-10-05', $key => $id])->json('data.kpis.gross'))->toEqual(0.0);
        expect(fnGet($this, $mine['operator'], '/transactions', ['from' => '2026-10-05', $key => $id])->json('data.meta.total'))->toEqual(0);
    }
    $csv = $this->actingAs($mine['operator'])->get('/api/operator/financials/export?from=2026-10-05')->assertSuccessful()->getContent();
    expect($csv)->not->toContain('Theirs')->not->toContain('TH-1');

    expect(fnGet($this, fnUser('admin'), '', ['from' => '2026-10-05'])->assertSuccessful()->json('data.kpis.gross'))->toEqual(1000.0);
});

it('refuses other roles and signed-out visitors', function () {
    $this->getJson('/api/operator/financials')->assertUnauthorized();
    $this->actingAs(fnUser('driver'))->getJson('/api/operator/financials')->assertForbidden();
    $this->actingAs(fnUser('conductor'))->getJson('/api/operator/financials/export')->assertForbidden();
});

it('puts every sale on its Manila day and hour across midnight, whichever clock the column uses', function () {
    $w = fnWorld();
    // Online, tickets minted 23:59:59 on the 4th (Manila wall-clock) -> the 4th, hour 23.
    fnSale($w, [10.00], '2026-10-04 23:59:50');
    DB::table('tickets')->where('amount', 10.00)->update(['created_at' => '2026-10-04 23:59:59']);
    // Online, 00:00:00 on the 5th -> the 5th, hour 0.
    fnSale($w, [20.00], '2026-10-05 00:00:00');
    // Cash issued 2026-10-04 16:30:00 UTC = 00:30 on the 5th in Manila (payment_created says 00:30 Manila): the 5th, hour 0 (NOT the 4th).
    fnSale($w, [30.00], '2026-10-05 00:30:00', 'cash', 'onsite', 'paid', '2026-10-04 16:30:00');
    // Cash issued 2026-10-04 15:59:59 UTC = 23:59:59 on the 4th in Manila: the 4th, hour 23.
    fnSale($w, [40.00], '2026-10-04 23:59:59', 'cash', 'onsite', 'paid', '2026-10-04 15:59:59');

    $day4 = fnGet($this, $w['operator'], '', ['from' => '2026-10-04', 'to' => '2026-10-04'])->json('data');
    $day5 = fnGet($this, $w['operator'], '', ['from' => '2026-10-05', 'to' => '2026-10-05'])->json('data');
    expect($day4['kpis']['gross'])->toEqual(50.0);
    expect($day5['kpis']['gross'])->toEqual(50.0);
    expect($day4['by_hour'][23]['gross'])->toEqual(50.0);
    expect($day5['by_hour'][0]['gross'])->toEqual(50.0);

    $both = fnGet($this, $w['operator'], '', ['from' => '2026-10-04', 'to' => '2026-10-05'])->json('data');
    expect(collect($both['daily'])->mapWithKeys(fn ($d) => [$d['date'] => $d['gross']])->all())->toEqual(['2026-10-04' => 50.0, '2026-10-05' => 50.0]);
    expect(collect($both['daily'])->mapWithKeys(fn ($d) => [$d['date'] => $d['cash']])->all())->toEqual(['2026-10-04' => 40.0, '2026-10-05' => 30.0]);
});

it('counts a refund on the Manila day it completed, compared as an instant (UTC in the column)', function () {
    $w = fnWorld();
    $s = fnSale($w, [100.00], '2026-10-04 20:00:00');
    fnRefund($s['ticket_ids'][0], $w['trip']->trip_id, 100.00, '2026-10-04 16:00:01'); // 00:00:01 on the 5th in Manila

    $day4 = fnGet($this, $w['operator'], '', ['from' => '2026-10-04', 'to' => '2026-10-04'])->json('data.kpis');
    $day5 = fnGet($this, $w['operator'], '', ['from' => '2026-10-05', 'to' => '2026-10-05'])->json('data.kpis');

    expect([$day4['gross'], $day4['refunds'], $day4['net']])->toEqual([100.0, 0.0, 100.0]);
    expect([$day5['gross'], $day5['refunds'], $day5['net']])->toEqual([0.0, 100.0, -100.0]); // refunded the next day: its own day's figure
});

it('returns the same rows as a paginated, sortable log and as the CSV export', function () {
    $w = fnWorld('=Evil Route', 'FN-77');
    fnSale($w, [10.00], '2026-10-05 09:00:00');
    fnSale($w, [30.00], '2026-10-05 10:00:00', 'cash', 'onsite', 'paid', '2026-10-05 02:00:00');
    fnSale($w, [20.00], '2026-10-05 08:00:00', channel: 'maya');
    $q = ['from' => '2026-10-05'];

    $page1 = fnGet($this, $w['operator'], '/transactions', $q + ['per_page' => 2, 'sort' => 'amount', 'dir' => 'desc'])->assertSuccessful()->json('data');
    $page2 = fnGet($this, $w['operator'], '/transactions', $q + ['per_page' => 2, 'sort' => 'amount', 'dir' => 'desc', 'page' => 2])->json('data');
    expect(collect($page1['data'])->pluck('amount')->all())->toEqual([30.0, 20.0]);
    expect(collect($page2['data'])->pluck('amount')->all())->toEqual([10.0]);
    expect($page1['meta'])->toMatchArray(['total' => 3, 'last_page' => 2, 'per_page' => 2, 'sort' => 'amount', 'dir' => 'desc']);

    $asc = fnGet($this, $w['operator'], '/transactions', $q + ['sort' => 'paid_at', 'dir' => 'asc'])->json('data.data');
    expect(collect($asc)->pluck('amount')->all())->toEqual([20.0, 10.0, 30.0]); // 08:00, 09:00, 10:00 Manila

    $csv = $this->actingAs($w['operator'])->get('/api/operator/financials/export?'.http_build_query($q + ['sort' => 'amount', 'dir' => 'desc']))->assertSuccessful();
    expect($csv->headers->get('content-type'))->toContain('text/csv');
    expect($csv->headers->get('content-disposition'))->toContain('financial-transactions-2026-10-05.csv');
    $text = $csv->getContent();
    expect(str_starts_with($text, "\xEF\xBB\xBF"))->toBeTrue(); // BOM for Excel
    $rows = array_map('str_getcsv', array_values(array_filter(preg_split('/\r?\n/', substr($text, 3)))));
    expect($rows)->toHaveCount(4); // header + the same 3 rows as the screen
    expect(array_sum(array_map(fn ($r) => (float) $r[13], array_slice($rows, 1))))->toEqual(60.0);
    expect($rows[1][5])->toEqual("'=Evil Route"); // a formula-looking cell is neutralised
    expect($rows[1][2])->toEqual('2026-10-05 10:00:00');
});

it('exports the daily summary from the same query', function () {
    $w = fnWorld();
    fnSale($w, [100.00], '2026-10-04 12:00:00');
    fnSale($w, [50.00], '2026-10-05 12:00:00', 'cash', 'onsite', 'paid', '2026-10-05 04:00:00');

    $text = $this->actingAs($w['operator'])->get('/api/operator/financials/export?type=daily&from=2026-10-04&to=2026-10-05')->assertSuccessful()->getContent();
    $rows = array_map('str_getcsv', array_values(array_filter(preg_split('/\r?\n/', substr($text, 3)))));

    expect($rows[0][0])->toEqual('Date (Manila)');
    expect($rows[1])->toEqual(['2026-10-04', '100.00', '0.00', '100.00', '1', '0.00', '100.00']);
    expect($rows[2])->toEqual(['2026-10-05', '50.00', '0.00', '50.00', '1', '50.00', '0.00']);
});

it('answers an empty range with zeros, an empty log and a header-only CSV', function () {
    $w = fnWorld();
    fnSale($w, [100.00], '2026-10-05 09:00:00');
    $q = ['from' => '2026-09-01', 'to' => '2026-09-02'];

    $data = fnGet($this, $w['operator'], '', $q)->assertSuccessful()->json('data');
    expect($data['kpis'])->toMatchArray(['gross' => 0.0, 'refunds' => 0.0, 'net' => 0.0, 'tickets' => 0, 'average_fare' => 0.0, 'boarded' => 0]);
    expect($data['kpis']['cash']['percent'])->toEqual(0.0);
    expect($data['channels'])->toEqual([]);
    expect($data['by_route'])->toEqual([]);
    expect($data['daily'])->toEqual([]);
    expect($data['by_hour'])->toHaveCount(24);
    expect(fnGet($this, $w['operator'], '/transactions', $q)->json('data'))->toMatchArray(['data' => [], 'meta' => ['page' => 1, 'per_page' => 25, 'total' => 0, 'last_page' => 1, 'sort' => 'paid_at', 'dir' => 'desc']]);
    $csv = $this->actingAs($w['operator'])->get('/api/operator/financials/export?'.http_build_query($q))->getContent();
    expect(array_filter(preg_split('/\r?\n/', substr($csv, 3))))->toHaveCount(1);
});

it('defaults to today in Manila and counts boarded tickets by their boarded time', function () {
    $w = fnWorld();
    $s = fnSale($w, [60.00, 40.00], '2026-10-05 07:00:00');
    DB::table('tickets')->where('ticket_id', $s['ticket_ids'][0])->update(['boarded_at' => '2026-10-05 08:30:00']);
    fnSale($w, [999.00], '2026-10-04 07:00:00'); // yesterday: outside the default range

    $data = fnGet($this, $w['operator'], '')->assertSuccessful()->json('data');

    expect($data['filters']['from'])->toEqual('2026-10-05')->and($data['filters']['to'])->toEqual('2026-10-05');
    expect($data['kpis']['gross'])->toEqual(100.0);
    expect($data['kpis']['boarded'])->toEqual(1);
});

it('validates the range, the filters and the page size', function () {
    $w = fnWorld();

    fnGet($this, $w['operator'], '', ['from' => '2026-10-05', 'to' => '2026-10-01'])->assertUnprocessable()->assertJsonValidationErrors('to');
    fnGet($this, $w['operator'], '', ['from' => '2024-01-01', 'to' => '2026-10-01'])->assertUnprocessable()->assertJsonValidationErrors('to');
    fnGet($this, $w['operator'], '', ['from' => 'yesterday'])->assertUnprocessable()->assertJsonValidationErrors('from');
    fnGet($this, $w['operator'], '', ['payment_method' => 'barter'])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
    fnGet($this, $w['operator'], '/transactions', ['sort' => 'password'])->assertUnprocessable()->assertJsonValidationErrors('sort');
    fnGet($this, $w['operator'], '/transactions', ['per_page' => 5000])->assertUnprocessable()->assertJsonValidationErrors('per_page');
});

it('rate limits the endpoints, the export hardest', function () {
    $w = fnWorld();

    foreach (range(1, 10) as $i) {
        $this->actingAs($w['operator'])->get('/api/operator/financials/export')->assertSuccessful();
    }
    $this->actingAs($w['operator'])->get('/api/operator/financials/export')->assertStatus(429);
});


it('dates refunds by completion for cash reconciliation, or by the original sale for profitability', function () {
    $w = fnWorld();
    $s = fnSale($w, [100.00], '2026-10-04 20:00:00');                                  // sold on the 4th
    fnRefund($s['ticket_ids'][0], $w['trip']->trip_id, 100.00, '2026-10-04 16:00:01'); // completed 00:00:01 on the 5th (Manila)
    $kpi = fn (string $day, string $basis) => fnGet($this, $w['operator'], '', ['from' => $day, 'to' => $day, 'refund_basis' => $basis])->json('data');

    // Completion (the default): the closed 4th is never rewritten; the refund is the 5th's cash-out.
    expect($kpi('2026-10-04', 'completed')['kpis']['refunds'])->toEqual(0);
    expect($kpi('2026-10-05', 'completed')['kpis']['refunds'])->toEqual(100);
    expect(fnGet($this, $w['operator'], '', ['from' => '2026-10-04', 'to' => '2026-10-04'])->json('data.filters.refund_basis'))->toBe('completed');

    // Sale basis: the refund goes back to the 4th, so that day's net is what the sale really earned.
    $sale4 = $kpi('2026-10-04', 'sale');
    expect([$sale4['kpis']['gross'], $sale4['kpis']['refunds'], $sale4['kpis']['net']])->toEqual([100, 100, 0]);
    expect($kpi('2026-10-05', 'sale')['kpis']['refunds'])->toEqual(0);
    expect(collect($sale4['daily'])->mapWithKeys(fn ($d) => [$d['date'] => $d['refunds']])->all())->toEqual(['2026-10-04' => 100]);
});

it('exports the refund lines with a link back to the original sale', function () {
    $w = fnWorld();
    $s = fnSale($w, [100.00], '2026-10-04 20:00:00');
    $txn = DB::table('payments')->where('payment_id', $s['payment_id'])->value('transaction_reference');
    fnRefund($s['ticket_ids'][0], $w['trip']->trip_id, 60.00, '2026-10-05 02:30:00', 'succeeded', 'cash');

    $text = $this->actingAs($w['operator'])->get('/api/operator/financials/export?type=refunds&from=2026-10-05&to=2026-10-05')->assertSuccessful()->getContent();
    $rows = array_map('str_getcsv', array_values(array_filter(preg_split('/\r?\n/', substr($text, 3)))));

    expect($rows)->toHaveCount(2);
    expect($rows[1][0])->toBe('2026-10-05 10:30:00');   // completed 02:30 UTC = 10:30 Manila
    expect($rows[1][1])->toBe('60.00');
    expect($rows[1][2])->toBe('Cash');
    expect($rows[1][3])->toBe($txn);                    // the original sale
    expect((int) $rows[1][4])->toBe($s['ticket_ids'][0]);
    expect($rows[1][5])->toBe('2026-10-04 20:00:00');   // when it was sold

    // By sale basis the same line belongs to the 4th.
    $byDay4 = $this->actingAs($w['operator'])->get('/api/operator/financials/export?type=refunds&refund_basis=sale&from=2026-10-04&to=2026-10-04')->getContent();
    expect(array_filter(preg_split('/\r?\n/', substr($byDay4, 3))))->toHaveCount(2);
});

it('keeps the admin endpoints out of reach of every non-admin session', function () {
    $this->getJson('/api/admin/financials')->assertUnauthorized();
    $operator = fnUser('operator');
    $this->actingAs($operator)->getJson('/api/admin/financials')->assertForbidden();
    $this->actingAs($operator)->get('/api/admin/financials/export')->assertForbidden();
    $this->actingAs(fnUser('driver'))->getJson('/api/admin/financials/transactions')->assertForbidden();
    $this->actingAs(fnUser('conductor'))->getJson('/api/admin/financials')->assertForbidden();
    $this->actingAs(fnUser('admin'))->getJson('/api/admin/financials')->assertSuccessful();
});

it('keeps the export limit separate from the other financial endpoints, so a busy dashboard does not lock the export', function () {
    $w = fnWorld();

    foreach (range(1, 12) as $i) {
        fnGet($this, $w['operator'], '', ['from' => '2026-10-05'])->assertSuccessful();
        fnGet($this, $w['operator'], '/transactions', ['from' => '2026-10-05'])->assertSuccessful();
    }

    $this->actingAs($w['operator'])->get('/api/operator/financials/export?from=2026-10-05')->assertSuccessful();
});
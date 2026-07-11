<?php

// @formatter:off
// phpcs:ignoreFile
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */


namespace App\Models{
/**
 * @property int $fare_rule_id
 * @property int $fleet_id
 * @property float $base_fare
 * @property float $fare_per_km
 * @property string $status
 * @property string $seat_type
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Fleet $fleet
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FareRule newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FareRule newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FareRule query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FareRule whereBaseFare($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FareRule whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FareRule whereFarePerKm($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FareRule whereFareRuleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FareRule whereFleetId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FareRule whereSeatType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FareRule whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FareRule whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperFareRule {}
}

namespace App\Models{
/**
 * @property int $fleet_id
 * @property int $company_user_id
 * @property string $plate_number
 * @property int $capacity
 * @property int $seated_capacity
 * @property int $standing_capacity
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $fleet_type
 * @property-read \App\Models\StaffUser $companyUser
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\FareRule> $fareRules
 * @property-read int|null $fare_rules_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\FleetRoute> $fleetRoutes
 * @property-read int|null $fleet_routes_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet whereCapacity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet whereCompanyUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet whereFleetId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet whereFleetType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet wherePlateNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet whereSeatedCapacity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet whereStandingCapacity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperFleet {}
}

namespace App\Models{
/**
 * @property-read \App\Models\Fleet $fleet
 * @property int $fleet_route_id
 * @property int $fleet_id
 * @property int $route_id
 * @property string $start_time
 * @property string $end_time
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Route $route
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Trip> $trips
 * @property-read int|null $trips_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetRoute newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetRoute newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetRoute query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetRoute whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetRoute whereEndTime($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetRoute whereFleetId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetRoute whereFleetRouteId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetRoute whereRouteId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetRoute whereStartTime($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetRoute whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetRoute whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperFleetRoute {}
}

namespace App\Models{
/**
 * @property int $online_payment_id
 * @property int $passenger_id
 * @property int $payment_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\PassengerUser $passenger
 * @property-read \App\Models\Payment $payment
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OnlinePayment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OnlinePayment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OnlinePayment query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OnlinePayment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OnlinePayment whereOnlinePaymentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OnlinePayment wherePassengerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OnlinePayment wherePaymentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OnlinePayment whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperOnlinePayment {}
}

namespace App\Models{
/**
 * @property int $onsite_payment_id
 * @property int $payment_id
 * @property int $conductor_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\StaffUser $conductor
 * @property-read \App\Models\Payment $payment
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OnsitePayment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OnsitePayment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OnsitePayment query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OnsitePayment whereConductorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OnsitePayment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OnsitePayment whereOnsitePaymentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OnsitePayment wherePaymentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OnsitePayment whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperOnsitePayment {}
}

namespace App\Models{
/**
 * @property int $passenger_id
 * @property string $passenger_uuid
 * @property int $user_id
 * @property string $name
 * @property string $phone_num
 * @property string $address
 * @property float $reward_points
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $birthdate
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PassengerUser newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PassengerUser newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PassengerUser query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PassengerUser whereAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PassengerUser whereBirthdate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PassengerUser whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PassengerUser whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PassengerUser wherePassengerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PassengerUser wherePassengerUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PassengerUser wherePhoneNum($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PassengerUser whereRewardPoints($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PassengerUser whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PassengerUser whereUserId($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperPassengerUser {}
}

namespace App\Models{
/**
 * @property int $payment_id
 * @property float $amount
 * @property \Illuminate\Support\Carbon $payment_created
 * @property string $transaction_reference
 * @property string $payment_method
 * @property string $payment_channel
 * @property string $status
 * @property string $payment_uuid
 * @property bool $is_valid
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $gateway_reference
 * @property string|null $guest_email
 * @property string|null $payment_intent_id
 * @property array<array-key, mixed>|null $items_payload
 * @property \Illuminate\Support\Carbon|null $hold_expires_at
 * @property-read \App\Models\OnlinePayment|null $onlinePayment
 * @property-read \App\Models\OnsitePayment|null $onsitePayment
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Ticket> $tickets
 * @property-read int|null $tickets_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereGatewayReference($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereGuestEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereHoldExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereIsValid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereItemsPayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment wherePaymentChannel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment wherePaymentCreated($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment wherePaymentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment wherePaymentIntentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment wherePaymentMethod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment wherePaymentUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereTransactionReference($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperPayment {}
}

namespace App\Models{
/**
 * @property int $reward_transaction_id
 * @property int $passenger_id
 * @property int|null $payment_id
 * @property int $points
 * @property string $type
 * @property string|null $description
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\PassengerUser $passenger
 * @property-read \App\Models\Payment|null $payment
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RewardTransaction newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RewardTransaction newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RewardTransaction query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RewardTransaction whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RewardTransaction whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RewardTransaction wherePassengerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RewardTransaction wherePaymentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RewardTransaction wherePoints($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RewardTransaction whereRewardTransactionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RewardTransaction whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RewardTransaction whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperRewardTransaction {}
}

namespace App\Models{
/**
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\RouteStop> $routeStops
 * @property int $route_id
 * @property string $origin
 * @property string $destination
 * @property string $route_name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\FleetRoute> $fleetRoutes
 * @property-read int|null $fleet_routes_count
 * @property-read int|null $route_stops_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Route newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Route newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Route query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Route whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Route whereDestination($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Route whereOrigin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Route whereRouteId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Route whereRouteName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Route whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperRoute {}
}

namespace App\Models{
/**
 * @property int $route_stop_id
 * @property int $stop_id
 * @property int $route_id
 * @property int $stop_order
 * @property numeric $distance_from_origin_km
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Route $route
 * @property-read \App\Models\Stop $stop
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RouteStop newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RouteStop newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RouteStop query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RouteStop whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RouteStop whereDistanceFromOriginKm($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RouteStop whereRouteId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RouteStop whereRouteStopId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RouteStop whereStopId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RouteStop whereStopOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RouteStop whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperRouteStop {}
}

namespace App\Models{
/**
 * @property int $company_user_id
 * @property string $company_user_uuid
 * @property int $user_id
 * @property string $phone_num
 * @property string $name
 * @property string $address
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StaffUser newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StaffUser newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StaffUser query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StaffUser whereAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StaffUser whereCompanyUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StaffUser whereCompanyUserUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StaffUser whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StaffUser whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StaffUser wherePhoneNum($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StaffUser whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StaffUser whereUserId($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperStaffUser {}
}

namespace App\Models{
/**
 * @property int $stop_id
 * @property string $stop_name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property numeric|null $latitude
 * @property numeric|null $longitude
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\RouteStop> $routeStops
 * @property-read int|null $route_stops_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Stop newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Stop newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Stop query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Stop whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Stop whereLatitude($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Stop whereLongitude($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Stop whereStopId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Stop whereStopName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Stop whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperStop {}
}

namespace App\Models{
/**
 * @property int $ticket_id
 * @property int $fleet_route_id
 * @property int $trip_id
 * @property int|null $fare_id
 * @property int $payment_id
 * @property int|null $passenger_id
 * @property string $ticket_uuid
 * @property string $status
 * @property numeric $amount
 * @property \Illuminate\Support\Carbon|null $boarded_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $seat_type
 * @property-read \App\Models\FareRule|null $fareRule
 * @property-read \App\Models\FleetRoute $fleetRoute
 * @property-read \App\Models\PassengerUser|null $passenger
 * @property-read \App\Models\Payment $payment
 * @property-read \App\Models\Trip $trip
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereBoardedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereFareId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereFleetRouteId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket wherePassengerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket wherePaymentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereSeatType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereTicketId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereTicketUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereTripId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperTicket {}
}

namespace App\Models{
/**
 * @property-read \App\Models\FleetRoute $fleetRoute
 * @property int $trip_id
 * @property int $fleet_route_id
 * @property int $company_user_id
 * @property \Illuminate\Support\Carbon $trip_date
 * @property string $status
 * @property int $current_seated_capacity
 * @property int $current_standing_capacity
 * @property int $total_occupancy
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int|null $driver_id
 * @property int|null $conductor_id
 * @property-read \App\Models\StaffUser $companyUser
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereCompanyUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereConductorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereCurrentSeatedCapacity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereCurrentStandingCapacity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereDriverId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereFleetRouteId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereTotalOccupancy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereTripDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereTripId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperTrip {}
}

namespace App\Models{
/**
 * @property int $user_id
 * @property string $email
 * @property string $username
 * @property string $password
 * @property string $role
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\StaffUser|null $companyProfile
 * @property-read int $id
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read \App\Models\PassengerUser|null $passengerProfile
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Laravel\Sanctum\PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRole($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUsername($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperUser {}
}


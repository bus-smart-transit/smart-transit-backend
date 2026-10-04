<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class OnsiteCheckoutRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'items'                       => 'required|array|min:1',
            'items.*.trip_id'             => 'required|integer|exists:trips,trip_id',
            'items.*.seat_type'           => 'required|string|in:seated,standing',
            // Task 6: `gps` = boarding between stops. The boarding point is resolved on the server from the bus's
            // own position, so no origin and no coordinates for it are accepted here.
            'items.*.boarding_mode'       => 'nullable|string|in:gps',
            'items.*.origin_stop_id'      => 'required_unless:items.*.boarding_mode,gps|nullable|integer|exists:stops,stop_id',
            // Task 7: the destination is a stop or a custom drop-off pin (coordinates re-validated and snapped server-side).
            'items.*.destination_stop_id' => 'required_without:items.*.destination_lat|nullable|integer|exists:stops,stop_id',
            'items.*.destination_lat'     => 'nullable|required_with:items.*.destination_lng|numeric|between:-90,90',
            'items.*.destination_lng'     => 'nullable|required_with:items.*.destination_lat|numeric|between:-180,180',
            'items.*.destination_label'   => 'nullable|string|max:255',
            'items.*.passenger_id'        => 'nullable|integer|exists:passenger_users,passenger_id',
        ];
    }
}

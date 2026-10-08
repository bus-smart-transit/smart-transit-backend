<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The segment a traffic answer is wanted for: where the bus is and where the next stop is. Both points must be
 * inside the service region and close together, so this cannot be used as an open window onto the provider.
 */
class TrafficSegmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // guests follow a bus too; the throttle and the monthly budget protect the provider allowance
    }

    public function rules(): array
    {
        $bbox = config('region.bbox');
        $lat = 'required|numeric|between:'.$bbox['min_lat'].','.$bbox['max_lat'];
        $lng = 'required|numeric|between:'.$bbox['min_lng'].','.$bbox['max_lng'];

        return ['from_lat' => $lat, 'from_lng' => $lng, 'to_lat' => $lat, 'to_lng' => $lng];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $km = $this->distanceKm((float) $this->input('from_lat'), (float) $this->input('from_lng'), (float) $this->input('to_lat'), (float) $this->input('to_lng'));
            if ($km > (float) config('traffic.max_distance_km')) {
                $validator->errors()->add('to_lat', 'The two points are too far apart for a traffic check.');
            }
        }];
    }

    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371 * 2 * asin(min(1, sqrt($h)));
    }
}

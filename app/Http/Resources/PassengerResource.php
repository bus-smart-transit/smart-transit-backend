<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PassengerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'passenger_uuid' => $this->passenger_uuid,
            'name' => $this->name,
            'phone_num' => $this->phone_num,
            'address' => $this->address,
            'birth_date' => $this->birth_date,
            'reward_points' => $this->reward_points,
            'gender' => $this->gender,
            'email' => $this->user?->email,
            'username' => $this->user?->username,
            'two_factor_enabled' => (bool) ($this->user?->two_factor_enabled ?? false),
            'sms_opt_in' => (bool) ($this->user?->sms_opt_in ?? false), // Batch 67
            'user' => [
                'email' => $this->user?->email,
                'username' => $this->user?->username,
                'two_factor_enabled' => (bool) ($this->user?->two_factor_enabled ?? false),
                'sms_opt_in' => (bool) ($this->user?->sms_opt_in ?? false),
            ],
            'created_at' => $this->created_at,
        ];
    }
}

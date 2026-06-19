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
            'id' => $this['id'] ?? null,
            // Maps structural keys from manual array results into clean response blocks
            'created_at' => $this['created_at'] ?? now()->toIso8601String(),
        ];
    }
}
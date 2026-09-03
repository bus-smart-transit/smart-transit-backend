<?php
namespace App\Services;
use App\Repositories\StopRepository;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class StopService
{
    public function __construct(private StopRepository $stopRepository)
    {
    }

    // $payload = ['stop_name','latitude','longitude']
    public function createStop(array $payload): object
    {
        $coordinates = $this->resolveCoordinates($payload);

        return $this->stopRepository->create([
            'stop_name' => $payload['stop_name'],
            'latitude' => $coordinates['latitude'],
            'longitude' => $coordinates['longitude'],
        ]);
    }

    public function listStops(): object
    {
        return $this->stopRepository->all();
    }

    // $payload = ['stop_name','latitude','longitude'] (all optional via sometimes)
    public function updateStop(int $stopId, array $payload): ?object
    {
        $update = $payload;

        if (array_key_exists('latitude', $payload) || array_key_exists('longitude', $payload) || array_key_exists('location', $payload)) {
            $existingStop = $this->stopRepository->findById($stopId);
            $mergeSource = [
                'stop_name' => $payload['stop_name'] ?? $existingStop?->stop_name,
                'location' => $payload['location'] ?? null,
                'latitude' => $payload['latitude'] ?? null,
                'longitude' => $payload['longitude'] ?? null,
            ];

            $coordinates = $this->resolveCoordinates($mergeSource, false);
            $update['latitude'] = $coordinates['latitude'];
            $update['longitude'] = $coordinates['longitude'];
        }

        unset($update['location']);

        $this->stopRepository->update($stopId, $update);
        return $this->stopRepository->findById($stopId);
    }

    public function deleteStop(int $stopId): bool
    {
        return $this->stopRepository->delete($stopId);
    }

    private function resolveCoordinates(array $payload, bool $requireCoordinates = true): array
    {
        $lat = isset($payload['latitude']) ? (float) $payload['latitude'] : null;
        $lng = isset($payload['longitude']) ? (float) $payload['longitude'] : null;

        if ($lat !== null && $lng !== null) {
            return [
                'latitude' => $lat,
                'longitude' => $lng,
            ];
        }

        $queryParts = array_filter([
            $payload['location'] ?? null,
            $payload['stop_name'] ?? null,
            'Davao City Philippines',
        ]);
        $query = trim(implode(' ', $queryParts));

        if ($query !== '') {
            try {
                $response = Http::timeout(5)
                    ->acceptJson()
                    ->withHeaders([
                        'User-Agent' => 'smart-transit-stop-geocoder/1.0',
                        'Accept-Language' => 'en',
                    ])
                    ->get('https://nominatim.openstreetmap.org/search', [
                        'q' => $query,
                        'format' => 'json',
                        'limit' => 1,
                    ]);

                $row = $response->successful() ? ($response->json()[0] ?? null) : null;
                $resolvedLat = isset($row['lat']) ? (float) $row['lat'] : null;
                $resolvedLng = isset($row['lon']) ? (float) $row['lon'] : null;

                if ($resolvedLat !== null && $resolvedLng !== null) {
                    return [
                        'latitude' => $resolvedLat,
                        'longitude' => $resolvedLng,
                    ];
                }
            } catch (\Throwable) {
                // Fall through to validation exception below.
            }
        }

        if (!$requireCoordinates) {
            throw ValidationException::withMessages([
                'location' => ['Unable to resolve stop coordinates. Please provide a clearer location name or manual coordinates.'],
            ]);
        }

        throw ValidationException::withMessages([
            'location' => ['Unable to resolve stop coordinates automatically. Please provide a clearer location name.'],
        ]);
    }
}

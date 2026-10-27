<?php

namespace App\Services;

use Illuminate\Support\Collection;

class RequirementChecklist
{
    public function signature(int $serviceId, Collection $requirements): string
    {
        $items = $requirements->sortBy('id')->map(fn ($item) => [
            (int) $item->id, $item->name, $item->description, (bool) $item->is_required,
        ])->values()->all();

        return hash_hmac('sha256', json_encode([$serviceId, $items], JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function matches(mixed $signature, int $serviceId, Collection $requirements): bool
    {
        return is_string($signature) && hash_equals($this->signature($serviceId, $requirements), $signature);
    }
}

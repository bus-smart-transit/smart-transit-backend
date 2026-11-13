<?php

namespace App\Repositories;

use App\Models\SmsLog;
use Illuminate\Support\Collection;

/** Batch 64: the text message log. */
class SmsLogRepository
{
    public function create(array $attributes): SmsLog
    {
        return SmsLog::create($attributes);
    }

    public function find(int $id): ?SmsLog
    {
        return SmsLog::find($id);
    }

    public function update(SmsLog $log, array $attributes): SmsLog
    {
        $log->fill($attributes)->save();

        return $log;
    }

    /** @return Collection<int, SmsLog> newest first */
    public function latest(int $limit = 20): Collection
    {
        return SmsLog::orderByDesc('id')->limit($limit)->get();
    }
}

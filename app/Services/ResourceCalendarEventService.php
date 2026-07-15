<?php

namespace App\Services;

use App\Models\ResourceCalendarEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ResourceCalendarEventService
{
    public const MONTHLY_HORIZON = 24;

    public const YEARLY_HORIZON = 10;

    /**
     * @param  array<string, mixed>  $data
     * @return list<ResourceCalendarEvent>
     */
    public function createWithRepeat(array $data): array
    {
        $repeat = ResourceCalendarEvent::normalizeRepeat($data['repeat'] ?? ResourceCalendarEvent::REPEAT_NONE);
        unset($data['repeat']);

        $start = Carbon::parse((string) $data['start_date'])->startOfDay();
        $end = Carbon::parse((string) $data['end_date'])->startOfDay();
        $durationDays = max(0, (int) $start->diffInDays($end));

        $base = array_merge($data, [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'repeat' => $repeat,
            'series_id' => null,
        ]);

        if ($repeat === ResourceCalendarEvent::REPEAT_NONE) {
            $event = ResourceCalendarEvent::query()->create($base);

            return [$event->fresh('creator')];
        }

        $count = $repeat === ResourceCalendarEvent::REPEAT_MONTHLY
            ? self::MONTHLY_HORIZON
            : self::YEARLY_HORIZON;
        $seriesId = (string) Str::uuid();

        return DB::transaction(function () use ($base, $repeat, $start, $durationDays, $count, $seriesId) {
            $created = [];
            for ($i = 0; $i < $count; $i++) {
                $occurrenceStart = $this->shiftOccurrenceStart($start, $repeat, $i);
                $occurrenceEnd = $occurrenceStart->copy()->addDays($durationDays);
                $created[] = ResourceCalendarEvent::query()->create(array_merge($base, [
                    'start_date' => $occurrenceStart->toDateString(),
                    'end_date' => $occurrenceEnd->toDateString(),
                    'repeat' => $repeat,
                    'series_id' => $seriesId,
                ]))->fresh('creator');
            }

            return $created;
        });
    }

    private function shiftOccurrenceStart(Carbon $baseStart, string $repeat, int $index): Carbon
    {
        if ($index === 0) {
            return $baseStart->copy();
        }

        if ($repeat === ResourceCalendarEvent::REPEAT_MONTHLY) {
            return $baseStart->copy()->addMonthsNoOverflow($index);
        }

        return $baseStart->copy()->addYearsNoOverflow($index);
    }
}

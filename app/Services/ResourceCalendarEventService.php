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

    /**
     * Update one event; when repeat changes, rebuild the series from this occurrence.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateWithRepeat(ResourceCalendarEvent $event, array $data): ResourceCalendarEvent
    {
        $repeatProvided = array_key_exists('repeat', $data);
        $newRepeat = $repeatProvided
            ? ResourceCalendarEvent::normalizeRepeat($data['repeat'] ?? ResourceCalendarEvent::REPEAT_NONE)
            : ResourceCalendarEvent::normalizeRepeat($event->repeat);
        unset($data['repeat'], $data['series_id']);

        $oldRepeat = ResourceCalendarEvent::normalizeRepeat($event->repeat);
        $rebuildSeries = $repeatProvided && (
            $newRepeat !== $oldRepeat
            || ($newRepeat !== ResourceCalendarEvent::REPEAT_NONE && $event->series_id === null)
        );

        if (! $rebuildSeries) {
            $event->fill($data);
            if ($repeatProvided) {
                $event->repeat = $newRepeat;
            }
            $event->save();

            if ($repeatProvided && $event->series_id) {
                ResourceCalendarEvent::query()
                    ->where('series_id', $event->series_id)
                    ->where('id', '!=', $event->id)
                    ->update(['repeat' => $newRepeat]);
            }

            return $event->fresh('creator');
        }

        return DB::transaction(function () use ($event, $data, $newRepeat) {
            $seriesId = $event->series_id;
            if ($seriesId) {
                ResourceCalendarEvent::query()
                    ->where('series_id', $seriesId)
                    ->where('id', '!=', $event->id)
                    ->delete();
            }

            $event->fill($data);

            if ($newRepeat === ResourceCalendarEvent::REPEAT_NONE) {
                $event->repeat = ResourceCalendarEvent::REPEAT_NONE;
                $event->series_id = null;
                $event->save();

                return $event->fresh('creator');
            }

            $start = Carbon::parse((string) $event->start_date)->startOfDay();
            $end = Carbon::parse((string) $event->end_date)->startOfDay();
            $durationDays = max(0, (int) $start->diffInDays($end));
            $count = $newRepeat === ResourceCalendarEvent::REPEAT_MONTHLY
                ? self::MONTHLY_HORIZON
                : self::YEARLY_HORIZON;
            $newSeriesId = (string) Str::uuid();

            $event->repeat = $newRepeat;
            $event->series_id = $newSeriesId;
            $event->save();

            for ($i = 1; $i < $count; $i++) {
                $occurrenceStart = $this->shiftOccurrenceStart($start, $newRepeat, $i);
                $occurrenceEnd = $occurrenceStart->copy()->addDays($durationDays);
                ResourceCalendarEvent::query()->create([
                    'created_by_user_id' => $event->created_by_user_id,
                    'title' => $event->title,
                    'category' => $event->category,
                    'start_date' => $occurrenceStart->toDateString(),
                    'end_date' => $occurrenceEnd->toDateString(),
                    'description' => $event->description,
                    'is_personal' => $event->is_personal,
                    'repeat' => $newRepeat,
                    'series_id' => $newSeriesId,
                ]);
            }

            return $event->fresh('creator');
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

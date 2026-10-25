<?php

namespace App\Services;

use App\Models\FuelTransaction;
use Illuminate\Support\Collection;

/**
 * Fuel consumption from the meter read at each fill-up (fuel_transactions.odometer_km): a fill-up
 * refuels what was burnt since the previous one, so its rate is its liters over the meter's
 * progress since then. A road vehicle's meter counts km (L/100 km); a tractor's or a machine's
 * counts hours (L/h). Fill-ups that don't fit are flagged for a look: meter going back or not
 * moving, far more than the vehicle's usual rate, more than its tank holds.
 */
class FuelConsumptionService
{
    /** Vehicle types whose meter counts km; every other one counts engine hours. */
    private const KM_TYPES = ['car', 'van', 'truck', 'pickup', 'quad', 'motorcycle', 'bus'];

    /** A rate above this many times the vehicle's median is "élevée". */
    private const HIGH_RATE_FACTOR = 1.5;

    /** The median needs this many rated fill-ups before anything is called high. */
    private const MIN_FILLS_FOR_MEDIAN = 4;

    public static function unitFor(?string $vehicleType): string
    {
        return in_array($vehicleType, self::KM_TYPES, true) ? 'km' : 'h';
    }

    public static function rateLabel(string $unit): string
    {
        return $unit === 'km' ? 'L/100 km' : 'L/h';
    }

    /**
     * Every fill-up of these vehicles analysed against the one before it (same vehicle), keyed
     * by fill-up id: [rate, delta, unit, alertes[]]. Pass the vehicles' whole history — the
     * first fill-up of a period still needs the reading before it.
     */
    public static function analyse(Collection $fillUps): Collection
    {
        $result = collect();

        foreach ($fillUps->where('transaction_type', 'fueling')->groupBy('vehicle_id') as $vehicleFills) {
            $vehicle = $vehicleFills->first()->vehicle;
            $unit = self::unitFor($vehicle?->type);
            $capacity = (float) ($vehicle?->capacity_liters ?? 0);
            $previous = null;
            $unread = 0.0; // liters put in without a meter reading since the last reading
            $rows = [];

            foreach ($vehicleFills->sortBy(fn ($f) => [$f->date->toDateString(), $f->id]) as $fill) {
                $reading = $fill->odometer_km !== null ? (float) $fill->odometer_km : null;
                $liters = (float) $fill->quantity_liters;
                $row = ['rate' => null, 'delta' => null, 'liters' => null, 'unit' => $unit, 'alertes' => []];

                if ($reading !== null && $previous !== null) {
                    $delta = round($reading - $previous, 2);
                    $row['delta'] = $delta;
                    if ($delta < 0) {
                        $row['alertes'][] = 'Compteur en recul ('.self::number($previous).' → '.self::number($reading).')';
                    } elseif ($delta == 0) {
                        $row['alertes'][] = 'Compteur inchangé depuis le plein précédent';
                    } else {
                        // What was put in since the last reading, this fill-up included.
                        $burnt = $liters + $unread;
                        $row['liters'] = $burnt;
                        $row['rate'] = round($unit === 'km' ? $burnt / $delta * 100 : $burnt / $delta, 2);
                    }
                }
                $unread = $reading === null && $previous !== null ? $unread + $liters : 0.0;
                if ($capacity > 0 && $liters > $capacity * 1.05) {
                    $row['alertes'][] = 'Plus que le réservoir ('.self::number($capacity).' L)';
                }

                $rows[$fill->id] = $row;
                if ($reading !== null && ($previous === null || $reading > $previous)) {
                    $previous = $reading;
                }
            }

            $rates = collect($rows)->pluck('rate')->filter(fn ($r) => $r !== null)->sort()->values();
            if ($rates->count() >= self::MIN_FILLS_FOR_MEDIAN) {
                $median = (float) $rates->median();
                foreach ($rows as $id => $row) {
                    if ($row['rate'] !== null && $median > 0 && $row['rate'] > $median * self::HIGH_RATE_FACTOR) {
                        $rows[$id]['alertes'][] = 'Consommation élevée : '.self::number($row['rate']).' '.self::rateLabel($unit)
                            .' (habituel '.self::number($median).')';
                    }
                }
            }

            foreach ($rows as $id => $row) {
                $result[$id] = $row;
            }
        }

        return $result;
    }

    /** A vehicle's average over the given fill-ups (liters of the rated ones over their meter progress). */
    public static function average(Collection $fillUps, Collection $analysis): ?float
    {
        $rated = $fillUps->filter(fn ($f) => ($analysis[$f->id]['rate'] ?? null) !== null);
        $progress = $rated->sum(fn ($f) => $analysis[$f->id]['delta']);
        if ($progress <= 0) {
            return null;
        }
        $liters = $rated->sum(fn ($f) => $analysis[$f->id]['liters']);
        $unit = $analysis[$rated->first()->id]['unit'];

        return round($unit === 'km' ? $liters / $progress * 100 : $liters / $progress, 2);
    }

    /** Every fill-up of the farm, with what the analysis needs. */
    public static function farmFillUps(?int $farmId): Collection
    {
        return FuelTransaction::with('vehicle:id,type,capacity_liters')
            ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->where('transaction_type', 'fueling')
            ->whereNotNull('vehicle_id')
            ->get(['id', 'vehicle_id', 'transaction_type', 'date', 'quantity_liters', 'odometer_km']);
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', ' '), '0'), ',');
    }
}

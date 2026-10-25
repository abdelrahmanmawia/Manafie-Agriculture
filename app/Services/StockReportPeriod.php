<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * The period a stock report covers, from its query string: a month (?periode=mois&mois=2026-10),
 * a quinzaine as the farm pays them (1–15 / 16–end: ?periode=quinzaine&quinzaine=2026-10-2), two
 * dates (?periode=dates&from=…&to=…) or everything (?periode=tout). The current month by default.
 */
class StockReportPeriod
{
    private const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    public function __construct(
        public readonly string $periode,
        public readonly ?Carbon $from,
        public readonly ?Carbon $to,
        public readonly string $label,
        private readonly array $params,
    ) {}

    public static function fromInput(array $input): self
    {
        $today = Carbon::today();
        $periode = in_array($input['periode'] ?? null, ['mois', 'quinzaine', 'dates', 'tout'], true) ? $input['periode'] : 'mois';

        if ($periode === 'tout') {
            return new self('tout', null, null, 'Toute la période', ['periode' => 'tout']);
        }

        if ($periode === 'dates') {
            $from = self::date($input['from'] ?? null);
            $to = self::date($input['to'] ?? null);
            if ($from && $to && $from->gt($to)) {
                [$from, $to] = [$to, $from];
            }
            $label = match (true) {
                $from && $to => 'Du '.$from->format('d/m/Y').' au '.$to->format('d/m/Y'),
                (bool) $from => 'Depuis le '.$from->format('d/m/Y'),
                (bool) $to => "Jusqu'au ".$to->format('d/m/Y'),
                default => 'Toute la période',
            };

            return new self('dates', $from, $to, $label, ['periode' => 'dates', 'from' => $from?->toDateString(), 'to' => $to?->toDateString()]);
        }

        if ($periode === 'quinzaine' && preg_match('/^(\d{4})-(\d{2})-([12])$/', $input['quinzaine'] ?? '', $m) && checkdate((int) $m[2], 1, (int) $m[1])) {
            $month = Carbon::create((int) $m[1], (int) $m[2], 1);
            $half = (int) $m[3];
        } elseif ($periode === 'quinzaine') {
            $month = $today->copy()->startOfMonth();
            $half = $today->day >= 16 ? 2 : 1;
        }
        if ($periode === 'quinzaine') {
            $from = $half === 1 ? $month->copy() : $month->copy()->day(16);
            $to = $half === 1 ? $month->copy()->day(15) : $month->copy()->endOfMonth()->startOfDay();
            $label = ($half === 1 ? '1ère' : '2ème').' quinzaine '.self::MOIS[$month->month - 1].' '.$month->year;

            return new self('quinzaine', $from, $to, $label, ['periode' => 'quinzaine', 'quinzaine' => $month->format('Y-m').'-'.$half]);
        }

        $month = preg_match('/^(\d{4})-(\d{2})$/', $input['mois'] ?? '', $m) && checkdate((int) $m[2], 1, (int) $m[1])
            ? Carbon::create((int) $m[1], (int) $m[2], 1)
            : $today->copy()->startOfMonth();

        return new self('mois', $month->copy(), $month->copy()->endOfMonth()->startOfDay(),
            ucfirst(self::MOIS[$month->month - 1]).' '.$month->year, ['periode' => 'mois', 'mois' => $month->format('Y-m')]);
    }

    private static function date(?string $value): ?Carbon
    {
        if (! $value || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            return Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Keeps only the rows dated inside the period (both ends included). */
    public function apply(Builder $query, string $column = 'date'): Builder
    {
        return $query
            ->when($this->from, fn ($q) => $q->whereDate($column, '>=', $this->from->toDateString()))
            ->when($this->to, fn ($q) => $q->whereDate($column, '<=', $this->to->toDateString()));
    }

    /** For the page: what is chosen (to fill the picker) and how to say it. */
    public function toArray(): array
    {
        return array_filter($this->params, fn ($v) => $v !== null) + ['label' => $this->label];
    }
}

<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CollectionReports
{
    public function data(array $filters, array $period): array
    {
        return DB::transaction(function () use ($filters, $period) {
            $ids = app(ReportingExclusions::class)->validatedIds();
            $queries = app(ReportQueries::class);
            $parts = $queries->build('collections', $period, $ids);
            $base = $parts['base'];
            $valid = $parts['valid'];
            $invalid = $parts['invalid'];
            $excluded = (clone $parts['population'])->whereIn($parts['reservation_key'], $ids)->count();
            $sum = $queries->sumExpression();
            $summary = (clone $valid)->selectRaw("COUNT(*) AS transactions, $sum AS total_amount")->first();
            $amount = $queries->amount($summary->total_amount);
            $count = (int) $summary->transactions;
            $issues = $invalid->count();
            if ($base->count() !== $count + $issues) {
                throw new RuntimeException(ReportingExclusions::UNAVAILABLE);
            }

            $days = (int) $period['start']->diffInDays($period['end']) + 1;
            $bucket = $days === 1 ? 'hour' : ($days <= 93 ? 'day' : ($days <= 731 ? 'month' : 'year'));
            $format = ['hour' => 'Y-m-d H:00', 'day' => 'Y-m-d', 'month' => 'Y-m', 'year' => 'Y'][$bucket];
            $sqlFormat = ['hour' => '%Y-%m-%d %H:00', 'day' => '%Y-%m-%d', 'month' => '%Y-%m', 'year' => '%Y'][$bucket];
            $expression = DB::getDriverName() === 'sqlite'
                ? "strftime('$sqlFormat', reservation_payments.paid_at, '+8 hours')"
                : "DATE_FORMAT(DATE_ADD(reservation_payments.paid_at, INTERVAL 8 HOUR), '$sqlFormat')";
            $grouped = (clone $valid)->selectRaw("$expression AS bucket, COUNT(*) AS transactions, $sum AS total_amount")
                ->groupByRaw($expression)->get()->keyBy('bucket');
            $cursor = match ($bucket) {
                'month' => $period['start']->startOfMonth(), 'year' => $period['start']->startOfYear(), default => $period['start'],
            };
            $series = [];
            while ($cursor <= $period['end']->endOfDay()) {
                $key = $cursor->format($format);
                $row = $grouped->get($key);
                $series[] = ['label' => $key, 'amount' => $row ? $queries->amount($row->total_amount) : '0.00', 'count' => (int) ($row?->transactions ?? 0)];
                $cursor = match ($bucket) {
                    'hour' => $cursor->addHour(), 'day' => $cursor->addDay(), 'month' => $cursor->addMonth(), 'year' => $cursor->addYear(),
                };
            }

            $services = (clone $valid)->leftJoin('reservations as report_reservation', 'report_reservation.id', '=', 'reservation_payments.reservation_id')
                ->leftJoin('services', 'services.id', '=', 'report_reservation.service_id')
                ->select('report_reservation.service_id', 'services.name')->selectRaw("COUNT(*) AS transactions, $sum AS total_amount")
                ->groupBy('report_reservation.service_id', 'services.name')->orderByDesc('total_amount')->orderBy('report_reservation.service_id')
                ->get()->map(fn ($row) => ['service_id' => $row->service_id, 'name' => $row->name ?? 'Unavailable service',
                    'amount' => $queries->amount($row->total_amount), 'count' => (int) $row->transactions])->all();

            $this->reconcile($series, $amount, $count);
            $this->reconcile($services, $amount, $count);
            $ranking = array_slice($services, 0, 5);
            if (count($services) > 5) {
                $other = BigDecimal::zero();
                $otherCount = 0;
                foreach (array_slice($services, 5) as $service) {
                    $other = $other->plus($service['amount']);
                    $otherCount += $service['count'];
                }
                $ranking[] = ['service_id' => null, 'name' => 'Other services', 'amount' => (string) $other->toScale(2), 'count' => $otherCount];
            }
            $this->reconcile($ranking, $amount, $count);
            $maximum = BigDecimal::of('0.01');
            foreach ($ranking as $row) {
                $maximum = BigDecimal::max($maximum, $row['amount']);
            }
            foreach ($ranking as &$row) {
                // Geometry only. Monetary calculations and labels remain exact decimals.
                $row['width'] = (string) BigDecimal::of($row['amount'])->multipliedBy(100)->dividedBy($maximum, 4, RoundingMode::HALF_UP);
            }
            unset($row);
            $peak = BigDecimal::of('0.01');
            foreach ($series as $row) {
                $peak = BigDecimal::max($peak, $row['amount']);
            }
            $points = [];
            foreach ($series as $index => $row) {
                $x = BigDecimal::of($index)->multipliedBy(590)->dividedBy(max(1, count($series) - 1), 4, RoundingMode::HALF_UP)->plus(55);
                $y = BigDecimal::of(215)->minus(BigDecimal::of($row['amount'])->multipliedBy(170)->dividedBy($peak, 4, RoundingMode::HALF_UP));
                $points[] = $x.','.$y;
            }
            $query = ($filters['view'] ?? 'valid') === 'integrity' ? $invalid : $valid;
            $payments = $queries->details('collections', $query)
                ->orderByDesc('reservation_payments.paid_at')->orderByDesc('reservation_payments.id')->paginate(15)->appends($filters);
            if ($payments->total() !== (($filters['view'] ?? 'valid') === 'integrity' ? $issues : $count)) {
                throw new RuntimeException(ReportingExclusions::UNAVAILABLE);
            }

            return ['amount' => $amount, 'count' => $count, 'integrity_count' => $issues, 'excluded' => $excluded,
                'series' => $series, 'bucket' => $bucket, 'peak' => (string) $peak->toScale(2), 'points' => implode(' ', $points),
                'services' => $services, 'ranking' => $ranking, 'payments' => $payments];
        });
    }

    private function reconcile(array $rows, string $amount, int $count): void
    {
        $sum = BigDecimal::zero();
        $transactions = 0;
        foreach ($rows as $row) {
            $sum = $sum->plus($row['amount']);
            $transactions += $row['count'];
        }
        if (! $sum->isEqualTo($amount) || $transactions !== $count) {
            throw new RuntimeException(ReportingExclusions::UNAVAILABLE);
        }
    }
}

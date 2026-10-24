<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ReportCsvExports
{
    public function __construct(private ReportQueries $queries) {}

    public function download(string $kind, array $filters, array $period): StreamedResponse
    {
        $started = hrtime(true);
        $file = app(ReportCsvFile::class);
        try {
            $size = DB::transaction(function () use ($kind, $filters, $period, $file, $started) {
                $this->checkLimits($file, $started);
                $ids = app(ReportingExclusions::class)->validatedIds();
                $parts = $this->queries->build($kind, $period, $ids);
                if ($kind === 'reservations') {
                    $this->queries->reservationCounts($parts['base']);
                }
                $validCount = (clone $parts['valid'])->count();
                $invalidCount = $parts['invalid'] ? (clone $parts['invalid'])->count() : 0;
                if ((clone $parts['base'])->count() !== $validCount + $invalidCount) {
                    throw new ReportExportException('CSV reconciliation failed. No download was created.');
                }
                $integrity = $kind === 'collections' && ($filters['view'] ?? 'valid') === 'integrity';
                $query = $integrity ? $parts['invalid'] : $parts['valid'];
                $expected = $integrity ? $invalidCount : $validCount;
                $expectedAmount = $kind === 'collections' && ! $integrity
                    ? $this->queries->amount((clone $query)->selectRaw($this->queries->sumExpression().' as total_amount')->first()->total_amount) : null;
                $this->checkLimits($file, $started);
                if (@fwrite($file->handle(), "\xEF\xBB\xBF") !== 3) {
                    throw new ReportExportException('Unable to write the CSV staging file. No download was created.');
                }
                $this->writeRow($file, $this->headers($kind), $started);
                $details = $this->queries->details($kind, $query);
                $written = 0;
                $amount = BigDecimal::zero();
                $lastTime = null;
                $lastId = null;
                // Compound keyset preserves UI order, including equal timestamps, without OFFSET.
                do {
                    $batch = (clone $details);
                    if ($lastTime !== null) {
                        $batch->where(function ($q) use ($parts, $lastTime, $lastId) {
                            $q->where($parts['time'], '<', $lastTime)->orWhere(function ($tie) use ($parts, $lastTime, $lastId) {
                                $tie->where($parts['time'], $lastTime)->where($parts['id'], '<', $lastId);
                            });
                        });
                    }
                    $this->checkLimits($file, $started);
                    $rows = $batch->orderByDesc($parts['time'])->orderByDesc($parts['id'])
                        ->limit(max(1, min(500, (int) config('reporting.exports.batch_size', 500))))->get();
                    $this->checkLimits($file, $started);
                    foreach ($rows as $row) {
                        $this->writeRow($file, $this->row($kind, $row, $integrity), $started);
                        if ($expectedAmount !== null) {
                            $amount = $amount->plus($row->amount);
                        }
                        $lastId = $row->id;
                        $lastTime = match ($kind) {
                            'reservations' => $row->getRawOriginal('created_at'),
                            'collections' => $row->getRawOriginal('paid_at'),
                            default => $row->occurred_at,
                        };
                        $written++;
                    }
                    $more = $rows->isNotEmpty();
                    unset($rows);
                } while ($more);
                if ($written !== $expected || ($expectedAmount !== null && ! $amount->isEqualTo($expectedAmount))) {
                    throw new ReportExportException('CSV reconciliation failed. No download was created.');
                }
                $this->checkLimits($file, $started);
                $size = ftell($file->handle());
                if ($size === false || ! rewind($file->handle())) {
                    throw new ReportExportException('Unable to read the CSV staging file. No download was created.');
                }

                return $size;
            });
            $this->checkLimits($file, $started);
            $partition = $kind === 'collections' ? '-'.(($filters['view'] ?? 'valid') === 'integrity' ? 'integrity-issues' : 'valid-payments') : '';
            $name = 'smartbarangay-'.$kind.$partition.'-'.$period['start']->format('Y-m-d').'-to-'.$period['end']->format('Y-m-d').'.csv';

            return response()->streamDownload(function () use ($file) {
                $previous = ignore_user_abort(true);
                try {
                    while (! feof($file->handle()) && ! connection_aborted()) {
                        $bytes = @fread($file->handle(), 8192);
                        if ($bytes === false) {
                            throw new ReportExportException('CSV transfer failed.');
                        }
                        echo $bytes;
                    }
                } finally {
                    $file->close();
                    ignore_user_abort((bool) $previous);
                }
            }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Length' => (string) $size,
                'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        } catch (Throwable $exception) {
            $file->close();
            throw $exception;
        }
    }

    private function checkLimits(ReportCsvFile $file, int $started): void
    {
        $seconds = max(0.001, min(60.0, (float) config('reporting.exports.max_seconds', 60)));
        $bytes = max(1, min(25 * 1024 * 1024, (int) config('reporting.exports.max_bytes', 25 * 1024 * 1024)));
        if ((hrtime(true) - $started) / 1e9 > $seconds) {
            throw new ReportExportException('CSV export exceeded the preparation time limit. Choose a shorter date range. No download was created.');
        }
        $position = ftell($file->handle());
        if ($position === false || $position > $bytes) {
            throw new ReportExportException('CSV export exceeded the size limit. Choose a shorter date range. No download was created.');
        }
    }

    private function writeRow(ReportCsvFile $file, array $cells, int $started): void
    {
        // Encode only one row in memory, then handle partial disk writes explicitly.
        $row = fopen('php://memory', 'w+b');
        try {
            if (fputcsv($row, $cells, ',', '"', '', "\r\n") === false || ! rewind($row)) {
                throw new ReportExportException('Unable to encode the CSV row. No download was created.');
            }
            $encoded = stream_get_contents($row);
            if ($encoded === false) {
                throw new ReportExportException('Unable to encode the CSV row. No download was created.');
            }
            $offset = 0;
            while ($offset < strlen($encoded)) {
                $this->checkLimits($file, $started);
                $written = @fwrite($file->handle(), substr($encoded, $offset));
                if ($written === false || $written === 0) {
                    throw new ReportExportException('Unable to write the CSV staging file. No download was created.');
                }
                $offset += $written;
            }
        } finally {
            fclose($row);
        }
        $this->checkLimits($file, $started);
    }

    private function headers(string $kind): array
    {
        return match ($kind) {
            'reservations' => ['Request', 'Resident', 'Service', 'Submitted At (Asia/Manila)', 'Current Reservation Status'],
            'collections' => ['Request', 'Resident', 'Service', 'Recorded Amount (PHP)', 'Paid At (Asia/Manila)', 'Recorded By', 'Payment Classification'],
            'checkins' => ['Request', 'Resident', 'Service', 'Checked In At (Asia/Manila)', 'Recorded By'],
            'documents' => ['Request', 'Resident', 'Service', 'Finalized At (Asia/Manila)', 'Recorded By'],
            default => throw new \InvalidArgumentException('Unknown report kind.'),
        };
    }

    private function text(?string $value): string
    {
        $value ??= '';

        // Whitespace/control characters may conceal a spreadsheet formula starter.
        return preg_match('/\A[\s\p{Z}\p{C}]*[=+\-@\x{FF1D}\x{FF0B}\x{FF0D}\x{FF20}]/u', $value)
            || preg_match('/\A[\t\r\n]/', $value) ? "'".$value : $value;
    }

    private function date(mixed $value): string
    {
        return CarbonImmutable::parse($value, 'UTC')->timezone('Asia/Manila')->toIso8601String();
    }

    private function row(string $kind, mixed $row, bool $integrity): array
    {
        if ($kind === 'reservations') {
            return ['Request #'.$row->id, $this->text($row->user?->name ?? 'Former account'), $this->text($row->service?->name ?? 'Unavailable service'),
                $this->date($row->created_at), $row->status->label()];
        }
        if ($kind === 'collections') {
            return ['Request #'.$row->reservation_id, $this->text($row->reservation?->user?->name ?? 'Former account'),
                $this->text($row->reservation?->service?->name ?? 'Unavailable service'), (string) BigDecimal::of($row->amount)->toScale(2),
                $this->date($row->paid_at), $this->text($row->recorder?->name ?? 'Former account'), $integrity ? 'Payment integrity issue' : 'Paid'];
        }

        return ['Request #'.$row->reservation_id, $this->text($row->resident_name), $this->text($row->service_name), $this->date($row->occurred_at), $this->text($row->actor_name ?? 'Former account')];
    }
}

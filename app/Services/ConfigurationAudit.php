<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\ServiceRequirement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

class ConfigurationAudit
{
    public function values(Model $target): array
    {
        $fields = match ($target::class) {
            Service::class => ['name', 'description', 'fee_amount', 'is_active'],
            Schedule::class => ['date', 'start_time', 'end_time', 'capacity', 'is_active'],
            ServiceRequirement::class => ['service_id', 'name', 'description', 'is_required'],
            default => throw new LogicException('Unsupported audit target.'),
        };
        $values = $target->only($fields);
        if ($target instanceof Schedule) {
            $values['date'] = $target->date->toDateString();
        }

        return $values;
    }

    public function record(User $actor, Model $target, string $operation, array $old, array $new): void
    {
        if (DB::transactionLevel() === 0 || ! in_array($operation, ['created', 'updated', 'deleted'], true)) {
            throw new LogicException('Configuration audit must share the configuration transaction.');
        }
        $kind = match ($target::class) {
            Service::class => 'service', Schedule::class => 'schedule', ServiceRequirement::class => 'requirement',
            default => throw new LogicException('Unsupported audit target.'),
        };
        $admin = User::findOrFail($actor->id);
        abort_unless($admin->role === UserRole::Admin && $admin->is_active && $admin->hasVerifiedEmail(), 403);
        if ($operation === 'updated' && $old === $new) {
            return;
        }
        $log = new AuditLog;
        $log->forceFill(['user_id' => $admin->id, 'action' => $operation.'_'.$kind, 'target_id' => $target->getKey(),
            'old_values' => $old, 'new_values' => $new, 'created_at' => now('UTC')])->save();
    }
}

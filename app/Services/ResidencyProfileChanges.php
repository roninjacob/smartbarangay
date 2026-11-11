<?php

namespace App\Services;

use App\Enums\ResidencyStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\ResidentProfile;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

class ResidencyProfileChanges
{
    public const ACTION = 'residency_profile_changed';

    public const LABELS = ['name' => 'Full name', 'address' => 'Recorded address',
        'first_name' => 'First name', 'middle_name' => 'Middle name', 'last_name' => 'Last name', 'suffix' => 'Suffix',
        'birthdate' => 'Birthdate', 'house_unit_number' => 'House / Unit', 'address_street' => 'Street', 'address_zone_purok' => 'Zone / Purok'];

    /** Called with the parent User locked, after filling fields and before saving either model. */
    public function record(User $user, Model $profile): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Residency changes require the profile transaction.');
        }
        if ($user->role !== UserRole::Resident) {
            return;
        }
        $models = match (true) {
            $profile instanceof User && $profile->id === $user->id => [$user],
            $profile instanceof ResidentProfile && $profile->user_id === $user->id => [$user, $profile],
            default => throw new LogicException('Residency changes must belong to the locked resident.'),
        };
        $before = [];
        $after = [];
        foreach ($models as $model) {
            foreach (array_keys(self::LABELS) as $field) {
                if (! $model->isDirty($field)) {
                    continue;
                }
                $old = $model->getOriginal($field);
                $new = $model->getAttribute($field);
                $before[$field] = $old instanceof CarbonInterface ? $old->toDateString() : $old;
                $after[$field] = $new instanceof CarbonInterface ? $new->toDateString() : $new;
            }
        }
        if ($before === []) {
            return;
        }

        $oldStatus = $user->residency_status;
        $oldRevision = $user->residency_revision;
        if ($oldStatus === ResidencyStatus::Approved) {
            $user->residency_status = ResidencyStatus::Pending;
            $user->residency_reviewed_by = null;
            $user->residency_reviewed_at = null;
        }
        // Material edits also invalidate an Admin's open form while review is pending.
        $user->residency_revision++;
        $log = new AuditLog;
        $log->forceFill(['user_id' => $user->id, 'action' => self::ACTION, 'target_id' => $user->id,
            'old_values' => ['residency_status' => $oldStatus->value, 'residency_revision' => $oldRevision, 'fields' => $before],
            'new_values' => ['residency_status' => $user->residency_status->value,
                'residency_revision' => $user->residency_revision, 'fields' => $after],
            'created_at' => now('UTC')])->save();
    }
}

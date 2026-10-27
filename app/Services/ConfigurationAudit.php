<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\DocumentTemplate;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\ServiceDocumentTemplate;
use App\Models\ServiceRequirement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class ConfigurationAudit
{
    public function revision(Model $target): string
    {
        $state = [$target::class, $target->getKey(), $this->values($target), $target->getRawOriginal('updated_at')];
        if ($target instanceof Service) {
            $state[] = $target->documentTemplate()->first()?->revision();
        }

        return hash_hmac('sha256', json_encode($state, JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function requireRevision(Model $target, mixed $revision): void
    {
        if (! is_string($revision) || ! hash_equals($this->revision($target), $revision)) {
            throw ValidationException::withMessages(['edit_revision' => 'These details have changed or this form is out of date. Reload the edit page and review the latest values before saving.']);
        }
    }

    public function values(Model $target): array
    {
        $fields = match ($target::class) {
            Service::class => ['name', 'description', 'fee_amount', 'is_active'],
            Schedule::class => ['date', 'start_time', 'end_time', 'capacity', 'is_active'],
            ServiceRequirement::class => ['service_id', 'name', 'description', 'is_required'],
            User::class => ['is_active'],
            ServiceDocumentTemplate::class => ['service_id', 'mime_type', 'file_size'],
            DocumentTemplate::class => ['service_id', 'version', 'is_active', 'source_revision', 'source_content_hash'],
            default => throw new LogicException('Unsupported audit target.'),
        };
        $values = $target->only($fields);
        if ($target instanceof Schedule) {
            $values['date'] = $target->date->toDateString();
        }

        if ($target instanceof ServiceDocumentTemplate) {
            $values['revision'] = $target->revision();
        }
        if ($target instanceof DocumentTemplate) {
            $values['fields'] = $target->fields()->orderBy('id')->with('positions')->get()->map(fn ($field) => [
                ...$field->only(['field_name', 'label', 'source', 'field_type', 'is_required', 'sort_order', 'options', 'validation_rules']),
                'positions' => $field->positions->sortBy('id')->values()->map(fn ($position) => $position->only([
                    'page_number', 'x_position', 'y_position', 'width', 'height', 'font_size', 'alignment', 'text_behavior',
                ]))->all(),
            ])->all();
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
            User::class => 'resident_account', DocumentTemplate::class => 'document_configuration',
            ServiceDocumentTemplate::class => 'official_template',
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

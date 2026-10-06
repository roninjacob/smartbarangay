<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\Schedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Admin;
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        foreach (['date', 'start_time', 'end_time', 'capacity'] as $field) {
            if (is_string($this->input($field))) {
                $values[$field] = trim($this->input($field));
            }
        }
        $this->merge($values);
    }

    public function rules(): array
    {
        return [
            'date' => ['bail', 'required', 'string', 'date_format:Y-m-d'],
            'start_time' => ['bail', 'required', 'string', 'date_format:H:i'],
            'end_time' => ['bail', 'required', 'string', 'date_format:H:i'],
            'capacity' => ['required', 'integer', 'min:1', 'max:4294967295'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            // Strict HH:MM validation above makes lexical comparison safe.
            if ($this->input('end_time') <= $this->input('start_time')) {
                $validator->errors()->add('end_time', 'The end time must be later than the start time.');

                return;
            }
            $query = Schedule::whereDate('date', $this->input('date'))
                ->where('start_time', $this->input('start_time').':00')
                ->where('end_time', $this->input('end_time').':00');
            if ($schedule = $this->route('schedule')) {
                $query->whereKeyNot($schedule->id);
            }
            if ($query->exists()) {
                $validator->errors()->add('date', 'A schedule with this date and time range already exists.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'start_time.date_format' => 'Enter a valid start time in HH:MM format.',
            'end_time.date_format' => 'Enter a valid end time in HH:MM format.',
        ];
    }

    public function scheduleData(): array
    {
        $data = $this->safe()->only(['date', 'start_time', 'end_time', 'capacity']);
        $data['start_time'] .= ':00';
        $data['end_time'] .= ':00';

        return $data;
    }
}

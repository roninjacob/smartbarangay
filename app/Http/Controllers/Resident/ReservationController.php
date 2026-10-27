<?php

namespace App\Http\Controllers\Resident;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Resident\ReservationWizardRequest;
use App\Models\DocumentTemplate;
use App\Models\Schedule;
use App\Models\Service;
use App\Services\AccountEligibility;
use App\Services\RequirementChecklist;
use App\Services\ReservationDocumentInformation;
use App\Services\ReservationFileUploads;
use App\Services\ReservationNotifications;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class ReservationController extends Controller
{
    private const SESSION_KEY = 'reservation_wizard';

    public function create(Request $request): View
    {
        $draft = $request->session()->get(self::SESSION_KEY, []);
        if (($draft['resident_id'] ?? null) !== $request->user()->id) {
            $request->session()->forget(self::SESSION_KEY);
            $draft = [];
        }

        return $this->page(1, ['services' => Service::where('is_active', true)->orderBy('name')->get(), 'draft' => $draft]);
    }

    public function selectService(ReservationWizardRequest $request): RedirectResponse
    {
        $service = $this->service($request, ['service_id' => (int) $request->validated('service_id')]);
        $request->session()->put(self::SESSION_KEY, [
            'resident_id' => $request->user()->id,
            'service_id' => (int) $request->validated('service_id'),
            'offered_fee' => $service->fee_amount,
        ]);

        return to_route('resident.reservations.requirements');
    }

    public function requirements(Request $request): View
    {
        $draft = $this->draft($request);

        $service = $this->service($request, $draft);
        $draft['offered_fee'] = $service->fee_amount;
        $request->session()->put(self::SESSION_KEY, $draft);
        $information = app(ReservationDocumentInformation::class);
        $template = $this->currentDocumentConfiguration($request, $draft, $service, $information);
        $profile = $information->profile($request->user());

        return $this->page(2, ['service' => $service, 'draft' => $draft, 'documentTemplate' => $template,
            'documentSignature' => $information->signature($template),
            'requirementSignature' => app(RequirementChecklist::class)->signature($service->id, $service->serviceRequirements),
            'residentInformation' => $profile,
            'missingProfileFields' => $information->missingRequiredProfileFields($template, $profile)]);
    }

    public function reviewRequirements(ReservationWizardRequest $request): RedirectResponse
    {
        $draft = $this->draft($request);
        $service = $this->service($request, $draft);
        if (($draft['offered_fee'] ?? null) !== $service->fee_amount) {
            $this->resetDocumentInformation($request, $draft, 'The service fee changed. Review the updated fee and confirm your request again.');
        }
        $this->requireReviewedChecklist($request, $draft, $service, $request->input('requirement_signature'));
        $information = app(ReservationDocumentInformation::class);
        $template = $this->currentDocumentConfiguration($request, $draft, $service, $information);
        $signature = $information->signature($template);
        if ($template && (! is_string($request->input('document_signature')) || ! hash_equals($signature, $request->input('document_signature')))) {
            $this->resetDocumentInformation($request, $draft, 'The document fields have changed. Review the current form before continuing.');
        }
        unset($draft['requirements_reviewed'], $draft['schedule_id'], $draft['confirmation_token']);
        $request->session()->put(self::SESSION_KEY, $draft);
        $profile = $information->profile($request->user());
        $answers = $information->validate($template, $request->input('document_answers'), $profile);
        $draft['document_signature'] = $signature;
        $draft['document_answers'] = $answers;
        $draft['document_profile'] = $profile;
        $draft['requirements_reviewed'] = true;
        $draft['requirement_signature'] = $request->input('requirement_signature');
        $draft['reviewed_fee'] = $service->fee_amount;
        $request->session()->put(self::SESSION_KEY, $draft);

        return to_route('resident.reservations.schedule');
    }

    public function schedule(Request $request): View
    {
        $filters = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $draft = $this->draft($request, true);

        $service = $this->service($request, $draft);
        $this->documentInformation($request, $draft, $service);

        return $this->page(3, [
            'service' => $service, 'draft' => $draft, 'scheduleFilters' => $filters,
            'schedules' => Schedule::where('is_active', true)->upcoming()->when($filters['date'] ?? null, fn ($query, $date) => $query->whereDate('date', $date))
                ->withCount('occupiedReservations')->orderBy('date')->orderBy('start_time')->orderBy('id')->paginate(12, ['*'], 'slots_page')->withQueryString(),
        ]);
    }

    public function selectSchedule(ReservationWizardRequest $request): RedirectResponse
    {
        $draft = $this->draft($request, true);
        $service = $this->service($request, $draft);
        $this->documentInformation($request, $draft, $service);
        $selected = Schedule::findOrFail($request->validated('schedule_id'));
        $this->requireUpcomingSchedule($selected);
        if ($selected->remainingSlots() === 0) {
            $this->restartAt('schedule', 'This schedule has just reached full capacity. Please choose another available schedule.');
        }
        $draft['schedule_id'] = (int) $request->validated('schedule_id');
        $draft['confirmation_token'] = (string) Str::uuid();
        $request->session()->put(self::SESSION_KEY, $draft);

        return to_route('resident.reservations.confirm');
    }

    public function confirm(Request $request): View
    {
        $draft = $this->draft($request, true);

        $service = $this->service($request, $draft);
        $template = $this->documentInformation($request, $draft, $service);

        return $this->page(4, [
            'service' => $service, 'documentTemplate' => $template,
            'residentInformation' => $draft['document_profile'] ?? app(ReservationDocumentInformation::class)->profile($request->user()),
            'schedule' => $this->selectedSchedule($draft), 'draft' => $draft,
        ]);
    }

    public function store(ReservationWizardRequest $request, ReservationFileUploads $uploads): RedirectResponse
    {
        $draft = $this->draft($request, true);
        if (! isset($draft['schedule_id'], $draft['confirmation_token'])) {
            $this->restartAt('schedule', 'Please choose a schedule before confirming.');
        }
        if (! hash_equals($draft['confirmation_token'], $request->validated('confirmation_token'))) {
            throw ValidationException::withMessages(['confirmation_token' => 'This confirmation has expired. Review your current selection and try again.']);
        }

        $storedPaths = [];
        try {
            DB::transaction(function () use ($request, $draft, $uploads, &$storedPaths) {
                $resident = app(AccountEligibility::class)->lock($request->user(), UserRole::Resident);
                // Serialize final checks against Admin edits to these same records.
                $service = Service::lockForUpdate()->find($draft['service_id']);
                $schedule = Schedule::lockForUpdate()->find($draft['schedule_id']);
                if (! $service?->is_active) {
                    $request->session()->forget(self::SESSION_KEY);
                    $this->restartAt('create', 'The selected service is no longer available. Please choose another service.');
                }
                if (! $schedule?->is_active) {
                    $this->restartAt('schedule', 'The selected schedule is no longer available. Please choose another schedule.');
                }
                $this->requireUpcomingSchedule($schedule);
                $template = $this->documentInformation($request, $draft, $service);
                // Parent locks serialize separate sessions; a locking read sees the latest committed reservation.
                $duplicate = $request->user()->reservations()->occupyingSlot()
                    ->where('service_id', $service->id)->where('schedule_id', $schedule->id)
                    ->lockForUpdate()->first(['id']);
                if ($duplicate) {
                    throw ValidationException::withMessages([
                        'reservation' => 'You already have an active reservation for this service and schedule.',
                    ]);
                }
                if ($schedule->lockedOccupancy() >= $schedule->capacity) {
                    $request->session()->forget([self::SESSION_KEY.'.schedule_id', self::SESSION_KEY.'.confirmation_token']);
                    $this->restartAt('schedule', 'This schedule has just reached full capacity. Please choose another available schedule.');
                }
                $requirements = $service->serviceRequirements()->lockForUpdate()->get();
                $service->setRelation('serviceRequirements', $requirements);
                $this->requireReviewedChecklist($request, $draft, $service, $draft['requirement_signature'] ?? null);
                $files = $uploads->validate($request, $requirements);
                $reservation = $request->user()->reservations()->make([
                    'service_id' => $service->id, 'schedule_id' => $schedule->id,
                    'status' => ReservationStatus::Pending,
                ]);
                $reservation->forceFill(['fee_snapshot' => $service->fee_amount,
                    'appointment_date' => $schedule->date->toDateString(),
                    'appointment_start_time' => $schedule->start_time, 'appointment_end_time' => $schedule->end_time,
                    'requirements_snapshotted_at' => now('UTC')])->save();
                foreach ($requirements as $requirement) {
                    $snapshot = $reservation->requirementSnapshots()->make();
                    $snapshot->forceFill(['service_requirement_id' => $requirement->id, 'name' => $requirement->name,
                        'description' => $requirement->description, 'is_required' => $requirement->is_required])->save();
                }
                $information = app(ReservationDocumentInformation::class);
                $profile = $information->profile($resident);
                if ($template && ($draft['document_profile'] ?? null) !== $profile) {
                    $this->resetDocumentInformation($request, $draft, 'Your profile information has changed. Review the document information again.');
                }
                $answers = $information->validate($template, $draft['document_answers'] ?? [], $profile);
                $information->store($reservation, $template, $answers, $profile, $storedPaths);
                $uploads->store($reservation, $files, $storedPaths);
                app(ReservationNotifications::class)->record('submitted', $reservation);
                $this->requireUpcomingSchedule($schedule);
            });
        } catch (Throwable $exception) {
            $uploads->cleanup($storedPaths);
            throw $exception;
        }
        // Routes lock the session; subsequent submissions cannot consume this draft again.
        $request->session()->forget(self::SESSION_KEY);

        return to_route('resident.home')->with('status', 'Reservation submitted successfully. Your request is Pending.');
    }

    private function currentDocumentConfiguration(Request $request, array $draft, Service $service, ReservationDocumentInformation $information): ?DocumentTemplate
    {
        try {
            return $information->configuration($service);
        } catch (ValidationException) {
            $request->session()->forget(self::SESSION_KEY);
            $this->restartAt('create', 'This service document setup is temporarily unavailable. Please choose another service or try again after the office updates it.');
        }
    }

    private function documentInformation(Request $request, array $draft, Service $service): ?DocumentTemplate
    {
        $this->requireReviewedChecklist($request, $draft, $service, $draft['requirement_signature'] ?? null);
        $this->requireConfiguredFee($request, $service);
        if (($draft['reviewed_fee'] ?? null) !== $service->fee_amount) {
            $this->resetDocumentInformation($request, $draft, 'The service fee changed. Review the updated fee and confirm your request again.');
        }
        $information = app(ReservationDocumentInformation::class);
        $template = $this->currentDocumentConfiguration($request, $draft, $service, $information);
        if (($draft['document_signature'] ?? 'none') !== $information->signature($template)
            || ($template && ($draft['document_profile'] ?? null) !== $information->profile($request->user()))) {
            $this->resetDocumentInformation($request, $draft, 'The document or profile information has changed. Review the current form before continuing.');
        }
        try {
            $information->validate($template, $draft['document_answers'] ?? [], $information->profile($request->user()));
        } catch (ValidationException $exception) {
            unset($draft['requirements_reviewed'], $draft['schedule_id'], $draft['confirmation_token']);
            $request->session()->put(self::SESSION_KEY, $draft);
            throw new HttpResponseException(to_route('resident.reservations.requirements')->withErrors($exception->errors()));
        }

        return $template;
    }

    private function requireReviewedChecklist(Request $request, array $draft, Service $service, mixed $signature): void
    {
        if (! app(RequirementChecklist::class)->matches($signature, $service->id, $service->serviceRequirements)) {
            $this->resetDocumentInformation($request, $draft, 'The requirements have changed. Review the current checklist before continuing; prepare your files for the updated requirements.');
        }
    }

    private function resetDocumentInformation(Request $request, array $draft, string $message): never
    {
        unset($draft['requirement_signature'], $draft['requirements_reviewed'], $draft['reviewed_fee'], $draft['document_signature'], $draft['document_answers'], $draft['document_profile'], $draft['schedule_id'], $draft['confirmation_token']);
        $request->session()->put(self::SESSION_KEY, $draft);
        $this->restartAt('requirements', $message);
    }

    private function draft(Request $request, bool $reviewed = false): array
    {
        $draft = $request->session()->get(self::SESSION_KEY, []);
        if (($draft['resident_id'] ?? null) !== $request->user()->id || ! isset($draft['service_id'])) {
            $request->session()->forget(self::SESSION_KEY);
            $this->restartAt('create', 'Start by choosing a service.');
        }
        if ($reviewed && ! ($draft['requirements_reviewed'] ?? false)) {
            $this->restartAt('requirements', 'Please review the service requirements first.');
        }

        return $draft;
    }

    private function service(Request $request, array $draft): Service
    {
        $service = Service::with('serviceRequirements')->find($draft['service_id']);
        if (! $service?->is_active) {
            $request->session()->forget(self::SESSION_KEY);
            $this->restartAt('create', 'The selected service is no longer available. Please choose another service.');
        }

        $this->requireConfiguredFee($request, $service);

        return $service;
    }

    private function requireConfiguredFee(Request $request, Service $service): void
    {
        if ($service->fee_amount === null) {
            $request->session()->forget(self::SESSION_KEY);
            $this->restartAt('create', 'This service fee is not configured yet. Please contact the barangay office so an Admin can configure the fee first.');
        }
    }

    private function selectedSchedule(array $draft): Schedule
    {
        $schedule = isset($draft['schedule_id']) ? Schedule::find($draft['schedule_id']) : null;
        if (! $schedule?->is_active) {
            $this->restartAt('schedule', 'Please choose an active schedule.');
        }
        $this->requireUpcomingSchedule($schedule);

        return $schedule;
    }

    private function restartAt(string $step, string $message): never
    {
        throw new HttpResponseException(to_route('resident.reservations.'.$step)->withErrors(['reservation' => $message]));
    }

    private function requireUpcomingSchedule(Schedule $schedule): void
    {
        if (! $schedule->startsInFuture()) {
            $this->restartAt('schedule', 'This schedule has already started. Please choose a future schedule.');
        }
    }

    private function page(int $step, array $data): View
    {
        return view('resident.reservations.wizard', [
            'roleLabel' => 'Resident', 'navigation' => config('navigation.resident'),
            'dashboardDate' => now('Asia/Manila'), 'pageTitle' => 'New Reservation',
            'pageHeading' => 'New Reservation', 'pageSection' => 'My services', 'step' => $step,
            ...$data,
        ]);
    }
}

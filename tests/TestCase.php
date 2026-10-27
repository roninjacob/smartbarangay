<?php

namespace Tests;

use App\Models\ServiceRequirement;
use App\Services\RequirementChecklist;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    //
    protected function requirementSignature(): string
    {
        $serviceId = (int) session('reservation_wizard.service_id');

        return app(RequirementChecklist::class)->signature($serviceId,
            ServiceRequirement::where('service_id', $serviceId)->get());
    }
}

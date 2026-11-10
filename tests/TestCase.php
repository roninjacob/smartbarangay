<?php

namespace Tests;

use App\Models\ServiceRequirement;
use App\Services\RequirementChecklist;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            // Production-mode tests can set Symfony's process-wide host allowlist.
            // Laravel resets TrustHosts configuration but leaves this state intact.
            Request::setTrustedHosts([]);
        }
    }

    protected function requirementSignature(): string
    {
        $serviceId = (int) session('reservation_wizard.service_id');

        return app(RequirementChecklist::class)->signature($serviceId,
            ServiceRequirement::where('service_id', $serviceId)->get());
    }
}

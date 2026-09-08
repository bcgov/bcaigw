<?php

namespace App\Http\Controllers\Applications;

use App\Enums\ApplicationTransition;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApplicationTransitionRequest;
use App\Models\Application;
use App\Services\ApplicationLifecycleService;
use Illuminate\Http\RedirectResponse;

final class ApplicationLifecycleController extends Controller
{
    public function submit(
        ApplicationTransitionRequest $request,
        Application $application,
        ApplicationLifecycleService $lifecycle,
    ): RedirectResponse {
        $this->authorize('submit', $application);
        $validated = $request->validated();

        if ($validated['transition'] !== ApplicationTransition::Submit->value) {
            abort(403);
        }

        $lifecycle->transition(
            $application,
            ApplicationTransition::Submit,
            $request->user(),
            $request,
            $validated['status_version'],
            $validated['note'] ?? null,
        );

        return back();
    }
}

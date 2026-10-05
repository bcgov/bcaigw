<?php

namespace Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Application;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PortalController extends Controller
{
    /**
     * Display the portal dashboard for ministry users.
     */
    public function home(Request $request): Response
    {
        $applications = $request->user()->applications()
            ->select([
                'applications.id',
                'applications.public_id',
                'applications.name',
                'applications.ministry_organization',
                'applications.status',
                'applications.updated_at',
            ])
            ->orderByDesc('applications.updated_at')
            ->get();

        return Inertia::render('Portal/Dashboard', [
            'appName' => config('app.name'),
            'applications' => $applications,
            'stats' => [
                'total' => $applications->count(),
                'draft' => $applications->where('status', Application::STATUS_DRAFT)->count(),
                'submitted' => $applications->where('status', Application::STATUS_SUBMITTED)->count(),
                'active' => $applications->where('status', Application::STATUS_ACTIVE)->count(),
            ],
        ]);
    }
}

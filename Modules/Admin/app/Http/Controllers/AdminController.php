<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationModelGrant;
use App\Models\ProviderAccount;
use App\Models\PublicModelAlias;
use App\Models\User;
use App\Services\Gateway\ApplicationUsageService;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends Controller
{
    public function __construct(private readonly ApplicationUsageService $usage) {}

    /**
     * Display the admin dashboard.
     */
    public function dashboard(): Response
    {
        return Inertia::render('Admin/Dashboard', [
            'appName' => config('app.name'),
            'stats' => [
                'users' => User::query()->count(),
                'applications' => Application::query()->count(),
                'submitted' => Application::query()->where('status', Application::STATUS_SUBMITTED)->count(),
                'active' => Application::query()->where('status', Application::STATUS_ACTIVE)->count(),
                'providers' => ProviderAccount::query()->count(),
                'aliases' => PublicModelAlias::query()->count(),
                'grants' => ApplicationModelGrant::query()->where('enabled', true)->count(),
            ],
            'usage' => $this->usage->dashboard(),
        ]);
    }
}

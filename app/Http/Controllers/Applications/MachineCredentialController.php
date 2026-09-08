<?php

namespace App\Http\Controllers\Applications;

use App\Http\Controllers\Controller;
use App\Http\Requests\RotateMachineCredentialRequest;
use App\Http\Requests\StoreMachineCredentialRequest;
use App\Models\Application;
use App\Models\MachineCredential;
use App\Services\MachineCredentialService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

final class MachineCredentialController extends Controller
{
    public function store(
        StoreMachineCredentialRequest $request,
        Application $application,
        MachineCredentialService $credentials,
    ): RedirectResponse {
        $issued = $credentials->create(
            $application,
            $request->validated(),
            $request->user(),
            $request,
        );

        return back()->with(
            'machineCredentialEncrypted',
            Crypt::encryptString(json_encode($issued->oneTimeDisplay(), JSON_THROW_ON_ERROR)),
        );
    }

    public function rotate(
        RotateMachineCredentialRequest $request,
        Application $application,
        MachineCredential $machineCredential,
        MachineCredentialService $credentials,
    ): RedirectResponse {
        $issued = $credentials->rotate(
            $application,
            $machineCredential,
            $request->validated(),
            $request->user(),
            $request,
        );

        return back()->with(
            'machineCredentialEncrypted',
            Crypt::encryptString(json_encode($issued->oneTimeDisplay(), JSON_THROW_ON_ERROR)),
        );
    }

    public function destroy(
        Request $request,
        Application $application,
        MachineCredential $machineCredential,
        MachineCredentialService $credentials,
    ): RedirectResponse {
        $this->authorize('manageCredentials', $application);
        $credentials->revoke(
            $application,
            $machineCredential,
            $request->user(),
            $request,
        );

        return back();
    }
}

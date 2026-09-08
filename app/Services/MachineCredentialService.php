<?php

namespace App\Services;

use App\Auth\IssuedMachineCredential;
use App\Enums\ApplicationStatus;
use App\Enums\AuditEventType;
use App\Enums\AuditOutcome;
use App\Models\Application;
use App\Models\MachineCredential;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class MachineCredentialService
{
    public function __construct(
        private MachineSecretHasher $hasher,
        private SecurityAuditRecorder $audit,
    ) {}

    /**
     * @param  array{name: string, abilities: list<string>, expires_at?: string|null}  $data
     */
    public function create(
        Application $application,
        array $data,
        User $actor,
        Request $request,
    ): IssuedMachineCredential {
        return DB::transaction(function () use ($application, $data, $actor, $request): IssuedMachineCredential {
            $locked = Application::query()->lockForUpdate()->findOrFail($application->id);
            $this->assertApplicationCanIssueCredentials($locked);

            $secret = $this->randomValue('bcaigw_secret_', 48);
            $credential = MachineCredential::create([
                'application_id' => $locked->id,
                'name' => $data['name'],
                'client_identifier' => $this->randomValue('bcaigw_client_', 24),
                'secret_hash' => $this->hasher->make($secret),
                'abilities' => array_values(array_unique($data['abilities'])),
                'expires_at' => filled($data['expires_at'] ?? null)
                    ? Carbon::parse($data['expires_at'])
                    : null,
                'created_by' => $actor->id,
            ]);
            $credential->setRelation('application', $locked);

            $this->audit->record(
                $request,
                AuditEventType::MachineCredentialCreated,
                AuditOutcome::Succeeded,
                actor: $actor,
                context: [
                    'application_public_id' => $locked->public_id,
                    'credential_public_id' => $credential->public_id,
                    'credential_name' => $credential->name,
                ],
            );

            return new IssuedMachineCredential($credential, $secret);
        });
    }

    /**
     * @param  array{name?: string|null, expires_at?: string|null, overlap_seconds: int}  $data
     */
    public function rotate(
        Application $application,
        MachineCredential $credential,
        array $data,
        User $actor,
        Request $request,
    ): IssuedMachineCredential {
        return DB::transaction(function () use (
            $application,
            $credential,
            $data,
            $actor,
            $request,
        ): IssuedMachineCredential {
            $lockedApplication = Application::query()->lockForUpdate()->findOrFail($application->id);
            $lockedCredential = MachineCredential::query()->lockForUpdate()->findOrFail($credential->id);
            $this->assertCredentialBelongsToApplication($lockedCredential, $lockedApplication);
            $this->assertApplicationCanIssueCredentials($lockedApplication);

            if (! $lockedCredential->isUsable() || $lockedCredential->rotation_overlap_ends_at !== null) {
                throw ValidationException::withMessages([
                    'credential' => 'Only a current, usable credential can be rotated.',
                ]);
            }

            $overlapSeconds = $data['overlap_seconds'];
            if ($overlapSeconds === 0) {
                $lockedCredential->forceFill([
                    'revoked_at' => now(),
                    'version' => $lockedCredential->version + 1,
                ])->save();
                $this->revokeOutstandingTokens($lockedCredential);
            } else {
                $lockedCredential->forceFill([
                    'rotation_overlap_ends_at' => now()->addSeconds($overlapSeconds),
                ])->save();
            }

            $secret = $this->randomValue('bcaigw_secret_', 48);
            $successor = MachineCredential::create([
                'application_id' => $lockedApplication->id,
                'name' => filled($data['name'] ?? null) ? $data['name'] : $lockedCredential->name,
                'client_identifier' => $this->randomValue('bcaigw_client_', 24),
                'secret_hash' => $this->hasher->make($secret),
                'abilities' => $lockedCredential->abilities,
                'expires_at' => filled($data['expires_at'] ?? null)
                    ? Carbon::parse($data['expires_at'])
                    : null,
                'rotated_from_id' => $lockedCredential->id,
                'created_by' => $actor->id,
            ]);
            $successor->setRelation('application', $lockedApplication);

            $this->audit->record(
                $request,
                AuditEventType::MachineCredentialRotated,
                AuditOutcome::Succeeded,
                actor: $actor,
                context: [
                    'application_public_id' => $lockedApplication->public_id,
                    'credential_public_id' => $lockedCredential->public_id,
                    'successor_public_id' => $successor->public_id,
                    'overlap_seconds' => $overlapSeconds,
                ],
            );

            return new IssuedMachineCredential($successor, $secret);
        });
    }

    public function revoke(
        Application $application,
        MachineCredential $credential,
        User $actor,
        Request $request,
    ): void {
        DB::transaction(function () use ($application, $credential, $actor, $request): void {
            $lockedApplication = Application::query()->lockForUpdate()->findOrFail($application->id);
            $lockedCredential = MachineCredential::query()->lockForUpdate()->findOrFail($credential->id);
            $this->assertCredentialBelongsToApplication($lockedCredential, $lockedApplication);

            if ($lockedCredential->revoked_at === null) {
                $lockedCredential->forceFill([
                    'revoked_at' => now(),
                    'version' => $lockedCredential->version + 1,
                ])->save();
                $this->revokeOutstandingTokens($lockedCredential);

                $this->audit->record(
                    $request,
                    AuditEventType::MachineCredentialRevoked,
                    AuditOutcome::Succeeded,
                    actor: $actor,
                    context: [
                        'application_public_id' => $lockedApplication->public_id,
                        'credential_public_id' => $lockedCredential->public_id,
                    ],
                );
            }
        });
    }

    private function assertApplicationCanIssueCredentials(Application $application): void
    {
        if ($application->status !== ApplicationStatus::Active || ! $application->configuration_ready) {
            throw ValidationException::withMessages([
                'application' => 'Machine credentials require an active, configuration-ready application.',
            ]);
        }
    }

    private function assertCredentialBelongsToApplication(
        MachineCredential $credential,
        Application $application,
    ): void {
        abort_unless($credential->application_id === $application->id, 404);
    }

    private function revokeOutstandingTokens(MachineCredential $credential): void
    {
        $credential->accessTokens()
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    private function randomValue(string $prefix, int $bytes): string
    {
        return $prefix.rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}

<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\AuditEventType;
use App\Enums\AuditOutcome;
use App\Exceptions\OAuthServerException;
use App\Models\Application;
use App\Models\MachineAccessToken;
use App\Models\MachineCredential;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final readonly class OAuthTokenService
{
    public function __construct(
        private MachineSecretHasher $hasher,
        private MachineRequestMetadata $requestMetadata,
        private SecurityAuditRecorder $audit,
    ) {}

    /**
     * @param  list<string>  $requestedAbilities
     * @return array{access_token: string, token_type: string, expires_in: int, scope: string}
     */
    public function issue(
        Request $request,
        string $apiDirectoryClientId,
        string $clientIdentifier,
        string $clientSecret,
        array $requestedAbilities,
    ): array {
        return DB::transaction(function () use (
            $request,
            $apiDirectoryClientId,
            $clientIdentifier,
            $clientSecret,
            $requestedAbilities,
        ): array {
            $application = Application::query()
                ->where('api_directory_client_id', $apiDirectoryClientId)
                ->lockForUpdate()
                ->first();
            $credential = MachineCredential::query()
                ->where('application_id', $application?->id ?? 0)
                ->where('client_identifier', $clientIdentifier)
                ->lockForUpdate()
                ->first();

            $secretValid = $this->hasher->verify($clientSecret, $credential?->secret_hash);
            if (! $secretValid || $application === null || $credential === null) {
                throw new OAuthServerException('invalid_client', 401, 'client_authentication_failed');
            }

            if ($application->status !== ApplicationStatus::Active
                || ! $application->configuration_ready
                || ! $credential->isUsable()) {
                throw new OAuthServerException(
                    'invalid_client',
                    401,
                    'client_not_eligible',
                    $application->public_id,
                );
            }

            $allowedAbilities = $credential->abilities;
            $abilities = $requestedAbilities === [] ? $allowedAbilities : $requestedAbilities;
            if (array_diff($abilities, $allowedAbilities) !== []) {
                throw new OAuthServerException(
                    'invalid_scope',
                    400,
                    'scope_not_allowed',
                    $application->public_id,
                );
            }

            $ttl = max(60, (int) config('machine-auth.access_token_ttl_seconds'));
            $plainTextToken = 'bcaigw_at_'.rtrim(
                strtr(base64_encode(random_bytes(48)), '+/', '-_'),
                '=',
            );
            MachineAccessToken::create([
                'application_id' => $application->id,
                'machine_credential_id' => $credential->id,
                'token_hash' => hash('sha256', $plainTextToken),
                'abilities' => array_values($abilities),
                'credential_version' => $credential->version,
                'application_status_version' => $application->status_version,
                'expires_at' => now()->addSeconds($ttl),
            ]);

            $credential->forceFill([
                'last_used_at' => now(),
                'last_used_ip_hash' => $this->requestMetadata->ipHash($request),
            ])->save();

            $this->audit->record(
                $request,
                AuditEventType::MachineTokenIssued,
                AuditOutcome::Succeeded,
                context: [
                    'application_public_id' => $application->public_id,
                    'credential_public_id' => $credential->public_id,
                    'scope_count' => count($abilities),
                ],
            );

            return [
                'access_token' => $plainTextToken,
                'token_type' => 'Bearer',
                'expires_in' => $ttl,
                'scope' => implode(' ', $abilities),
            ];
        });
    }
}

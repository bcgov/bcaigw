<?php

namespace App\Services;

use App\Auth\Keycloak\KeycloakAuthenticationException;
use App\Auth\Keycloak\KeycloakIdentity;
use App\Enums\PortalRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class IdirUserProvisioner
{
    public function provision(KeycloakIdentity $identity): User
    {
        return DB::transaction(function () use ($identity): User {
            $normalizedGuid = Str::lower($identity->idirGuid);
            $subjectUser = User::query()
                ->where('keycloak_subject', $identity->subject)
                ->first();
            $guidUser = User::query()
                ->where('idir_user_guid', $normalizedGuid)
                ->first();

            if (($subjectUser && $guidUser && ! $subjectUser->is($guidUser))
                || ($subjectUser && $subjectUser->idir_user_guid !== $normalizedGuid)
                || ($guidUser && $guidUser->keycloak_subject !== $identity->subject)) {
                throw new KeycloakAuthenticationException('identity_conflict');
            }

            $user = $subjectUser ?? $guidUser ?? new User;

            if (! $user->exists) {
                $user->portal_role = PortalRole::ApplicationOwner;
                $user->keycloak_subject = $identity->subject;
            }

            $user->fill([
                'idir_user_guid' => $normalizedGuid,
                'idir_username' => Str::upper($identity->idirUsername),
                'name' => $identity->displayName,
                'first_name' => $identity->firstName,
                'last_name' => $identity->lastName,
                'email' => $identity->email ? Str::lower($identity->email) : null,
                'last_login_at' => now(),
            ]);
            $user->save();

            return $user;
        });
    }
}

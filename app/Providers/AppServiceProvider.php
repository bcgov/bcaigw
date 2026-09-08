<?php

namespace App\Providers;

use App\Auth\Keycloak\KeycloakClient;
use App\Auth\Keycloak\KeycloakOAuthClient;
use App\Contracts\BedrockRuntimeGateway;
use App\Contracts\ContentKeyProvider;
use App\Contracts\HostResolver;
use App\Contracts\MetricsStore;
use App\Contracts\PlatformSecretResolver;
use App\Contracts\QuotaCounterStore;
use App\Enums\ProviderType;
use App\Models\Application;
use App\Models\User;
use App\Policies\ApplicationPolicy;
use App\Services\AdapterRegistry;
use App\Services\AwsBedrockAdapter;
use App\Services\AwsSdkBedrockRuntimeGateway;
use App\Services\EnvironmentPlatformSecretResolver;
use App\Services\InMemoryMetricsStore;
use App\Services\InMemoryQuotaCounterStore;
use App\Services\NativeHostResolver;
use App\Services\OpenAiCompatibleAdapter;
use App\Services\PinnedHttpTransport;
use App\Services\PlatformContentKeyProvider;
use App\Services\RedisMetricsStore;
use App\Services\RedisQuotaCounterStore;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(KeycloakClient::class, KeycloakOAuthClient::class);
        $this->app->bind(HostResolver::class, NativeHostResolver::class);
        $this->app->bind(PlatformSecretResolver::class, EnvironmentPlatformSecretResolver::class);
        $this->app->bind(BedrockRuntimeGateway::class, AwsSdkBedrockRuntimeGateway::class);
        $this->app->singleton(ContentKeyProvider::class, PlatformContentKeyProvider::class);
        $this->app->singleton(
            MetricsStore::class,
            fn () => $this->app->runningUnitTests()
                ? new InMemoryMetricsStore
                : new RedisMetricsStore,
        );
        $this->app->singleton(
            QuotaCounterStore::class,
            fn () => $this->app->runningUnitTests()
                ? new InMemoryQuotaCounterStore
                : new RedisQuotaCounterStore,
        );
        $this->app->singleton(AdapterRegistry::class, function ($app): AdapterRegistry {
            $transport = $app->make(PinnedHttpTransport::class);

            return new AdapterRegistry([
                $app->make(AwsBedrockAdapter::class),
                new OpenAiCompatibleAdapter(ProviderType::AzureAiFoundry, $transport),
                new OpenAiCompatibleAdapter(ProviderType::Vllm, $transport),
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Application::class, ApplicationPolicy::class);
        Gate::define('access-portal', fn (User $user): bool => $user->portal_role !== null);
        Gate::define('access-admin', fn (User $user): bool => $user->isAdministrator());

        RateLimiter::for(
            'oauth-token',
            fn (Request $request): Limit => Limit::perMinute(
                max(1, (int) config('machine-auth.token_rate_limit_per_minute')),
            )->by('oauth-token:'.($request->ip() ?? 'unknown')),
        );
    }
}

<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BaselineTest extends TestCase
{
    public function test_home_page_renders_the_inertia_application(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Home')
                ->where('appName', 'BC AI Gateway')
            );
    }

    public function test_liveness_endpoint_is_available(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_api_status_endpoint_is_available(): void
    {
        $this->getJson('/api/status')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonStructure(['service', 'timestamp']);
    }
}

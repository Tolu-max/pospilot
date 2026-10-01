<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublicDemoTest extends TestCase
{
    public function test_read_only_showcase_is_available_without_sign_in(): void
    {
        $this->get('/demo')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Demo/Showcase')
            ->where('auth.user', null));
    }
}

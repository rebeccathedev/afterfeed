<?php

namespace Tests;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    protected string $apiToken;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $this->apiToken = 'af_'.Str::random(64);
        PersonalAccessToken::create(['user_id' => $this->user->id, 'name' => 'Tests', 'token' => hash('sha256', $this->apiToken)]);
        $this->withToken($this->apiToken);
    }
}

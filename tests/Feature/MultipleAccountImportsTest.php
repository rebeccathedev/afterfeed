<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\SocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultipleAccountImportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_platform_supports_multiple_accounts_and_each_account_supports_multiple_imports(): void
    {
        $first = SocialAccount::create(['platform' => 'x', 'external_id' => '101', 'handle' => '@first']);
        $second = SocialAccount::create(['platform' => 'x', 'external_id' => '202', 'handle' => '@second']);
        Archive::create(['social_account_id' => $first->id, 'label' => '2024 export', 'fingerprint' => 'first-2024']);
        Archive::create(['social_account_id' => $first->id, 'label' => '2025 export', 'fingerprint' => 'first-2025']);
        Archive::create(['social_account_id' => $second->id, 'label' => '2025 export', 'fingerprint' => 'second-2025']);

        $this->assertCount(2, $first->archives);
        $this->assertCount(1, $second->archives);
        $this->assertSame(2, SocialAccount::where('platform', 'x')->count());
    }
}

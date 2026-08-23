<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class MultiUserAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_routes_require_login_and_model_binding_is_tenant_scoped(): void
    {
        $mine = SocialAccount::create(['platform' => 'mastodon', 'external_id' => 'mine', 'handle' => '@mine']);
        Post::create(['social_account_id' => $mine->id, 'external_id' => 'mine', 'body' => 'My private memory']);

        $other = User::factory()->create();
        $this->actingAs($other);
        $theirs = SocialAccount::create(['platform' => 'mastodon', 'external_id' => 'theirs', 'handle' => '@theirs']);
        $theirPost = Post::create(['social_account_id' => $theirs->id, 'external_id' => 'theirs', 'body' => 'Someone else private memory']);

        $this->actingAs($this->user);
        $this->get('/')->assertOk()->assertSee('My private memory')->assertDontSee('Someone else private memory');
        $this->get(route('posts.show', $theirPost->id))->assertNotFound();
        Auth::logout();
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_api_tokens_only_expose_their_owners_archive(): void
    {
        $mine = SocialAccount::create(['platform' => 'twitter', 'external_id' => 'api-mine', 'handle' => '@mine']);
        Post::create(['social_account_id' => $mine->id, 'external_id' => 'mine', 'body' => 'Visible through my token']);

        $other = User::factory()->create();
        $this->actingAs($other);
        $theirs = SocialAccount::create(['platform' => 'twitter', 'external_id' => 'api-theirs', 'handle' => '@theirs']);
        $theirPost = Post::create(['social_account_id' => $theirs->id, 'external_id' => 'theirs', 'body' => 'Never visible through my token']);

        $this->withToken($this->apiToken)->getJson('/api/v1/posts')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertSee('Visible through my token')->assertDontSee('Never visible through my token');
        $this->withToken($this->apiToken)->getJson('/api/v1/posts/'.$theirPost->id)->assertNotFound();
        $this->withHeader('Authorization', '')->getJson('/api/v1/posts')->assertUnauthorized();
    }

    public function test_each_user_can_have_the_same_social_identity_and_setting_keys(): void
    {
        SocialAccount::create(['platform' => 'mastodon', 'external_id' => 'https://example/@same', 'handle' => '@same@example']);
        $this->put('/settings', ['timezone' => 'America/Denver', 'timeline_per_page' => 12]);

        $other = User::factory()->create();
        $this->actingAs($other);
        SocialAccount::create(['platform' => 'mastodon', 'external_id' => 'https://example/@same', 'handle' => '@same@example']);
        $this->put('/settings', ['timezone' => 'UTC', 'timeline_per_page' => 48]);

        $this->assertDatabaseCount('social_accounts', 2);
        $this->assertDatabaseHas('app_settings', ['user_id' => $this->user->id, 'key' => 'timezone', 'value' => '"America\/Denver"']);
        $this->assertDatabaseHas('app_settings', ['user_id' => $other->id, 'key' => 'timezone', 'value' => '"UTC"']);
    }
}

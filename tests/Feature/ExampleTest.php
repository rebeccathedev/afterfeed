<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Attachment;
use App\Models\DirectMessage;
use App\Models\Person;
use App\Models\Post;
use App\Models\PostAnnotation;
use App\Models\PostCollection;
use App\Models\SocialAccount;
use App\Services\DatabaseDialect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Phar;
use PharData;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use ZipArchive;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_the_archive_library_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertOk()->assertSee('Afterfeed')->assertSee('Your archives')->assertSee('Facebook')->assertSee('Timeline')->assertDontSee('>Library<', false);
    }

    public function test_the_timeline_accepts_media_and_post_type_filters(): void
    {
        $this->get('/?media=1')->assertOk()->assertSee('With media');
        $this->get('/?type=reply')->assertOk()->assertSee('Replies');
    }

    public function test_account_pages_hide_the_unified_intro_and_the_unified_view_has_no_profile_banner(): void
    {
        $account = SocialAccount::create([
            'platform' => 'instagram',
            'external_id' => 'layout-test',
            'handle' => '@layout-test',
            'display_name' => 'Layout Test',
            'header_path' => 'profile-media/layout/header.jpg',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Everything you shared')
            ->assertDontSee('profile-cover');

        $this->get('/?account='.$account->id)
            ->assertOk()
            ->assertDontSee('Everything you shared')
            ->assertDontSee('Your archives')
            ->assertSee('profile-cover');
    }

    public function test_the_timeline_can_be_filtered_by_year_and_month(): void
    {
        $account = SocialAccount::create(['platform' => 'livejournal', 'external_id' => 'dates', 'handle' => '@dates']);
        Post::create(['social_account_id' => $account->id, 'external_id' => 'jan', 'body' => 'January memory', 'posted_at' => '2020-01-15 12:00:00']);
        Post::create(['social_account_id' => $account->id, 'external_id' => 'feb', 'body' => 'February memory', 'posted_at' => '2020-02-15 12:00:00']);
        Post::create(['social_account_id' => $account->id, 'external_id' => 'later', 'body' => 'Later memory', 'posted_at' => '2021-01-15 12:00:00']);

        $this->get('/?account='.$account->id.'&year=2020&month=1')
            ->assertOk()
            ->assertSee('January memory')
            ->assertDontSee('February memory')
            ->assertDontSee('Later memory');
    }

    public function test_search_finds_timeline_content_and_respects_the_account_filter(): void
    {
        $first = SocialAccount::create(['platform' => 'livejournal', 'external_id' => 'search-one', 'handle' => '@search-one']);
        $second = SocialAccount::create(['platform' => 'reddit', 'external_id' => 'search-two', 'handle' => '@search-two']);
        Post::create(['social_account_id' => $first->id, 'external_id' => 'match', 'body' => 'A very specific marmalade memory', 'posted_at' => '2020-01-15 12:00:00']);
        Post::create(['social_account_id' => $second->id, 'external_id' => 'other', 'body' => 'Another marmalade mention', 'posted_at' => '2020-01-16 12:00:00']);

        $this->get('/?account='.$first->id.'&q=marmalade')
            ->assertOk()
            ->assertSee('A very specific marmalade memory')
            ->assertDontSee('Another marmalade mention');
    }

    public function test_post_bodies_link_urls_and_searchable_hashtags_safely(): void
    {
        $account = SocialAccount::create(['platform' => 'mastodon', 'external_id' => 'body-links', 'handle' => '@body-links']);
        $post = Post::create([
            'social_account_id' => $account->id,
            'external_id' => 'linked-body',
            'body' => 'Read https://example.com/archive?q=memory. More thoughts at #ArchiveLife <script>alert("no")</script>',
            'posted_at' => '2024-06-01 12:00:00',
        ]);
        Post::create(['social_account_id' => $account->id, 'external_id' => 'unrelated-body', 'body' => 'Nothing relevant here', 'posted_at' => '2024-06-02 12:00:00']);

        $this->get(route('posts.show', $post))
            ->assertOk()
            ->assertSee('class="post-body-link" href="https://example.com/archive?q=memory"', false)
            ->assertSee('class="post-hashtag" href="'.route('archives.index', ['q' => '#ArchiveLife']).'#timeline"', false)
            ->assertSee('&lt;script&gt;alert(&quot;no&quot;)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert', false);

        $this->get('/?q='.urlencode('#ArchiveLife'))
            ->assertOk()
            ->assertSee('More thoughts at')
            ->assertDontSee('Nothing relevant here');
    }

    public function test_mastodon_boosts_and_metadata_hashtags_have_useful_actions(): void
    {
        $account = SocialAccount::create(['platform' => 'mastodon', 'external_id' => 'boosts', 'handle' => '@me@rebeccapeck.org', 'display_name' => 'Archive Keeper']);
        $boost = Post::create([
            'social_account_id' => $account->id,
            'external_id' => 'boost-123',
            'type' => 'boost',
            'url' => 'https://social.example/users/gardener/statuses/123',
            'posted_at' => '2024-06-01 12:00:00',
            'metadata' => ['activity_id' => 'https://archive.test/activity/123'],
        ]);
        $tagged = Post::create([
            'social_account_id' => $account->id,
            'external_id' => 'tagged-123',
            'type' => 'post',
            'body' => 'A metadata-tagged memory',
            'posted_at' => '2024-06-02 12:00:00',
            'metadata' => ['tags' => [['type' => 'Hashtag', 'name' => 'GardenLife']]],
        ]);

        $this->get(route('posts.show', $boost))
            ->assertOk()
            ->assertSee('Boosted @me@rebeccapeck.org')
            ->assertSee('BOOSTED POST · SOCIAL.EXAMPLE')
            ->assertSee('View post')
            ->assertDontSee('Original ↗');

        $this->get(route('posts.show', $tagged))
            ->assertOk()
            ->assertSee('href="'.route('archives.index', ['q' => '#GardenLife']).'#timeline"', false);

        $this->get('/?q='.urlencode('#GardenLife'))
            ->assertOk()
            ->assertSee('A metadata-tagged memory')
            ->assertDontSee('Boosted @me@rebeccapeck.org');
    }

    public function test_mastodon_boosts_render_an_imported_source_post_inline(): void
    {
        $booster = SocialAccount::create(['platform' => 'mastodon', 'external_id' => 'booster', 'handle' => '@me@rebeccapeck.org', 'display_name' => 'Archive Keeper']);
        $author = SocialAccount::create(['platform' => 'mastodon', 'external_id' => 'gardener', 'handle' => '@me@rebeccapeck.org', 'display_name' => 'Garden Friend']);
        $source = Post::create([
            'social_account_id' => $author->id,
            'external_id' => '456',
            'type' => 'post',
            'body' => 'The first seedlings are finally here. #GardenLife',
            'url' => 'https://social.example/@gardener/456',
            'posted_at' => '2024-05-31 11:00:00',
            'metadata' => ['tags' => [['type' => 'Hashtag', 'name' => 'GardenLife']]],
        ]);
        $boost = Post::create([
            'social_account_id' => $booster->id,
            'external_id' => 'boost-456',
            'type' => 'boost',
            'url' => 'https://social.example/users/gardener/statuses/456',
            'posted_at' => '2024-06-01 12:00:00',
        ]);

        $this->get(route('posts.show', $boost))
            ->assertOk()
            ->assertSee('Boosted from @me@rebeccapeck.org')
            ->assertSee('Garden Friend')
            ->assertSee('The first seedlings are finally here.')
            ->assertSee('#GardenLife')
            ->assertSee('Open original boosted post')
            ->assertSee('class="post-hashtag"', false)
            ->assertDontSee('BOOSTED POST · SOCIAL.EXAMPLE');
    }

    public function test_a_zip_can_be_uploaded_in_chunks_and_imported(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'afterfeed-upload-');
        $zip = new ZipArchive;
        $zip->open($source, ZipArchive::OVERWRITE);
        $zip->addFromString('uploadjournal/20200115_1200', "Date:      2020-01-15 12:00\nSubject:   Uploaded memory\nTags:      testing\nPicture:   smile\nItemID:    42\n\nThe uploaded archive works.");
        $zip->close();
        $uploadId = (string) Str::uuid();
        $contents = File::get($source);
        $splitAt = (int) ceil(strlen($contents) / 2);

        $this->call(
            'POST',
            '/imports/chunk?'.http_build_query(['upload_id' => $uploadId, 'filename' => 'uploadjournal.zip', 'index' => 0, 'total' => 2]),
            server: ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'],
            content: substr($contents, 0, $splitAt),
        )->assertOk()->assertJson(['received' => 1, 'total' => 2]);

        $response = $this->call(
            'POST',
            '/imports/chunk?'.http_build_query(['upload_id' => $uploadId, 'filename' => 'uploadjournal.zip', 'index' => 1, 'total' => 2]),
            server: ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'],
            content: substr($contents, $splitAt),
        );

        $response->assertOk()->assertJson(['complete' => true]);
        $this->assertDatabaseHas('social_accounts', ['platform' => 'livejournal', 'external_id' => 'uploadjournal']);
        $this->assertDatabaseHas('posts', ['external_id' => '42', 'body' => "Uploaded memory\n\nThe uploaded archive works."]);
        File::delete($source);
        File::delete(storage_path('app/private/imports/uploadjournal-'.$uploadId.'.zip'));
        File::delete(storage_path('app/private/imports/incoming/'.$uploadId.'.json'));
        File::delete(storage_path('app/private/imports/incoming/'.$uploadId.'.lock'));
    }

    public function test_a_tar_gz_can_be_uploaded_and_imported(): void
    {
        $uploadId = (string) Str::uuid();
        $tarPath = sys_get_temp_dir().'/afterfeed-'.$uploadId.'.tar';
        $tar = new PharData($tarPath);
        $tar->addFromString('targzjournal/20210115_1200', "Date:      2021-01-15 12:00\nSubject:   Compressed memory\nTags:      testing\nPicture:   smile\nItemID:    84\n\nThe tar.gz archive works.");
        $tar->compress(Phar::GZ);
        unset($tar);
        $contents = File::get($tarPath.'.gz');

        $this->call(
            'POST',
            '/imports/chunk?'.http_build_query(['upload_id' => $uploadId, 'filename' => 'targzjournal.tar.gz', 'index' => 0, 'total' => 1]),
            server: ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'],
            content: $contents,
        )->assertOk()->assertJson(['complete' => true]);

        $this->assertDatabaseHas('social_accounts', ['platform' => 'livejournal', 'external_id' => 'targzjournal']);
        $this->assertDatabaseHas('posts', ['external_id' => '84', 'body' => "Compressed memory\n\nThe tar.gz archive works."]);
        File::delete([$tarPath, $tarPath.'.gz', storage_path('app/private/imports/targzjournal-'.$uploadId.'.zip')]);
        File::delete(storage_path('app/private/imports/incoming/'.$uploadId.'.json'));
        File::delete(storage_path('app/private/imports/incoming/'.$uploadId.'.lock'));
    }

    public function test_the_media_gallery_shows_and_filters_archived_media(): void
    {
        $first = SocialAccount::create(['platform' => 'instagram', 'external_id' => 'media-one', 'handle' => '@media-one']);
        $second = SocialAccount::create(['platform' => 'twitter', 'external_id' => 'media-two', 'handle' => '@media-two']);
        $photoPost = Post::create(['social_account_id' => $first->id, 'external_id' => 'photo', 'posted_at' => '2022-01-01']);
        $videoPost = Post::create(['social_account_id' => $second->id, 'external_id' => 'video', 'posted_at' => '2023-01-01']);
        Attachment::create(['post_id' => $photoPost->id, 'type' => 'image', 'path' => 'media/photo.jpg', 'alt_text' => 'Gallery photo']);
        Attachment::create(['post_id' => $videoPost->id, 'type' => 'video', 'path' => 'media/video.mp4']);

        $this->get('/media?account='.$first->id.'&type=image')
            ->assertOk()
            ->assertSee('Gallery photo')
            ->assertSee('data-lightbox', false)
            ->assertDontSee('media/video.mp4');
    }

    public function test_on_this_day_and_calendar_surface_archived_memories(): void
    {
        $account = SocialAccount::create(['platform' => 'twitter', 'external_id' => 'memories', 'handle' => '@memories']);
        Post::create(['social_account_id' => $account->id, 'external_id' => 'memory', 'body' => 'A midsummer memory', 'posted_at' => '2012-07-14 10:00:00']);

        $this->get('/on-this-day?month=7&day=14')->assertOk()->assertSee('A midsummer memory')->assertSee('2012');
        $this->get('/calendar?year=2012')->assertOk()->assertSee('2012 calendar')->assertSee('July 14 · 1 posts');
    }

    public function test_paginated_discovery_pages_use_the_compact_afterfeed_pager(): void
    {
        $account = SocialAccount::create(['platform' => 'twitter', 'external_id' => 'pager', 'handle' => '@pager']);
        foreach (range(1, 21) as $index) {
            Post::create(['social_account_id' => $account->id, 'external_id' => 'page-'.$index, 'body' => 'Paginated memory '.$index, 'posted_at' => sprintf('20%02d-07-14 12:00:00', $index)]);
        }

        $this->get('/on-this-day?month=7&day=14')
            ->assertOk()
            ->assertSee('class="pagination"', false)
            ->assertSee('Next →')
            ->assertDontSee('<svg', false);

        $this->get('/on-this-day?month=7&day=14&page=2')
            ->assertOk()
            ->assertSee('← Previous');
    }

    public function test_conversations_reconstruct_linked_replies(): void
    {
        $account = SocialAccount::create(['platform' => 'reddit', 'external_id' => 'threaded', 'handle' => 'u/threaded']);
        Post::create(['social_account_id' => $account->id, 'external_id' => 'root', 'body' => 'Original thought', 'posted_at' => '2020-01-01']);
        Post::create(['social_account_id' => $account->id, 'external_id' => 'reply', 'reply_to_external_id' => 'root', 'body' => 'A linked response', 'posted_at' => '2020-01-02']);

        $this->get('/conversations')->assertOk()->assertSee('Original thought')->assertSee('A linked response');
    }

    public function test_posts_can_be_annotated_collected_and_hidden(): void
    {
        $account = SocialAccount::create(['platform' => 'mastodon', 'external_id' => 'curation', 'handle' => '@curation']);
        $post = Post::create(['social_account_id' => $account->id, 'external_id' => 'keepsake', 'body' => 'A personal keepsake', 'posted_at' => '2020-01-01']);
        $this->post('/collections', ['name' => 'Favorites', 'description' => 'Things worth keeping', 'color' => '#375d4a'])->assertRedirect();
        $collection = PostCollection::firstOrFail();
        $this->post(route('collections.posts.store', [$collection, $post]))->assertRedirect();
        $this->put(route('posts.annotation.update', $post), ['note' => 'Remember this', 'tags' => 'personal, milestone', 'favorite' => 1, 'hidden' => 1])->assertRedirect();

        $this->assertTrue($collection->posts()->whereKey($post->id)->exists());
        $this->assertDatabaseHas('post_annotations', ['post_id' => $post->id, 'note' => 'Remember this', 'favorite' => 1, 'hidden' => 1]);
        $this->get('/')->assertDontSee('A personal keepsake');
        $this->get('/collections')->assertOk()->assertSee('Favorites')->assertSee('A personal keepsake');
    }

    public function test_post_details_include_a_local_share_card_generator(): void
    {
        $account = SocialAccount::create(['platform' => 'instagram', 'external_id' => 'shareable', 'handle' => '@shareable', 'display_name' => 'Archive Keeper']);
        $post = Post::create(['social_account_id' => $account->id, 'external_id' => 'old-memory', 'body' => 'A memory worth sharing again', 'posted_at' => '2014-05-06 12:00:00']);
        Attachment::create(['post_id' => $post->id, 'type' => 'image', 'path' => 'share/memory.jpg']);

        $this->get(route('posts.show', $post))
            ->assertOk()
            ->assertSee('Share as image')
            ->assertSee('Download PNG')
            ->assertSee('Copy image')
            ->assertSee('1080 × 1350')
            ->assertSee('share/memory.jpg');
    }

    public function test_shared_links_and_platform_context_are_distinct_from_original_posts(): void
    {
        $facebook = SocialAccount::create(['platform' => 'facebook', 'external_id' => 'links', 'handle' => '@links', 'display_name' => 'Archive Keeper']);
        $facebookPost = Post::create([
            'social_account_id' => $facebook->id,
            'external_id' => 'shared-link',
            'body' => 'This article still holds up.',
            'url' => 'https://example.com/an-old-article',
            'posted_at' => '2013-04-05 12:00:00',
            'metadata' => ['title' => 'Archive Keeper shared a link.', 'external_url' => 'https://example.com/an-old-article', 'external_name' => 'An old article', 'location' => ['name' => 'Denver', 'latitude' => 39.7392, 'longitude' => -104.9903]],
        ]);

        $this->get(route('posts.show', $facebookPost))
            ->assertOk()
            ->assertSee('SHARED LINK · EXAMPLE.COM')
            ->assertSee('An old article')
            ->assertSee('Shared a link.')
            ->assertSee('⌖ Denver')
            ->assertSee('Load map')
            ->assertDontSee('Original ↗');

        $this->getJson('/api/v1/posts/'.$facebookPost->id)
            ->assertOk()
            ->assertJsonPath('data.original_url', null)
            ->assertJsonPath('data.shared_url', 'https://example.com/an-old-article');

        $reddit = SocialAccount::create(['platform' => 'reddit', 'external_id' => 'reddit-links', 'handle' => 'u/archive']);
        $redditPost = Post::create([
            'social_account_id' => $reddit->id,
            'external_id' => 'reddit-link',
            'body' => 'A useful submission',
            'url' => 'https://reddit.com/r/archives/comments/abc/useful/',
            'posted_at' => '2014-05-06 12:00:00',
            'metadata' => ['subreddit' => 'archives', 'external_url' => 'https://example.org/source'],
        ]);

        $this->get(route('posts.show', $redditPost))
            ->assertOk()
            ->assertSee('r/archives')
            ->assertSee('SHARED LINK · EXAMPLE.ORG')
            ->assertSee('Original ↗');
    }

    public function test_the_map_plots_privately_pinned_posts(): void
    {
        $account = SocialAccount::create(['platform' => 'facebook', 'external_id' => 'mapped', 'handle' => '@mapped']);
        $post = Post::create(['social_account_id' => $account->id, 'external_id' => 'trip', 'body' => 'A mountain trip', 'posted_at' => '2022-06-01']);
        PostAnnotation::create(['post_id' => $post->id, 'place_name' => 'Rocky Mountain National Park', 'latitude' => 40.3428, 'longitude' => -105.6836]);
        Attachment::create(['post_id' => $post->id, 'type' => 'image', 'path' => 'map/mountain.jpg', 'alt_text' => 'Mountain view']);

        $this->get('/map?photos=1')->assertOk()->assertSee('Rocky Mountain National Park')->assertSee('archive-map')->assertSee('Mountain view')->assertSee('1 photos');
    }

    public function test_the_statistics_dashboard_summarizes_personal_trends(): void
    {
        $account = SocialAccount::create(['platform' => 'twitter', 'external_id' => 'trends', 'handle' => '@trends']);
        $post = Post::create(['social_account_id' => $account->id, 'external_id' => 'trend-post', 'body' => 'Photography photography memories', 'posted_at' => '2019-04-05 14:00:00', 'metadata' => ['subreddit' => 'photography', 'entities' => ['hashtags' => [['text' => 'ArchiveLife']], 'user_mentions' => [['screen_name' => 'friend']]]]]);
        Attachment::create(['post_id' => $post->id, 'type' => 'image', 'path' => 'statistics/photo.jpg']);
        $this->artisan('archive:people')->assertSuccessful();

        $this->get('/statistics')->assertOk()->assertSee('Personal trends')->assertSee('Most active years')->assertSee('photography')->assertSee('archivelife')->assertSee('@friend');
    }

    public function test_the_read_only_api_searches_and_serializes_posts(): void
    {
        $account = SocialAccount::create(['platform' => 'twitter', 'external_id' => 'api', 'handle' => '@api']);
        $post = Post::create(['social_account_id' => $account->id, 'external_id' => 'api-post', 'body' => 'A searchable API memory', 'posted_at' => '2024-03-04 12:00:00']);
        Attachment::create(['post_id' => $post->id, 'type' => 'image', 'path' => 'api/photo.jpg']);

        $this->getJson('/api/v1/posts?q=searchable&has_media=1')
            ->assertOk()
            ->assertJsonPath('data.0.external_id', 'api-post')
            ->assertJsonPath('data.0.account.platform', 'twitter')
            ->assertJsonPath('data.0.attachments.0.path', 'api/photo.jpg')
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/posts/'.$post->id)->assertOk()->assertJsonPath('data.body', 'A searchable API memory');
        $this->getJson('/api/v1/accounts')->assertOk()->assertJsonPath('data.0.posts_count', 1);
        $this->getJson('/api/v1/statistics')->assertOk()->assertJsonPath('data.posts', 1);
    }

    public function test_the_mcp_stdio_server_negotiates_and_lists_tools(): void
    {
        $process = new Process([PHP_BINARY, base_path('artisan'), 'afterfeed:mcp'], base_path());
        $process->setInput(
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']])."\n".
            json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => (object) []])."\n"
        );
        $process->mustRun();
        $responses = collect(explode("\n", trim($process->getOutput())))->map(fn (string $line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR));

        $this->assertSame('afterfeed', $responses[0]['result']['serverInfo']['name']);
        $this->assertSame(['search_posts', 'get_post', 'list_accounts', 'archive_statistics'], array_column($responses[1]['result']['tools'], 'name'));
        $this->assertSame('', $process->getErrorOutput());
    }

    public function test_the_people_directory_builds_shared_history(): void
    {
        $account = SocialAccount::create(['platform' => 'twitter', 'external_id' => 'people-owner', 'handle' => '@owner']);
        Post::create(['social_account_id' => $account->id, 'external_id' => 'hello-friend', 'body' => '@friend hello again', 'posted_at' => '2023-04-05', 'metadata' => ['entities' => ['user_mentions' => [['screen_name' => 'friend', 'name' => 'Friendly Person']]]]]);
        DirectMessage::create(['social_account_id' => $account->id, 'external_id' => 'dm-friend', 'direction' => 'received', 'sender' => '@friend', 'recipient' => '@owner', 'body' => 'A private hello', 'sent_at' => '2023-04-06']);

        $this->artisan('archive:people')->assertSuccessful();
        $person = Person::where('identifier', '@friend')->firstOrFail();

        $this->get('/people')->assertOk()->assertSee('Friendly Person')->assertSee('1 messages', false)->assertSee('mentions/replies');
        $this->get('/people/'.$person->id)->assertOk()->assertSee('SHARED HISTORY')->assertSee('A private hello')->assertSee('@friend hello again');
    }

    public function test_collections_export_as_private_html_json_zip_and_pdf(): void
    {
        $account = SocialAccount::create(['platform' => 'instagram', 'external_id' => 'export-owner', 'handle' => '@export-owner', 'display_name' => 'Export Owner']);
        $post = Post::create(['social_account_id' => $account->id, 'external_id' => 'export-memory', 'body' => 'A portable memory', 'url' => 'https://example.test/private', 'posted_at' => '2024-05-06', 'metadata' => ['private_source' => 'remove me']]);
        $collection = PostCollection::create(['name' => 'Portable memories', 'description' => 'A small memory book', 'color' => '#375d4a']);
        $collection->posts()->attach($post);

        $options = ['include_text' => 1, 'include_dates' => 1, 'include_identity' => 1, 'strip_metadata' => 1];
        $this->post(route('collections.export.store', $collection), ['format' => 'html'] + $options)->assertOk()->assertHeader('content-disposition', 'attachment; filename="portable-memories.html"')->assertSee('A portable memory')->assertDontSee('private_source')->assertDontSee('example.test/private');
        $this->post(route('collections.export.store', $collection), ['format' => 'json'] + $options)->assertOk()->assertJsonPath('posts.0.body', 'A portable memory')->assertJsonMissing(['source_metadata' => ['private_source' => 'remove me']]);

        $zipResponse = $this->post(route('collections.export.store', $collection), ['format' => 'zip'] + $options)->assertOk()->baseResponse;
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipResponse->getFile()->getPathname()) === true);
        $this->assertNotFalse($zip->locateName('index.html'));
        $this->assertNotFalse($zip->locateName('archive.json'));
        $zip->close();

        $pdfResponse = $this->post(route('collections.export.store', $collection), ['format' => 'pdf'] + $options)->assertOk()->baseResponse;
        $this->assertStringStartsWith('%PDF-', File::get($pdfResponse->getFile()->getPathname()));
    }

    public function test_database_dialects_compile_backend_native_expressions(): void
    {
        $mysql = new DatabaseDialect('mysql');
        $this->assertSame('year(posts.posted_at)', $mysql->year('posts.posted_at'));
        $this->assertSame("json_unquote(json_extract(metadata, '$.coordinates.coordinates[0]'))", $mysql->jsonText('metadata', 'coordinates.coordinates.0'));
        $this->assertSame('dayofweek(posted_at) - 1', $mysql->weekday('posted_at'));
        $this->assertStringContainsString('match(posts.body) against', $mysql->searchPosts(Post::query(), 'memory')->toSql());

        $postgres = new DatabaseDialect('pgsql');
        $this->assertSame('cast(extract(year from posted_at) as integer)', $postgres->year('posted_at'));
        $this->assertSame("metadata #>> '{place,coordinate,latitude}'", $postgres->jsonText('metadata', 'place.coordinate.latitude'));
        $this->assertSame("to_char(posted_at, 'MM-DD')", $postgres->monthDay('posted_at'));
        $this->assertStringContainsString("to_tsvector('simple'", $postgres->searchPosts(Post::query(), 'memory')->toSql());
    }

    public function test_remote_mcp_requires_auth_and_supports_streamable_http(): void
    {
        $token = $this->apiToken;
        config(['services.afterfeed_mcp.allowed_origins' => 'https://client.example']);
        $initialize = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']];

        $this->withHeader('Authorization', '')->postJson('/api/mcp', $initialize)->assertUnauthorized();
        $this->withToken($token)->withHeader('Origin', 'https://attacker.example')->postJson('/api/mcp', $initialize)->assertForbidden();
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ORIGIN' => 'https://client.example', 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'CONTENT_TYPE' => 'application/json'];
        $response = $this->call('POST', '/api/mcp', server: $server, content: json_encode($initialize));
        $response->assertOk()->assertHeader('MCP-Protocol-Version', '2025-06-18')->assertJsonPath('result.serverInfo.name', 'afterfeed');
        $this->call('POST', '/api/mcp', server: $server, content: json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']))->assertStatus(202);
        $this->call('GET', '/api/mcp', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ORIGIN' => 'https://client.example'])->assertStatus(405)->assertHeader('Allow', 'POST');
    }

    public function test_settings_persist_display_and_export_privacy_preferences(): void
    {
        $this->get('/settings')->assertOk()->assertSee('Export privacy')->assertSee('Remote MCP');
        $this->put('/settings', ['timezone' => 'America/Denver', 'timeline_per_page' => 12, 'default_export_include_links' => 1])
            ->assertRedirect()
            ->assertSessionHas('status', 'Settings saved.');
        $this->assertDatabaseHas('app_settings', ['key' => 'timezone', 'value' => '"America\/Denver"']);
        $this->assertDatabaseHas('app_settings', ['key' => 'timeline_per_page', 'value' => '12']);

        $collection = PostCollection::create(['name' => 'Settings export', 'color' => '#375d4a']);
        $this->get(route('collections.export.create', $collection))->assertOk()->assertSee('name="include_links" value="1" checked', false)->assertSee('name="strip_metadata" value="1"', false)->assertDontSee('name="strip_metadata" value="1" checked', false);
    }

    public function test_deadname_privacy_filters_pages_and_api_without_hiding_settings_values(): void
    {
        $account = SocialAccount::create(['platform' => 'facebook', 'external_id' => 'privacy', 'handle' => '@privacy', 'display_name' => 'Old Name']);
        Post::create(['social_account_id' => $account->id, 'external_id' => 'privacy-post', 'type' => 'post', 'body' => 'OLD NAME met Ancient Fang, but Old Namespace is unrelated.', 'posted_at' => now()]);
        AppSetting::create(['key' => 'privacy_hide_deadnames', 'value' => true]);
        AppSetting::create(['key' => 'privacy_name_mappings', 'value' => [['from' => 'Old Name', 'to' => 'Rebecca'], ['from' => 'Ancient Fang', 'to' => 'New Fang']]]);

        $this->get('/')->assertOk()->assertSee('Rebecca met New Fang')->assertDontSee('OLD NAME met Ancient Fang')->assertSee('Old Namespace');
        $this->get('/api/v1/posts')->assertOk()->assertJsonMissing(['body' => 'OLD NAME met Ancient Fang, but Old Namespace is unrelated.'])->assertSee('Rebecca met New Fang');
        $this->get('/settings')->assertOk()->assertSee('Old Name =&gt; Rebecca', false)->assertSee('Ancient Fang =&gt; New Fang', false);
    }
}

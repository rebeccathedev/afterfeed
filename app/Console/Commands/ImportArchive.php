<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Import\FacebookArchiveImporter;
use App\Services\Import\GooglePlusArchiveImporter;
use App\Services\Import\InstagramArchiveImporter;
use App\Services\Import\LiveJournalArchiveImporter;
use App\Services\Import\MastodonArchiveImporter;
use App\Services\Import\RedditArchiveImporter;
use App\Services\Import\TwitterArchiveImporter;
use App\Services\PeopleIndexer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Throwable;

class ImportArchive extends Command
{
    protected $signature = 'archive:import {path : Path to a social archive ZIP} {--user= : User ID or email that owns the import}';

    protected $description = 'Import a social media archive';

    public function handle(TwitterArchiveImporter $twitter, MastodonArchiveImporter $mastodon, FacebookArchiveImporter $facebook, GooglePlusArchiveImporter $googlePlus, RedditArchiveImporter $reddit, InstagramArchiveImporter $instagram, LiveJournalArchiveImporter $liveJournal, PeopleIndexer $people): int
    {
        try {
            $owner = $this->option('user');
            $user = $owner ? User::query()->where('id', $owner)->orWhere('email', $owner)->first() : (User::count() === 1 ? User::first() : null);
            if (! $user) {
                throw new \InvalidArgumentException('Choose an import owner with --user=<id-or-email>.');
            }
            Auth::login($user);
            $zip = new \ZipArchive;
            if ($zip->open($this->argument('path')) !== true) {
                throw new \InvalidArgumentException('The file is not a readable ZIP archive.');
            }
            $isMastodon = $zip->locateName('actor.json') !== false && $zip->locateName('outbox.json') !== false;
            $isFacebook = $zip->locateName('personal_information/profile_information/profile_information.json') !== false;
            $isReddit = $zip->locateName('posts.csv') !== false && $zip->locateName('comments.csv') !== false;
            $isInstagram = $zip->locateName('personal_information/personal_information/personal_information.json') !== false && $zip->locateName('your_instagram_activity/content/posts_1.json') !== false;
            $isGooglePlus = false;
            $isLiveJournal = false;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (preg_match('#^Takeout/Google\+ Stream/Posts/.+\.html$#', $name)) {
                    $isGooglePlus = true;
                }
                if ($i < 100 && preg_match('#^[^/]+/\d{8}_\d{4}$#', $name)) {
                    $isLiveJournal = true;
                }
            }
            $zip->close();
            $importer = $isGooglePlus ? $googlePlus : ($isLiveJournal ? $liveJournal : ($isInstagram ? $instagram : ($isFacebook ? $facebook : ($isMastodon ? $mastodon : ($isReddit ? $reddit : $twitter)))));
            $result = $importer->import($this->argument('path'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $account = $result['archive']->socialAccount;
        $action = $result['created'] ? 'Imported' : 'Refreshed';
        $people->rebuild();
        $this->info("{$action} {$result['posts']} posts for {$account->handle}.");

        return self::SUCCESS;
    }
}

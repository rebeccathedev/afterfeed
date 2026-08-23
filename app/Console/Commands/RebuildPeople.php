<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PeopleIndexer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

class RebuildPeople extends Command
{
    protected $signature = 'archive:people {--user= : User ID or email to index}';

    protected $description = 'Rebuild the recurring-people and interaction index';

    public function handle(PeopleIndexer $indexer): int
    {
        $owner = $this->option('user');
        $user = $owner ? User::query()->where('id', $owner)->orWhere('email', $owner)->first() : (Auth::user() ?: (User::count() === 1 ? User::first() : null));
        if (! $user) {
            $this->error('Choose an owner with --user=<id-or-email>.');

            return self::FAILURE;
        }
        Auth::login($user);
        $result = $indexer->rebuild();
        $this->info("Indexed {$result['people']} people from {$result['interactions']} interactions.");

        return self::SUCCESS;
    }
}

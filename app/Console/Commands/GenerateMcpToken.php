<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateMcpToken extends Command
{
    protected $signature = 'afterfeed:mcp-token {user : User ID or email} {--name=CLI : Token name}';

    protected $description = 'Generate a secure bearer token for remote MCP access';

    public function handle(): int
    {
        $owner = $this->argument('user');
        $user = User::query()->where('id', $owner)->orWhere('email', $owner)->first();
        if (! $user) {
            $this->error('User not found.');

            return self::FAILURE;
        }
        $plain = 'af_'.Str::random(64);
        $user->tokens()->create(['name' => $this->option('name'), 'token' => hash('sha256', $plain)]);
        $this->warn('Copy this token now; it will not be shown again:');
        $this->line($plain);

        return self::SUCCESS;
    }
}

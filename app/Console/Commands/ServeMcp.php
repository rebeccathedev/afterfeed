<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\McpProtocol;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ServeMcp extends Command
{
    protected $signature = 'afterfeed:mcp {--user= : User ID or email whose archive is exposed}';

    protected $description = 'Serve Afterfeed archive tools over MCP stdio';

    public function handle(McpProtocol $protocol): int
    {
        if (Schema::hasTable('users')) {
            $owner = $this->option('user');
            $user = $owner ? User::query()->where('id', $owner)->orWhere('email', $owner)->first() : (User::count() === 1 ? User::first() : null);
            if (! $user) {
                $this->error('Choose an MCP owner with --user=<id-or-email>.');

                return self::FAILURE;
            }
            Auth::login($user);
        }
        while (($line = fgets(STDIN)) !== false) {
            if (($line = trim($line)) === '') {
                continue;
            }
            try {
                $request = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $response = $protocol->dispatch($request);
            } catch (Throwable $exception) {
                $response = $protocol->error(null, -32700, $exception->getMessage());
            }
            if ($response !== null) {
                fwrite(STDOUT, json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
                fflush(STDOUT);
            }
        }

        return self::SUCCESS;
    }
}

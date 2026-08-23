<?php

namespace App\Services;

use App\Models\Post;
use Throwable;

class McpProtocol
{
    public const VERSION = '2025-06-18';

    public function __construct(private readonly ArchiveAccessService $archives, private readonly PrivacyFilter $privacy) {}

    public function dispatch(array $request): ?array
    {
        if (! array_key_exists('id', $request)) {
            return null;
        }
        $id = $request['id'];

        return match ($request['method'] ?? '') {
            'initialize' => $this->initialize($id, $request['params'] ?? []),
            'ping' => $this->result($id, (object) []),
            'tools/list' => $this->result($id, ['tools' => $this->tools()]),
            'tools/call' => $this->callTool($id, $request['params'] ?? []),
            default => $this->error($id, -32601, 'Method not found'),
        };
    }

    public function error(string|int|null $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    private function initialize(string|int|null $id, array $params): array
    {
        $requested = $params['protocolVersion'] ?? null;
        $version = in_array($requested, [self::VERSION, '2025-03-26'], true) ? $requested : self::VERSION;

        return $this->result($id, ['protocolVersion' => $version, 'capabilities' => ['tools' => ['listChanged' => false]], 'serverInfo' => ['name' => 'afterfeed', 'version' => '1.1.0']]);
    }

    private function callTool(string|int|null $id, array $params): array
    {
        $name = $params['name'] ?? '';
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        try {
            $data = match ($name) {
                'list_accounts' => $this->archives->accounts(),
                'archive_statistics' => $this->archives->statistics(),
                'get_post' => $this->archives->post(Post::whereDoesntHave('annotation', fn ($query) => $query->where('hidden', true))->findOrFail((int) ($arguments['id'] ?? 0))),
                'search_posts' => $this->search($arguments),
                default => throw new \InvalidArgumentException("Unknown tool: {$name}"),
            };

            $data = $this->privacy->apply($data);

            return $this->result($id, ['content' => [['type' => 'text', 'text' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]], 'structuredContent' => ['result' => $data], 'isError' => false]);
        } catch (Throwable $exception) {
            return $this->result($id, ['content' => [['type' => 'text', 'text' => $exception->getMessage()]], 'isError' => true]);
        }
    }

    private function search(array $arguments): array
    {
        $arguments['per_page'] = min(max((int) ($arguments['limit'] ?? 25), 1), 100);
        $arguments['page'] = 1;
        $posts = $this->archives->posts($arguments);

        return ['posts' => collect($posts->items())->map(fn (Post $post) => $this->archives->serializePost($post))->all(), 'total' => $posts->total(), 'returned' => $posts->count()];
    }

    private function tools(): array
    {
        return [
            ['name' => 'search_posts', 'description' => 'Search and filter visible posts in the private social archive.', 'inputSchema' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string', 'description' => 'Exact phrase to search for.'], 'account_id' => ['type' => 'integer'], 'platform' => ['type' => 'string'], 'year' => ['type' => 'integer'], 'month' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12], 'has_media' => ['type' => 'boolean'], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25]], 'additionalProperties' => false]],
            ['name' => 'get_post', 'description' => 'Get one visible archived post, including media, annotations, and collections.', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id'], 'additionalProperties' => false]],
            ['name' => 'list_accounts', 'description' => 'List imported social accounts and their post/import counts.', 'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false]],
            ['name' => 'archive_statistics', 'description' => 'Get archive totals, date range, and activity grouped by platform and year.', 'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false]],
        ];
    }

    private function result(string|int|null $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }
}

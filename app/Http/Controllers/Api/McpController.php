<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\McpProtocol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use JsonException;

class McpController extends Controller
{
    public function __invoke(Request $request, McpProtocol $protocol): JsonResponse|Response
    {
        if ($response = $this->authorizeRequest($request)) {
            return $response;
        }
        if (! $request->isMethod('post')) {
            return response('', 405, ['Allow' => 'POST']);
        }
        $accept = $request->header('Accept', '');
        if (! str_contains($accept, 'application/json') || ! str_contains($accept, 'text/event-stream')) {
            return response()->json($protocol->error(null, -32600, 'Accept must include application/json and text/event-stream.'), 406);
        }
        try {
            $message = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json($protocol->error(null, -32700, 'Parse error.'), 400);
        }
        if (! is_array($message) || array_is_list($message) || ($message['jsonrpc'] ?? null) !== '2.0') {
            return response()->json($protocol->error($message['id'] ?? null, -32600, 'Invalid JSON-RPC request.'), 400);
        }
        $version = $request->header('MCP-Protocol-Version');
        if (($message['method'] ?? null) !== 'initialize' && $version && ! in_array($version, [McpProtocol::VERSION, '2025-03-26'], true)) {
            return response()->json($protocol->error($message['id'] ?? null, -32600, 'Unsupported MCP protocol version.'), 400);
        }
        $result = $protocol->dispatch($message);

        $responseVersion = data_get($result, 'result.protocolVersion', McpProtocol::VERSION);

        return $result === null ? response('', 202) : response()->json($result, 200, ['MCP-Protocol-Version' => $responseVersion]);
    }

    private function authorizeRequest(Request $request): ?JsonResponse
    {
        if ($origin = $request->header('Origin')) {
            $allowed = array_filter(array_map('trim', explode(',', (string) config('services.afterfeed_mcp.allowed_origins'))));
            if (! in_array($origin, $allowed, true)) {
                return response()->json(['error' => 'Origin is not allowed.'], 403);
            }
        }

        return null;
    }
}

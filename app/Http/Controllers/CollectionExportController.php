<?php

namespace App\Http\Controllers;

use App\Models\PostCollection;
use App\Services\AppSettings;
use App\Services\CollectionExporter;
use Illuminate\Http\Request;

class CollectionExportController extends Controller
{
    public function create(PostCollection $postCollection, AppSettings $settings)
    {
        $postCollection->loadCount('posts');

        $exportDefaults = $settings->all();

        return view('exports.create', compact('postCollection', 'exportDefaults'));
    }

    public function store(Request $request, PostCollection $postCollection, CollectionExporter $exporter): mixed
    {
        $validated = $request->validate(['format' => ['required', 'in:html,pdf,json,zip']]);
        $options = $validated + collect(['include_text', 'include_media', 'include_identity', 'include_dates', 'include_annotations', 'include_links', 'include_locations', 'strip_metadata'])->mapWithKeys(fn ($name) => [$name => $request->boolean($name)])->all();

        return $exporter->download($postCollection, $options);
    }
}

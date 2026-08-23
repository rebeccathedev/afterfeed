<?php

namespace App\Services;

use App\Models\PostCollection;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Process\Process;
use ZipArchive;

class CollectionExporter
{
    public function __construct(private readonly PrivacyFilter $privacy) {}

    public function download(PostCollection $collection, array $options): mixed
    {
        $collection->load(['posts' => fn ($query) => $query->with(['socialAccount', 'attachments', 'annotation'])->oldest('posted_at')]);
        $slug = Str::slug($collection->name) ?: 'afterfeed-collection';

        return match ($options['format']) {
            'html' => response($this->html($collection, $options, true), 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$slug}.html\""]),
            'json' => response()->json($this->payload($collection, $options), 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)->header('Content-Disposition', "attachment; filename=\"{$slug}.json\""),
            'pdf' => $this->pdf($collection, $options, $slug),
            'zip' => $this->zip($collection, $options, $slug),
        };
    }

    public function payload(PostCollection $collection, array $options): array
    {
        return $this->privacy->apply([
            'export' => ['generator' => 'Afterfeed', 'version' => 1, 'created_at' => now()->toIso8601String(), 'privacy' => $options],
            'collection' => ['name' => $collection->name, 'description' => $collection->description],
            'posts' => $collection->posts->map(function ($post) use ($options) {
                $record = ['id' => $post->id, 'type' => $post->type];
                if ($options['include_text']) {
                    $record['body'] = $post->body;
                }
                if ($options['include_dates']) {
                    $record['posted_at'] = $post->posted_at?->toIso8601String();
                }
                if ($options['include_identity']) {
                    $record['account'] = ['platform' => $post->socialAccount->platform, 'handle' => $post->socialAccount->handle, 'display_name' => $post->socialAccount->display_name];
                }
                if ($options['include_links']) {
                    $record['original_url'] = $post->originalUrl();
                    $record['shared_url'] = $post->sharedUrl();
                }
                if ($options['include_annotations'] && $post->annotation) {
                    $record['annotation'] = ['note' => $post->annotation->note, 'tags' => $post->annotation->tags, 'favorite' => $post->annotation->favorite];
                }
                if ($options['include_locations'] && $post->annotation) {
                    $record['location'] = ['name' => $post->annotation->place_name, 'latitude' => $post->annotation->latitude, 'longitude' => $post->annotation->longitude];
                }
                if (! $options['strip_metadata']) {
                    $record['source_metadata'] = $post->metadata;
                }
                if ($options['include_media']) {
                    $record['media'] = $post->attachments->map(fn ($media) => array_filter(['filename' => basename($media->path), 'type' => $media->type, 'alt_text' => $media->alt_text, 'metadata' => $options['strip_metadata'] ? null : $media->metadata], fn ($value) => $value !== null))->all();
                }

                return $record;
            })->all(),
        ]);
    }

    private function html(PostCollection $collection, array $options, bool $embed): string
    {
        $media = $this->mediaMap($collection, $options, $embed);

        return $this->privacy->apply(view('exports.album', compact('collection', 'options', 'media'))->render());
    }

    private function pdf(PostCollection $collection, array $options, string $slug): BinaryFileResponse
    {
        abort_if($collection->posts->count() > 250, 422, 'PDF memory books are limited to 250 posts. Use HTML or ZIP for larger collections.');
        $dompdfOptions = new Options;
        $dompdfOptions->set('isRemoteEnabled', false);
        $dompdfOptions->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($dompdfOptions);
        $dompdf->loadHtml($this->html($collection, $options, true));
        $dompdf->setPaper('letter');
        $dompdf->render();
        $path = tempnam(storage_path('app/private'), 'afterfeed-pdf-');
        File::put($path, $dompdf->output());

        return response()->download($path, $slug.'.pdf', ['Content-Type' => 'application/pdf'])->deleteFileAfterSend(true);
    }

    private function zip(PostCollection $collection, array $options, string $slug): BinaryFileResponse
    {
        $path = tempnam(storage_path('app/private'), 'afterfeed-zip-');
        $zip = new ZipArchive;
        $temporaryMedia = [];
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create export ZIP.');
        }
        $zip->addFromString('archive.json', json_encode($this->payload($collection, $options), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $media = [];
        if ($options['include_media']) {
            foreach ($collection->posts as $post) {
                foreach ($post->attachments as $attachment) {
                    $name = 'media/'.$post->id.'-'.$attachment->id.'-'.basename($attachment->path);
                    $source = Storage::disk('public')->path($attachment->path);
                    if (! is_file($source)) {
                        continue;
                    }
                    if ($options['strip_metadata'] && $attachment->type === 'video') {
                        $sanitized = $this->sanitizedVideoPath($source);
                        $temporaryMedia[] = $sanitized;
                        $zip->addFile($sanitized, $name);
                    } elseif ($options['strip_metadata']) {
                        $zip->addFromString($name, $this->sanitizedMedia($source, $attachment->type));
                    } else {
                        $zip->addFile($source, $name);
                    }
                    $media[$attachment->id] = $name;
                }
            }
        }
        $zip->addFromString('index.html', $this->privacy->apply(view('exports.album', compact('collection', 'options', 'media'))->render()));
        $zip->close();
        File::delete($temporaryMedia);

        return response()->download($path, $slug.'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }

    private function mediaMap(PostCollection $collection, array $options, bool $embed): array
    {
        if (! $options['include_media']) {
            return [];
        }
        $map = [];
        $embeddedBytes = 0;
        foreach ($collection->posts as $post) {
            foreach ($post->attachments as $attachment) {
                $path = Storage::disk('public')->path($attachment->path);
                if (! is_file($path)) {
                    continue;
                }
                if ($options['format'] === 'pdf' && $attachment->type === 'video') {
                    continue;
                }
                $embeddedBytes += filesize($path) ?: 0;
                abort_if($embeddedBytes > 60 * 1024 * 1024, 422, 'Self-contained HTML and PDF exports are limited to 60 MB of media. Use ZIP for this collection.');
                $bytes = $options['strip_metadata'] ? $this->sanitizedMedia($path, $attachment->type) : File::get($path);
                $mime = $attachment->type === 'video' ? 'video/'.(pathinfo($path, PATHINFO_EXTENSION) ?: 'mp4') : (File::mimeType($path) ?: 'image/jpeg');
                $map[$attachment->id] = $embed ? 'data:'.$mime.';base64,'.base64_encode($bytes) : basename($path);
            }
        }

        return $map;
    }

    private function sanitizedMedia(string $path, string $type): string
    {
        if ($type === 'video') {
            $output = $this->sanitizedVideoPath($path);
            $bytes = File::get($output);
            File::delete($output);

            return $bytes;
        }
        $image = @imagecreatefromstring(File::get($path));
        if (! $image) {
            throw new RuntimeException('Could not sanitize '.basename($path));
        }
        ob_start();
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === 'png') {
            imagepng($image, null, 7);
        } elseif ($extension === 'webp') {
            imagewebp($image, null, 88);
        } elseif ($extension === 'gif') {
            imagegif($image);
        } else {
            imagejpeg($image, null, 90);
        }
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    private function sanitizedVideoPath(string $path): string
    {
        $temporary = tempnam(storage_path('app/private'), 'afterfeed-video-');
        $output = $temporary.'.'.(pathinfo($path, PATHINFO_EXTENSION) ?: 'mp4');
        File::move($temporary, $output);
        $process = new Process(['ffmpeg', '-y', '-i', $path, '-map_metadata', '-1', '-c', 'copy', $output]);
        $process->setTimeout(300)->mustRun();

        return $output;
    }
}

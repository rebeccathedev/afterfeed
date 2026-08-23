<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PharData;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

class ImportUploadController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'upload_id' => ['required', 'uuid'],
            'filename' => ['required', 'string', 'max:255', 'regex:/\.(?:zip|tar\.gz|tgz)$/i'],
            'index' => ['required', 'integer', 'min:0'],
            'total' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);
        $data['index'] = (int) $data['index'];
        $data['total'] = (int) $data['total'];

        if ($data['index'] >= $data['total']) {
            return response()->json(['message' => 'Invalid chunk number.'], 422);
        }

        $contents = $request->getContent();
        if ($contents === '' || strlen($contents) > 5 * 1024 * 1024) {
            return response()->json(['message' => 'Each upload chunk must be between 1 byte and 5 MB.'], 422);
        }

        $directory = storage_path('app/private/imports/incoming');
        File::ensureDirectoryExists($directory);
        $uploadPath = $directory.'/'.$data['upload_id'];
        $statePath = $uploadPath.'.json';
        $partialPath = $uploadPath.'.part';
        $extension = preg_match('/\.tar\.gz$/i', $data['filename']) ? 'tar.gz' : strtolower(pathinfo($data['filename'], PATHINFO_EXTENSION));
        $basename = preg_replace('/\.(?:zip|tar\.gz|tgz)$/i', '', $data['filename']);
        $filename = Str::slug($basename).'-'.$data['upload_id'].'.'.$extension;
        $archivePath = storage_path('app/private/imports/'.$filename);
        $lock = fopen($uploadPath.'.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX)) {
            return response()->json(['message' => 'Could not lock the upload. Please retry.'], 503);
        }

        try {
            $state = File::exists($statePath) ? json_decode(File::get($statePath), true, flags: JSON_THROW_ON_ERROR) : [
                'filename' => $data['filename'], 'total' => $data['total'], 'next' => 0, 'bytes' => 0, 'complete' => false,
            ];
            if ($state['filename'] !== $data['filename'] || $state['total'] !== $data['total']) {
                return response()->json(['message' => 'Upload details changed. Please select the file again.'], 409);
            }
            if ($data['index'] > $state['next']) {
                return response()->json(['message' => "Expected chunk {$state['next']}; received {$data['index']}. Retrying should resume the upload."], 409);
            }
            if ($data['index'] === $state['next']) {
                $partial = fopen($partialPath, 'c+b');
                ftruncate($partial, $state['bytes']);
                fseek($partial, $state['bytes']);
                fwrite($partial, $contents);
                fflush($partial);
                fclose($partial);
                $state['bytes'] += strlen($contents);
                $state['next']++;
                $state['complete'] = $state['next'] === $state['total'];
                if ($state['complete']) {
                    File::move($partialPath, $archivePath);
                }
                File::put($statePath, json_encode($state, JSON_THROW_ON_ERROR));
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        if (! $state['complete']) {
            return response()->json(['received' => $state['next'], 'total' => $data['total']]);
        }

        try {
            $importPath = in_array($extension, ['tar.gz', 'tgz'], true) ? $this->convertTarToZip($archivePath) : $archivePath;
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'The tar archive could not be prepared: '.$exception->getMessage()], 422);
        }

        return $this->import($importPath, $request->user()->id);
    }

    private function convertTarToZip(string $archivePath): string
    {
        $extractPath = storage_path('app/private/imports/extracted/'.Str::uuid());
        File::ensureDirectoryExists($extractPath);
        $tar = new PharData($archivePath);
        $prefix = 'phar://'.$archivePath.'/';

        foreach (new RecursiveIteratorIterator($tar) as $entry) {
            $relative = str_replace('\\', '/', Str::after($entry->getPathname(), $prefix));
            if ($relative === '' || str_starts_with($relative, '/') || in_array('..', explode('/', $relative), true) || $entry->isLink()) {
                File::deleteDirectory($extractPath);
                throw new RuntimeException('The tar archive contains an unsafe file path.');
            }
        }

        try {
            $tar->extractTo($extractPath);
            $zipPath = preg_replace('/\.(?:tar\.gz|tgz)$/i', '.zip', $archivePath);
            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create the normalized ZIP archive.');
            }
            foreach (File::allFiles($extractPath) as $file) {
                $zip->addFile($file->getPathname(), $file->getRelativePathname());
            }
            $zip->close();
        } finally {
            File::deleteDirectory($extractPath);
        }

        File::delete($archivePath);

        return $zipPath;
    }

    private function import(string $archivePath, int $userId): JsonResponse
    {
        try {
            set_time_limit(0);
            $exitCode = Artisan::call('archive:import', ['path' => $archivePath, '--user' => $userId]);
            $message = trim(Artisan::output());
            if ($exitCode !== 0) {
                return response()->json(['message' => $message ?: 'The archive could not be imported.'], 422);
            }

            return response()->json(['complete' => true, 'message' => $message ?: 'Archive imported.']);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'The upload completed, but the archive could not be imported: '.$exception->getMessage()], 500);
        }
    }
}

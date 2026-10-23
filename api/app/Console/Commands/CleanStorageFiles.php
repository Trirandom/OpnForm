<?php

namespace App\Console\Commands;

use App\Http\Controllers\Forms\FormController;
use App\Models\Forms\FormSubmission;
use App\Service\Storage\FileUploadPathService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CleanStorageFiles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'forms:storage-cleanup
                            {--dry-run : List the files that would be deleted without deleting them}
                            {--hours=24 : Minimum age (in hours) of a file before it can be deleted}
                            {--asset-hours=168 : Minimum age (in hours) of a form asset before it can be deleted}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete abandoned temporary uploads, and submission files or form assets no longer referenced';

    /**
     * Tables whose rows may contain form asset urls (logo, cover, images in descriptions, prefilled files...)
     */
    private const ASSET_REFERENCING_TABLES = ['forms', 'templates', 'form_integrations', 'workspaces', 'form_submissions'];

    private int $deletedCount = 0;

    private int $deletedBytes = 0;

    public function handle()
    {
        // Files younger than the threshold are never touched: they may belong to a submission still being processed
        $threshold = now()->subHours((int) $this->option('hours'))->getTimestamp();

        $this->cleanTmpFiles($threshold);
        $this->cleanOrphanedSubmissionFiles($threshold);
        // Assets get a longer grace period: they are uploaded in the editor before the form is saved
        $this->cleanOrphanedAssets(now()->subHours((int) $this->option('asset-hours'))->getTimestamp());

        $this->line(sprintf(
            '%s%d files deleted (%.2f MB)',
            $this->option('dry-run') ? '[dry-run] ' : '',
            $this->deletedCount,
            $this->deletedBytes / 1024 / 1024
        ));

        return self::SUCCESS;
    }

    private function cleanTmpFiles(int $threshold): void
    {
        foreach (Storage::files(rtrim(FileUploadPathService::getTmpFileUploadPath(), '/')) as $path) {
            $this->deleteIfOlderThan($path, $threshold);
        }
    }

    private function cleanOrphanedSubmissionFiles(int $threshold): void
    {
        foreach (Storage::directories('forms') as $formDirectory) {
            $formId = basename($formDirectory);
            if (!ctype_digit($formId)) {
                continue;
            }

            $files = Storage::files(FileUploadPathService::getFileUploadPath($formId));
            if (empty($files)) {
                continue;
            }

            $referenced = $this->referencedFileNames($formId);
            foreach ($files as $path) {
                if (!isset($referenced[basename($path)])) {
                    $this->deleteIfOlderThan($path, $threshold);
                }
            }
        }
    }

    /**
     * Every string found in the submissions data of the form, whatever the field (including fields
     * removed from the form since), is considered as a potential file reference.
     */
    private function referencedFileNames(int|string $formId): array
    {
        $referenced = [];
        FormSubmission::where('form_id', $formId)
            ->select(['id', 'data'])
            ->chunkById(500, function ($submissions) use (&$referenced) {
                foreach ($submissions as $submission) {
                    $data = $submission->data ?? [];
                    array_walk_recursive($data, function ($value) use (&$referenced) {
                        if (!is_string($value) || $value === '') {
                            return;
                        }
                        $name = basename(explode('?', $value)[0]);
                        $referenced[$value] = true;
                        $referenced[$name] = true;
                        $referenced[urldecode($name)] = true;
                    });
                }
            });

        return $referenced;
    }

    private function cleanOrphanedAssets(int $threshold): void
    {
        $candidates = [];
        foreach (Storage::files(FormController::ASSETS_UPLOAD_PATH) as $path) {
            if (Storage::lastModified($path) < $threshold) {
                $candidates[basename($path)] = $path;
            }
        }

        // Raw rows are scanned (soft deleted forms included), any mention of the file name keeps it
        foreach (self::ASSET_REFERENCING_TABLES as $table) {
            if (empty($candidates)) {
                break;
            }
            DB::table($table)->orderBy('id')->chunkById(500, function ($rows) use (&$candidates) {
                foreach ($rows as $row) {
                    $text = str_replace('\\/', '/', implode(' ', array_map('strval', (array) $row)));
                    $text .= ' ' . urldecode($text);
                    foreach ($candidates as $name => $path) {
                        if (str_contains($text, $name)) {
                            unset($candidates[$name]);
                        }
                    }
                }

                return !empty($candidates);
            });
        }

        foreach ($candidates as $path) {
            $this->deleteIfOlderThan($path, $threshold);
        }
    }

    private function deleteIfOlderThan(string $path, int $threshold): void
    {
        if (Storage::lastModified($path) >= $threshold) {
            return;
        }

        $size = Storage::size($path);
        if ($this->option('dry-run')) {
            $this->line('Would delete ' . $path);
        } else {
            Storage::delete($path);
        }

        $this->deletedCount++;
        $this->deletedBytes += $size;
    }
}

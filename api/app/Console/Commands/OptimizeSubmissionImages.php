<?php

namespace App\Console\Commands;

use App\Models\Forms\FormSubmission;
use App\Service\Storage\FileUploadPathService;
use App\Service\Storage\ImageOptimizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class OptimizeSubmissionImages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'forms:optimize-submission-images
                            {--form= : Only process the submissions of this form id}
                            {--dry-run : Compute the savings without writing anything}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Resize and convert already stored submission images to WebP';

    private int $processedCount = 0;

    private int $bytesBefore = 0;

    private int $bytesAfter = 0;

    public function handle()
    {
        if (!ImageOptimizer::enabled()) {
            $this->warn('Image optimization is disabled (IMAGE_OPTIMIZATION_ENABLED=false).');
            return self::SUCCESS;
        }

        $query = FormSubmission::query()->select(['id', 'form_id', 'data']);
        if ($this->option('form')) {
            $query->where('form_id', $this->option('form'));
        }

        $query->chunkById(100, function ($submissions) {
            foreach ($submissions as $submission) {
                $this->processSubmission($submission);
            }
        });

        $this->line(sprintf(
            '%s%d images optimized: %.2f MB -> %.2f MB',
            $this->option('dry-run') ? '[dry-run] ' : '',
            $this->processedCount,
            $this->bytesBefore / 1024 / 1024,
            $this->bytesAfter / 1024 / 1024
        ));

        return self::SUCCESS;
    }

    private function processSubmission(FormSubmission $submission): void
    {
        $data = $submission->data;
        if (empty($data) || !is_array($data)) {
            return;
        }

        // Originals are only deleted once the submission references the new files
        $originalsToDelete = [];
        $changed = false;

        array_walk_recursive($data, function (&$value) use ($submission, &$originalsToDelete, &$changed) {
            if (!is_string($value) || $value === '' || filter_var($value, FILTER_VALIDATE_URL) !== false) {
                return;
            }

            $fileName = urldecode($value);
            // Drawn signatures are kept as is
            if (str_starts_with($fileName, 'sign_') || !ImageOptimizer::isOptimizable($fileName)) {
                return;
            }

            try {
                $path = FileUploadPathService::getFileUploadPath($submission->form_id, $fileName);
            } catch (\Throwable $e) {
                return; // Not a valid file name
            }
            if (!Storage::exists($path)) {
                return;
            }

            $original = Storage::get($path);
            $optimized = ImageOptimizer::optimize($original, $fileName);
            if ($optimized === null) {
                return;
            }

            $this->processedCount++;
            $this->bytesBefore += strlen($original);
            $this->bytesAfter += strlen($optimized);
            if ($this->option('dry-run')) {
                return;
            }

            $newFileName = ImageOptimizer::webpName($fileName);
            $newPath = FileUploadPathService::getFileUploadPath($submission->form_id, $newFileName);
            Storage::put($newPath, $optimized);
            if ($newPath !== $path) {
                $originalsToDelete[] = $path;
            }
            $value = $newFileName;
            $changed = true;
        });

        if (!$changed) {
            return;
        }

        $submission->data = $data;
        $submission->timestamps = false;
        $submission->saveQuietly();
        Storage::delete($originalsToDelete);
    }
}

<?php

namespace App\Commands;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use LaravelZero\Framework\Commands\Command;
use Throwable;

class RunBackup extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'backup:run {url}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Run a backup';

    /**
     * The Backup Job data from the API.
     */
    protected Response $backupJob;

    /**
     * The Dumper instance.
     */
    protected Dumper $dumper;

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->info('Fetching backup job from API');
        $this->backupJob = Http::acceptJson()->throw()->get($this->argument('url'));

        $this->dumper = app()->make(Dumper::class, [
            'backupJob' => $this->backupJob,
        ]);

        try {
            $size = $this->dumpBackup();
        } catch (Throwable $e) {
            $this->error('An error occurred while running the backup:');

            $errorMessage = implode(PHP_EOL, [$e->getMessage(), ...$this->dumper->getErrors()]);

            $this->error($errorMessage);

            $this->patch([
                'success' => false,
                'error' => $errorMessage,
            ]);

            return Command::FAILURE;
        }

        $allErrors = $this->dumper->getErrors();
        $errorMessage = implode(PHP_EOL, $allErrors);

        if (empty($allErrors)) {
            $this->info('Backup complete');
        } else {
            $this->error('Backup complete with errors:');
            $this->error($errorMessage);
        }

        $this->info('Sending backup info to API');

        $this->patch([
            'success' => empty($allErrors),
            'error' => $errorMessage,
            'size' => $size,
        ])->collect('backups_to_delete')->each(function (string $backupName) {
            $this->info("Deleting old backup {$backupName}");
            $this->dumper->deleteFromFilesystem($backupName);
        });

        return Command::SUCCESS;
    }

    /**
     * Send a PATCH request to the API.
     */
    private function patch(array $data): Response
    {
        return Http::asJson()->throw()->patch($this->backupJob->json('patch_url'), $data);
    }

    /**
     * Handle the backup process.
     */
    private function dumpBackup(): int
    {
        $this->info('Dumping databases');
        $this->dumper->dumpDatabases();

        $this->info('Copying files to zip archive');
        $this->dumper->copyFilesToZipArchive();

        $this->info('Calculating zip archive size');
        $size = $this->dumper->getZipArchiveSize();

        $this->info('Uploading zip archive');
        $this->dumper->uploadZipArchive();

        $this->info('Cleaning up temporary directory');
        $this->dumper->cleanupTemporaryDirectory();

        return $size;
    }
}

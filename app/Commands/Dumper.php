<?php

namespace App\Commands;

use App\SpatieBackup\Manifest;
use App\SpatieBackup\Zip;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Spatie\DbDumper\Compressors\GzipCompressor;
use Spatie\DbDumper\Databases\MySql;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Symfony\Component\Finder\Finder;
use Throwable;

class Dumper
{
    use ResolvesFilesystem;

    /**
     * The Backup Job data from the API.
     */
    protected Response $backupJob;

    /**
     * The temporary directory to store the backup in.
     */
    protected TemporaryDirectory $temporaryDirectory;

    /**
     * The zip archive of the backup.
     */
    protected string $backupZip;

    /**
     * Local filesystem helper.
     */
    protected Filesystem $filesystem;

    /**
     * The files to archive.
     */
    protected Manifest $manifest;

    /**
     * Zip archive helper.
     */
    protected Zip $zip;

    /**
     * The errors that occurred during the backup.
     */
    protected array $errors = [];

    public function __construct(Response $backupJob)
    {
        $this->backupJob = $backupJob;

        $this->initializeBackup();

        $this->filesystem = app(Filesystem::class);
    }

    /**
     * Prepares the temporary directory and zip archive.
     */
    public function initializeBackup()
    {
        $this->temporaryDirectory = TemporaryDirectory::make();
        $this->backupZip = $this->temporaryDirectory->path('backup.zip');
        $this->manifest = new Manifest($this->temporaryDirectory->path('manifest.txt'));

        register_shutdown_function(function () {
            $this->temporaryDirectory->delete();
        });
    }

    /**
     * Returns all errors that occurred during the backup.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Loops over the databases and dumps them.
     */
    public function dumpDatabases()
    {
        $this->backupJob->collect('databases')->map(function (string $database) {
            $this->rescue(fn () => $this->dumpDatabase($database));
        });
    }

    /**
     * Dumps a database and adds it to the manifest.
     */
    private function dumpDatabase(string $database)
    {
        $filename = Str::slug($database).'.sql.gz';

        $dumper = app(MySql::class);
        $dumper->setDbName($database);
        $dumper->setUserName('root');
        $dumper->setPassword($this->backupJob->json('database_password'));
        $dumper->useCompressor(new GzipCompressor);
        $dumper->dumpToFile($dumpPath = $this->temporaryDirectory->path($filename));

        $this->manifest->addFiles($dumpPath);
    }

    /**
     * Loops over the files and finds out which ones to include in the backup.
     */
    protected function fillManifest()
    {
        /** @var Finder */
        $finder = app(Finder::class);
        $finder->ignoreDotFiles(false);
        $finder->ignoreVCS(false);

        $this->backupJob->collect('include_files')->each(function (string $path) use ($finder) {
            $this->rescue(function () use ($path, $finder) {
                if ($this->filesystem->isFile($path)) {
                    if (! $this->shouldExcludePath($path)) {
                        $this->manifest->addFiles($path);
                    }

                    return;
                }

                if ($this->filesystem->isDirectory($path)) {
                    foreach ($finder->in($path)->getIterator() as $directory) {
                        if (! $this->shouldExcludePath($directory)) {
                            $this->manifest->addFiles($directory->getPathname());
                        }
                    }
                }
            });
        });
    }

    /**
     * Copy the files to the zip archive.
     */
    public function copyFilesToZipArchive()
    {
        $this->rescue(function () {
            $this->fillManifest();

            Zip::createForManifest($this->manifest, $this->backupZip);
        });
    }

    /**
     * Determine if the given path should be excluded.
     *
     * This is similar to Spatie's FileSelection class, but this one supports wildcards.
     *
     * @see https://github.com/spatie/laravel-backup/blob/main/src/Tasks/Backup/FileSelection.phps
     */
    private function shouldExcludePath(string $path): bool
    {
        $path = realpath($path) ?: $path;

        if ($this->filesystem->isDirectory($path) && ! Str::endsWith($path, DIRECTORY_SEPARATOR)) {
            $path .= DIRECTORY_SEPARATOR;
        }

        $excludeFiles = $this->backupJob->collect('exclude_files')->all();

        foreach ($excludeFiles as $excludedPath) {
            if (Str::contains($excludedPath, '*')) {
                if (Str::is($excludedPath, $path)) {
                    return true;
                }

                continue;
            }

            if ($this->filesystem->isDirectory($excludedPath) && ! Str::endsWith($excludedPath, DIRECTORY_SEPARATOR)) {
                $excludedPath .= DIRECTORY_SEPARATOR;
            }

            if (Str::startsWith($path, $excludedPath)) {
                if ($path != $excludedPath && $this->filesystem->isFile($excludedPath)) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

    /**
     * Upload the zip archive to the filesystem.
     */
    public function uploadZipArchive()
    {
        $this->rescue(function () {
            $this->filesystem($this->backupJob->json('disk'))->writeStream(
                $this->backupJob->json('name').'.zip',
                fopen($this->backupZip, 'r')
            );
        });
    }

    /**
     * Delete the backup from the filesystem.
     */
    public function deleteFromFilesystem(string $backupName)
    {
        $this->rescue(function () use ($backupName) {
            $this->filesystem($this->backupJob->json('disk'))->delete($backupName.'.zip');
        });
    }

    /**
     * Get the path to the zip archive.
     */
    public function getBackupZipPath(): string
    {
        return $this->backupZip;
    }

    /**
     * Get the size of the zip archive.
     */
    public function getZipArchiveSize(): int
    {
        return $this->filesystem->size($this->backupZip);
    }

    /**
     * Cleanup the temporary directory.
     */
    public function cleanupTemporaryDirectory(): void
    {
        $this->temporaryDirectory->delete();
    }

    /**
     * Execute the given callback and rescue any errors that occur.
     */
    private function rescue(callable $callback)
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            $this->errors[] = $e->getMessage() ?: 'An unknown error occurred';
        }
    }
}

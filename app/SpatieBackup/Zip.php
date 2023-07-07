<?php

namespace App\SpatieBackup;

use Illuminate\Support\Str;
use ZipArchive;

/**
 * Copied from https://github.com/spatie/laravel-backup
 */
class Zip
{
    protected ZipArchive $zipFile;

    protected string $pathToZip;

    public static function createForManifest(Manifest $manifest, string $pathToZip): self
    {
        $zip = app(static::class, ['pathToZip' => $pathToZip]);

        $zip->open();

        foreach ($manifest->files() as $file) {
            $zip->add($file, self::determineNameOfFileInZip($file, $pathToZip));
        }

        $zip->close();

        return $zip;
    }

    protected static function determineNameOfFileInZip(string $pathToFile, string $pathToZip)
    {
        $fileDirectory = pathinfo($pathToFile, PATHINFO_DIRNAME).DIRECTORY_SEPARATOR;

        $zipDirectory = pathinfo($pathToZip, PATHINFO_DIRNAME).DIRECTORY_SEPARATOR;

        if (Str::startsWith($fileDirectory, $zipDirectory)) {
            return str_replace($zipDirectory, '', $pathToFile);
        }

        return $pathToFile;
    }

    public function __construct(string $pathToZip)
    {
        $this->zipFile = new ZipArchive();

        $this->pathToZip = $pathToZip;

        $this->open();
    }

    public function open(): void
    {
        $this->zipFile->open($this->pathToZip, ZipArchive::CREATE);
    }

    public function close(): void
    {
        $this->zipFile->close();
    }

    public function add(string|iterable $files, string $nameInZip = null): self
    {
        if (is_array($files)) {
            $nameInZip = null;
        }

        if (is_string($files)) {
            $files = [$files];
        }

        foreach ($files as $file) {
            if (is_dir($file)) {
                $this->zipFile->addEmptyDir(ltrim($nameInZip ?: $file, DIRECTORY_SEPARATOR));
            }

            if (is_file($file)) {
                $this->zipFile->addFile($file, ltrim($nameInZip, DIRECTORY_SEPARATOR)).PHP_EOL;
            }
        }

        return $this;
    }
}

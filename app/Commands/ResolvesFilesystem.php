<?php

namespace App\Commands;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use LaravelZero\Framework\Commands\Command;

/**
 * @mixin Command
 */
trait ResolvesFilesystem
{
    /**
     * Resolves the filesystem from the given config.
     */
    protected function filesystem(array $config): Filesystem
    {
        $driver = $config['driver'] ?? null;
        $useSshKey = $config['use_ssh_key'] ?? false;

        if ($driver === 'sftp' && $useSshKey) {
            $config['privateKey'] = File::get($config['privateKey']);
        }

        return Storage::build($config);
    }
}

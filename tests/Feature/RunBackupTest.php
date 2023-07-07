<?php

use App\Commands\Dumper;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('fetches the backup job, runs the dumper, sends the size, and deletes old backups', function ($backupJob) {
    Http::fake([
        'https://eddy.test/backup-job/id' => Http::response($backupJob),
        'https://eddy.test/backup-job/id?signature=sig' => function (Request $request) {
            expect($request['size'])->toBe(100);

            return Http::response([
                'backups_to_delete' => [
                    '2023-05-31-12-00-00-ftp',
                ],
            ]);
        },
    ]);

    Http::preventStrayRequests();

    $dumper = Mockery::mock(Dumper::class);
    $dumper->shouldReceive('dumpDatabases')->once();
    $dumper->shouldReceive('copyFilesToZipArchive')->once();
    $dumper->shouldReceive('getZipArchiveSize')->once()->andReturn(100);
    $dumper->shouldReceive('uploadZipArchive')->once();
    $dumper->shouldReceive('cleanupTemporaryDirectory')->once();
    $dumper->shouldReceive('deleteFromFilesystem')->once()->with('2023-05-31-12-00-00-ftp');
    $dumper->shouldReceive('getErrors')->once()->andReturn([]);

    app()->bind(Dumper::class, function () use ($dumper) {
        return $dumper;
    });

    $this->artisan('backup:run https://eddy.test/backup-job/id')
        ->assertExitCode(0);

})->with('backup_job');

it('send the error message when something fails', function ($backupJob) {
    Http::fake([
        'https://eddy.test/backup-job/id' => Http::response($backupJob),
        'https://eddy.test/backup-job/id?signature=sig' => function (Request $request) {
            expect($request['error'])->toBe(
                'Something went wrong'.PHP_EOL.'Error 1'.PHP_EOL.'Error 2'
            );

            return Http::response([
                'backups_to_delete' => [],
            ]);
        },
    ]);

    Http::preventStrayRequests();

    $dumper = Mockery::mock(Dumper::class);
    $dumper->shouldReceive('dumpDatabases')->andThrow(new Exception('Something went wrong'));
    $dumper->shouldReceive('getErrors')->once()->andReturn([
        'Error 1',
        'Error 2',
    ]);

    app()->bind(Dumper::class, function () use ($dumper) {
        return $dumper;
    });

    $this->artisan('backup:run https://eddy.test/backup-job/id')
        ->assertExitCode(1);
})->with('backup_job');

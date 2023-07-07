<?php

use App\Commands\Dumper;
use App\SpatieBackup\Zip;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Contracts\Filesystem\Filesystem as Disk;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\DbDumper\Databases\MySql;
use Symfony\Component\Finder\Finder;

it('dumps the databases to the db-dumps directory and adds it to the zip', function (array $backupJob) {
    $this->mock(MySql::class, function ($mock) {
        $mock->shouldReceive('setDbName')->once()->with('database1');
        $mock->shouldReceive('setUserName')->once()->with('root');
        $mock->shouldReceive('setPassword')->once()->with('secret');
        $mock->shouldReceive('useCompressor')->once();
        $mock->shouldReceive('dumpToFile')->once()->withArgs(function ($path) {
            return Str::endsWith($path, 'database1.sql.gz');
        });
    });

    $dumper = new Dumper(new Response(new Psr7Response(200, [], json_encode($backupJob))));
    $dumper->dumpDatabases();

})->with('backup_job');

it('copies the files to the zip archive', function (array $backupJob) {
    $this->mock(Filesystem::class, function ($mock) {
        $mock->shouldReceive('isFile')->with('/home/eddy/test.json')->andReturn(true);
        $mock->shouldReceive('isFile')->with('/home/eddy/example.com')->andReturn(false);
        $mock->shouldReceive('isDirectory')->with('/home/eddy/example.com')->andReturn(true);
        $mock->shouldReceive('isDirectory')->with('/home/eddy/test.json')->andReturn(false);
        $mock->shouldReceive('isDirectory')->with('/home/eddy/example.com/test_config.json')->andReturn(false);
        $mock->shouldReceive('isDirectory')->with('/home/eddy/example.com/app/Commands')->andReturn(true);
        $mock->shouldReceive('isDirectory')->with('/home/eddy/example.com/composer.json')->andReturn(false);
        $mock->shouldReceive('isDirectory')->with('/home/eddy/example.com/vendor')->andReturn(true);
        $mock->shouldReceive('isDirectory')->with('/home/eddy/example.com/vendor/autoload.php')->andReturn(false);
        $mock->shouldReceive('isDirectory')->with('/home/eddy/example.com/app/Http')->andReturn(true);
    });

    $this->mock(Finder::class, function ($mock) {
        $mock->shouldReceive('ignoreDotFiles')->once()->with(false);
        $mock->shouldReceive('ignoreVCS')->once()->with(false);
        $mock->shouldReceive('in')->once()->with('/home/eddy/example.com')->andReturnSelf();
        $mock->shouldReceive('getIterator')->once()->andReturn(new ArrayIterator([
            new SplFileInfo('/home/eddy/example.com/composer.json'),
            new SplFileInfo('/home/eddy/example.com/vendor'),
            new SplFileInfo('/home/eddy/example.com/vendor/autoload.php'),
            new SplFileInfo('/home/eddy/example.com/app/Commands'),
            new SplFileInfo('/home/eddy/example.com/app/Http'),
        ]));
    });

    $zip = $this->mock(Zip::class);
    $zip->shouldReceive('open')->once();
    $zip->shouldReceive('add')->once()->with('/home/eddy/test.json', '/home/eddy/test.json');
    $zip->shouldReceive('add')->once()->with('/home/eddy/example.com/composer.json', '/home/eddy/example.com/composer.json');
    $zip->shouldReceive('add')->once()->with('/home/eddy/example.com/app/Http', '/home/eddy/example.com/app/Http');
    $zip->shouldReceive('close')->once();

    app()->bind(Zip::class, fn () => $zip);

    $dumper = new Dumper(new Response(new Psr7Response(200, [], json_encode($backupJob))));
    $dumper->copyFilesToZipArchive();

})->with('backup_job');

it('builds the storage disk from the config and uploads the zip file', function (array $backupJob) {
    $disk = Mockery::mock(Disk::class);
    $disk->shouldReceive('writeStream')->once()->withArgs(function ($path, $stream) {
        return $path === '2023-06-01-12-00-00-ftp.zip' && is_resource($stream);
    });

    $manager = Mockery::mock(FilesystemManager::class);
    $manager->shouldReceive('build')->once()->with([
        'host' => 'ftp.example.com',
        'username' => 'test@eddy.management',
        'password' => 'secret',
        'driver' => 'ftp',
    ])->andReturn($disk);

    Storage::swap($manager);

    $dumper = new Dumper(new Response(new Psr7Response(200, [], json_encode($backupJob))));
    touch($dumper->getBackupZipPath());
    $dumper->uploadZipArchive();
})->with('backup_job');

it('can return the filesize of the backup', function (array $backupJob) {
    $this->mock(Filesystem::class, function ($mock) {
        $mock->shouldReceive('size')->once()->andReturn(100);
    });

    $dumper = new Dumper(new Response(new Psr7Response(200, [], json_encode($backupJob))));
    expect($dumper->getZipArchiveSize())->toBe(100);
})->with('backup_job');

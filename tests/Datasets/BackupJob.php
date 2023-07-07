<?php

dataset('backup_job', function () {
    return [
        [
            [
                'name' => '2023-06-01-12-00-00-ftp',
                'database_password' => 'secret',
                'databases' => ['id1' => 'database1'],
                'disk' => [
                    'host' => 'ftp.example.com',
                    'username' => 'test@eddy.management',
                    'password' => 'secret',
                    'driver' => 'ftp',
                ],
                'include_files' => [
                    '/home/eddy/test.json',
                    '/home/eddy/example.com',
                ],
                'exclude_files' => [
                    '*/vendor/*',
                    '/home/eddy/example.com/test_config.json',
                    '/home/eddy/example.com/app/Commands',
                ],
                'patch_url' => 'https://eddy.test/backup-job/id?signature=sig',
            ],
        ],
    ];
});

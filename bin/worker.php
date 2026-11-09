<?php
declare(strict_types=1);

// Queue worker: `php bin/worker.php ai` (equivalent of `php artisan queue:work --queue=ai`)
require __DIR__ . '/../src/bootstrap.php';

$queue = new App\Queue\JobQueue(db());
$name = $argv[1] ?? 'ai';
$once = in_array('--once', $argv, true);
do {
    $n = $queue->work($name);
    if ($n) {
        fwrite(STDOUT, date('c') . " processed $n job(s) on '$name'\n");
    }
    if (!$once) {
        sleep(1);
    }
} while (!$once);

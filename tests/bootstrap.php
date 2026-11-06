<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

passthru('php '.escapeshellarg(dirname(__DIR__).'/bin/console').' doctrine:migrations:migrate -n -q --env=test --allow-no-migration', $status);
if (0 !== $status) {
    exit($status);
}

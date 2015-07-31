<?php

require __DIR__.'/vendor/autoload.php';

use SlackBulkDelete\Command\DeleteFilesCommand;
use Symfony\Component\Console\Application;

$application = new Application();
$application->add(new DeleteFilesCommand());
$application->run();
<?php

$composerAutoload = [
    __DIR__ . '/../vendor/autoload.php', // standalone with "composer install" run
    __DIR__ . '/../../../autoload.php',  // script is installed as a composer binary
];
foreach ($composerAutoload as $autoload) {
    if (file_exists($autoload)) {
        require($autoload);
        break;
    }
}

$command = new EcsDeploy\DeployCommand();

$app = new Symfony\Component\Console\Application();
$app->add($command);
$app->setDefaultCommand($command->getName());

return $app;

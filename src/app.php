<?php

require __DIR__ . '/../vendor/autoload.php';

$command = new EcsDeploy\DeployCommand();

$app = new Symfony\Component\Console\Application();
$app->add($command);
$app->setDefaultCommand($command->getName());

return $app;

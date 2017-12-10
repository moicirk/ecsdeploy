<?php
namespace EcsDeploy;

use Aws\Ecs\EcsClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Igor Murujev <imurujev@gmail.com>
 */
class DeployCommand extends Command
{
    /**
     * @var EcsClient
     */
    private $client;

    /**
     * @var InputInterface
     */
    private $input;

    /**
     * @var OutputInterface
     */
    private $output;

    private $runningTasksCount;
    private $taskArn;
    private $tasksToRun = 1;

    protected function configure()
    {
        $this
            ->setName('deploy')
            ->setDefinition(new InputDefinition([
                new InputOption('key', 'k', InputOption::VALUE_REQUIRED),
                new InputOption('secret', 's', InputOption::VALUE_REQUIRED),
                new InputOption('region', 'r', InputOption::VALUE_REQUIRED),
                new InputOption('cluster', null, InputOption::VALUE_REQUIRED),
                new InputOption('service', null, InputOption::VALUE_REQUIRED),
                new InputOption('task', null, InputOption::VALUE_REQUIRED),
                new InputOption('task-file', null, InputOption::VALUE_REQUIRED),
                new InputOption('running-amount', null, InputOption::VALUE_REQUIRED, '', 1),
            ]));
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $this->tasksToRun = $input->getOption('running-amount');

        $this->output->writeln('Deploying task to AWS');

        $this->createClient();
        $this->checkCluster();

        if ($this->isServiceMode()) {
            $this->checkService();
        }

        $this->registerTask();

        if ($this->isServiceMode()) {
            $this->downScaleService();
            $this->updateService();
            $this->upScaleService();
        } else {
            $this->runTask();
        }
    }

    protected function createClient()
    {
        $this->describeStep('Configure AWS client');

        $this->client = new EcsClient([
            'debug' => false,
            'credentials' => [
                'key' => $this->input->getOption('key'),
                'secret' => $this->input->getOption('secret')
            ],
            'region' => $this->input->getOption('region'),
            'version' => '2014-11-13'
        ]);

        $this->stepSuccess('Client config success');
    }

    protected function checkCluster()
    {
        $clusterName = $this->input->getOption('cluster');
        if (!$clusterName) {
            throw new \Exception('Cluster option value is required');
        }

        $this->describeStep("Check cluster '{$clusterName}'");

        $result = $this->client->describeClusters([
            'clusters' => [$clusterName]
        ]);

        $clusters = $result->get('clusters');
        if (empty($clusters)) {
            throw new \Exception("Cluster named '{$clusterName}' not found");
        }

        $this->stepSuccess("Cluster '{$clusterName}' check success");
    }

    protected function checkService()
    {
        $serviceName = $this->input->getOption('service');
        $this->describeStep("Check service '{$serviceName}'");

        $result = $this->client->describeServices([
            'cluster' => $this->input->getOption('cluster'),
            'services' => [$serviceName]
        ]);

        $services = $result->get('services');
        if (empty($services)) {
            throw new \Exception("Service '{$serviceName}' not found");
        }

        $this->runningTasksCount = $services[0]['runningCount'];
        $this->stepSuccess("Service '{$serviceName}' check success");
    }

    protected function registerTask()
    {
        $this->describeStep('Register new task');

        $result = $this->client->registerTaskDefinition([
            'family' => $this->getTaskName(),
            'containerDefinitions' => require $this->getTaskFile()
        ]);

        $this->taskArn = $result->get('taskDefinition')['taskDefinitionArn'];
        $this->stepSuccess('Task definition register success');
    }

    protected function downScaleService()
    {
        $serviceName = $this->input->getOption('service');
        $this->describeStep("Downscaling service '{$serviceName}'");

        if ($this->runningTasksCount < $this->tasksToRun) {
            $this->stepSuccess('Running tasks amount is less than requested. Downscaling skipped');
            $this->tasksToRun = $this->tasksToRun - $this->runningTasksCount;
            return;
        }


        $result = $this->client->describeServices([
            'cluster' => $this->input->getOption('cluster'),
            'services' => [$serviceName]
        ]);

        $serviceConfig = $result->get('services')[0];
        $desiredCount = ($serviceConfig['runningCount'] - 1);

        $result = $this->client->updateService([
            'cluster' => $this->input->getOption('cluster'),
            'service' => $serviceName,
            'desiredCount' => $desiredCount,
            'taskDefinition' => $serviceConfig['taskDefinition']
        ]);

        $serviceConfig = $result->get('services')[0];

        $this->stepSuccess("Service '{$serviceName}' downscaled from {$this->runningTasksCount} to {$serviceConfig['runningCount']}");
    }

    protected function updateService()
    {
        $serviceName = $this->input->getOption('service');
        $this->describeStep("Updating service '{$serviceName}'");

        $result = $this->client->updateService([
            'cluster' => $this->input->getOption('cluster'),
            'service' => $serviceName,
            'taskDefinition' => $this->taskArn
        ]);

        $this->stepSuccess("Service '{$serviceName}' updated");
    }

    protected function upScaleService()
    {
        $serviceName = $this->input->getOption('service');
        $this->describeStep("Upscaling service '{$serviceName}'");

        $result = $this->client->updateService([
            'cluster' => $this->input->getOption('cluster'),
            'service' => $serviceName,
            'desiredCount' => $this->tasksToRun,
            'taskDefinition' => $this->taskArn
        ]);

        $serviceConfig = $result->get('services')[0];

        $this->stepSuccess("Service '{$serviceName}' upscaled to {$serviceConfig['runningCount']}");
    }

    protected function runTask()
    {
        $this->describeStep('Run task');

        $result = $this->client->runTask([
            'cluster' => $this->input->getOption('cluster'),
            'taskDefinition' => $this->input->getOption('task')
        ]);

        $failures = $result->get('failures');
        if (!empty($failures)) {
            throw new \Exception("Task '{$failures[0]['arn']}' failed: {$failures[0]['reason']}");
        }

        $this->taskArn = $result->get('tasks')[0]['taskArn'];
        $this->waitToRun();
    }

    protected function waitToRun()
    {
        $result = $this->client->describeTasks([
            'cluster' => $this->input->getOption('cluster'),
            'tasks' => [$this->taskArn]
        ]);

        $failures = $result->get('failures');
        if (!empty($failures)) {
            throw new \Exception("Task '{$failures[0]['arn']}' failed: {$failures[0]['reason']}");
        }

        $status = $result->get('tasks')[0]['lastStatus'];
        if ($status === 'RUNNING') {
            $this->stepSuccess('Task is running');
            return;
        }

        $this->output->writeln("Task status is '{$status}'. Waiting...");
        sleep(5);

        $this->waitToRun();
    }

    protected function getTaskName(): string
    {
        if (!$taskName = $this->input->getOption('task')) {
            throw new \Exception('Task name option is required');
        }
        return $taskName;
    }

    protected function getTaskFile(): string
    {
        if (!$taskFile = $this->input->getOption('task-file')) {
            throw new \Exception('Task file option is required');
        }

        $path = strpos($taskFile, './') === 0 ?
            getcwd() . DIRECTORY_SEPARATOR . substr($taskFile, 2) : $taskFile;

        if (!file_exists($path)) {
            throw new \Exception("File not found in path: {$path}");
        }

        return $path;
    }

    protected function describeStep(string $description)
    {
        $this->output->writeln([
            $description,
            '=====================',
        ]);
    }

    protected function stepSuccess(string $message)
    {
        $this->output->writeln([
            "<info>{$message}</info>",
            ''
        ]);
    }

    protected function isServiceMode(): bool
    {
        return (bool)$this->input->getOption('service');
    }
}

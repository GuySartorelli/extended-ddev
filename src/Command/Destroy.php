<?php declare(strict_types=1);

namespace GuySartorelli\ExtendedDdev\Command;

use GuySartorelli\DdevWrapper\DDevHelper;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Command for creating a new opinionated Silverstripe CMS DDEV project.
 */
#[AsCommand(name: 'destroy', description: 'Destroys a Silverstripe CMS installation.')]
class Destroy extends BaseCommand
{
    private string $projectRoot;

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
        $this->validateOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectName = $this->input->getArgument('project-name');
        $this->outputStep("Destroying project $projectName");

        $this->outputStep("Shutting down DDEV project");
        chdir($this->projectRoot);
        $success = DDevHelper::runInteractiveOnVerbose('delete', ['-O', '-y'], $this->output, [$this, 'handleDdevOutput']);
        if (!$success) {
            $this->error('Could not shut down DDEV project.');
            return self::FAILURE;
        }

        $this->outputStep("Deleting project directory");
        $filesystem = new Filesystem();
        try {
            $filesystem->remove($this->projectRoot);
        } catch (IOExceptionInterface $e) {
            $this->error('Could not delete project directory: ' . $e->getMessage());
            $this->output->writeln($e->getTraceAsString(), OutputInterface::VERBOSITY_DEBUG);
            return self::FAILURE;
        }

        $this->success("Project <options=bold>{$projectName}</> successfully destroyed");
        return self::SUCCESS;
    }

    private function validateOptions()
    {
        $projectName = $this->input->getArgument('project-name');
        if (!$projectName) {
            return;
        }

        $allProjects = DDevHelper::runJson('list');
        if (empty($allProjects)) {
            throw new RuntimeException('There are no current DDEV projects to destroy');
        }

        $found = false;
        foreach ($allProjects as $projectDetails) {
            if ($projectDetails->name !== $projectName) {
                continue;
            }
            $found = true;
            $this->projectRoot = $projectDetails->approot;
            break;
        }

        if (!$found) {
            throw new RuntimeException("Project $projectName doesn't exist");
        }
    }

    protected function configure(): void
    {
        $this->setHelp(<<<HELP
        Removes a project by tearing down the docker containers, destroying the volumes,
        deleting the directory in the project directory, etc.
        HELP);
        $this->addArgument(
            'project-name',
            InputArgument::REQUIRED,
            'The name of the project to destroy - use ddev list if you aren\'t sure.',
            null,
            fn () => array_map(fn ($item) => "{$item->name}\t{$item->status}", DDevHelper::runJson('list'))
        );
    }
}

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
        $this->output->writeln(self::STYLE_STEP . "Destroying project $projectName" . self::STYLE_END);

        $this->output->writeln(self::STYLE_STEP . "Shutting down DDEV project" . self::STYLE_END);
        chdir($this->projectRoot);
        $success = DDevHelper::runInteractiveOnVerbose('delete', ['-O', '-y'], $output);
        if (!$success) {
            $this->output->error('Could not shut down DDEV project.');
            return self::FAILURE;
        }

        $this->output->writeln(self::STYLE_STEP . "Deleting project directory" . self::STYLE_END);
        $filesystem = new Filesystem();
        try {
            $filesystem->remove($this->projectRoot);
        } catch (IOExceptionInterface $e) {
            $this->output->error('Could not delete project directory: ' . $e->getMessage());
            $this->output->writeln($e->getTraceAsString(), OutputInterface::VERBOSITY_DEBUG);
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function validateOptions()
    {
        $projectName = $this->input->getArgument('project-name');
        $allProjects = DDevHelper::runJson('list');

        if (empty($allProjects)) {
            throw new RuntimeException('There are no current DDEV projects to destroy');
        }

        $allProjectNames = array_column($allProjects, 'name');
        if (!in_array($projectName, $allProjectNames)) {
            $projectName = $this->output->ask(
                'Which project do you want to destroy?',
                validator: function (string $answer) use ($allProjectNames): string {
                    if (!in_array($answer, $allProjectNames)) {
                        throw new RuntimeException('You must provide a valid project name.');
                    }
                    return $answer;
                }
            );
            $this->input->setArgument('project-name', $projectName);
        }

        foreach ($allProjects as $projectDetails) {
            if (!$projectDetails->name === $projectName) {
                continue;
            }
            $this->projectRoot = $projectDetails->approot;
            break;
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
            'The name of the project to destroy - use ddev list if you aren\'t sure.'
        );
    }
}

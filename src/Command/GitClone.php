<?php declare(strict_types=1);

namespace GuySartorelli\ExtendedDdev\Command;

use Gitonomy\Git\Admin as Git;
use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Repository;
use GuySartorelli\ExtendedDdev\Utility\GitHubService;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Path;

/**
 * Simplifies cloning PRs without needing a full DDEV project
 */
#[AsCommand(
    name: 'git-clone',
    description: 'Clone a git repo an optionally check out a PR based on the URL into a predetermined directory.',
    aliases: ['clone']
)]
class GitClone extends BaseCommand
{
    private array $repoDetails;

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
        $this->validateOptions();
    }

    private function validateOptions(): void
    {
        $identifier = $this->input->getArgument('identifier');
        $this->repoDetails = GitHubService::getRepositoryDetails($identifier, $this->getEnv('EDDEV_GITHUB_TOKEN'));
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cloneDir = Path::canonicalize($this->getEnv('EDDEV_CLONE_DIR'));
        if (!is_dir($cloneDir)) {
            $this->error("$cloneDir does not exist or is not a directory. Check your EDDEV_CLONE_DIR environment variable.");
            return self::FAILURE;
        }
        $this->outputStep("Cloning {$this->repoDetails['composerName']} into {$cloneDir}");

        $repoPath = Path::join($cloneDir, preg_replace('/^silverstripe-/', '', $this->repoDetails['repo']));
        Git::cloneRepository($repoPath, $this->repoDetails['cloneUri']);

        if (isset($this->repoDetails['pr'])) {
            $details = $this->repoDetails['pr'];
            $this->outputStep('Setting remote ' . $details['remote'] . ' as "' . $details['remoteName'] . '" and checking out branch ' . $details['prBranch']);

            try {
                $gitRepo = new Repository($repoPath);
                $gitRepo->run('remote', ['add', $details['remoteName'], $details['remote']]);
                $gitRepo->run('fetch', [$details['remoteName']]);
                $gitRepo->run('checkout', ["{$details['remoteName']}/" . $details['prBranch'], '--track', '--no-guess']);
            } catch (ProcessException $e) {
                $this->error("Could not check out PR branch <options=bold>{$details['prBranch']}</> - please check it out manually.");
                return self::FAILURE;
            }
        }

        $this->success("{$this->repoDetails['composerName']} cloned successfully.");
        return self::SUCCESS;
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->addArgument(
            'identifier',
            InputArgument::REQUIRED,
            'URL pr org/repo#123 reference to a GitHub repo - optionally for a specific pull request'
        );
    }
}

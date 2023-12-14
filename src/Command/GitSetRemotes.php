<?php declare(strict_types=1);

namespace GuySartorelli\ExtendedDdev\Command;

use Gitonomy\Git\Repository;
use LogicException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Path;

/*
 * @TODO
 * - Remove CC remote if --security and the cc remote is present
 *   - Show warning to make it clear that happened
 * - Error and fail if trying to add CC remote when we already have security remote
 */

/**
 * Based on https://gist.github.com/maxime-rainville/0e2cc280cc9d2e014a21b55a192076d9
 */
#[AsCommand(
    name: 'git-set-remotes',
    description: 'Set the various development remotes in the git project for the current working dir.',
    aliases: ['remotes']
)]
class GitSetRemotes extends BaseCommand
{

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $gitRepo = new Repository(Path::canonicalize($input->getArgument('dir')));
        $ccAccount = 'git@github.com:creative-commoners/';
        $securityAccount = 'git@github.com:silverstripe-security/';
        $prefixAndOrgRegex = '#^(?>git@github\.com:|https://github\.com/).*/#';

        $originUrl = trim($gitRepo->run('remote', ['get-url', 'origin']));

        // Validate origin URL
        if (!preg_match($prefixAndOrgRegex, $originUrl)) {
            throw new LogicException("Origin $originUrl does not appear to be valid");
        }

        if ($input->getOption('security')) {
            // Add security remote
            $this->output->writeln(self::STYLE_STEP . 'Adding the security remote' . self::STYLE_END);
            $securityRemote = preg_replace($prefixAndOrgRegex, $securityAccount, $originUrl);
            $gitRepo->run('remote', ['add', 'security', $securityRemote]);
        } else {
            // Add cc remote
            $this->output->writeln(self::STYLE_STEP . 'Adding the creative-commoners remote' . self::STYLE_END);
            $ccRemote = preg_replace($prefixAndOrgRegex, $ccAccount, $originUrl);
            $gitRepo->run('remote', ['add', 'cc', $ccRemote]);
        }

        // Rename origin
        if ($input->getOption('rename-origin')) {
            $this->output->writeln(self::STYLE_STEP . 'Renaming the origin remote' . self::STYLE_END);
            $gitRepo->run('remote', ['rename', 'origin', 'orig']);
        }

        // Fetch
        if ($input->getOption('fetch')) {
            $this->output->writeln(self::STYLE_STEP . 'Fetching all remotes' . self::STYLE_END);
            $gitRepo->run('fetch', ['--all']);
        }

        $successMsg = 'Remotes added';
        if ($input->getOption('fetch')) {
            $successMsg .= ' and fetched';
        }
        $this->success($successMsg);
        return Command::SUCCESS;
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->addArgument(
            'dir',
            InputArgument::OPTIONAL,
            'The full path to the directory that holds the git project',
            './'
        );
        $this->addOption(
            'rename-origin',
            'r',
            InputOption::VALUE_NEGATABLE,
            'Rename the "origin" remote to "orig"',
            true
        );
        $this->addOption(
            'security',
            's',
            InputOption::VALUE_NONE,
            'Add the security remote instead of the creative commoners remote'
        );
        $this->addOption(
            'fetch',
            'f',
            InputOption::VALUE_NONE,
            'Run git fetch after defining remotes'
        );
    }
}

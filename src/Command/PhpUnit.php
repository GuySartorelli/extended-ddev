<?php declare(strict_types=1);

namespace GuySartorelli\ExtendedDdev\Command;

use GuySartorelli\DdevWrapper\DDevHelper;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * Command for simplifying running phpunit tests for modules in the vendor dir
 */
#[AsCommand(name: 'phpunit', description: 'Run PHPUnit tests on modules in the vendor dir')]
class PhpUnit extends BaseCommand
{
    private ?stdClass $projectDetails;

    private string $phpunitArg;

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);

        $this->projectDetails = DDevHelper::runJson('describe');
        if (!$this->projectDetails) {
            $dir = getcwd();
            throw new RuntimeException("Failed to find project(s): could not find a project in $dir.");
        }

        $this->validateOptions();
    }

    private function validateOptions(): void
    {
        $module = $this->input->getOption('module');
        if (!$module && preg_match('@/vendor/(?<module>[^/]*/[^/]*)@', getcwd(), $match)) {
            $module = $match['module'];
            $this->input->setOption('module', $module);
        }

        if (empty($module) && empty($this->input->getOption('test-class'))) {
            throw new RuntimeException('At least one of "module" or "test-class" must be passed in.');
        }

        $this->phpunitArg = $this->getPhpunitArg();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output->writeln(self::STYLE_STEP . 'Finding directory to run tests in, or exact test file.' . self::STYLE_END);

        // Build args
        $args = [$this->phpunitArg];
        if ($this->input->getOption('configuration')) {
            $args[] = '--configuration=' . $this->input->getOption('configuration');
        }
        if ($filter = $this->input->getOption('filter')) {
            $args[] = "--filter=$filter";
        }
        $module = (string)$this->input->getOption('module');
        if ($module === 'dynamodb' || $module === 'silverstripe/dynamodb') {
            $args[] = '--stderr';
        }

        // We have to remove old cache because for some reason cache from non-test usage doesn't get correctly flushed away
        // which results in TestOnly classes not having their config set correctly.
        $this->output->writeln(self::STYLE_STEP . 'Removing old cache.' . self::STYLE_END);
        $cachePath = Path::join($this->projectDetails->approot, 'silverstripe-cache');
        $fileSystem = new Filesystem();
        if ($fileSystem->exists($cachePath)) {
            $fileSystem->remove($cachePath);
            $fileSystem->mkdir($cachePath);
        }

        // Run tests
        $this->output->writeln(self::STYLE_STEP . 'Running PHPUnit.' . self::STYLE_END);
        $success = DDevHelper::runInteractive('exec', ['phpunit', ...$args]);

        if (!$success) {
            return self::FAILURE;
        }

        $this->output->success('PHPUnit ran successfully.');
        return self::SUCCESS;
    }

    private function getPhpunitArg(): string
    {
        $testClass = $this->input->getOption('test-class');
        $module = $this->input->getOption('module');
        $searchDir = 'vendor';

        // Search for the directory the module is in
        if ($module) {
            $searchDir = $this->getModuleDir($module);
            if (!$testClass) {
                return $searchDir;
            }
        }

        // TODO: Make (or reuse an existing) recursive function instead of duplicating logic here and in getModuleDir()

        // We need to find the file for this test class. We'll assume PSR-4 compliance.
        // Recursively check everything from the search dir down until we either find it or fail to find it
        $candidates = [Path::makeAbsolute($searchDir, $this->projectDetails->approot)];
        $checked = [];
        while (!empty($candidates)) {
            $candidate = array_shift($candidates);
            $checked[$candidate] = null;
            foreach (scandir($candidate) as $toCheck) {
                if ($toCheck === '.' || $toCheck === '..') {
                    continue;
                }

                $currentPath = Path::join($candidate, $toCheck);

                // If this file is the right file, we found it!
                if (!is_dir($currentPath) && $toCheck === $testClass . '.php') {
                    return Path::makeRelative($currentPath, $this->projectDetails->approot);
                }

                // If this is a directory, we need to check it too.
                if (is_dir($currentPath) && !array_key_exists($currentPath, $checked)) {
                    $candidates[] = $currentPath;
                }
            }
        }
        // If we get to this point, we weren't able to find that test class.
        throw new RuntimeException("Test class '$testClass' was not found.");
    }

    /**
     * Get the relative path for the given module (starting with vendor/)
     *
     * @TODO: Make (or reuse an existing) recursive function instead of duplicating logic here and in getPhpunitArg()
     */
    private function getModuleDir(string $module): string
    {
        $vendorDir = Path::join($this->projectDetails->approot, 'vendor');

        if (str_contains($module, '/')) {
            // If there's a slash assume it's a full module name, e.g. "silverstripe/admin"
            if (!is_dir(Path::join($vendorDir, $module))) {
                throw new RuntimeException("Module '$module' was not found.");
            }
            return Path::join('vendor', $module);
        } else {
            $checked = [];
            // Look at all the org dirs in the vendor directory
            foreach (scandir($vendorDir) as $orgDir) {
                if ($orgDir === '.' || $orgDir === '..') {
                    continue;
                }

                $currentPath = Path::join($vendorDir, $orgDir);

                if (is_dir($currentPath) && !array_key_exists($currentPath, $checked)) {
                    // Look at all repo dirs in each organisation directory
                    foreach (scandir($currentPath) as $repoDir) {
                        if ($repoDir === '.' || $repoDir === '..') {
                            continue;
                        }

                        $repoPath = Path::join($currentPath, $repoDir);

                        if ($repoDir === $module && is_dir($repoPath) && !array_key_exists($repoPath, $checked)) {
                            // Found the correct module (assuming there aren't duplicate module names across orgs)
                            return Path::join('vendor', $orgDir, $repoDir);
                        }

                        $checked[$repoPath] = true;
                    }
                }

                $checked[$currentPath] = true;
            }
        }
        // If we get to this point, we weren't able to find that module.
        throw new RuntimeException("Module '$module' was not found.");
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->addOption(
            'module',
            'm',
            InputOption::VALUE_REQUIRED,
            'A specific module for which to run tests. Can be used to narrow the search for test classes, or used without "test-class" to run all tests for that module.',
            null,
            function () {
                return explode("\n", DDevHelper::run('composer', ['show', '--installed', '--name-only']));
            }
        );
        $this->addOption(
            'test-class',
            'c',
            InputOption::VALUE_REQUIRED,
            'A specific test class to run tests in'
        );
        $this->addOption(
            'filter',
            'f',
            InputOption::VALUE_REQUIRED,
            'Filter which tests to run'
        );
        $this->addOption(
            'configuration',
            null,
            InputOption::VALUE_REQUIRED,
            'Configuration file to use'
        );
    }
}

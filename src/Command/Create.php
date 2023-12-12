<?php declare(strict_types=1);

namespace GuySartorelli\ExtendedDdev\Command;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Repository;
use GuySartorelli\DdevWrapper\DDevHelper;
use GuySartorelli\ExtendedDdev\Utility\GitHubService;
use Packagist\Api\Client as PackagistClient;
use Packagist\Api\PackageNotFoundException;
use Packagist\Api\Result\Package\Version;
use RecursiveDirectoryIterator;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/*
    @TODO

    - Add behat helper command (probably just as a global ddev command though)
    - Add phpunit helper command
    - Sort out verbosity vs interactivity (probably steal from the other local dev project)
        - MUST allow verbosity for custom commands
        - MUST NOT enforce verbosity for pass-through commands
    - See if any ddev specific stuff should be added
    - See what improvements can be taken from the other local dev projects
    - Check mailpit, or see if you need mailhog, or etc

    - Add more global commands:
        - add-pr
        - lint-php (and start using it please)
        - find modules with un-pushed changes (could be staged, unstaged, etc - normally stuff I do via find-and-replace)
        DO I NEED THESE? Or is DDEV quick and simple enough as-is?
        - Attach (with all of the relevant defaults/settings)
        - Detach (with all of the relevant defaults/settings)

    - Add protection to eddev config command
        - Only let it be used in subdirectories of the global projects dir or in a valid pre-existing project.
        - Creating new DDEV projects is EXPLICITLY only allowed in subdirectories of the main projects dir - and not nested in existing projects.
        - That probably will mean we need a specific attach/detach here, as a way to bypass that rule - but maybe we can have it as a prompted warning instead.
*/

/**
 * Command for creating a new opinionated Silverstripe CMS DDEV project.
 */
#[AsCommand(name: 'create', description: 'Installs an opinionated Silverstripe CMS installation in a pre-defined projects directory.')]
class Create extends BaseCommand
{
    /**
     * Used to define short names to easily select common recipes
     */
    protected static array $recipeShortcuts = [
        'installer' => 'silverstripe/installer',
        'sink' => 'silverstripe/recipe-kitchen-sink',
        'core' => 'silverstripe/recipe-core',
        'cms' => 'silverstripe/recipe-cms',
        // we need an admin recipe
    ];

    /**
     * Characters that cannot be used for an environment name
     * @TODO check if this is still relevant
     */
    protected static string $invalidEnvNameChars = ' !@#$%^&*()"\',.<>/?:;\\';

    private array $composerArgs = [];

    private Filesystem $filesystem;

    private string $projectRoot;

    private Version $recipeVersionDetails;

    private array $prs = [];

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->filesystem = new Filesystem();
        parent::initialize($input, $output);
        $this->normaliseRecipe();
        $this->identifyPhpVersion();
        $this->normaliseName();

        $this->projectRoot = Path::join(
            $this->getEnv('EDDEV_DEFAULT_PROJECTS_PATH'),
            $this->input->getArgument('env-name')
        );

        $this->validateOptions();

        if (in_array('--no-install', $this->input->getOption('composer-option')) && !empty($this->input->getOption('pr'))) {
            $this->output->warning('Composer --no-install has been set. Cannot checkout PRs.');
        } elseif (!empty($this->input->getOption('pr'))) {
            $this->prs = GitHubService::getPullRequestDetails($this->input->getOption('pr'), $this->getEnv('EDDEV_GITHUB_TOKEN'));
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Set up project root directory
        $success = $this->prepareProjectRoot();
        if (!$success) {
            // @TODO rollback
            return self::FAILURE;
        }

        chdir($this->projectRoot);

        // DDEV config
        $success = $this->setupDdevProject();
        if (!$success) {
            // @TODO rollback
            return self::FAILURE;
        }

        $success = $this->setupComposerProject();
        if (!$success) {
            // @TODO rollback?
            return self::FAILURE;
        }

        // Don't fail if we didn't get PRs in - we can add those manually if needs be.
        $this->handlePRs();

        $success = $this->copyProjectFiles();
        if (!$success) {
            // @TODO rollback?
            return self::FAILURE;
        }

        // @TODO only interactive on verbose
        $success = DDevHelper::runInteractive('exec', ['sake', 'dev/build']);
        if (!$success) {
            $appName = $this->getApplication()->getName();
            $this->output->warning("Couldn't build database - run `$appName exec sake dev/build`");
        }

        return $success ? self::SUCCESS : self::FAILURE;
    }

    private function prepareProjectRoot(): bool
    {
        $this->output->writeln(self::STYLE_STEP . 'Preparing project directory' . self::STYLE_END);

        try {
            // Make the project directory
            if (!is_dir($this->projectRoot)) {
                $this->filesystem->mkdir($this->projectRoot);
            }
        } catch (IOExceptionInterface $e) {
            // @TODO replace this with more standardised error/failure handling.
            $this->output->error("Couldn't create environment directory: {$e->getMessage()}");
            $this->output->writeln($e->getTraceAsString(), OutputInterface::VERBOSITY_DEBUG);

            return false;
        }

        return true;
    }

    /**
     * Copy files to the project. Has to be done AFTER composer create
     */
    private function copyProjectFiles(): bool
    {
        try {
            // Copy files through (config, .env, etc)
            $this->filesystem->mirror(
                Path::join(__DIR__, '../..', 'copy-to-project'),
                $this->projectRoot,
                options: ['override' => true]
            );
        } catch (IOExceptionInterface $e) {
            // @TODO replace this with more standardised error/failure handling.
            $this->output->error("Couldn't copy project files: {$e->getMessage()}");
            $this->output->writeln($e->getTraceAsString(), OutputInterface::VERBOSITY_DEBUG);

            return false;
        }

        return true;
    }

    private function setupDdevProject(): bool
    {
        $dbType = $this->input->getOption('db');
        $dbVersion = $this->input->getOption('db-version');
        if ($dbVersion) {
            $db = "--database={$dbType}:{$dbVersion}";
        } else {
            $db = "--db-image={$dbType}";
        }

        // @TODO only run interactive when running verbosely - otherwise use a progressbar.
        $success = DDevHelper::runInteractive('config', [
            $db,
            '--webserver-type=apache-fpm',
            '--project-type=php',
            '--php-version=' . $this->input->getOption('php-version'),
            '--project-name=' . $this->input->getArgument('env-name'),
            '--timezone=Pacific/Auckland',
            '--docroot=public',
            '--create-docroot',
        ]);

        // @TODO only run interactive when running verbosely - otherwise use a progressbar.
        $hasBehat = DDevHelper::runInteractive('get', ['ddev/ddev-selenium-standalone-chrome']);
        if (!$hasBehat) {
            $this->output->warning('Could not add DDEV addon "ddev/ddev-selenium-standalone-chrome" - add that manually.');
        }

        return $success;
    }

    private function setupComposerProject(): bool
    {
        // Run composer command
        // @TODO Only run interactive in verbose mode
        $composerArgs = $this->prepareComposerCommand('create');
        $success = DDevHelper::runInteractive('composer', $composerArgs);
        if (!$success) {
            $this->output->error('Couldn\'t create composer project.');
            return false;
        }

        // Install optional modules as appropriate
        $this->includeOptionalModule('silverstripe/dynamodb:' . $this->input->getOption('constraint'), (bool) $this->input->getOption('include-dynamodb'));
        $this->includeOptionalModule('behat/mink-selenium2-driver', isDev: true);
        $this->includeOptionalModule('silverstripe/frameworktest', (bool) $this->input->getOption('include-frameworktest'), isDev: true);
        $this->includeOptionalModule('silverstripe/recipe-testing', (bool) $this->input->getOption('include-recipe-testing'), isDev: true);
        // Always include dev docs if we're not using sink, which has it as a dependency
        $this->includeOptionalModule('silverstripe/developer-docs', ($this->input->getOption('recipe') !== 'silverstripe/recipe-kitchen-sink'));

        foreach ($this->input->getOption('extra-module') as $module) {
            $this->includeOptionalModule($module);
        }

        return $success;
    }

    private function handlePRs(): bool
    {
        if (empty($this->prs)) {
            return true;
        }

        if (!$this->input->getOption('pr-has-deps')) {
            return $this->checkoutPRs();
        }

        // Add prs to composer.json
        $this->output->writeln(self::STYLE_STEP . 'Adding PRs to composer.json' . self::STYLE_END);
        $composerService = new ComposerJsonService($this->projectRoot);
        $composerService->addForks($this->prs);
        $composerService->addForkedDeps($this->prs);

        // Run composer install
        $this->output->writeln(self::STYLE_STEP . 'Running composer install now that dependencies have been defined' . self::STYLE_END);
        $composerArgs = $this->prepareComposerCommand('install');
        // @TODO Only run interactively in verbose mode
        $success = DDevHelper::runInteractive('composer', $composerArgs);
        if (!$success) {
            $this->output->error('Couldn\'t run composer install.');
            return false;
        }
        return true;
    }

    private function checkoutPRs(): bool
    {
        $success = true;
        foreach ($this->prs as $composerName => $details) {
            $this->output->writeln(self::STYLE_STEP . 'Setting up PR for ' . $composerName . self::STYLE_END);

            // Check PR type and prepare remotes name
            $prIsCC = str_starts_with($details['remote'], 'git@github.com:creative-commoners/');
            $prIsSecurity = str_starts_with($details['remote'], 'git@github.com:silverstripe-security/');
            if ($prIsCC) {
                $remoteName = 'cc';
            } elseif ($prIsSecurity) {
                $remoteName = 'security';
            } else {
                $remoteName = 'pr';
            }

            $this->output->writeln(self::STYLE_STEP . 'Setting remote ' . $details['remote'] . ' as "' . $remoteName . '" and checking out branch ' . $details['prBranch'] . self::STYLE_END);

            // Try to add dependency if it's not there already
            $prPath = Path::join($this->projectRoot, 'vendor', $composerName);
            if (!$this->filesystem->exists($prPath)) {
                // Try composer require-ing it - and if that fails, toss out a warning about it and move on.
                $this->output->writeln(self::STYLE_STEP . $composerName . ' is not yet added as a dependency - requiring it.' . self::STYLE_END);
                // @TODO Only run interactively in verbose mode
                $checkoutSuccess = DDevHelper::runInteractive('composer', ['require', $composerName, '--prefer-source']);
                if (!$checkoutSuccess) {
                    $this->failCheckout($composerName, $success);
                    continue;
                }
            }

            try {
                $gitRepo = new Repository($prPath);
                $gitRepo->run('remote', ['add', $remoteName, $details['remote']]);
                $gitRepo->run('fetch', [$remoteName]);
                $gitRepo->run('checkout', ["$remoteName/" . $details['prBranch'], '--track', '--no-guess']);
            } catch (ProcessException $e) {
                $this->failCheckout($composerName, $success);
                continue;
            }
        }
        return $success;
    }

    private function failCheckout(string $composerName, mixed &$success): void
    {
        $this->output->warning('Could not check out PR for ' . $composerName . ' - please check out that PR manually.');
        $success = false;
    }

    private function prepareComposerCommand(string $commandType)
    {
        $composerArgs = $this->prepareComposerArgs($commandType);
        $command = [
            $commandType,
            ...$composerArgs
        ];
        if ($commandType === 'create') {
            $command[] = $this->input->getOption('recipe') . ':' . $this->input->getOption('constraint');
        }
        return $command;
    }

    private function includeOptionalModule(string $moduleName, bool $shouldInclude = true, bool $isDev = false)
    {
        if ($shouldInclude) {
            $this->output->writeln(self::STYLE_STEP . "Adding optional module $moduleName" . self::STYLE_END);
            $composerArgs = [
                'require',
                $moduleName,
                ...$this->prepareComposerArgs('require'),
            ];

            if ($isDev) {
                $composerArgs[] = '--dev';
            }

            // Run composer command
            // @TODO Only interactive in verbose mode
            $success = DDevHelper::runInteractive('composer', $composerArgs);
            if (!$success) {
                $this->output->warning("Couldn't require '$moduleName' - add that dependency manually.");
            }
            return $success;
        }
    }

    private function prepareComposerArgs(string $commandType): array
    {
        if (!$this->composerArgs) {
            // Prepare composer command
            $this->composerArgs = [
                '--no-interaction',
                ...$this->input->getOption('composer-option')
            ];
        }
        $args = $this->composerArgs;

        // Don't install on create if a PR has dependencies
        if ($commandType === 'create' && $this->input->getOption('pr-has-deps') && !empty($this->prs)) {
            $args[] = '--no-install';
        }

        // composer install can't take --no-audit, but we don't want to include audits in other commands.
        if ($commandType !== 'install') {
            $args[] = '--no-audit';
        }

        // Make sure --no-install isn't in there twice.
        return array_unique($args);
    }

    /**
     * Normalises the recipe to be installed based on static::$recipeShortcuts
     */
    private function normaliseRecipe(): void
    {
        // Normalise recipe based on shortcuts
        $recipe = $this->input->getOption('recipe');
        if (isset(static::$recipeShortcuts[$recipe])) {
            $recipe = static::$recipeShortcuts[$recipe];
            $this->input->setOption('recipe', $recipe);
        }

        // Validate recipe exists
        $recipeDetailsSet = [];
        try {
            $packagist = new PackagistClient();
            $recipeDetailsSet = $packagist->getComposer($recipe);
        } catch (PackageNotFoundException) {
            // no-op, it'll be thrown in our exception below.
        }
        if (!array_key_exists($recipe, $recipeDetailsSet)) {
            throw new InvalidOptionException("The recipe '$recipe' doesn't exist in packagist");
        }
        $recipeDetails = $recipeDetailsSet[$recipe];

        // Validate recipe has a version matching the constraint
        $versionDetailsSet = $recipeDetails->getVersions();
        $constraint = $this->input->getOption('constraint');
        $versionDetails = $versionDetailsSet[$constraint] ?? null;
        if (!$versionDetails) {
            $versionCandidates = Semver::satisfiedBy(array_keys($versionDetailsSet), $constraint);
            if (empty($versionCandidates)) {
                throw new InvalidOptionException("The recipe '$recipe' has no versions compatible with the constraint '$constraint");
            }
            $versionDetails = $versionDetailsSet[Semver::rsort($versionCandidates)[0]];
        }
        $this->recipeVersionDetails = $versionDetails;
    }

    private function identifyPhpVersion(): void
    {
        $phpVersion = $this->input->getOption('php-version');
        // @TODO validate php version?
        if ($phpVersion) {
            return;
        }

        $dependencies = $this->recipeVersionDetails->getRequire();
        if (!isset($dependencies['php'])) {
            // @TODO get from dependencies of dependencies if we ever hit this
            throw new InvalidOptionException('Unable to detect appropriate PHP version, as the chosen recipe has no direct constraint for PHP');
        }

        // Get the lowest possible PHP version allowed by the constraint
        // Note we can't go for the heighest since there's no guarantee such a version exists
        $versionParser = new VersionParser();
        $phpConstraint = $versionParser->parseConstraints($dependencies['php']);
        $phpVersion = $phpConstraint->getLowerBound()->getVersion();
        $this->input->setOption('php-version', substr($phpVersion, 0, 3));
    }

    private function normaliseName(): void
    {
        $name = $this->input->getArgument('env-name') ?? '';

        if (!$this->validateEnvName($name)) {
            $defaultName = $this->getDefaultEnvName();
            $name = $this->output->ask('Name this environment.', $defaultName, function (string $answer): string {
                if (!$this->validateEnvName($answer)) {
                    throw new RuntimeException(
                        'You must provide an environment name. Is must not contain the following characters: '
                        . static::$invalidEnvNameChars
                    );
                }
                return $answer;
            });
        }

        $this->input->setArgument('env-name', $name);
    }

    private function validateEnvName(string $name): bool
    {
        // Name must have a value
        if (!$name) {
            return false;
        }
        // Name must not represent a pre-existing project
        if (DDevHelper::getProjectDetails($name) !== null) {
            return false;
        }
        // Name must not have invalid characters
        $invalidCharsRegex = '/[' . preg_quote(static::$invalidEnvNameChars, '/') . ']/';
        return !preg_match($invalidCharsRegex, $name);
    }

    private function getDefaultEnvName(): string
    {
        $invalidCharsRegex = '/[' . preg_quote(static::$invalidEnvNameChars, '/') . ']/';
        // Normalise recipe by replacing 'invalid' chars with hyphen
        $recipeParts = explode('-', preg_replace($invalidCharsRegex, '-', $this->input->getOption('recipe')));
        $recipe = end($recipeParts);
        // Normalise constraints to remove stability flags
        $constraint = preg_replace('/^(dev-|v(?=\d))|-dev|(#|@).*?$/', '', $this->input->getOption('constraint'));
        $constraint = preg_replace($invalidCharsRegex, '-', trim($constraint, '~^'));
        $name = $recipe . '_' . $constraint;

        if (!empty($this->input->getOption('pr'))) {
            $name .= '_' . 'with-prs';
        }

        return $name;
    }

    private function validateOptions()
    {
        $this->input->validate();

        // Validate project path
        $rootNotEmpty = is_dir($this->projectRoot) && (new RecursiveDirectoryIterator($this->projectRoot, RecursiveDirectoryIterator::SKIP_DOTS))->valid();
        if ($rootNotEmpty) {
            throw new RuntimeException('Project root path must be empty.');
        }
        if (is_file($this->projectRoot)) {
            throw new RuntimeException('Project root path must not be a file.');
        }

        // Validate DB
        $validDbDrivers = [
            'mysql',
            'mariadb',
            // 'postgres', // @TODO add postgres support. Maybe.
        ];
        if (!in_array($this->input->getOption('db'), $validDbDrivers)) {
            throw new InvalidOptionException('--db must be one of ' . implode(', ', $validDbDrivers));
        }

        // Validate recipe
        // see https://getcomposer.org/doc/04-schema.md#name for regex
        if (!preg_match('%^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*$%', $this->input->getOption('recipe'))) {
            throw new InvalidOptionException('recipe must be a valid composer package name.');
        }

        // @TODO validate if extra module(s) even exist

        // @TODO validate if composer options are valid??
    }

    protected function configure(): void
    {
        $this->setHelp(<<<HELP
        Creates a new environment in the project path using the env name and a unique integer value.
        The environment directory contains the docker-compose file, test artifacts, logs, web root, and .env file.
        HELP);
        $this->addArgument(
            'env-name',
            InputArgument::OPTIONAL,
            'The name of the environment. This will be used for the directory and the webhost. '
            . 'Defaults to a name generated based on the recipe and constraint. '
            . 'Must not contain the following characters: ' . static::$invalidEnvNameChars
        );
        $recipeDescription = '';
        foreach (static::$recipeShortcuts as $shortcut => $recipe) {
            $recipeDescription .= "\"$shortcut\" ($recipe), ";
        }
        $this->addOption(
            'recipe',
            'r',
            InputOption::VALUE_REQUIRED,
            'The recipe to install. Options: ' . $recipeDescription . 'any recipe composer name (e.g. "silverstripe/recipe-cms")',
            'installer'
        );
        $this->addOption(
            'constraint',
            'c',
            InputOption::VALUE_REQUIRED,
            'The version constraint to use for the installed recipe.',
            '5.x-dev'
        );
        $this->addOption(
            'extra-module',
            'm',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Any additional modules to be required before dev/build.',
            []
        );
        $this->addOption(
            'composer-option',
            'o',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Any additional arguments to be passed to the composer create-project command.',
            ['--prefer-source']
        );
        $this->addOption(
            'php-version',
            'P',
            InputOption::VALUE_REQUIRED,
            'The PHP version to use for this environment. Uses the lowest allowed version by default.'
        );
        $this->addOption(
            'db',
            null,
            InputOption::VALUE_REQUIRED,
            // @TODO we sure we don't want to let postgres and sqlite3 be used?
            'The database type to be used. Must be one of "mariadb", "mysql".',
            'mysql'
        );
        $this->addOption(
            'db-version',
            null,
            InputOption::VALUE_REQUIRED,
            'The version of the database docker image to be used.'
        );
        $this->addOption(
            'pr',
            null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            <<<DESC
            Optional pull request URL or github referece, e.g. "silverstripe/silverstripe-framework#123" or "https://github.com/silverstripe/silverstripe-framework/pull/123"
            If included, the command will checkout out the PR branch in the appropriate vendor package.
            Multiple PRs can be included (for separate modules) by using `--pr` multiple times.
            DESC,
            []
        );
        $this->addOption(
            'pr-has-deps',
            null,
            InputOption::VALUE_NONE,
            'A PR from the --pr option has dependencies which need to be included in the first composer install.'
        );
        $this->addOption(
            'include-dynamodb',
            null,
            InputOption::VALUE_NEGATABLE,
            'Use a local dynamo db container to store session data and install silverstripe/dynamodb.',
            false
        );
        $this->addOption(
            'include-frameworktest',
            null,
            InputOption::VALUE_NEGATABLE,
            'Include silverstripe/frameworktest even if it isnt in the chosen recipe.',
            true
        );
        $this->addOption(
            'include-recipe-testing',
            null,
            InputOption::VALUE_NEGATABLE,
            'Include silverstripe/recipe-testing even if it isnt in the chosen recipe.',
            true
        );
    }
}
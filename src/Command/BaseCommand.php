<?php declare(strict_types=1);

namespace GuySartorelli\ExtendedDdev\Command;

use LogicException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @author Guy Sartorelli
 */
abstract class BaseCommand extends Command
{
    protected InputInterface $input;

    protected SymfonyStyle $output;

    protected const META_DIR_NAME = '.eddev';

    protected const STYLE_STEP = '<fg=blue>';
    protected const STYLE_END = '</>';

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = new SymfonyStyle($input, $output);

        parent::initialize($input, $output);
    }

    /**
     * Get the value of the environment variable, or null if not set
     */
    protected function getEnv(string $key, bool $allowNull = false): mixed
    {
        $value = isset($_ENV[$key]) ? $_ENV[$key] : null;
        if ($value === null && !$allowNull) {
            throw new LogicException("Environment value '$key' must be defined in the .env file.");
        }
        return $value;
    }

    /**
     * An easier-to-read success message format than the default of SymfonyStyle.
     */
    protected function success(string|array $message): void
    {
        $this->output->block($message, 'OK', 'fg=black;bg=bright-green', ' ', true);
    }
}

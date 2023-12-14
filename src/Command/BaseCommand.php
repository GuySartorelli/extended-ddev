<?php declare(strict_types=1);

namespace GuySartorelli\ExtendedDdev\Command;

use LogicException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Terminal;

/**
 * @author Guy Sartorelli
 */
abstract class BaseCommand extends Command
{
    protected InputInterface $input;

    protected SymfonyStyle $output;

    private ?ProgressBar $progressBar = null;

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
     * Nice standardised output style for outputting step information
     */
    protected function outputSubStep(string $output): void
    {
        $this->clearProgressBar();
        $this->output->writeln('<fg=gray>' . $output . self::STYLE_END);
    }

    /**
     * Nice standardised output style for outputting step information
     */
    protected function outputStep(string $output): void
    {
        $this->clearProgressBar();
        $this->output->writeln('<fg=blue>' . $output . self::STYLE_END);
    }

    /**
     * An easier-to-read success message format than the default of SymfonyStyle.
     */
    protected function success(string|array $message): void
    {
        // Retain background style inside any formatted sections
        $message = preg_replace('#(<)([^/]+>.+?</>)#', '$1bg=bright-green;$2', $message);
        // Render the message
        $this->output->block($message, 'OK', 'fg=black;bg=bright-green', padding: true, escape: false);
    }

    /**
     * An easier-to-read error message format than the default of SymfonyStyle.
     * Also clears any progress bars in progress.
     */
    protected function warning(string|array $message)
    {
        $this->clearProgressBar();
        // Retain colour style inside any formatted sections
        $message = preg_replace('#(<)([^/]+>.+?</>)#', '$1bg=yellow$2', $message);
        // Render the message
        $this->output->block($message, 'WARNING', 'fg=black;bg=yellow', padding: true, escape: false);
    }

    /**
     * An easier-to-read error message format than the default of SymfonyStyle.
     * Also stops any progress bars in progress.
     */
    protected function error(string|array $message): void
    {
        $this->endProgressBar();
        // Retain colour style inside any formatted sections
        $message = preg_replace('#(<)([^/]+>.+?</>)#', '$1fg=white;bg=red$2', $message);
        // Render the message
        $this->output->block($message, 'ERROR', 'fg=white;bg=red', padding: true, escape: false);
    }

    public function handleDdevOutput($type, $data): void
    {
        $this->advanceProgressBar($data);
    }

    /**
     * Advances the current progress bar, starting a new one if necessary.
     */
    protected function advanceProgressBar(?string $message = null): void
    {
        $barWidth = 15;
        $timeWidth = 20;
        if ($this->progressBar === null) {
            $this->progressBar = $this->output->createProgressBar();
            $this->progressBar->setFormat("%elapsed:10s% %bar% %message%");
            $this->progressBar->setBarWidth($barWidth);
            $this->progressBar->setMessage('');
        }
        $this->progressBar->display();

        if ($message !== null) {
            // Make sure messages can't span multiple lines - truncate if necessary
            $terminal = new Terminal();
            $threshold = $terminal->getWidth() - $barWidth - $timeWidth - 5;
            $message = Helper::removeDecoration($this->output->getFormatter(), str_replace("\n", ' ', $message));
            if (strlen($message) > $threshold) {
                $message = substr($message, 0, $threshold - 3) . '...';
            }
            $this->progressBar->setMessage($message);
        }

        $this->progressBar->advance();
    }

    /**
     * Clears the current progress bar (if any) from the console.
     *
     * Useful if we need to output a warning while a progress bar may be running.
     */
    protected function clearProgressBar(): void
    {
        $this->progressBar?->clear();
    }

    /**
     * Clears and unsets the progressbar if there is one.
     */
    protected function endProgressBar(): void
    {
        if ($this->progressBar !== null) {
            $this->progressBar->finish();
            $this->progressBar->clear();
            $this->progressBar = null;
        }
    }
}

<?php

declare(strict_types=1);

namespace LaraGram\Server\Console;

use LaraGram\Console\Command\Command;
use LaraGram\Console\Exception\ExceptionInterface;
use LaraGram\Console\Input\ArgvInput;
use LaraGram\Console\Input\InputInterface;
use LaraGram\Console\Input\InputOption;
use LaraGram\Console\Output\OutputInterface;
use LaraGram\Server\Storage\RetentionPolicy;
use LaraGram\Server\Storage\StorageCleaner;
use LaraGram\Server\Storage\StorageReport;
use LaraGram\Server\Support\Units;

/**
 * laragram-server storage:clean: one clean-up of the downloaded files, for
 * cron or a scheduler. Safe to run next to a running server: a deleted file
 * is simply downloaded again by the next getFile.
 */
final class StorageCleanCommand extends Command
{
    /**
     * name => [takes a value, description].
     */
    private const OPTIONS = [
        'help' => [false, 'display this help text and exit'],
        'dir' => [true, 'server working directory (default is the current directory)'],
        'files-dir' => [true, 'directory of the downloaded files (defaults to the working directory)'],
        'filter' => [true, "\"<remainder>/<modulo>\". Only bots with 'bot_user_id % modulo == remainder'"],
        'max-files' => [true, 'keep at most this many files; the least recently used are deleted'],
        'max-size' => [true, 'keep at most this much data, like 500M or 2G; the least recently used are deleted'],
        'max-age' => [true, 'delete files older than this, like 12h or 7d'],
        'all' => [false, 'delete every file the filters select, without any limit'],
        'age-from' => [true, 'measure the age from the last "access" (default) or from the "download"'],
        'immunity' => [true, 'never delete files downloaded within this time (default is 1h, 0 turns it off)'],
        'scope' => [true, 'apply the limits to each "bot" (default) or to the whole "server"'],
        'types' => [true, 'only these types, comma-separated: '.'photos, videos, documents, music, ...'],
        'exclude-types' => [true, 'never these types, comma-separated'],
        'extensions' => [true, 'only these file extensions, comma-separated'],
        'exclude-extensions' => [true, 'never these file extensions, comma-separated'],
        'min-file-size' => [true, 'only files of at least this size'],
        'max-file-size' => [true, 'only files of at most this size'],
        'bots' => [true, 'only these bot identifiers, comma-separated'],
        'exclude-bots' => [true, 'never these bot identifiers, comma-separated'],
        'dry-run' => [false, 'report what would be deleted without deleting it'],
        'list' => [false, 'print every file deleted'],
        'json' => [false, 'print the report as JSON'],
    ];

    private string $binary = 'laragram-server';

    public function __construct()
    {
        parent::__construct('storage:clean');
    }

    protected function configure(): void
    {
        foreach (self::OPTIONS as $name => [$hasValue, $description]) {
            $this->addOption($name, null, $hasValue ? InputOption::VALUE_REQUIRED : InputOption::VALUE_NONE, $description);
        }
    }

    /**
     * @param list<string> $argv
     */
    public function main(array $argv, OutputInterface $output): int
    {
        $this->binary = $argv[0] ?? $this->binary;
        $this->mergeApplicationDefinition();

        try {
            $input = new ArgvInput($argv, $this->getDefinition());
        } catch (ExceptionInterface $e) {
            $output->writeln("{$this->binary}: {$e->getMessage()}");
            $output->write($this->usage());

            return 1;
        }

        return $this->execute($input, $output);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('help')) {
            $output->write($this->usage());

            return 0;
        }

        try {
            $values = [];
            foreach (self::OPTIONS as $name => [$hasValue]) {
                if ($hasValue && $input->getOption($name) !== null) {
                    $values[$name] = $input->getOption($name);
                }
            }
            $policy = RetentionPolicy::fromOptions($values, null, (bool) $input->getOption('all'));
            if (!$policy->hasLimits()) {
                throw new \InvalidArgumentException('Specify what to delete: --max-files, --max-size, --max-age or --all');
            }

            $working = $this->directory((string) ($input->getOption('dir') ?? '.'));
            $files = $input->getOption('files-dir') === null ? $working : $this->directory((string) $input->getOption('files-dir'));
            $botFilter = $this->botFilter($input->getOption('filter'));
        } catch (\InvalidArgumentException $e) {
            $output->writeln("{$this->binary}: {$e->getMessage()}");

            return 1;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $report = (new StorageCleaner($files, $working, $botFilter))->run($policy, $dryRun);

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode(['dry_run' => $dryRun] + $report->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->printReport($report, $dryRun, (bool) $input->getOption('list'), $output);
        }

        return $report->failed > 0 ? 1 : 0;
    }

    private function printReport(StorageReport $report, bool $dryRun, bool $list, OutputInterface $output): void
    {
        if ($list) {
            foreach ($report->removed as ['path' => $path, 'size' => $size, 'reason' => $reason]) {
                $output->writeln(sprintf('%s  %s  (%s)', $path, Units::formatSize($size), $reason));
            }
        }

        $output->writeln(sprintf('Scanned: %d files, %s', $report->scannedFiles, Units::formatSize($report->scannedBytes)));
        $output->writeln(sprintf('%s: %d files, %s', $dryRun ? 'Would delete' : 'Deleted', $report->removedFiles, Units::formatSize($report->removedBytes)));
        $output->writeln(sprintf('  by age: %d, by count: %d, by size: %d, by --all: %d', $report->removedByAge, $report->removedByCount, $report->removedBySize, $report->removedByRequest));
        foreach ($report->removedTypes as $type => ['files' => $files, 'bytes' => $bytes]) {
            $output->writeln(sprintf('  %s: %d files, %s', $type, $files, Units::formatSize($bytes)));
        }
        $output->writeln(sprintf('Kept as recent: %d files', $report->immuneFiles));
        if ($report->failed > 0) {
            $output->writeln(sprintf('Failed to delete: %d files', $report->failed));
        }
    }

    private function directory(string $path): string
    {
        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            throw new \InvalidArgumentException("Directory \"{$path}\" doesn't exist");
        }

        return rtrim($real, '/').'/';
    }

    /**
     * @return (\Closure(int): bool)|null
     */
    private function botFilter(mixed $filter): ?\Closure
    {
        if ($filter === null) {
            return null;
        }
        [$remainder, $modulo] = array_pad(explode('/', (string) $filter, 2), 2, '');
        if (!ctype_digit($remainder) || !ctype_digit($modulo) || (int) $remainder >= (int) $modulo) {
            throw new \InvalidArgumentException('Wrong argument specified: ensure that remainder < modulo');
        }

        return static fn (int $botId): bool => $botId % (int) $modulo === (int) $remainder;
    }

    private function usage(): string
    {
        $lines = ["Usage: {$this->binary} storage:clean [OPTION]...", '', 'Delete downloaded files. Options:'];
        foreach (self::OPTIONS as $name => [$hasValue, $description]) {
            $lines[] = str_pad('  --'.$name.($hasValue ? '=<arg>' : ''), 30).' '.$description;
        }

        return implode("\n", $lines)."\n";
    }
}

<?php

declare(strict_types=1);

namespace LaraGram\Server\Console;

use LaraGram\Console\Command\Command;
use LaraGram\Console\Input\InputInterface;
use LaraGram\Console\Input\InputOption;
use LaraGram\Console\Output\OutputInterface;
use LaraGram\Console\Style\Style;
use LaraGram\Filesystem\Filesystem;
use LaraGram\Server\Generator\DriftReport;
use LaraGram\Server\Generator\MethodTableEmitter;
use LaraGram\Server\Generator\SpecRepository;

/**
 * bin/generate: regenerate src/Generated from the pinned Bot API spec.
 *
 *   bin/generate           regenerate from resources/spec/telegram-api.json
 *   bin/generate --fetch   download the latest spec first and report what changed
 */
final class GenerateCommand extends Command
{
    public function __construct(private readonly string $basePath)
    {
        parent::__construct('generate');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Regenerate the Bot API tables from the spec')
            ->addOption('fetch', null, InputOption::VALUE_NONE, 'Download the latest scraped Bot API spec')
            ->addOption('spec-url', null, InputOption::VALUE_REQUIRED, 'Spec URL used with --fetch', SpecRepository::SPEC_URL);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new Style($input, $output);
        $files = new Filesystem();
        $specs = new SpecRepository($files, $this->basePath.'/resources/spec');

        $previous = null;
        if ($input->getOption('fetch')) {
            $previous = $files->exists($specs->specPath()) ? $specs->spec() : null;
            $specs->fetchSpec((string) $input->getOption('spec-url'));
            $io->writeln('<info>Fetched</info> '.$specs->specPath());
        }

        $spec = $specs->spec();

        $target = $this->basePath.'/src/Generated/MethodTable.php';
        (new MethodTableEmitter($files))->emit($spec, $target);
        $io->writeln('<info>Generated</info> '.$target);

        $io->newLine();
        $io->writeln('Bot API <comment>'.$spec['version'].'</comment>'.($previous !== null ? " (was <comment>{$previous['version']}</comment>)" : ''));

        foreach ((new DriftReport())->build($previous, $spec) as $section => $items) {
            $io->writeln(sprintf('%s: <comment>%d</comment>', str_replace('_', ' ', ucfirst($section)), count($items)));
            if ($items !== [] && ($section !== 'unimplemented_handlers' || $io->isVerbose())) {
                $io->listing($items);
            }
        }

        return self::SUCCESS;
    }
}

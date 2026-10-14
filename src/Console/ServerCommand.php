<?php

declare(strict_types=1);

namespace LaraGram\Server\Console;

use LaraGram\Console\Command\Command;
use LaraGram\Console\Exception\ExceptionInterface;
use LaraGram\Console\Input\ArgvInput;
use LaraGram\Console\Input\InputInterface;
use LaraGram\Console\Input\InputOption;
use LaraGram\Console\Output\OutputInterface;
use LaraGram\Filesystem\Filesystem;
use LaraGram\Log\Logger\Handler\StreamHandler;
use LaraGram\Log\Logger\Level;
use LaraGram\Log\Logger\Logger;
use LaraGram\MTProto\TL\Compiler\SchemaCompiler;
use LaraGram\Server\Foundation\ServerParameters;
use LaraGram\Server\Generated\MethodTable;
use LaraGram\Server\Http\HttpServer;

/**
 * The server entry point. Options, defaults, environment variables and
 * start-up checks follow telegram-bot-api.cpp.
 */
final class ServerCommand extends Command
{
    /**
     * name => [shortcut, takes a value, description]; in the official order.
     */
    private const OPTIONS = [
        'help' => ['h', false, 'display this help text and exit'],
        'version' => [null, false, 'display version number and exit'],
        'local' => [null, false, 'allow the Bot API server to serve local requests'],
        'api-id' => [null, true, 'application identifier for Telegram API access, which can be obtained at https://my.telegram.org (defaults to the value of the TELEGRAM_API_ID environment variable)'],
        'api-hash' => [null, true, 'application identifier hash for Telegram API access, which can be obtained at https://my.telegram.org (defaults to the value of the TELEGRAM_API_HASH environment variable)'],
        'http-port' => ['p', true, 'HTTP listening port (default is 8081)'],
        'http-stat-port' => ['s', true, 'HTTP statistics port'],
        'dir' => ['d', true, 'server working directory'],
        'files-dir' => ['f', true, 'directory for storing auxiliary files (defaults to the working directory)'],
        'temp-dir' => ['t', true, 'directory for storing HTTP server temporary files'],
        'filter' => [null, true, "\"<remainder>/<modulo>\". Allow only bots with 'bot_user_id % modulo == remainder'"],
        'max-webhook-connections' => [null, true, 'default value of the maximum webhook connections per bot'],
        'http-ip-address' => [null, true, 'local IP address, HTTP connections to which will be accepted. By default, connections to any local IPv4 address are accepted'],
        'http-stat-ip-address' => [null, true, 'local IP address, HTTP statistics connections to which will be accepted. By default, statistics connections to any local IPv4 address are accepted'],
        'log' => ['l', true, 'path to the file where the log will be written'],
        'verbosity' => ['v', true, 'log verbosity level'],
        'memory-verbosity' => [null, true, 'memory log verbosity level; defaults to 3'],
        'log-max-file-size' => [null, true, 'maximum size of the log file in bytes before it will be auto-rotated (default is 2000000000)'],
        'username' => ['u', true, 'effective user name to switch to'],
        'groupname' => ['g', true, 'effective group name to switch to'],
        'max-connections' => ['c', true, 'maximum number of open file descriptors'],
        'cpu-affinity' => [null, true, 'CPU affinity as 64-bit mask (defaults to all available CPUs)'],
        'main-thread-affinity' => [null, true, 'CPU affinity of the main thread as 64-bit mask (defaults to the value of the option --cpu-affinity)'],
        'proxy' => [null, true, 'HTTP proxy server for outgoing webhook requests in the format http://host:port'],
    ];

    private string $binary = 'laragram-server';

    public function __construct()
    {
        parent::__construct('laragram-server');
    }

    protected function configure(): void
    {
        foreach (self::OPTIONS as $name => [$shortcut, $hasValue, $description]) {
            $this->addOption($name, $shortcut, $hasValue ? InputOption::VALUE_REQUIRED : InputOption::VALUE_NONE, $description);
        }
    }

    /**
     * Parse argv without the console Application, whose own -v/--version
     * options would shadow the official ones.
     *
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
        if ($input->getOption('version')) {
            $output->writeln('Bot API '.MethodTable::VERSION);

            return 0;
        }

        $logger = new Logger('laragram-server');
        $parameters = new ServerParameters($logger);

        try {
            $settings = $this->settings($input, $parameters);
            $this->prepareDirectories($input, $parameters);
            $this->configureLogger($logger, $settings, $parameters);
        } catch (\InvalidArgumentException $e) {
            $output->writeln($e->getMessage());
            $output->write($this->usage());

            return 1;
        }

        if (!$this->ensureSchemaCompiled($output)) {
            return 1;
        }

        if ($parameters->defaultMaxWebhookConnections <= 0) {
            $parameters->defaultMaxWebhookConnections = $parameters->localMode ? 100 : 40;
        }

        $logger->warning("Bot API {$parameters->version} server started");

        (new HttpServer($parameters, $settings))->start();

        return 0;
    }

    /**
     * @return array<string, mixed> HTTP server and logging settings
     */
    private function settings(InputInterface $input, ServerParameters $parameters): array
    {
        $parameters->localMode = (bool) $input->getOption('local');

        $apiId = $input->getOption('api-id') ?? (getenv('TELEGRAM_API_ID') ?: '0');
        $parameters->apiId = $this->integer('api-id', $apiId);
        $parameters->apiHash = (string) ($input->getOption('api-hash') ?? (getenv('TELEGRAM_API_HASH') ?: ''));

        $settings = [
            'http_port' => $this->integer('http-port', $input->getOption('http-port') ?? '8081'),
            'http_stat_port' => $this->integer('http-stat-port', $input->getOption('http-stat-port') ?? '0'),
            'http_ip_address' => $this->ipAddress($input->getOption('http-ip-address') ?? '0.0.0.0'),
            'http_stat_ip_address' => $this->ipAddress($input->getOption('http-stat-ip-address') ?? '0.0.0.0'),
            'verbosity' => $this->integer('verbosity', $input->getOption('verbosity') ?? '0'),
            'memory_verbosity' => $this->integer('memory-verbosity', $input->getOption('memory-verbosity') ?? '3'),
            'log' => (string) ($input->getOption('log') ?? ''),
            'log_max_file_size' => $this->integer('log-max-file-size', $input->getOption('log-max-file-size') ?? '2000000000'),
            'token_range' => [0, 1],
            'username' => (string) ($input->getOption('username') ?? ''),
            'groupname' => (string) ($input->getOption('groupname') ?? ''),
            'max_connections' => $this->integer('max-connections', $input->getOption('max-connections') ?? '0'),
        ];

        if (($filter = $input->getOption('filter')) !== null) {
            [$remainder, $modulo] = array_pad(explode('/', (string) $filter, 2), 2, '');
            if (!ctype_digit($remainder) || !ctype_digit($modulo)) {
                throw new \InvalidArgumentException("{$this->binary}: Wrong argument specified: ensure that remainder < modulo");
            }
            if ((int) $remainder >= (int) $modulo) {
                throw new \InvalidArgumentException("{$this->binary}: Wrong argument specified: ensure that remainder < modulo");
            }
            $settings['token_range'] = [(int) $remainder, (int) $modulo];
        }

        if (($max = $input->getOption('max-webhook-connections')) !== null) {
            $parameters->defaultMaxWebhookConnections = $this->integer('max-webhook-connections', $max);
        }

        if (($proxy = $input->getOption('proxy')) !== null) {
            $proxy = preg_replace('#^https?://#', '', (string) $proxy);
            if (!preg_match('/^(\[[^\]]+\]|[^:]+):(\d+)$/', $proxy)) {
                throw new \InvalidArgumentException("{$this->binary}: Wrong proxy address specified");
            }
            $parameters->webhookProxy = $proxy;
        }

        if ($parameters->apiId <= 0 || $parameters->apiHash === '') {
            throw new \InvalidArgumentException('You must provide valid api-id and api-hash obtained at https://my.telegram.org');
        }
        if ($settings['verbosity'] < 0) {
            throw new \InvalidArgumentException('Wrong verbosity level specified');
        }
        if ($settings['memory_verbosity'] < 0) {
            throw new \InvalidArgumentException('Wrong memory verbosity level specified');
        }

        return $settings;
    }

    /**
     * MTProto builds its TL classes from a composer script of its own, which
     * composer skips when MTProto is not the root package (a global install
     * or a project requiring this server), so build them here when missing.
     * The check looks for the file: asking the autoloader would make it
     * remember the class as missing.
     */
    private function ensureSchemaCompiled(OutputInterface $output): bool
    {
        $compiler = new SchemaCompiler();
        if (is_file($compiler->outputDirectory().'/ClientMethods.php')) {
            return true;
        }

        $output->writeln("Compiling the MTProto TL schema into {$compiler->outputDirectory()}");

        try {
            $compiler->compile();
        } catch (\Throwable $e) {
            $output->writeln("Can't compile the MTProto TL schema: {$e->getMessage()}");

            return false;
        }

        return true;
    }

    /**
     * The working, files and temporary directory checks of the official start-up.
     */
    private function prepareDirectories(InputInterface $input, ServerParameters $parameters): void
    {
        $files = new Filesystem();

        $working = $this->writableDirectory($files, (string) ($input->getOption('dir') ?? '.'), 'working directory', 'Use --dir option to specify a writable working directory', $parameters);
        $parameters->workingDirectory = $working;

        $filesDir = $input->getOption('files-dir');
        $parameters->filesDirectory = $filesDir === null
            ? $working
            : $this->writableDirectory($files, (string) $filesDir, 'files directory', 'Use --files-dir option to specify a writable files directory', $parameters);

        $temp = (string) ($input->getOption('temp-dir') ?? '');
        if ($temp !== '') {
            if (!str_starts_with($temp, '/')) {
                $temp = $parameters->filesDirectory.$temp;
            }
            if (!$files->isDirectory($temp) && !@mkdir($temp, 0750, true)) {
                throw new \InvalidArgumentException("Can't set temporary directory: can't create directory \"{$temp}\"");
            }
        } else {
            $temp = sys_get_temp_dir();
        }

        $probe = @tempnam($temp, 'tmp');
        if ($probe === false || dirname($probe) !== rtrim(realpath($temp) ?: $temp, '/')) {
            if ($probe !== false) {
                @unlink($probe);
            }
            throw new \InvalidArgumentException("Can't create files in the directory \"{$temp}\". Use --temp-dir option to specify another directory for temporary files");
        }
        @unlink($probe);

        $parameters->tempDirectory = rtrim(realpath($temp) ?: $temp, '/');
    }

    private function writableDirectory(Filesystem $files, string $path, string $what, string $hint, ServerParameters $parameters): string
    {
        if ($path === '') {
            throw new \InvalidArgumentException('Empty path specified as '.$what);
        }
        if (!$files->isDirectory($path) && !@mkdir($path, 0750, true)) {
            throw new \InvalidArgumentException('Failed to create '.$what.": can't create directory \"{$path}\"");
        }

        $real = realpath($path);
        if ($real === false) {
            throw new \InvalidArgumentException('Invalid '.$what." specified: can't resolve \"{$path}\"");
        }
        $real = rtrim($real, '/').'/';

        $probe = @tempnam($real, 'tmp');
        if ($probe === false) {
            throw new \InvalidArgumentException("Can't create files in the directory \"{$real}\". {$hint}");
        }
        @unlink($probe);

        if (@mkdir($real.'1:a')) {
            @rmdir($real.'1:a');
        } elseif (@mkdir($real.'1~a')) {
            @rmdir($real.'1~a');
            $parameters->allowColonInFilenames = false;
        } else {
            throw new \InvalidArgumentException("Can't create directories in the directory \"{$real}\". {$hint}");
        }

        return $real;
    }

    /**
     * TDLib verbosity: 0 fatal, 1 error, 2 warning, 3 info, 4+ debug.
     *
     * @param array<string, mixed> $settings
     */
    private function configureLogger(Logger $logger, array $settings, ServerParameters $parameters): void
    {
        $level = self::logLevel($settings['verbosity']);

        $path = $settings['log'];
        if ($path !== '' && !str_starts_with($path, '/')) {
            $path = $parameters->filesDirectory.$path;
        }

        try {
            $handler = new StreamHandler($path === '' ? 'php://stderr' : $path, $level);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException("Can't open log file: {$e->getMessage()}");
        }
        $logger->pushHandler($handler);
        // the "v" argument of the statistics page (next_verbosity_level)
        $parameters->setVerbosity = static fn (int $verbosity) => $handler->setLevel(self::logLevel($verbosity));
    }

    private static function logLevel(int $verbosity): Level
    {
        return match (true) {
            $verbosity <= 0 => Level::Critical,
            $verbosity === 1 => Level::Error,
            $verbosity === 2 => Level::Warning,
            $verbosity === 3 => Level::Info,
            default => Level::Debug,
        };
    }

    private function integer(string $option, mixed $value): int
    {
        $value = trim((string) $value);
        if (!preg_match('/^-?\d+$/', $value)) {
            throw new \InvalidArgumentException("{$this->binary}: Wrong argument specified for option --{$option}: expected an integer");
        }

        return (int) $value;
    }

    private function ipAddress(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException("{$this->binary}: Invalid IP address specified: {$ip}");
        }

        return $ip;
    }

    private function usage(): string
    {
        $lines = ["Usage: {$this->binary} --api-id=<arg> --api-hash=<arg> [--local] [OPTION]...", '', 'Telegram Bot API server. Options:'];

        foreach (self::OPTIONS as $name => [$shortcut, $hasValue, $description]) {
            $head = '  '.($shortcut !== null ? "-{$shortcut}, " : '    ').'--'.$name.($hasValue ? '=<arg>' : '');
            $lines[] = str_pad($head, 35).' '.$description;
        }

        return implode("\n", $lines)."\n";
    }
}

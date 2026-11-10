<?php

declare(strict_types=1);

namespace LaraGram\Server\Http;

use LaraGram\Server\Bot\ClientManager;
use LaraGram\Server\Foundation\ServerParameters;
use LaraGram\Server\Stats\ServerCpuStat;
use Swoole\Server;
use Swoole\Timer;

/**
 * The TCP server hosting the Bot API and, optionally, the statistics port.
 * One worker owns every bot for now, so all traffic of a bot stays in the
 * process that holds its MTProto connection.
 *
 * It is a plain TCP server because Swoole's HTTP server keeps a whole request
 * in memory; {@see HttpMessageParser} reads requests as they arrive instead.
 */
final class HttpServer
{
    /** HttpServer::IDLE_TIMEOUT in the official server. */
    private const IDLE_TIMEOUT = 500;

    private ?ClientManager $clientManager = null;

    private ?HttpConnection $connection = null;

    /** @var array<int, HttpInboundConnection> */
    private array $connections = [];

    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(
        private readonly ServerParameters $parameters,
        private readonly array $settings,
    ) {
    }

    public function start(): void
    {
        $server = new Server($this->settings['http_ip_address'], $this->settings['http_port'], SWOOLE_BASE);

        $options = [
            'worker_num' => 1,
            'enable_coroutine' => true,
            'hook_flags' => SWOOLE_HOOK_ALL,
            'send_yield' => true,
            'heartbeat_check_interval' => 60,
            'heartbeat_idle_time' => self::IDLE_TIMEOUT,
            'max_wait_time' => 30,
            'reload_async' => true,
            'log_level' => SWOOLE_LOG_WARNING,
        ];
        if ($this->settings['username'] !== '') {
            $options['user'] = $this->settings['username'];
        }
        if ($this->settings['groupname'] !== '') {
            $options['group'] = $this->settings['groupname'];
        }
        if ($this->settings['max_connections'] > 0) {
            $options['max_connection'] = $this->settings['max_connections'];
        }
        $server->set($options);

        $statPort = (int) $this->settings['http_stat_port'];
        if ($statPort !== 0) {
            $server->addListener($this->settings['http_stat_ip_address'], $statPort, SWOOLE_SOCK_TCP);
        }

        $server->on('workerStart', function (Server $server): void {
            $this->clientManager = new ClientManager($this->parameters, $this->settings['token_range']);
            $this->connection = new HttpConnection($this->clientManager, $this->parameters->streamFiles);
            \Swoole\Coroutine::create(fn () => $this->clientManager?->restoreWebhooks());

            Timer::tick(1000, function (): void {
                $this->clientManager?->tick();
                ServerCpuStat::update(microtime(true));
            });
        });

        $server->on('workerExit', function (): void {
            // Not a coroutine context: closing answers waiting queries through channels.
            \Swoole\Coroutine::create(fn () => $this->clientManager?->close());
            Timer::clearAll();
        });

        $server->on('connect', function (Server $server, int $fd) use ($statPort): void {
            $info = $server->getClientInfo($fd);
            $isStat = $statPort !== 0 && (int) ($info['server_port'] ?? 0) === $statPort;

            $this->connections[$fd] = new HttpInboundConnection(
                $server,
                $fd,
                (string) ($info['remote_ip'] ?? ''),
                $this->parameters->tempDirectory,
                $this->parameters->logger,
                $isStat
                    ? fn (array $request, HttpInboundConnection $connection) => $this->connection->handleStat($request, $connection)
                    : fn (array $request, HttpInboundConnection $connection) => $this->connection->handle($request, $connection),
                // without file storage, uploads go to the bot while the request is read
                !$isStat && !$this->parameters->fileStorage
                    ? fn (string $path, string $ip) => $this->clientManager?->uploadClient($path, $ip)
                    : null,
            );
        });

        $server->on('receive', function (Server $server, int $fd, int $reactorId, string $data): void {
            ($this->connections[$fd] ?? null)?->receive($data);
        });

        $server->on('close', function (Server $server, int $fd): void {
            ($this->connections[$fd] ?? null)?->onClosed();
            unset($this->connections[$fd]);
        });

        $server->start();

        $this->parameters->logger->warning('--------------------FINISH ENGINE--------------------');
    }
}

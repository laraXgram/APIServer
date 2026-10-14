<?php

declare(strict_types=1);

namespace LaraGram\Server\Http;

use LaraGram\Server\Bot\ClientManager;
use LaraGram\Server\Foundation\ServerParameters;
use LaraGram\Server\Stats\ServerCpuStat;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Swoole\Timer;

/**
 * The Swoole HTTP server hosting the Bot API and, optionally, the
 * statistics port. One worker owns every bot for now, so all traffic of a
 * bot stays in the process that holds its MTProto connection.
 */
final class HttpServer
{
    /** HttpServer::IDLE_TIMEOUT in the official server. */
    private const IDLE_TIMEOUT = 500;

    private ?ClientManager $clientManager = null;

    private ?HttpConnection $connection = null;

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
            'http_parse_post' => false,
            'http_parse_cookie' => false,
            'http_compression' => false,
            'upload_tmp_dir' => $this->parameters->tempDirectory,
            'package_max_length' => $this->parameters->localMode ? 0x7FFFFFFF : 512 * 1024 * 1024,
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
            $server->addListener($this->settings['http_stat_ip_address'], $statPort, SWOOLE_SOCK_TCP)
                ->set(['open_http_protocol' => true]);
        }

        $server->on('workerStart', function (Server $server): void {
            $this->clientManager = new ClientManager($this->parameters, $this->settings['token_range']);
            $this->connection = new HttpConnection($server, $this->clientManager, $this->parameters);
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

        $server->on('request', function (Request $request, Response $response) use ($statPort): void {
            if ($statPort !== 0 && (int) ($request->server['server_port'] ?? 0) === $statPort) {
                $this->connection->handleStat($request, $response);

                return;
            }

            $this->connection->handle($request, $response);
        });

        $server->start();

        $this->parameters->logger->warning('--------------------FINISH ENGINE--------------------');
    }
}

<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Connection;

use PhpAmqpLib\Connection\Heartbeat\AbstractSignalHeartbeatSender;
use RuntimeException;

/** @internal */
final class HeartbeatSender extends AbstractSignalHeartbeatSender
{
    private int $childPid = 0;

    public function register(): void
    {
        $interval = (int) ceil(($this->connection?->getHeartbeat() ?? 0) / 2);
        if ($interval <= 0 || $this->childPid > 0) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGUSR1, fn () => $this->handleSignal($interval));
        $parent = getmypid();
        $pid = pcntl_fork();
        if ($pid === -1) {
            pcntl_signal(SIGUSR1, SIG_IGN);
            throw new RuntimeException('Cannot start the RabbitMQ heartbeat process.');
        }

        if ($pid === 0) {
            // Do not run inherited PHP destructors: they can close the parent's
            // broker connection. SIGKILL releases only this process's handles.
            foreach ([SIGTERM, SIGINT, SIGQUIT, SIGALRM, SIGUSR1, SIGUSR2] as $signal) {
                pcntl_signal($signal, SIG_IGN);
            }
            $next = microtime(true) + $interval;
            while (posix_getppid() === $parent) {
                if (microtime(true) >= $next) {
                    posix_kill($parent, SIGUSR1);
                    $next = microtime(true) + $interval;
                }
                usleep(100000);
            }
            posix_kill(getmypid(), SIGKILL);
            exit(1);
        }

        $this->childPid = $pid;
    }

    public function unregister(): void
    {
        $this->connection = null;
        pcntl_signal(SIGUSR1, SIG_IGN);
        if ($this->childPid > 0) {
            posix_kill($this->childPid, SIGKILL);
            pcntl_waitpid($this->childPid, $status);
            $this->childPid = 0;
        }
    }
}

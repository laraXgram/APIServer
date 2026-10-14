<?php

declare(strict_types=1);

namespace LaraGram\Server\Updates;

use LaraGram\MTProto\Contracts\Store;

/**
 * The pending updates of one bot: a port of td::TQueue for a single queue.
 *
 * Every event is persisted under its own key and the queue tail under
 * "meta", so any MTProto store driver works and a restart resumes with the
 * same update identifiers.
 */
final class UpdateQueue
{
    public const MAX_ID = 2000000000;
    private const MAX_EVENT_LENGTH = 65536 * 8;
    private const MAX_QUEUE_EVENTS = 100000;
    private const MAX_TOTAL_EVENT_LENGTH = 1 << 27;

    /** @var array<int, array{0: string, 1: int, 2: int}> id => [data, expires_at, extra], ascending */
    private array $events = [];

    private int $tailId = 0;

    private int $totalEventLength = 0;

    private int $savedHead = 0;

    private int $savedTail = 0;

    public function __construct(private readonly Store $store)
    {
        $this->load();
    }

    /**
     * @return int the new event identifier, or 0 when the event is rejected
     */
    public function push(string $data, int $expiresAt, int $extra = 0): int
    {
        $length = strlen($data);
        if ($length === 0 || $length > self::MAX_EVENT_LENGTH || count($this->events) >= self::MAX_QUEUE_EVENTS
            || $this->totalEventLength > self::MAX_TOTAL_EVENT_LENGTH - $length || $expiresAt <= 0) {
            return 0;
        }

        if ($this->tailId === 0 || $this->tailId + 1 >= self::MAX_ID) {
            if ($this->tailId !== 0) {
                $this->clear(0);
            }
            $this->tailId = random_int(2 * max(self::MAX_QUEUE_EVENTS, 1000000) + 1, intdiv(self::MAX_ID, 2));
        }

        $id = $this->tailId;
        $this->events[$id] = [$data, $expiresAt, $extra];
        $this->totalEventLength += $length;
        $this->tailId = $id + 1;

        $this->store->put('e'.$id, json_encode([$data, $expiresAt, $extra], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->saveMeta();

        return $id;
    }

    public function getHead(): int
    {
        return $this->events === [] ? $this->tailId : array_key_first($this->events);
    }

    public function getTail(): int
    {
        return $this->tailId;
    }

    public function size(): int
    {
        return count($this->events);
    }

    /**
     * TQueue::get: events from $fromId on, dropping expired ones (and, with
     * $forgetPrevious, everything before $fromId).
     *
     * @return array{0: list<array{id: int, data: string, expires_at: int, extra: int}>, 1: int}|null
     *         [events, queue size], or null when $fromId is out of range
     */
    public function get(int $fromId, bool $forgetPrevious, int $now, int $limit): ?array
    {
        if ($this->tailId === 0 && $this->events === []) {
            return [[], 0];
        }
        if ($fromId > $this->tailId + 10 || $fromId < $this->getHead() - self::MAX_QUEUE_EVENTS) {
            return null;
        }

        if ($forgetPrevious) {
            foreach ($this->events as $id => $_) {
                if ($id >= $fromId) {
                    break;
                }
                $this->remove($id);
            }
        }

        $result = [];
        foreach ($this->events as $id => [$data, $expiresAt, $extra]) {
            if ($id < $fromId) {
                continue;
            }
            if ($expiresAt < $now) {
                $this->remove($id);
                continue;
            }
            if (count($result) === $limit) {
                break;
            }
            $result[] = ['id' => $id, 'data' => $data, 'expires_at' => $expiresAt, 'extra' => $extra];
        }

        $this->saveMeta();

        return [$result, count($this->events)];
    }

    public function forget(int $id): void
    {
        if (isset($this->events[$id])) {
            $this->remove($id);
            $this->saveMeta();
        }
    }

    /**
     * Drop everything but the newest $keepCount events.
     */
    public function clear(int $keepCount): void
    {
        $drop = count($this->events) - $keepCount;
        foreach ($this->events as $id => $_) {
            if ($drop-- <= 0) {
                break;
            }
            $this->remove($id);
        }

        $this->saveMeta();
    }

    /**
     * Delete expired events.
     */
    public function runGc(int $now): int
    {
        $deleted = 0;
        foreach ($this->events as $id => [, $expiresAt]) {
            if ($expiresAt < $now) {
                $this->remove($id);
                $deleted++;
            }
        }

        if ($deleted > 0) {
            $this->saveMeta();
        }

        return $deleted;
    }

    /**
     * Forget the queue entirely (logOut).
     */
    public function destroy(): void
    {
        foreach (array_keys($this->events) as $id) {
            $this->store->forget('e'.$id);
        }
        $this->events = [];
        $this->totalEventLength = 0;
        $this->tailId = 0;
        $this->store->forget('meta');
    }

    private function remove(int $id): void
    {
        $this->totalEventLength -= strlen($this->events[$id][0]);
        unset($this->events[$id]);
        $this->store->forget('e'.$id);
    }

    private function saveMeta(): void
    {
        $head = $this->getHead();
        if ($head === $this->savedHead && $this->tailId === $this->savedTail) {
            return;
        }

        $this->savedHead = $head;
        $this->savedTail = $this->tailId;
        $this->store->put('meta', $this->tailId.':'.$head);
    }

    private function load(): void
    {
        $meta = $this->store->get('meta');
        if ($meta === null || !str_contains($meta, ':')) {
            return;
        }

        [$tail, $head] = array_map('intval', explode(':', $meta, 2));
        if ($tail <= 0 || $head <= 0 || $head > $tail) {
            return;
        }

        // Events pushed after the last meta write: the tail moves on while keys exist.
        while ($this->store->has('e'.$tail)) {
            $tail++;
        }

        $this->tailId = $tail;
        $this->savedHead = $head;
        $this->savedTail = $tail;

        for ($id = $head; $id < $tail; $id++) {
            $raw = $this->store->get('e'.$id);
            if ($raw === null) {
                continue;
            }
            $event = json_decode($raw, true);
            if (!is_array($event) || count($event) !== 3) {
                $this->store->forget('e'.$id);
                continue;
            }
            $this->events[$id] = [(string) $event[0], (int) $event[1], (int) $event[2]];
            $this->totalEventLength += strlen((string) $event[0]);
        }
    }
}

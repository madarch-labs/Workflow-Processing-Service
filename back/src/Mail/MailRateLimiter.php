<?php

declare(strict_types=1);

namespace Wps\Mail;

use Symfony\Component\Mime\Address;
use Wps\Exceptions\RecoverableException;
use Wps\Infrastructure\RedisConnection;

/**
 * Mail rate limits (md/tasks_list/done/4.1-send-email.md §4.5): letters per transport and minute (over → a
 * recoverable error, the step is retried), letters per recipient and hour (over → the recipient is skipped).
 */
final class MailRateLimiter
{
    public function __construct(
        private readonly ?RedisConnection $redis,
        private readonly int $perMinute,
        private readonly int $perRecipientHour,
        /** @var (\Closure(string, int): int)|null counter for tests: key, ttl → new value */
        private readonly ?\Closure $counter = null,
    ) {
    }

    public function checkTransport(string $transportHash): void
    {
        if (($this->redis === null && $this->counter === null) || $this->perMinute <= 0) {
            return;
        }
        $count = $this->increment("mail:rate:{$transportHash}:" . intdiv(time(), 60), 120);
        if ($count > $this->perMinute) {
            throw new RecoverableException("Mail rate limit exceeded ({$this->perMinute} letters per minute for this transport)", ['error_code' => 'MAIL_RATE_LIMIT']);
        }
    }

    /**
     * @param list<Address> $addresses
     */
    public function filterRecipients(array $addresses): RecipientList
    {
        if (($this->redis === null && $this->counter === null) || $this->perRecipientHour <= 0) {
            return new RecipientList($addresses);
        }
        $kept = [];
        $warnings = [];
        $hour = intdiv(time(), 3600);
        foreach ($addresses as $address) {
            $count = $this->increment('mail:rcpt:' . sha1(strtolower($address->getAddress())) . ":{$hour}", 7200);
            if ($count > $this->perRecipientHour) {
                $warnings[] = ['value' => $address->getAddress(), 'reason' => 'rate_limited'];
                continue;
            }
            $kept[] = $address;
        }
        return new RecipientList($kept, $warnings);
    }

    private function increment(string $key, int $ttl): int
    {
        if ($this->counter !== null) {
            return ($this->counter)($key, $ttl);
        }
        return (int)$this->redis?->run(static function (\Redis $r) use ($key, $ttl): int {
            $count = (int)$r->incr($key);
            if ($count === 1) {
                $r->expire($key, $ttl);
            }
            return $count;
        });
    }
}

<?php

declare(strict_types=1);

namespace ForgePanel\Agent\System;

/**
 * Postfix mail queue — pregled (postqueue -j), flush (postqueue -f) i brisanje
 * pojedine poruke ili svih (postsuper -d). Razlozi deferrala vidljivi po poruci
 * (deliverability suite — najveća rupa svih panela).
 */
final class MailQueue
{
    /**
     * Parsira `postqueue -j` izlaz (jedan JSON objekt po retku).
     * @return list<array{queue_id: string, queue_name: string, sender: string, recipients: list<string>, size: int, arrival_time: int, reason: ?string}>
     */
    public static function parseQueue(string $json_lines): array
    {
        $out = [];
        foreach (explode("\n", $json_lines) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $msg = json_decode($line, true);
            if (!is_array($msg) || !isset($msg['queue_id'])) {
                continue;
            }
            $recipients = [];
            $reason = null;
            foreach ($msg['recipients'] ?? [] as $rcpt) {
                if (is_array($rcpt) && isset($rcpt['address'])) {
                    $recipients[] = (string) $rcpt['address'];
                    $reason ??= isset($rcpt['delay_reason']) ? (string) $rcpt['delay_reason'] : null;
                }
            }
            $out[] = [
                'queue_id' => (string) $msg['queue_id'],
                'queue_name' => (string) ($msg['queue_name'] ?? 'maildrop'),
                'sender' => (string) ($msg['sender'] ?? ''),
                'recipients' => $recipients,
                'size' => (int) ($msg['message_size'] ?? 0),
                'arrival_time' => (int) ($msg['arrival_time'] ?? 0),
                'reason' => $reason,
            ];
        }
        return $out;
    }

    /**
     * Trenutni red — agregat po stanju (active/deferred/...) + lista poruka.
     * @return array{total: int, by_queue: array<string, int>, messages: list<array<string, mixed>>}
     */
    public static function list(int $limit = 300): array
    {
        $result = Proc::run(['postqueue', '-j'], timeout_s: 30);
        if (!$result->ok()) {
            // Mail nije instaliran / postfix ne radi — prazan red, ne fatal
            return ['total' => 0, 'by_queue' => [], 'messages' => []];
        }
        $messages = self::parseQueue($result->stdout);
        $by_queue = [];
        foreach ($messages as $m) {
            $by_queue[$m['queue_name']] = ($by_queue[$m['queue_name']] ?? 0) + 1;
        }
        return [
            'total' => count($messages),
            'by_queue' => $by_queue,
            'messages' => array_slice($messages, 0, $limit),
        ];
    }

    public static function flush(): void
    {
        Proc::mustRun(['postqueue', '-f'], timeout_s: 30);
    }

    /** Brisanje jedne poruke (postsuper -d <id>). Queue ID = veliki heksadekadski. */
    public static function delete(string $queue_id): void
    {
        Proc::mustRun(['postsuper', '-d', $queue_id], timeout_s: 30);
    }

    /** Brisanje cijelog reda (postsuper -d ALL). */
    public static function deleteAll(): void
    {
        Proc::mustRun(['postsuper', '-d', 'ALL'], timeout_s: 60);
    }
}

<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Deliverability;
use ForgePanel\Agent\System\DmarcIngest;
use ForgePanel\Agent\System\MailQueue;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\ValidationException;
use ForgePanel\Agent\Validator;

/**
 * deliverability.check — RBL provjera IP-a, SPF/DKIM/DMARC validacija domene,
 * mail queue (postfix) pregled/flush/brisanje i DMARC rua ingest.
 */
final class DeliverabilityCheck extends Operation
{
    private const ACTIONS = ['rbl', 'validate', 'queue', 'queue_flush', 'queue_delete', 'queue_delete_all', 'dmarc_ingest'];

    public function validate(array $params): void
    {
        $action = Validator::oneOf($params['action'] ?? null, self::ACTIONS, 'action');
        if ($action === 'rbl' && filter_var($params['ip'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new ValidationException('ip mora biti IPv4');
        }
        if ($action === 'validate') {
            Validator::fqdn($params['domain'] ?? null);
        }
        if ($action === 'queue_delete' && !preg_match('/^[0-9A-F]{6,32}$/', (string) ($params['queue_id'] ?? ''))) {
            throw new ValidationException('queue_id mora biti Postfix queue ID');
        }
    }

    public function execute(array $params, TaskContext $context): array
    {
        return match ($params['action']) {
            'rbl' => $this->rbl((string) $params['ip']),
            'validate' => [
                'domain' => $params['domain'],
                'validation' => Deliverability::validateDomain(
                    Validator::fqdn($params['domain']),
                    (string) ($params['dkim_selector'] ?? 'forge')
                ),
            ],
            'queue' => MailQueue::list(),
            'queue_flush' => $this->thenList(MailQueue::flush(...)),
            'queue_delete' => $this->thenList(fn () => MailQueue::delete((string) $params['queue_id'])),
            'queue_delete_all' => $this->thenList(MailQueue::deleteAll(...)),
            'dmarc_ingest' => DmarcIngest::run($this->db),
            default => throw new ValidationException('action nepoznat'),
        };
    }

    /** @return array{ip: string, checked: int, listed_on: list<string>} */
    private function rbl(string $ip): array
    {
        $results = Deliverability::checkRbls($ip);
        $listed = array_values(array_filter($results, static fn (array $r) => $r['listed']));
        foreach ($results as $r) {
            $this->db->run(
                'INSERT INTO rbl_checks (ip, rbl, listed) VALUES (?, ?, ?)',
                [$ip, $r['rbl'], (int) $r['listed']]
            );
        }
        return ['ip' => $ip, 'checked' => count($results), 'listed_on' => array_column($listed, 'rbl')];
    }

    /** Izvrši queue mutaciju, zatim vrati svjež red. @return array<string, mixed> */
    private function thenList(\Closure $action): array
    {
        $action();
        return MailQueue::list();
    }
}

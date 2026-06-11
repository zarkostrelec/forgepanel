<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Deliverability;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\ValidationException;
use ForgePanel\Agent\Validator;

/** deliverability.check — RBL provjera IP-a ili SPF/DKIM/DMARC validacija domene. */
final class DeliverabilityCheck extends Operation
{
    public function validate(array $params): void
    {
        Validator::oneOf($params['action'] ?? null, ['rbl', 'validate'], 'action');
        if (($params['action'] ?? '') === 'rbl' && filter_var($params['ip'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new ValidationException('ip mora biti IPv4');
        }
        if (($params['action'] ?? '') === 'validate') {
            Validator::fqdn($params['domain'] ?? null);
        }
    }

    public function execute(array $params, TaskContext $context): array
    {
        if ($params['action'] === 'rbl') {
            $ip = (string) $params['ip'];
            $results = Deliverability::checkRbls($ip);
            $listed = array_values(array_filter($results, static fn (array $r) => $r['listed']));
            // Trajni zapis u rbl_checks
            foreach ($results as $r) {
                $this->db->run(
                    'INSERT INTO rbl_checks (ip, rbl, listed) VALUES (?, ?, ?)',
                    [$ip, $r['rbl'], (int) $r['listed']]
                );
            }
            return ['ip' => $ip, 'checked' => count($results), 'listed_on' => array_column($listed, 'rbl')];
        }

        return ['domain' => $params['domain'], 'validation' => Deliverability::validateDomain(
            Validator::fqdn($params['domain']),
            (string) ($params['dkim_selector'] ?? 'forge')
        )];
    }
}

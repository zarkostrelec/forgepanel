<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Proc;
use ForgePanel\Agent\System\Systemd;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\ValidationException;

/**
 * country.block — ipset + ufw country blokada (dnevno ažurirane GeoIP liste).
 * Gotovi setovi: AI/SEO bot-blocking, xmlrpc blok generira firewall modul.
 */
final class CountryBlock extends Operation
{
    private const IPSET = 'forgepanel_geoblock';
    private const SOURCE = 'https://www.ipdeny.com/ipblocks/data/aggregated';

    public function isLongRunning(): bool
    {
        return true;
    }

    public function validate(array $params): void
    {
        foreach ($params['countries'] ?? [] as $cc) {
            if (!is_string($cc) || !preg_match('/^[a-z]{2}$/i', $cc)) {
                throw new ValidationException("Neispravan country code: $cc");
            }
        }
    }

    public function execute(array $params, TaskContext $context): array
    {
        $this->ensureIpset($context);
        $countries = array_map('strtolower', $params['countries'] ?? []);

        // Reset seta
        Proc::run(['ipset', 'flush', self::IPSET]);
        $total = 0;

        foreach ($countries as $cc) {
            $context->output("Učitavam GeoIP rangeve za $cc");
            $url = self::SOURCE . "/$cc-aggregated.zone";
            $data = Proc::run(['curl', '-fsSL', '--max-time', '60', $url], timeout_s: 90)->stdout;
            foreach (explode("\n", $data) as $cidr) {
                $cidr = trim($cidr);
                if ($cidr !== '' && preg_match('#^\d+\.\d+\.\d+\.\d+(/\d+)?$#', $cidr)) {
                    Proc::run(['ipset', 'add', '-exist', self::IPSET, $cidr]);
                    $total++;
                }
            }
        }

        // iptables pravilo koje dropa set (idempotentno)
        $rule = ['INPUT', '-m', 'set', '--match-set', self::IPSET, 'src', '-j', 'DROP'];
        if (!Proc::run(['iptables', '-C', ...$rule])->ok() && $countries !== []) {
            Proc::mustRun(['iptables', '-I', ...$rule]);
        }
        if ($countries === []) {
            Proc::run(['iptables', '-D', ...$rule]);
        }

        $context->output("Blokirano $total rangeva iz " . count($countries) . ' zemalja');
        return ['countries' => $countries, 'ranges' => $total];
    }

    private function ensureIpset(TaskContext $context): void
    {
        if (!Proc::run(['which', 'ipset'])->ok()) {
            $context->output('Instaliram ipset');
            \ForgePanel\Agent\System\Apt::install(['ipset'], $context->output(...));
        }
        Proc::run(['ipset', 'create', '-exist', self::IPSET, 'hash:net']);
    }
}

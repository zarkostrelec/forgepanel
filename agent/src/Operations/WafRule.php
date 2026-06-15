<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Proc;
use ForgePanel\Agent\System\Systemd;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\ValidationException;
use ForgePanel\Agent\Validator;

/**
 * waf.rule — pregled blokiranih zahtjeva (ModSecurity log) i upravljanje
 * whitelistom (SecRuleRemoveById) per vhost. Rješava glavnu manu OWASP CRS-a:
 * false positive horor bez dobrog UI-ja → whitelist jednim klikom.
 */
final class WafRule extends Operation
{
    private const ACTIONS = ['log', 'whitelist_add', 'whitelist_remove'];
    private const LOGS = ['/var/log/nginx/error.log', '/var/log/modsec_audit.log'];

    public function validate(array $params): void
    {
        $action = Validator::oneOf($params['action'] ?? null, self::ACTIONS, 'action');
        Validator::fqdn($params['domain'] ?? null);
        if ($action !== 'log' && !preg_match('/^\d{1,9}$/', (string) ($params['rule_id'] ?? ''))) {
            throw new ValidationException('rule_id mora biti CRS numerički ID');
        }
    }

    public function execute(array $params, TaskContext $context): array
    {
        $domain = Validator::fqdn($params['domain']);
        return match ($params['action']) {
            'log' => ['domain' => $domain, 'events' => $this->readLog($domain)],
            'whitelist_add' => $this->whitelist($domain, (string) $params['rule_id'], true),
            'whitelist_remove' => $this->whitelist($domain, (string) $params['rule_id'], false),
            default => throw new ValidationException('action nepoznat'),
        };
    }

    /**
     * Parsira ModSecurity denijale iz nginx error loga za zadanu domenu.
     * @return list<array{rule_id: string, msg: string, uri: string, client: string, count: int}>
     */
    private function readLog(string $domain): array
    {
        $agg = [];
        foreach (self::LOGS as $log) {
            if (!is_readable($log)) {
                continue;
            }
            // zadnjih ~4000 redaka je dovoljno za pregled
            $lines = $this->tail($log, 4000);
            foreach ($lines as $line) {
                if (!str_contains($line, 'ModSecurity')) {
                    continue;
                }
                $hostname = $this->field($line, 'hostname');
                if ($hostname !== '' && $hostname !== $domain) {
                    continue;
                }
                $id = $this->field($line, 'id');
                if ($id === '') {
                    continue;
                }
                $key = $id . '|' . $this->field($line, 'uri');
                if (isset($agg[$key])) {
                    $agg[$key]['count']++;
                    continue;
                }
                $agg[$key] = [
                    'rule_id' => $id,
                    'msg' => $this->field($line, 'msg'),
                    'uri' => $this->field($line, 'uri'),
                    'client' => $this->field($line, 'client') ?: $this->clientIp($line),
                    'count' => 1,
                ];
            }
        }
        usort($agg, static fn ($a, $b) => $b['count'] <=> $a['count']);
        return array_slice(array_values($agg), 0, 100);
    }

    private function field(string $line, string $name): string
    {
        return preg_match('/\[' . preg_quote($name, '/') . ' "([^"]*)"\]/', $line, $m) ? $m[1] : '';
    }

    private function clientIp(string $line): string
    {
        return preg_match('/\[client ([0-9a-fA-F:.]+)\]/', $line, $m) ? $m[1] : '';
    }

    /** Zadnjih N redaka datoteke bez učitavanja cijele u memoriju. @return list<string> */
    private function tail(string $path, int $n): array
    {
        $f = @fopen($path, 'r');
        if ($f === false) {
            return [];
        }
        $buffer = '';
        $chunk = 65536;
        fseek($f, 0, SEEK_END);
        $pos = ftell($f);
        $lines = 0;
        while ($pos > 0 && $lines <= $n) {
            $read = (int) min($chunk, $pos);
            $pos -= $read;
            fseek($f, $pos);
            $buffer = fread($f, $read) . $buffer;
            $lines = substr_count($buffer, "\n");
        }
        fclose($f);
        return array_slice(explode("\n", $buffer), -$n);
    }

    /** @return array{domain: string, rule_id: string, whitelist: list<string>} */
    private function whitelist(string $domain, string $rule_id, bool $add): array
    {
        $file = WafToggle::WAF_DIR . "/$domain.whitelist.conf";
        if (!is_dir(WafToggle::WAF_DIR)) {
            mkdir(WafToggle::WAF_DIR, 0o755, true);
        }
        $ids = [];
        foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
            if (preg_match('/^SecRuleRemoveById\s+(\d+)/', $line, $m)) {
                $ids[$m[1]] = true;
            }
        }
        if ($add) {
            $ids[$rule_id] = true;
        } else {
            unset($ids[$rule_id]);
        }

        $body = "# ForgePanel WAF whitelist za $domain (SecRuleRemoveById)\n";
        foreach (array_keys($ids) as $id) {
            $body .= "SecRuleRemoveById $id\n";
        }
        $prev = is_file($file) ? (string) file_get_contents($file) : null;
        file_put_contents($file, $body);

        if (Proc::run(['nginx', '-t'])->ok()) {
            Systemd::reload('nginx');
        } else {
            if ($prev === null) {
                @unlink($file);
            } else {
                file_put_contents($file, $prev);
            }
            throw new \RuntimeException('nginx -t pao nakon WAF whitelist promjene — vraćeno');
        }
        return ['domain' => $domain, 'rule_id' => $rule_id, 'whitelist' => array_keys($ids)];
    }
}

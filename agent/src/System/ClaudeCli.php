<?php

declare(strict_types=1);

namespace ForgePanel\Agent\System;

use ForgePanel\Agent\Config;

/**
 * Most prema lokalno instaliranom Claude Code CLI-ju (prijavljen na korisnikov
 * Max/Pro račun — bez API ključa i bez troška tokena).
 *
 * Koristi se SAMO za dijagnostiku/prijedloge ("propose"): claude se pokreće u
 * non-interaktivnom `-p` modu s read-only alatima (Read/Grep/Glob), pa NE može
 * mijenjati sustav. Stvarno izvršavanje predloženih komandi ide kroz zaseban,
 * čovjekom potvrđen korak (op `assistant.exec`).
 */
final class ClaudeCli
{
    /** Kandidatske putanje (agent radi kao root → /root/.local/bin). */
    private const CANDIDATES = [
        '/root/.local/bin/claude',
        '/usr/local/bin/claude',
        '/usr/bin/claude',
    ];

    public function __construct(private readonly Config $config)
    {
    }

    /** Putanja do claude binarija ili null ako nije instaliran. */
    public function bin(): ?string
    {
        $configured = $this->config->get('claude_bin', '');
        if ($configured !== null && $configured !== '' && is_executable($configured)) {
            return $configured;
        }
        foreach (self::CANDIDATES as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }
        $which = Proc::run(['env', 'HOME=' . $this->home(), 'which', 'claude']);
        $found = trim($which->stdout);
        return ($which->ok() && $found !== '' && is_executable($found)) ? $found : null;
    }

    /** Je li CLI dostupan i prijavljen (postoje credentialsi u HOME-u). */
    public function status(): array
    {
        $bin = $this->bin();
        $home = $this->home();
        $logged_in = is_file("$home/.claude/.credentials.json") || is_file("$home/.claude.json");
        return ['available' => $bin !== null, 'logged_in' => $logged_in, 'bin' => $bin];
    }

    /**
     * Pokreni claude u read-only "propose" modu. Vraća tekstualni odgovor.
     * @throws \RuntimeException ako CLI nije dostupan ili padne
     */
    public function query(string $prompt, int $timeout_s = 180): string
    {
        $bin = $this->bin() ?? throw new \RuntimeException('Claude CLI nije instaliran na serveru.');
        // env HOME=… → claude nalazi OAuth credentialse; --allowedTools ograničava
        // na čitanje (write/bash/edit su auto-odbijeni u -p modu). 'timeout' jamči
        // da claude ne visi zauvijek (npr. trust/network) — ubije ga prije Proca.
        $result = Proc::run([
            'env', 'HOME=' . $this->home(),
            'timeout', '-k', '5', (string) $timeout_s,
            $bin, '-p',
            '--allowedTools', 'Read,Grep,Glob',
            '--output-format', 'text',
            $prompt,
        ], timeout_s: $timeout_s + 20);

        if (!$result->ok()) {
            if ($result->exit_code === 124 || $result->exit_code === 137) {
                throw new \RuntimeException("Claude CLI istekao nakon {$timeout_s}s (moguć trust/network problem).");
            }
            throw new \RuntimeException('Claude CLI: ' . trim($result->stderr ?: $result->stdout ?: 'nepoznata greška'));
        }
        return trim($result->stdout);
    }

    private function home(): string
    {
        $home = $this->config->get('claude_home', '/root');
        return ($home === null || $home === '') ? '/root' : $home;
    }
}

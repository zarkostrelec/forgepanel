<?php

declare(strict_types=1);

namespace ForgePanel\Web\Core;

/**
 * Plugin SDK / modul sustav (poglavlje 6 + 14.x). Svaki modul je direktorij u
 * modules/ s manifest.json: ime, verzija, ovisnosti, agent operacije koje
 * registrira. Treća strana dodaje modul bez diranja jezgre.
 */
final class ModuleRegistry
{
    public const MODULES_DIR = '/opt/forgepanel/modules';

    /** @var list<array<string, mixed>>|null */
    private ?array $cache = null;

    public function __construct(private readonly string $dir = self::MODULES_DIR)
    {
    }

    /** @return list<array<string, mixed>> učitani i validirani manifesti */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $modules = [];
        foreach (glob($this->dir . '/*/manifest.json') ?: [] as $manifest_path) {
            $manifest = $this->load($manifest_path);
            if ($manifest !== null) {
                $modules[] = $manifest;
            }
        }
        usort($modules, static fn (array $a, array $b) => $a['name'] <=> $b['name']);
        return $this->cache = $modules;
    }

    /** Sve agent op-codove koje moduli registriraju (za whitelist provjeru). @return list<string> */
    public function registeredOperations(): array
    {
        $ops = [];
        foreach ($this->all() as $module) {
            foreach ($module['operations'] ?? [] as $op) {
                $ops[] = (string) $op;
            }
        }
        return array_values(array_unique($ops));
    }

    /**
     * Validacija manifesta — odbija neispravne (modul se ne učitava, jezgra ostaje sigurna).
     * @return array<string, mixed>|null
     */
    public function load(string $manifest_path): ?array
    {
        $raw = @file_get_contents($manifest_path);
        if ($raw === false) {
            return null;
        }
        $m = json_decode($raw, true);
        if (!is_array($m)) {
            return null;
        }
        // Obavezna polja + format
        if (!isset($m['name'], $m['version'])
            || !is_string($m['name']) || !preg_match('/^[a-z][a-z0-9_]{1,32}$/', $m['name'])
            || !is_string($m['version']) || !preg_match('/^\d+\.\d+\.\d+$/', $m['version'])
        ) {
            return null;
        }
        // Direktorij modula mora odgovarati imenu (anti-spoofing)
        if (basename(dirname($manifest_path)) !== $m['name']) {
            return null;
        }
        $operations = [];
        foreach ($m['operations'] ?? [] as $op) {
            // Modul smije registrirati samo op-codove u vlastitom namespaceu (ime.op)
            if (is_string($op) && preg_match('/^' . preg_quote($m['name'], '/') . '\.[a-z_]{1,32}$/', $op)) {
                $operations[] = $op;
            }
        }
        return [
            'name' => $m['name'],
            'version' => $m['version'],
            'title' => is_string($m['title'] ?? null) ? $m['title'] : $m['name'],
            'description' => is_string($m['description'] ?? null) ? mb_substr($m['description'], 0, 500) : '',
            'author' => is_string($m['author'] ?? null) ? mb_substr($m['author'], 0, 128) : '',
            'depends' => array_values(array_filter((array) ($m['depends'] ?? []), is_string(...))),
            'operations' => $operations,
            'path' => dirname($manifest_path),
        ];
    }
}

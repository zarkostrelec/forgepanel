<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\AnthropicClient;
use ForgePanel\Web\Core\Crypto;
use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

/**
 * AI asistent (modul `assistant`) — opcionalan, admin unosi vlastiti Anthropic
 * ključ. Dijagnostika iz konteksta, objašnjenje configa/log retka, prijedlozi
 * pravila. Asistent SAMO čita i predlaže — izmjene potvrđuje čovjek (poglavlje 14.2).
 */
final class AssistantController extends Controller
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
        Ti si dijagnostički asistent za ForgePanel, web hosting control panel na Ubuntu Server 26.04.
        Dobivaš stvarni kontekst sa servera (metrike, status servisa, log retke, config blokove).
        Tvoj zadatak: objasni problem jasno i predloži konkretne korake.

        STROGA PRAVILA:
        - SAMO čitaš i predlažeš. NIKAD ne tvrdiš da si nešto promijenio — ti nemaš pristup izvršavanju.
        - Sve izmjene izvodi administrator ručno kroz panel; ti daješ prijedlog koji on potvrđuje.
        - Kad predlažeš nginx/fail2ban/config pravilo, daj točan blok koji se može zalijepiti.
        - Budi konkretan i kratak. Vodi s odgovorom (što je problem / što napraviti), pa detalji.
        - Odgovaraj na hrvatskom jeziku.
        PROMPT;

    /** Dodatak za lokalni CLI mod: predložene komande u ```sh blokovima (panel nudi "Izvrši"). */
    private const LOCAL_SUFFIX = <<<'PROMPT'

        DODATNO (lokalni mod):
        - Imaš read-only pristup serveru (smiješ čitati fileove, ali ne mijenjati).
        - Kad predlažeš promjenu, napiši svaku komandu u zasebnom ```sh bloku, jednu komandu po bloku.
        - Administrator vidi tvoj prijedlog i ručno klikne "Izvrši" za svaku komandu.
        - NE tvrdi da si nešto pokrenuo — ti samo predlažeš; izvršava čovjek.
        PROMPT;

    public function register(Router $router): void
    {
        $router->add('GET', '/api/v1/assistant/status', $this->status(...));
        $router->add('POST', '/api/v1/assistant/key', $this->setKey(...));
        $router->add('DELETE', '/api/v1/assistant/key', $this->removeKey(...));
        $router->add('POST', '/api/v1/assistant/ask', $this->ask(...));
        $router->add('POST', '/api/v1/assistant/exec', $this->exec(...));
        $router->add('POST', '/api/v1/assistant/diagnose/{id}', $this->diagnoseVhost(...));
    }

    private function status(Request $request): never
    {
        $this->admin($request);
        $local = $this->localStatus();
        if ($local['available'] && $local['logged_in']) {
            Response::ok(['configured' => true, 'mode' => 'local', 'model' => 'claude-cli']);
        }
        $hasKey = $this->apiKey() !== null;
        Response::ok(['configured' => $hasKey, 'mode' => $hasKey ? 'api' : null, 'model' => $this->model()]);
    }

    /** Status lokalnog Claude CLI-ja preko agenta (dostupan + prijavljen). */
    private function localStatus(): array
    {
        try {
            $s = $this->app->agent->call('assistant.status');
            return ['available' => (bool) ($s['available'] ?? false), 'logged_in' => (bool) ($s['logged_in'] ?? false)];
        } catch (\Throwable) {
            return ['available' => false, 'logged_in' => false];
        }
    }

    /**
     * Izvrši komandu koju je admin POTVRDIO (Forge AI predloži → potvrdi → izvrši).
     * Samo lokalni mod; admin-only; svaka komanda u audit_log.
     */
    private function exec(Request $request): never
    {
        $ctx = $this->admin($request);
        if (!$this->localStatus()['available']) {
            throw new HttpException(409, 'local_assistant_unavailable');
        }
        $command = trim($request->str('command') ?? '');
        if ($command === '' || mb_strlen($command) > 4000) {
            throw new HttpException(422, 'invalid_command');
        }
        $this->app->audit->log($ctx->user_id, $ctx->email, 'assistant.exec', ['command' => mb_substr($command, 0, 500)], $request->ip);
        $result = $this->app->agent->call('assistant.exec', ['command' => $command], 130);
        Response::ok($result);
    }

    private function setKey(Request $request): never
    {
        $ctx = $this->admin($request);
        $key = trim($request->str('api_key') ?? '');
        if (!preg_match('/^sk-ant-[A-Za-z0-9_-]{20,200}$/', $key)) {
            throw new HttpException(422, 'invalid_api_key');
        }
        $model = $request->str('model', AnthropicClient::DEFAULT_MODEL);
        if (!in_array($model, ['claude-opus-4-8', 'claude-sonnet-4-6', 'claude-haiku-4-5', 'claude-fable-5'], true)) {
            throw new HttpException(422, 'invalid_model');
        }

        // Verifikacija ključa prije spremanja
        if (!(new AnthropicClient($key, $model))->verify()) {
            throw new HttpException(422, 'api_key_verification_failed');
        }

        $encrypted = (new Crypto($this->app->config))->encrypt($key);
        $this->app->db->run(
            "INSERT INTO settings (`key`, value) VALUES ('assistant_api_key', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
            [json_encode($encrypted)]
        );
        $this->app->db->run(
            "INSERT INTO settings (`key`, value) VALUES ('assistant_model', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
            [json_encode($model)]
        );
        $this->app->audit->log($ctx->user_id, $ctx->email, 'assistant.key_set', ['model' => $model], $request->ip);
        Response::ok(['configured' => true]);
    }

    private function removeKey(Request $request): never
    {
        $ctx = $this->admin($request);
        $this->app->db->run("DELETE FROM settings WHERE `key` IN ('assistant_api_key', 'assistant_model')");
        Response::ok();
    }

    private function ask(Request $request): never
    {
        $ctx = $this->admin($request);
        $question = trim($request->str('question') ?? '');
        if ($question === '' || mb_strlen($question) > 4000) {
            throw new HttpException(422, 'invalid_question');
        }
        // Opcionalni kontekst koji UI prilaže (config blok, log redak)
        $context = $request->str('context');
        $user_message = $question;
        if (is_string($context) && $context !== '') {
            $user_message .= "\n\n--- KONTEKST ---\n" . mb_substr($context, 0, 12000);
        }

        $local = $this->localStatus();
        if ($local['available'] && $local['logged_in']) {
            $res = $this->app->agent->call(
                'assistant.query',
                ['prompt' => self::SYSTEM_PROMPT . self::LOCAL_SUFFIX . "\n\n--- UPIT ---\n" . $user_message],
                200
            );
            $this->app->audit->log($ctx->user_id, $ctx->email, 'assistant.ask', ['mode' => 'local'], $request->ip);
            Response::ok(['answer' => (string) ($res['answer'] ?? ''), 'mode' => 'local']);
        }

        $answer = $this->client()->ask(self::SYSTEM_PROMPT, $user_message);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'assistant.ask', ['mode' => 'api'], $request->ip);
        Response::ok(['answer' => $answer, 'mode' => 'api']);
    }

    /** "Zašto je site spor?" — asistent dobiva metrike, FPM status, error log vhosta. */
    private function diagnoseVhost(Request $request): never
    {
        $ctx = $this->admin($request);
        $vhost = $ctx->vhostOr404((int) $request->param('id'));

        $context = "Domena: {$vhost['domain']}\nPHP: {$vhost['php_version']}\nBackend: {$vhost['web_backend']}\n\n";

        // Server metrike
        try {
            $metrics = $this->app->agent->call('system.metrics');
            $context .= 'Server: load ' . implode('/', array_map(static fn ($l) => round((float) $l, 2), $metrics['load']))
                . ", RAM slobodno " . $this->mb($metrics['mem_available_bytes']) . " od " . $this->mb($metrics['mem_total_bytes'])
                . ", disk slobodno " . $this->mb($metrics['disk_free_bytes']) . "\n";
        } catch (\Throwable) {
            $context .= "Server metrike nedostupne.\n";
        }

        // FPM pool status
        try {
            $fpm = $this->app->agent->call('service.status', ['service' => 'php' . $vhost['php_version'] . '-fpm']);
            $context .= "PHP-FPM: {$fpm['ActiveState']}\n";
        } catch (\Throwable) {
        }

        // Zadnji error log retci
        try {
            $log = $this->app->agent->call('fs.read', [
                'path' => '/var/www/vhosts/' . $vhost['domain'] . '/logs/error.log',
            ]);
            $decoded = base64_decode((string) ($log['content'] ?? ''), true);
            if (is_string($decoded) && $decoded !== '') {
                $lines = array_slice(explode("\n", $decoded), -40);
                $context .= "\nZadnji error log retci:\n" . implode("\n", $lines);
            }
        } catch (\Throwable) {
        }

        $answer = $this->client()->ask(
            self::SYSTEM_PROMPT,
            "Zašto bi stranica {$vhost['domain']} mogla biti spora ili imati problema? Analiziraj kontekst i predloži korake.\n\n--- KONTEKST ---\n$context"
        );
        $this->app->audit->log($ctx->user_id, $ctx->email, 'assistant.diagnose', ['domain' => $vhost['domain']], $request->ip);
        Response::ok(['answer' => $answer]);
    }

    private function client(): AnthropicClient
    {
        $key = $this->apiKey() ?? throw new HttpException(409, 'assistant_not_configured');
        return new AnthropicClient($key, $this->model());
    }

    private function apiKey(): ?string
    {
        $row = $this->app->db->one("SELECT value FROM settings WHERE `key` = 'assistant_api_key'");
        if ($row === null) {
            return null;
        }
        $encrypted = json_decode((string) $row['value'], true);
        try {
            return (new Crypto($this->app->config))->decrypt((string) $encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    private function model(): string
    {
        $row = $this->app->db->one("SELECT value FROM settings WHERE `key` = 'assistant_model'");
        $model = $row === null ? null : json_decode((string) $row['value'], true);
        return is_string($model) ? $model : AnthropicClient::DEFAULT_MODEL;
    }

    private function mb(int|float $bytes): string
    {
        return round((float) $bytes / 1048576) . ' MB';
    }

    private function admin(Request $request): \ForgePanel\Web\Core\AuthContext
    {
        $ctx = $this->ctx($request, 'assistant:write');
        $ctx->requireRole('admin');
        return $ctx;
    }
}

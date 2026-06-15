<?php

declare(strict_types=1);

namespace ForgePanel\Agent\System;

use ForgePanel\Agent\Db;

/**
 * Vanjski alarm kanali: e-mail (sendmail/Postfix), Telegram bot i generički
 * webhook. Konfiguracija živi u settings.monitoring_alarms. Svaki kanal je
 * best-effort — pad jednog ne ruši ostale niti pozivatelja.
 */
final class Notifier
{
    /** @return array<string, mixed> dekodirana monitoring_alarms konfiguracija */
    public static function config(Db $db): array
    {
        $row = $db->one("SELECT value FROM settings WHERE `key` = 'monitoring_alarms'");
        if ($row === null) {
            return [];
        }
        $cfg = json_decode((string) $row['value'], true);
        return is_array($cfg) ? $cfg : [];
    }

    /** Pošalji poruku na sve konfigurirane kanale. Nikad ne baca. */
    public static function dispatch(Db $db, string $severity, string $title, string $body): void
    {
        $cfg = self::config($db);
        if (($cfg['enabled'] ?? false) !== true) {
            return;
        }
        $channels = is_array($cfg['channels'] ?? null) ? $cfg['channels'] : [];
        $prefix = strtoupper($severity);

        $email = (string) ($channels['email'] ?? '');
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::sendEmail($email, "[$prefix] $title", $body);
        }
        $tg = is_array($channels['telegram'] ?? null) ? $channels['telegram'] : [];
        if (($tg['bot_token'] ?? '') !== '' && ($tg['chat_id'] ?? '') !== '') {
            self::sendTelegram((string) $tg['bot_token'], (string) $tg['chat_id'], "*$prefix* — $title\n$body");
        }
        $webhook = (string) ($channels['webhook'] ?? '');
        if (str_starts_with($webhook, 'https://')) {
            self::sendWebhook($webhook, ['severity' => $severity, 'title' => $title, 'body' => $body, 'ts' => time()]);
        }
    }

    private static function sendEmail(string $to, string $subject, string $body): void
    {
        try {
            $host = trim((string) @file_get_contents('/etc/hostname')) ?: 'localhost';
            $from = "forgepanel@$host";
            $message = "From: ForgePanel <$from>\r\n"
                . 'To: ' . $to . "\r\n"
                . 'Subject: ' . $subject . "\r\n"
                . "Content-Type: text/plain; charset=utf-8\r\n"
                . "X-ForgePanel-Alarm: 1\r\n\r\n"
                . $body . "\r\n";
            Proc::run(['/usr/sbin/sendmail', '-t', '-f', $from], $message, 20);
        } catch (\Throwable $e) {
            error_log('Notifier email: ' . $e->getMessage());
        }
    }

    private static function sendTelegram(string $bot_token, string $chat_id, string $text): void
    {
        // Token je oblika 123:ABC — ne smije u path bez provjere
        if (!preg_match('/^\d+:[\w-]+$/', $bot_token)) {
            return;
        }
        self::post("https://api.telegram.org/bot$bot_token/sendMessage", [
            'chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'Markdown',
        ]);
    }

    /** @param array<string, mixed> $payload */
    private static function sendWebhook(string $url, array $payload): void
    {
        self::post($url, $payload, json: true);
    }

    /** @param array<string, mixed> $data */
    private static function post(string $url, array $data, bool $json = false): void
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POST => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_POSTFIELDS => $json ? json_encode($data, JSON_UNESCAPED_SLASHES) : http_build_query($data),
            CURLOPT_HTTPHEADER => $json ? ['Content-Type: application/json'] : [],
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}

<?php

declare(strict_types=1);

use ForgePanel\Agent\Operations\CronSync;
use ForgePanel\Agent\ValidationException;

// schedule
T::assertSame('*/15 * * * *', CronSync::assertSchedule('*/15 * * * *'), 'cron prihvaća */15');
T::assertSame('0 3 * * 0', CronSync::assertSchedule(' 0  3 * * 0 '), 'cron normalizira razmake');
T::assertSame('0-30/5 1,2 * * 1-5', CronSync::assertSchedule('0-30/5 1,2 * * 1-5'), 'cron prihvaća range/list/step');
T::assertThrows(ValidationException::class, fn () => CronSync::assertSchedule('* * * *'), 'cron odbija 4 polja');
T::assertThrows(ValidationException::class, fn () => CronSync::assertSchedule('* * * * * *'), 'cron odbija 6 polja');
T::assertThrows(ValidationException::class, fn () => CronSync::assertSchedule('@reboot x x x x'), 'cron odbija @reboot');
T::assertThrows(ValidationException::class, fn () => CronSync::assertSchedule('* * * * root'), 'cron odbija slova (user injection)');
T::assertThrows(ValidationException::class, fn () => CronSync::assertSchedule(null), 'cron odbija null');

// command
T::assertSame('php cron.php', CronSync::assertCommand(' php cron.php '), 'command se trima');
T::assertThrows(ValidationException::class, fn () => CronSync::assertCommand("a\nb"), 'command odbija newline');
T::assertThrows(ValidationException::class, fn () => CronSync::assertCommand('date +%s'), 'command odbija % (cron newline)');
T::assertThrows(ValidationException::class, fn () => CronSync::assertCommand(''), 'command odbija prazno');
T::assertThrows(ValidationException::class, fn () => CronSync::assertCommand(str_repeat('x', 501)), 'command odbija predugo');

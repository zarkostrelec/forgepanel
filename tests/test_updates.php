<?php

declare(strict_types=1);

use ForgePanel\Agent\System\Components;
use ForgePanel\Agent\System\UpdatePolicy;

// componentForPackage
T::assertSame('nginx', Components::componentForPackage('nginx'), 'nginx paket → nginx');
T::assertSame('php8.4', Components::componentForPackage('php8.4-fpm'), 'php8.4-fpm → php8.4');
T::assertSame('php8.3', Components::componentForPackage('php8.3-opcache'), 'php8.3-opcache (nije u listi) → php8.3 po prefiksu');
T::assertSame('mariadb', Components::componentForPackage('mariadb-server'), 'mariadb-server → mariadb');
T::assertSame(null, Components::componentForPackage('cowsay'), 'nepoznat paket → null');

// inWindow (1=pon … 7=ned)
$sunday_4am = strtotime('2026-06-14 04:00:00'); // nedjelja
$sunday_6am = strtotime('2026-06-14 06:00:00');
$monday_4am = strtotime('2026-06-15 04:00:00');
T::assert(UpdatePolicy::inWindow('03:00:00', '05:00:00', [7], $sunday_4am), 'window: ned 04h u [7] 03-05');
T::assert(!UpdatePolicy::inWindow('03:00:00', '05:00:00', [7], $sunday_6am), 'window: ned 06h izvan 03-05');
T::assert(!UpdatePolicy::inWindow('03:00:00', '05:00:00', [7], $monday_4am), 'window: pon nije u [7]');
T::assert(UpdatePolicy::inWindow('03:00:00', '05:00:00', [1, 7], $monday_4am), 'window: pon u [1,7]');

// isMajorJump
T::assert(!UpdatePolicy::isMajorJump('8.4.10-1', '8.4.11-1'), 'PHP patch nije major');
T::assert(UpdatePolicy::isMajorJump('8.4.10-1', '8.5.0-1'), 'PHP 8.4→8.5 JE major');
T::assert(!UpdatePolicy::isMajorJump('1:11.8.2-deb', '1:11.8.3-deb'), 'MariaDB patch (epoch) nije major');
T::assert(UpdatePolicy::isMajorJump('1:11.8.2', '1:12.0.1'), 'MariaDB 11→12 JE major');
T::assert(!UpdatePolicy::isMajorJump('1.27.3-1', '1.27.4-1'), 'nginx patch nije major');
T::assert(UpdatePolicy::isMajorJump('1.27.3', '1.28.0'), 'nginx 1.27→1.28 je major (jednoznamenkasti)');
T::assert(!UpdatePolicy::isMajorJump('11.8', '11.9'), 'MariaDB 11.8→11.9 nije major (dvoznamenkasti major)');

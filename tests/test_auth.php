<?php

declare(strict_types=1);

use ForgePanel\Web\Core\Auth;

// argon2id hash roundtrip
$hash = Auth::hashPassword('Tajna-Lozinka-123');
T::assert(str_starts_with($hash, '$argon2id$'), 'hash je argon2id');
T::assert(password_verify('Tajna-Lozinka-123', $hash), 'verify prihvaća ispravnu lozinku');
T::assert(!password_verify('kriva', $hash), 'verify odbija krivu lozinku');

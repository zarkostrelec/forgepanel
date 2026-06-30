<?php

declare(strict_types=1);

use ForgePanel\Web\Core\Auth;

// argon2id hash roundtrip
$hash = Auth::hashPassword('Tajna-Lozinka-123');
T::assert(str_starts_with($hash, '$argon2id$'), 'hash je argon2id');
T::assert(password_verify('Tajna-Lozinka-123', $hash), 'verify prihvaća ispravnu lozinku');
T::assert(!password_verify('kriva', $hash), 'verify odbija krivu lozinku');

// 2FA recovery kodovi: format + normalizacija (jamči da hash kod generiranja == hash kod potrošnje)
$fmt = new ReflectionMethod(Auth::class, 'formatRecoveryCode');
$fmt->setAccessible(true);
$norm = new ReflectionMethod(Auth::class, 'normalizeRecoveryCode');
$norm->setAccessible(true);

$code = $fmt->invoke(null, 'abcde12345');
T::assert($code === 'abcde-12345', 'recovery kod ima oblik xxxxx-xxxxx');
T::assert($norm->invoke(null, 'abcde12345') === 'abcde12345', 'normalizacija hex ostaje ista');
T::assert($norm->invoke(null, $code) === 'abcde12345', 'normalizacija skida crticu');
T::assert($norm->invoke(null, 'ABCDE-12345') === 'abcde12345', 'normalizacija lowercase');
T::assert($norm->invoke(null, ' abcde 12345 ') === 'abcde12345', 'normalizacija skida razmake');
// Korisnik koji upiše kod s crticom ILI bez nje (i bilo kojim caseom) → isti hash kao spremljeni
$stored = hash('sha256', $norm->invoke(null, $code));
T::assert(hash('sha256', $norm->invoke(null, 'ABCDE12345')) === $stored, 'unos bez crtice/uppercase daje isti hash');

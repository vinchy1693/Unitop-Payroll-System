<?php
// Verifies auth.php?action=verify: exiting kiosk mode needs a staff password,
// and a wrong password or an employee account must NOT disturb the session.
require __DIR__ . '/_harness.php';

mkUser('dsh_kiosk_admin', 'Administrator');
$jar = login('dsh_kiosk_admin');

section('1. A wrong password is rejected');
$r = req('POST', 'auth.php?action=verify', ['username' => 'admin', 'password' => 'definitely-wrong'], $jar);
eq('wrong password -> 401', $r['code'], 401);

section('2. Missing fields are rejected');
$r = req('POST', 'auth.php?action=verify', ['username' => 'admin', 'password' => ''], $jar);
eq('empty password -> 422', $r['code'], 422);

section('3. An employee account cannot exit the kiosk');
$r = req('POST', 'auth.php?action=verify', ['username' => 'juan.delacruz', 'password' => 'test123'], $jar);
eq('employee credentials -> 403', $r['code'], 403);
printf("  message: %s\n", $r['json']['message'] ?? '');

section('4. An administrator password succeeds');
$r = req('POST', 'auth.php?action=verify', ['username' => 'admin', 'password' => 'test123'], $jar);
eq('admin credentials -> 200', $r['code'], 200);
printf("  role returned: %s\n", $r['json']['data']['role'] ?? '(none)');

section('5. The kiosk session survived every attempt');
$me = req('GET', 'auth.php?action=me', [], $jar);
eq('still authenticated after failed + successful verifies -> 200', $me['code'], 200);
eq('still the kiosk operator', $me['json']['data']['username'] ?? '', 'dsh_kiosk_admin');

runCleanup();
sweepTestData();
summary();

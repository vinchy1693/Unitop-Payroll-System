<?php
// =====================================================================
// tests/nav_matrix_test.php
//
// Proves the role-aware navigation (assets/js/nav.js) cannot drift from the
// API:
//
//   1. the NAV_ACCESS table covers EVERY data-page value that exists in the
//      HTML, and no data-page value was renamed, dropped or invented
//   2. every page that carries the sidebar actually loads nav.js and starts
//      locked (so no forbidden link can flash into view)
//   3. every role in the table is a real role
//   4. for EVERY page, the roles the nav lets through are exactly the roles
//      the page's own API endpoint lets through - driven over real HTTP:
//      200 for a listed role, 403 for every other one
//
// Run:  C:\xampp\php\php.exe tests/nav_matrix_test.php
// =====================================================================

require __DIR__ . '/_harness.php';

const ROOT_DIR = __DIR__ . '/..';
const ROLE_ORDER = ['Employee', 'Payroll Officer', 'Approver', 'Administrator'];

// Frozen contract: the data-page values the navigation links use today. A
// rename or a dropped link shows up here as a mismatch instead of silently
// breaking api.js highlightNav().
const FROZEN_LINKED_PAGES = [
    'index.html',
    'departments.html',
    'positions.html',
    'employees.html',
    'users.html',
    'attendance.html',
    'qr_codes.html',
    'leave.html',
    'overtime.html',
    'attendance_corrections.html',
    'loans.html',
    'kiosk.html',
    'payroll_periods.html',
    'payroll_runs.html',
    'ledger.html',
    'reports.html',
    'audit.html',
];

// Every admin page that carries the sidebar nav (and therefore nav.js).
const FROZEN_NAV_PAGES = [
    'index.html',
    'departments.html',
    'positions.html',
    'employees.html',
    'users.html',
    'attendance.html',
    'attendance_corrections.html',
    'qr_codes.html',
    'leave.html',
    'leave_types.html',
    'leave_balances.html',
    'overtime.html',
    'loans.html',
    'payroll_periods.html',
    'payroll_runs.html',
    'payslips.html',
    'ledger.html',
    'reports.html',
    'audit.html',
];

// The endpoint each page's own screen is driven by. A page may only be
// navigable when that endpoint answers 200 for the role.
$PAGE_ENDPOINT = [
    'index.html'                  => 'dashboard.php',
    'departments.html'            => 'departments.php',
    'positions.html'              => 'positions.php',
    'employees.html'              => 'employees.php',
    'salary_rates.html'           => 'salary_rates.php',
    'users.html'                  => 'users.php',
    'attendance.html'             => 'attendance.php?date=2027-01-04',
    'qr_codes.html'               => 'qr_codes.php',
    'leave.html'                  => 'leave_requests.php',
    'leave_types.html'            => 'leave_types.php',
    'leave_balances.html'         => 'leave_balances.php',
    'overtime.html'               => 'overtime_records.php?all=1',
    'attendance_corrections.html' => 'attendance_corrections.php',
    'loans.html'                  => 'loans.php',
    'payroll_periods.html'        => 'payroll_periods.php',
    'payroll_runs.html'           => 'payroll_runs.php',
    'payslips.html'               => 'payslips.php?run_id=1',
    'ledger.html'                 => 'ledger.php?employee_id=4&from=2027-01-01&to=2027-01-31',
    'reports.html'                => 'reports.php?report=payroll_register&run_id=1',
    'audit.html'                  => 'audit_log.php',
    'kiosk.html'                  => 'auth.php?action=me',
];

$AUDIT_MAX = (int) (dbRow("SELECT COALESCE(MAX(audit_id), 0) AS m FROM audit_log")['m'] ?? 0);

try {
    // =================================================================
    section('1. The NAV_ACCESS table parses and is well formed');
    // =================================================================
    $js = (string) file_get_contents(ROOT_DIR . '/assets/js/nav.js');
    is_true('nav.js exists and is not empty', $js !== '');

    $table = [];
    if (preg_match('~// NAV_ACCESS_BEGIN\s*(.*?)// NAV_ACCESS_END~s', $js, $m)) {
        $json = trim($m[1]);
        $json = preg_replace('/^\s*const\s+NAV_ACCESS\s*=\s*/', '', $json, 1);
        $json = rtrim(trim($json), ';');
        $decoded = json_decode($json, true);
        if (is_array($decoded)) $table = $decoded;
    }
    is_true('NAV_ACCESS is one JSON-parseable table in nav.js', $table !== [],
        'the // NAV_ACCESS_BEGIN / END block did not decode');
    is_true('nav.js keys off data-page', str_contains($js, 'a[data-page]'));
    is_true('nav.js hides rather than removes links', str_contains($js, 'classList.add("nav-hidden")'));

    $knownRoles = ROLE_ORDER;
    $badRoles = [];
    foreach ($table as $page => $roles) {
        foreach ((array) $roles as $role) {
            if (!in_array($role, $knownRoles, true)) $badRoles[] = "$page:$role";
        }
    }
    eq('every role named in the table is a real role', $badRoles, []);

    // =================================================================
    section('2. The table covers every data-page value in the HTML');
    // =================================================================
    $linked   = [];   // data-page value => true
    $navFiles = [];
    $hrefMismatch = [];
    $missingNavJs = [];
    $notLockedNav = [];

    foreach (glob(ROOT_DIR . '/*.html') as $file) {
        $name = basename($file);
        $html = (string) file_get_contents($file);
        if (!str_contains($html, 'sidebar-links')) continue;

        $navFiles[] = $name;
        if (!str_contains($html, 'assets/js/nav.js'))  $missingNavJs[] = $name;
        if (!str_contains($html, 'class="sidebar nav-locked"')) $notLockedNav[] = $name;

        preg_match_all('/<a href="([^"]+)" data-page="([^"]+)"/', $html, $mm, PREG_SET_ORDER);
        foreach ($mm as $hit) {
            $href = $hit[1];
            $page = $hit[2];
            $linked[$page] = true;
            // data-page has always equalled the link target; keep it that way.
            if ($href !== $page) $hrefMismatch[] = "$name: $page -> $href";
        }
    }

    sort($navFiles);
    $expectedNavFiles = FROZEN_NAV_PAGES;
    sort($expectedNavFiles);
    eq('every expected admin page carries the sidebar nav', $navFiles, $expectedNavFiles);

    eq('every nav link keeps data-page == href', array_values(array_unique($hrefMismatch)), []);
    eq('every page with the nav loads nav.js', $missingNavJs, []);
    eq('every page with the nav starts locked (nav-locked)', $notLockedNav, []);

    $linkedList = array_keys($linked);
    sort($linkedList);
    $expectedLinked = FROZEN_LINKED_PAGES;
    sort($expectedLinked);
    eq('no data-page value was renamed or dropped', $linkedList, $expectedLinked);

    $notInTable = array_values(array_filter($linkedList, fn($p) => !array_key_exists($p, $table)));
    eq('every linked data-page value is in the table', $notInTable, []);

    // Pages that carry the nav but are not linked from it (they are reached
    // from other screens) still need an entry, or typing the URL is unguarded.
    $navPagesMissing = array_values(array_filter(
        $expectedNavFiles,
        fn($p) => !array_key_exists($p, $table)
    ));
    eq('every page that carries the nav has a table entry', $navPagesMissing, []);

    // A table entry must point at a page that exists. The one documented
    // exception is Salary Rates, which this branch ships as a .bak file: its
    // entry is kept so restoring the page is already guarded.
    $phantom = [];
    $bakOnly = [];
    foreach (array_keys($table) as $page) {
        if (is_file(ROOT_DIR . '/' . $page)) continue;
        if (is_file(ROOT_DIR . '/' . $page . '.bak')) { $bakOnly[] = $page; continue; }
        $phantom[] = $page;
    }
    eq('the table has no entry for a page that does not exist', $phantom, []);
    eq('the only table entry without a live page is the .bak one', $bakOnly, ['salary_rates.html']);

    eq('the kiosk is open to every role', $table['kiosk.html'] ?? null, ROLE_ORDER);

    // =================================================================
    section('3. The table matches the API guard, page by page');
    // =================================================================
    $jars = [];
    $map = ['Employee' => 'emp', 'Payroll Officer' => 'maker', 'Approver' => 'check', 'Administrator' => 'admin'];
    foreach (ROLE_ORDER as $role) {
        mkUser('dsh_nav_' . $map[$role], $role);
        $jars[$role] = login('dsh_nav_' . $map[$role]);
    }

    $pagesChecked = 0;
    foreach ($PAGE_ENDPOINT as $page => $endpoint) {
        if (!array_key_exists($page, $table)) {
            bad("$page has a table entry", 'missing from NAV_ACCESS');
            continue;
        }
        $allowed = $table[$page];
        $mismatch = [];
        foreach (ROLE_ORDER as $role) {
            $expect = in_array($role, $allowed, true) ? 200 : 403;
            $r = req('GET', $endpoint, null, $jars[$role]);
            if ($r['code'] !== $expect) {
                $mismatch[] = "$role expected $expect got {$r['code']}";
            }
        }
        eq("$page visibility matches $endpoint", $mismatch, []);
        $pagesChecked++;
    }
    eq('every page in the map was checked', $pagesChecked, count($PAGE_ENDPOINT));

    // The endpoints the nav links point at are the ones the table claims.
    $unmapped = array_values(array_filter(
        FROZEN_LINKED_PAGES,
        fn($p) => !isset($PAGE_ENDPOINT[$p])
    ));
    eq('every linked page has an endpoint to verify against', $unmapped, []);

    // =================================================================
    section('4. The table, as produced');
    // =================================================================
    foreach ($table as $page => $roles) {
        $endpoint = $PAGE_ENDPOINT[$page] ?? '(no endpoint mapped)';
        printf("  %-28s %-46s %s\n", $page, implode(', ', $roles), $endpoint);
    }

} catch (Throwable $e) {
    bad('harness ran without exception', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    runCleanup();
    sweepTestData();
    $GLOBALS['pdo']->exec("DELETE FROM audit_log WHERE audit_id > $AUDIT_MAX");

    printf("\n=== cleanup verification ===\n");
    printf("  fixture users: %d\n", dbCount('users', "username LIKE 'dsh\\_nav\\_%'"));

    is_true('all nav fixtures cleaned up',
        dbCount('users', "username LIKE 'dsh\\_nav\\_%'") === 0,
        'a fixture user survived cleanup');
}

summary();

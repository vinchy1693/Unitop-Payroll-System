<?php
// =====================================================================
// api/loans.php
//
// Roles:
//   GET   TEAM_QUEUE_ROLES  (maker + checker see every loan; a Supervisor
//                            sees only their own team's loans - a filter)
//   PATCH action=approve    CHECKER_ROLES only (Payroll/HR decides)
//   PATCH action=recommend  SUPERVISOR_ROLE only, own team, ADVICE ONLY:
//                           it records recommended_by / recommended_at /
//                           recommendation_remarks and NEVER changes
//                           loans.status - Payroll/HR still decides.
//   POST / PUT / PATCH status / DELETE   MAKER_ROLES only
//
// A Supervisor therefore has exactly one write here and it cannot decide
// anything: the approve action is checked against CHECKER_ROLES before the
// supervisor branch is even considered, and loans.status is untouched by a
// recommendation.
// =====================================================================
require __DIR__ . "/_bootstrap.php";

requireRole(array_merge(TEAM_QUEUE_ROLES, [FINANCE_ROLE]));   // maker + checker + manager may read
require __DIR__ . "/_payroll_helpers.php";

$method = $_SERVER["REQUEST_METHOD"];

// ---- GET: list loans with computed balance ----
if ($method === "GET") {
    // Team scope. A Supervisor only ever sees their own team's loans; the
    // maker and the checker keep every row. A Supervisor who supervises
    // nobody gets an empty list (200), never an error.
    $scope  = teamScopeIds($pdo);
    $params = [];
    $scopeSql = "";
    if ($scope !== null) {
        if (!$scope) {
            respond(true, "", []);
        }
        $scopeSql = " WHERE l.employee_id IN (" . implode(",", array_fill(0, count($scope), "?")) . ")";
        $params   = $scope;
    }

    $sql = "SELECT l.loan_id, l.employee_id, l.loan_type,
                   l.principal_amount, l.monthly_amortization, l.status,
                   l.recommended_by, l.recommended_at, l.recommendation_remarks,
                   rv.username AS recommended_by_name,
                   e.employee_number,
                   CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                   d.department_name,
                   COALESCE(
                       (SELECT SUM(amount_paid) FROM loan_payments lp WHERE lp.loan_id = l.loan_id),
                       0
                   ) AS total_paid
            FROM loans l
            JOIN employees e ON e.employee_id = l.employee_id
            LEFT JOIN departments d ON d.department_id = e.department_id
            LEFT JOIN users rv ON rv.user_id = l.recommended_by"
            . $scopeSql
            . " ORDER BY l.status ASC, l.loan_id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Attach computed remaining balance
    foreach ($rows as &$r) {
        $r["remaining_balance"] = round((float) $r["principal_amount"] - (float) $r["total_paid"], 2);
        $r["recommended_by"]    = $r["recommended_by"] !== null ? (int) $r["recommended_by"] : null;
        $r["has_recommendation"] = $r["recommended_at"] !== null;
    }
    unset($r);

    respond(true, "", $rows);
}

// ---------------------------------------------------------------------
// Supervisor-only action: RECOMMEND a team member's loan.
//
// This is advice, not a decision. It records who recommended what and when,
// and deliberately leaves loans.status alone: Payroll/HR below is still the
// only role that can turn a Pending loan into an Active one.
//
// Handled before the maker guard so a Supervisor can reach this one action,
// and before the checker branch (checked below) so a Supervisor sending
// action=approve is refused by the CHECKER_ROLES guard rather than here.
// ---------------------------------------------------------------------
$early       = json_input();
$earlyAction = strtolower(trim((string) ($early["action"] ?? "")));

if ($method === "PATCH" && $earlyAction === "recommend") {
    requireRole([MANAGER_ROLE]);   // maker and checker have no recommendation step

    $id = (int) ($early["loan_id"] ?? 0);
    if ($id === 0) respond(false, "loan_id is required.", null, 422);

    $remarks = trim((string) ($early["remarks"] ?? ""));
    $remarkLength = function_exists("mb_strlen") ? mb_strlen($remarks, "UTF-8") : strlen($remarks);
    if ($remarkLength > 500) {
        respond(false, "Recommendation remarks must be at most 500 characters.", null, 422);
    }

    $stmt = $pdo->prepare("SELECT loan_id, employee_id, status FROM loans WHERE loan_id = ?");
    $stmt->execute([$id]);
    $loan = $stmt->fetch();
    if (!$loan) respond(false, "Loan not found.", null, 404);

    // Own-request rule + team test, both inside assertManages().
    assertManages($pdo, (int) $loan["employee_id"], "recommend this loan request");

    $user = currentUser();
    $pdo->prepare(
        "UPDATE loans
         SET recommended_by = ?, recommended_at = NOW(), recommendation_remarks = ?
         WHERE loan_id = ?"
    )->execute([$user["user_id"], $remarks !== "" ? $remarks : null, $id]);

    respond(true, "Loan recommendation recorded. Payroll/HR still decides this request.", [
        "loan_id"                => $id,
        "status"                 => $loan["status"],   // unchanged: advice only
        "recommended_by"         => (int) $user["user_id"],
        "recommended_by_name"    => $user["username"],
        "recommendation_remarks" => $remarks !== "" ? $remarks : null,
    ]);
}

// ---------------------------------------------------------------------
// Checker-only action: approve a proposed (Pending) loan.
// The maker prepares loans; the Approver authorises them. Decided before
// the maker guard below so the checker can reach this one action.
// A Supervisor is NOT a checker, so this answers 403 for them.
// ---------------------------------------------------------------------
if ($method === "PATCH" && $earlyAction === "approve") {
    requireRole(CHECKER_ROLES);

    $id = (int) ($early["loan_id"] ?? 0);
    if ($id === 0) respond(false, "loan_id is required.", null, 422);

    $stmt = $pdo->prepare("SELECT loan_id, employee_id, status FROM loans WHERE loan_id = ?");
    $stmt->execute([$id]);
    $loan = $stmt->fetch();
    if (!$loan) respond(false, "Loan not found.", null, 404);
    assertNotOwnEmployee((int) $loan["employee_id"], "approve this loan");
    if ($loan["status"] !== "Pending") {
        respond(false, "Only Pending loans can be approved. This one is {$loan['status']}.", null, 409);
    }

    $pdo->prepare("UPDATE loans SET status = 'Active' WHERE loan_id = ?")->execute([$id]);
    respond(true, "Loan approved.", ["loan_id" => $id, "status" => "Active"]);
}

// Everything below mutates: only the maker (Payroll Officer) may write.
requireRole(MAKER_ROLES);

// ---- POST: create loan, or record a loan payment ----
if ($method === "POST") {
    $in     = json_input();
    $action = strtolower(trim((string) ($in["action"] ?? "")));

    // Loan payments belong to a payslip, so a locked run blocks them too.
    if ($action === "payment") {
        $loanId    = (int)   ($in["loan_id"]    ?? 0);
        $payslipId = (int)   ($in["payslip_id"] ?? 0);
        $amount    = (float) ($in["amount_paid"] ?? 0);
        $payDate   = trim((string) ($in["payment_date"] ?? ""));

        if ($loanId === 0 || $payslipId === 0 || $amount <= 0) {
            respond(false, "Loan, payslip, and amount_paid (>0) are required.", null, 422);
        }
        if ($payDate === "") $payDate = date("Y-m-d");

        // The loan and the payslip must belong to the same employee.
        $stmt = $pdo->prepare(
            "SELECT l.employee_id AS loan_employee, ps.employee_id AS payslip_employee
             FROM loans l
             JOIN payslips ps ON ps.payslip_id = ?
             WHERE l.loan_id = ?"
        );
        $stmt->execute([$payslipId, $loanId]);
        $pair = $stmt->fetch();
        if (!$pair) respond(false, "Loan or payslip not found.", null, 404);
        if ((int) $pair["loan_employee"] !== (int) $pair["payslip_employee"]) {
            respond(false, "The loan and the payslip belong to different employees.", null, 422);
        }

        assertPayslipUnlocked($pdo, $payslipId, "Loan payments on this payslip");

        $dateCheck = DateTime::createFromFormat("!Y-m-d", $payDate);
        if (!$dateCheck || $dateCheck->format("Y-m-d") !== $payDate) {
            respond(false, "Payment date must be a real date in YYYY-MM-DD form.", null, 422);
        }

        $stateStmt = $pdo->prepare(
            "SELECT l.status, l.principal_amount,
                    COALESCE((SELECT SUM(amount_paid) FROM loan_payments lp WHERE lp.loan_id = l.loan_id), 0) AS paid
             FROM loans l WHERE l.loan_id = ?"
        );
        $stateStmt->execute([$loanId]);
        $state = $stateStmt->fetch();
        if (!$state) respond(false, "Loan not found.", null, 404);
        if ($state["status"] !== "Active") {
            respond(false, "Only an Active loan can receive a payment. This one is {$state['status']}.", null, 409);
        }
        $outstanding = round((float) $state["principal_amount"] - (float) $state["paid"], 2);
        if ($amount > $outstanding + 0.005) {
            respond(false, "Payment exceeds the outstanding balance of " . number_format($outstanding, 2) . ".", null, 422);
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare(
                "INSERT INTO loan_payments (loan_id, payslip_id, amount_paid, payment_date)
                 VALUES (?, ?, ?, ?)"
            )->execute([$loanId, $payslipId, $amount, $payDate]);
            $paymentId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare(
                "SELECT l.principal_amount,
                        COALESCE((SELECT SUM(amount_paid) FROM loan_payments lp WHERE lp.loan_id = l.loan_id), 0) AS paid
                 FROM loans l WHERE l.loan_id = ?"
            );
            $stmt->execute([$loanId]);
            $sum = $stmt->fetch();
            if ($sum && round((float) $sum["principal_amount"] - (float) $sum["paid"], 2) <= 0) {
                $pdo->prepare("UPDATE loans SET status = 'Paid' WHERE loan_id = ?")->execute([$loanId]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            respondServerError("The loan payment could not be recorded. Please try again.", $e, "loans payment");
        }

        respond(true, "Loan payment recorded.", ["payment_id" => $paymentId]);
    }

    $empId      = (int)   ($in["employee_id"]           ?? 0);
    $type       = trim($in["loan_type"]                 ?? "");
    $principal  = (float) ($in["principal_amount"]      ?? 0);
    $amort      = (float) ($in["monthly_amortization"]  ?? 0);
    $status     = trim((string) ($in["status"] ?? "Pending"));

    if ($empId === 0 || $type === "" || $principal <= 0 || $amort <= 0) {
        respond(false, "Employee, Type, Principal, and Monthly Amortization are required.", null, 422);
    }
    if ($status !== "Pending") {
        respond(false,
            "A new loan must start as Pending; Payroll/HR activates it. "
            . "The maker cannot create an Active loan.",
            null, 403);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO loans (employee_id, loan_type, principal_amount, monthly_amortization, status)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->execute([$empId, $type, $principal, $amort, $status]);

    respond(true, $status === "Pending"
        ? "Loan submitted for approval."
        : "Loan added.", ["loan_id" => (int) $pdo->lastInsertId(), "status" => $status]);
}

// ---- PUT: update basic loan info ----
if ($method === "PUT") {
    $in         = json_input();
    $id         = (int)   ($in["loan_id"]              ?? 0);
    $empId      = (int)   ($in["employee_id"]          ?? 0);
    $type       = trim($in["loan_type"]                ?? "");
    $principal  = (float) ($in["principal_amount"]     ?? 0);
    $amort      = (float) ($in["monthly_amortization"] ?? 0);

    if ($id === 0 || $empId === 0 || $type === "" || $principal <= 0 || $amort <= 0) {
        respond(false, "All fields are required.", null, 422);
    }

    $stmt = $pdo->prepare(
        "UPDATE loans
         SET employee_id = ?, loan_type = ?, principal_amount = ?, monthly_amortization = ?
         WHERE loan_id = ?"
    );
    $stmt->execute([$empId, $type, $principal, $amort, $id]);

    respond(true, "Loan updated.");
}

// ---- PATCH: change status (Active / Paid / Cancelled) ----
if ($method === "PATCH") {
    $in     = json_input();
    $id     = (int)  ($in["loan_id"] ?? 0);
    $status = trim($in["status"] ?? "");

    if ($id === 0 || !in_array($status, ["Active", "Paid", "Cancelled"], true)) {
        respond(false, "Valid loan_id and status (Active / Paid / Cancelled) required.", null, 422);
    }

    $pdo->prepare("UPDATE loans SET status = ? WHERE loan_id = ?")
        ->execute([$status, $id]);

    respond(true, "Loan marked as $status.");
}

// ---- DELETE: remove a loan (only if no payments), or a single payment ----
if ($method === "DELETE") {
    // ?payment_id=N removes one payment (lock-checked through its payslip).
    $paymentId = (int) ($_GET["payment_id"] ?? 0);
    if ($paymentId > 0) {
        assertLoanPaymentUnlocked($pdo, $paymentId, "This loan payment");

        $stmt = $pdo->prepare("SELECT loan_id FROM loan_payments WHERE payment_id = ?");
        $stmt->execute([$paymentId]);
        $loanId = (int) $stmt->fetchColumn();

        $pdo->prepare("DELETE FROM loan_payments WHERE payment_id = ?")->execute([$paymentId]);

        // Re-open a loan that is no longer fully paid.
        $stmt = $pdo->prepare(
            "SELECT l.principal_amount,
                    COALESCE((SELECT SUM(amount_paid) FROM loan_payments lp WHERE lp.loan_id = l.loan_id), 0) AS paid
             FROM loans l WHERE l.loan_id = ?"
        );
        $stmt->execute([$loanId]);
        $sum = $stmt->fetch();
        if ($sum && round((float) $sum["principal_amount"] - (float) $sum["paid"], 2) > 0) {
            $pdo->prepare("UPDATE loans SET status = 'Active' WHERE loan_id = ? AND status = 'Paid'")
                ->execute([$loanId]);
        }

        respond(true, "Loan payment removed.");
    }

    $id = (int) ($_GET["id"] ?? 0);
    if ($id === 0) {
        respond(false, "Missing loan_id.", null, 422);
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM loan_payments WHERE loan_id = ?");
    $stmt->execute([$id]);
    if ((int) $stmt->fetchColumn() > 0) {
        respond(false, "Cannot delete: payments already recorded. Mark as Cancelled instead.", null, 409);
    }

    $pdo->prepare("DELETE FROM loans WHERE loan_id = ?")->execute([$id]);
    respond(true, "Loan deleted.");
}

respond(false, "Method not allowed.", null, 405);

<?php
require __DIR__ . "/_bootstrap.php";

requireRole(PAYROLL_ROLES);   // maker + checker may read
require __DIR__ . "/_payroll_helpers.php";

$method = $_SERVER["REQUEST_METHOD"];

// ---- GET: list loans with computed balance ----
if ($method === "GET") {
    $sql = "SELECT l.loan_id, l.employee_id, l.loan_type,
                   l.principal_amount, l.monthly_amortization, l.status,
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
            ORDER BY l.status ASC, l.loan_id DESC";

    $rows = $pdo->query($sql)->fetchAll();

    // Attach computed remaining balance
    foreach ($rows as &$r) {
        $r["remaining_balance"] = round((float) $r["principal_amount"] - (float) $r["total_paid"], 2);
    }
    unset($r);

    respond(true, "", $rows);
}

// ---------------------------------------------------------------------
// Checker-only action: approve a proposed (Pending) loan.
// The maker prepares loans; the Approver authorises them. Decided before
// the maker guard below so the checker can reach this one action.
// ---------------------------------------------------------------------
$early = json_input();
if ($method === "PATCH" && strtolower(trim((string) ($early["action"] ?? ""))) === "approve") {
    requireRole(CHECKER_ROLES);

    $id = (int) ($early["loan_id"] ?? 0);
    if ($id === 0) respond(false, "loan_id is required.", null, 422);

    $stmt = $pdo->prepare("SELECT loan_id, status FROM loans WHERE loan_id = ?");
    $stmt->execute([$id]);
    $loan = $stmt->fetch();
    if (!$loan) respond(false, "Loan not found.", null, 404);
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
            respond(false, "Failed to record loan payment: " . $e->getMessage(), null, 500);
        }

        respond(true, "Loan payment recorded.", ["payment_id" => $paymentId]);
    }

    $empId      = (int)   ($in["employee_id"]           ?? 0);
    $type       = trim($in["loan_type"]                 ?? "");
    $principal  = (float) ($in["principal_amount"]      ?? 0);
    $amort      = (float) ($in["monthly_amortization"]  ?? 0);
    $status     = trim((string) ($in["status"] ?? "Active"));

    if ($empId === 0 || $type === "" || $principal <= 0 || $amort <= 0) {
        respond(false, "Employee, Type, Principal, and Monthly Amortization are required.", null, 422);
    }
    if (!in_array($status, ["Active", "Pending"], true)) {
        respond(false, "A new loan must be Active or Pending.", null, 422);
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

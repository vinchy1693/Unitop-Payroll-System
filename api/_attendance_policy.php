<?php
// =====================================================================
// api/_attendance_policy.php
//
// One shared shift-start/grace policy for manual attendance and QR kiosk
// clock-ins. The grace is a threshold, not a deduction offset:
//
//   raw late <= 15 minutes -> 0 late minutes
//   raw late >  15 minutes -> full raw late minutes (trap rule)
//
// So 08:12 is on time, while 08:16 is 16 minutes late — not 1 minute.
// =====================================================================

const ATTENDANCE_SHIFT_START = "08:00:00";
const ATTENDANCE_GRACE_MINUTES = 15;

function attendanceLateMinutes(string $timeIn): int {
    $start = new DateTime(ATTENDANCE_SHIFT_START);
    $in    = new DateTime($timeIn);

    $rawSeconds = $in->getTimestamp() - $start->getTimestamp();
    if ($rawSeconds <= 0) {
        return 0;
    }

    // Allow the full grace window (inclusive through 08:15:00).
    if ($rawSeconds <= ATTENDANCE_GRACE_MINUTES * 60) {
        return 0;
    }

    // Once the window is exceeded, deduct from 08:00. Round a partial minute
    // upward so 08:15:01 cannot be recorded as only 15 minutes.
    return (int) ceil($rawSeconds / 60);
}

/**
 * Minutes of undertime for one attendance row.
 * - no clock-out: the remaining shift after any late arrival
 * - clock-out before 17:00: the gap to 17:00
 * - on/after 17:00: none
 */
function attendanceUndertimeMinutes(?string $timeOut, int $minutesLate): int {
    if ($timeOut === null || trim($timeOut) === "") {
        return max(0, 480 - $minutesLate);
    }

    $out = new DateTime($timeOut);
    $end = new DateTime("17:00:00");
    if ($out >= $end) {
        return 0;
    }

    $diff = $out->diff($end);
    return min(480, ($diff->h * 60) + $diff->i);
}

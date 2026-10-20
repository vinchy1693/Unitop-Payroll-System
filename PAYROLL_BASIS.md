# UNITOP — Payroll Basis and Computation Reference

**Purpose:** the policy, legal basis and formula order behind every payroll figure in UNITOP.
**Scope:** semi-monthly payroll for monthly-paid employees, Monday–Saturday work week.
**Status:** this describes the engine as implemented; verify rates annually and obtain management sign-off on the policy assumptions marked **POLICY**.

---

## 1. Inputs

| Input | Meaning |
|---|---|
| `MONTHLY_BASIC_SALARY` | Contractual monthly basic salary |
| `SEMI_MONTHLY_BASIC` | `MONTHLY_BASIC_SALARY / 2` |
| `PERIOD_GROSS_PAY` | Basic period pay + overtime + premium pay + allowances |
| Attendance | Time in/out, late minutes, undertime, absences |
| Approved overtime | Date, requested window, actual time in/out, hours |
| Allowances | Amount and `is_taxable` flag |
| Loans | Principal, amortisation, payments |
| Leave | Paid/unpaid leave and approved days |

### Periods

| Period | Coverage |
|---|---|
| First cutoff | 1st to 15th |
| Second cutoff | 16th to end of month |

---

## 2. Calculation order

The engine must follow this order:

```text
Phase 1  Government contributions
Phase 2  Taxable income
Phase 3  Withholding tax
Phase 4  Other deductions and net pay
```

### Phase 1 — Government contributions

Contributions are based on **monthly basic salary**, not on overtime or allowances.

```text
PERIOD_SSS_EE  = MONTHLY_SSS_EE / 2
PERIOD_PH_EE   = MONTHLY_PH_EE / 2
PERIOD_HDMF_EE = MONTHLY_HDMF_EE / 2
```

### Phase 2 — Taxable income

Current system rule:

```text
PERIOD_TAXABLE_INCOME =
    SEMI_MONTHLY_BASIC
  + overtime pay
  + premium pay
  + taxable allowances
  − attendance deductions (absence / late / undertime)
  − PERIOD_SSS_EE
  − PERIOD_PH_EE
  − PERIOD_HDMF_EE
```

Non-taxable allowances are excluded.

### Phase 3 — Withholding tax

Apply the semi-monthly BIR table to `PERIOD_TAXABLE_INCOME`.

### Phase 4 — Net pay

```text
PERIOD_NET_PAY =
    PERIOD_GROSS_PAY
  − PERIOD_SSS_EE
  − PERIOD_PH_EE
  − PERIOD_HDMF_EE
  − PERIOD_TAX_DED
  − attendance deductions
  − loan amortisation
  − other lawful deductions
```

No negative net pay is allowed; deductions are capped at what remains.

---

## 3. SSS

### Legal basis

**RA 11199 — Social Security Act of 2018**, as implemented by the 2025 SSS contribution schedule.

### Rate

| Share | Rate |
|---|---:|
| Employee | **5%** |
| Employer | **10%** |
| Total | **15%** |

### Monthly Salary Credit

The MSC is a **₱500-step bracket**, not the raw salary.

```text
MSC = clamp(
    floor((MONTHLY_BASIC_SALARY + 250) / 500) × 500,
    5,000,
    35,000
)
```

| Monthly basic | MSC |
|---:|---:|
| ₱20,249 | ₱20,000 |
| ₱20,250 | ₱20,500 |
| ₱60,000 | ₱35,000 |

```text
MONTHLY_SSS_EE = MSC × 0.05
PERIOD_SSS_EE  = MONTHLY_SSS_EE / 2
```

Maximum period employee contribution:

```text
₱35,000 × 5% / 2 = ₱875.00
```

Employer share is recorded for remittance but is **not deducted** from the employee.

---

## 4. PhilHealth

### Legal basis

**RA 11223 — Universal Health Care Act**.

### Rate

| Share | Rate |
|---|---:|
| Employee | **2.5%** |
| Employer | **2.5%** |
| Total premium | **5%** |

### Base and floor/ceiling

```text
base = clamp(MONTHLY_BASIC_SALARY, 10,000, 100,000)
MONTHLY_PH_EE = base × 2.5%
PERIOD_PH_EE  = MONTHLY_PH_EE / 2
```

Period floor and ceiling:

```text
minimum PERIOD_PH_EE = ₱125.00
maximum PERIOD_PH_EE = ₱1,250.00
```

---

## 5. Pag-IBIG / HDMF

### Legal basis

**RA 9679 — Home Development Mutual Fund Law of 2009**.

```text
base = min(MONTHLY_BASIC_SALARY, 10,000)
MONTHLY_HDMF_EE = base × 2%
PERIOD_HDMF_EE  = MONTHLY_HDMF_EE / 2
```

Hard period ceiling:

```text
if PERIOD_HDMF_EE > ₱100.00:
    PERIOD_HDMF_EE = ₱100.00
```

Employer share is recorded separately.

---

## 6. BIR withholding tax

### Legal basis

**RA 10963 — TRAIN Law**, implementing the progressive individual income tax rates. The payroll uses the **semi-monthly** withholding table, not an annual computation.

### Semi-monthly table

| Taxable income | Tax |
|---|---:|
| ≤ ₱10,417 | ₱0 |
| ₱10,418 – ₱16,666 | 15% of excess over ₱10,417 |
| ₱16,667 – ₱33,332 | ₱937.50 + 20% of excess over ₱16,667 |
| ₱33,333 – ₱83,332 | ₱4,270.70 + 25% of excess over ₱33,333 |
| ₱83,333 – ₱333,332 | ₱16,770.70 + 30% of excess over ₱83,333 |
| Above ₱333,332 | ₱91,770.70 + 35% of excess over ₱333,333 |

Tax is rounded once, at the final semi-monthly amount.

### Annual equivalence

The semi-monthly table is the periodic counterpart of the annual TRAIN brackets:

| Annual taxable income | Tax |
|---|---:|
| ≤ ₱250,000 | 0 |
| ₱250,001 – ₱400,000 | 15% of excess over ₱250,000 |
| ₱400,001 – ₱800,000 | ₱22,500 + 20% of excess over ₱400,000 |
| ₱800,001 – ₱2,000,000 | ₱102,500 + 25% of excess over ₱800,000 |
| ₱2,000,001 – ₱8,000,000 | ₱402,500 + 30% of excess over ₱2,000,000 |
| Above ₱8,000,000 | ₱2,202,500 + 35% of excess over ₱8,000,000 |

---

## 7. Attendance and basic pay

### Work week

- Monday to Saturday
- 8 hours per day
- Shift: 08:00–17:00
- 60-minute meal break for a span over 5 hours
- Sunday is the rest day

### Daily rate

The engine uses a self-balancing period rate:

```text
EXPECTED_WORKING_DAYS =
    Monday–Saturday days in the period
  − regular holidays in the period

DAILY_RATE = SEMI_MONTHLY_BASIC / EXPECTED_WORKING_DAYS
HOURLY_RATE = DAILY_RATE / 8
```

Regular holidays are excluded from expected working days; a holiday with no attendance is not scored as an absence.

### Absence

```text
ABSENCE_DEDUCTION = absent days × DAILY_RATE
```

### Late and undertime

```text
LATE_DEDUCTION      = late minutes × (DAILY_RATE / 480)
UNDERTIME_DEDUCTION = undertime minutes × (DAILY_RATE / 480)
```

A missing clock-out docks the remaining shift. Lateness is not double-docked as undertime.

Attendance deductions are capped at the remaining pay so net pay cannot go below zero.

---

## 8. Overtime

### Legal basis

**Labor Code Articles 87, 93 and 94**; company policy controls prior authorisation.

### Rates used

| Day type | First 8 hours | Overtime rate |
|---|---:|---:|
| Ordinary day | 1.00× | **1.25×** |
| Rest day | **1.30×** | **1.69×** |
| Special holiday | **1.30×** | **1.69×** |
| Regular holiday | **2.00×** | **2.60×** |

### Hours

```text
OT_HOURS =
    actual time out − actual time in
  − 60 minutes when the span exceeds 5 hours
```

A block crossing midnight is supported.

### Approval and window

- Only a **Supervisor** may file an overtime request.
- The employee cannot file overtime in the portal.
- The kiosk never creates an overtime request.
- The **Approver** approves it.
- The kiosk enforces a 5-minute grace around the approved start/end.
- A late clock-out is recorded, but paid hours stop at the approved end.

### Premium pay

Premium pay is paid for work actually recorded on a rest day or holiday.

**Current engine:**

- Rest day: adds the **full 1.30×** for the first 8 hours, because the basic is treated as covering only working days.
- Regular holiday: basic already includes 1.00×; adds **1.00×**.
- Special holiday: basic already includes 1.00×; adds **0.30×**.
- Beyond 8 hours uses the day-type OT rate.

> **POLICY / POSSIBLE OVERPAYMENT:** for a **monthly-paid** employee whose monthly basic already covers rest days, the correct rest-day premium is normally an **additional 30%**, not a full extra 130%. The current engine pays a full 1.30× for Sunday work. This must be confirmed by management before production. It is deliberately called out rather than silently changed.

---

## 9. Allowances

### Taxable

A taxable allowance raises both gross pay **and** the withholding-tax base.

### Non-taxable

A non-taxable allowance raises gross pay only. It is excluded from the tax base.

`allowances.is_taxable` controls the treatment. The default is taxable.

De minimis benefits must be marked non-taxable only when they genuinely qualify under the applicable BIR rules.

---

## 10. Loans

- Loan amortisation is charged per cutoff.
- It is capped at the remaining net pay after statutory and attendance deductions.
- The unpaid balance remains on the loan and is collected in a later run.
- When the run is voided, a loan marked `Paid` solely by that run is reopened to `Active`.
- The employee sees the loan balance in the portal.

---

## 11. Leave

- Leave days are counted **Monday–Saturday**.
- Paid leave is treated as an excused day, not an absence.
- A **regular holiday** is not counted as an expected attendance day.
- Approved paid leave does not create an absence deduction.
- Unpaid leave follows the absence rule.
- Leave balances are reduced in the same transaction as approval.

---

## 12. 13th month pay

### Legal basis

**PD 851**, read with the TRAIN Law’s ₱90,000 exemption.

```text
13TH_MONTH = total annual basic salary / 12
```

- First **₱90,000** is non-taxable.
- The excess is subject to the 20% final tax in the current implementation.
- It is written as a non-taxable allowance and is **not** included in the semi-monthly tax base.

---

## 13. Payroll lifecycle and controls

```text
Open period
  → Payroll run Submitted
  → Approver Approved
  → Released
```

- **Submitted** waits for the Approver.
- **Returned** goes back to the maker for correction and resubmission.
- **Approved** and **Released** lock attendance, overtime, payslips, allowances, deductions and loan payments.
- A **Released** run cannot be voided; a formal reversing adjustment is required.
- A period in **Processed** state cannot be edited or reopened.
- The employee portal shows a payslip only when the run is **Released**.

---

## 14. Example — ₱60,000 monthly basic, ₱39,000 gross

Assume no absence and no loan:

| Line | Amount |
|---|---:|
| Basic (semi-monthly) | ₱30,000.00 |
| Premium / rest-day / holiday | ₱9,000.00 |
| **Gross** | **₱39,000.00** |
| SSS EE | ₱875.00 |
| PhilHealth EE | ₱750.00 |
| Pag-IBIG EE | ₱100.00 |
| Taxable income | ₱37,275.00 |
| Withholding tax | ₱5,256.20 |
| **Total deductions** | **₱6,981.20** |
| **Net pay** | **₱32,018.80** |

The ₱9,000 premium in this example consists of two Sundays at full 1.30× and Bonifacio Day at 1.00× additional. See the rest-day policy warning in §8.

---

## 15. Limitations and items not implemented

The current engine does **not** compute:

- night differential (10:00 PM–6:00 AM)
- annual BIR reconciliation / BIR Form 2316
- separation pay, final pay or retirement pay
- multiple employers or multiple payroll schedules
- hazard, dirty-work, and other special premiums
- statutory maternity/paternity benefit integration
- attendance via biometric hardware
- an employee-specific shift schedule; the work week is fixed Monday–Saturday, 08:00–17:00

These are separate policy/feature decisions, not bugs in the implemented formulas.

---

## 16. Official sources

| Subject | Source |
|---|---|
| SSS contributions | RA 11199; 2025 SSS contribution schedule |
| PhilHealth | RA 11223; PhilHealth premium schedule |
| Pag-IBIG | RA 9679 |
| Withholding tax | RA 10963 (TRAIN); BIR semi-monthly withholding table |
| Overtime / premium | Labor Code Arts. 87, 93, 94 |
| 13th month | PD 851; RA 10963 |
| Service Incentive Leave | Labor Code Art. 95 |

Rates and tables must be reviewed each year and whenever a new circular or contribution schedule takes effect.

---

## 17. Sign-off points

Before production, management must approve:

- [ ] Rest-day treatment: full **1.30×** vs additional **30%** for monthly-paid staff
- [ ] Which allowances are genuinely non-taxable
- [ ] Night-differential policy and whether to implement it
- [ ] Meal-break and missing-clock-out rules
- [ ] 13th-month tax treatment
- [ ] SSS/PhilHealth/Pag-IBIG rates for the current year
- [ ] BIR withholding table version

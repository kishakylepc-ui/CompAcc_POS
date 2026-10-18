<?php

/*
|--------------------------------------------------------------------------
| PAYROLL DEDUCTIONS (Philippines)
|--------------------------------------------------------------------------
|
| Shared by Payroll (processing + live preview), the payslip and
| Settings -> Payroll Deductions. Each payroll keeps its deductions as
| separate lines in payroll_deductions (tools/migrate_payroll_deductions.php);
| payroll.deductions keeps the total, so Reports did not change.
|
| Automatic employee shares (every amount can still be edited before saving):
|   SSS              employee rate x Monthly Salary Credit (MSC: the monthly
|                    pay rounded to the 500-peso bracket, between a minimum
|                    and a maximum)
|   PhilHealth       employee rate x monthly pay, kept between a floor and a
|                    ceiling
|   Pag-IBIG         low rate up to the low limit, otherwise the normal rate,
|                    on pay up to the maximum fund salary
|   Withholding tax  BIR monthly table on (pay - the three contributions)
|
| Contributions and tax are MONTHLY, but employees are paid by the hour for
| any period. So each payroll looks at the employee's processed payroll in
| the same month (by period end) and deducts only what is still due for the
| month: after the last payroll of the month the totals are exactly right.
|
| The rates below are only the starting values. An Admin keeps them up to
| date in Settings -> Payroll Deductions (they change by law). Check them
| with SSS, PhilHealth, Pag-IBIG and BIR, or the store's accountant.
|
*/

const PAYROLL_DEDUCTION_TYPES = [
    'SSS' => 'SSS (employee share)',
    'PhilHealth' => 'PhilHealth (employee share)',
    'Pag-IBIG' => 'Pag-IBIG (employee share)',
    'Withholding Tax' => 'Withholding tax (BIR)',
    'Other' => 'Other deduction'
];

/* Form / setting keys of the automatic types. */
const PAYROLL_AUTO_KEYS = [
    'SSS' => 'sss',
    'PhilHealth' => 'philhealth',
    'Pag-IBIG' => 'pagibig',
    'Withholding Tax' => 'tax'
];


/* Starting values (setting_key => value). Saved by the migration. */
function payrollDefaultSettings(): array
{
    return [
        'payroll_auto_sss' => '1',
        'payroll_auto_philhealth' => '1',
        'payroll_auto_pagibig' => '1',
        'payroll_auto_tax' => '1',

        'payroll_sss_employee_rate' => '5',
        'payroll_sss_msc_min' => '5000',
        'payroll_sss_msc_max' => '35000',
        'payroll_sss_msc_step' => '500',

        'payroll_philhealth_employee_rate' => '2.5',
        'payroll_philhealth_floor' => '10000',
        'payroll_philhealth_ceiling' => '100000',

        'payroll_pagibig_low_rate' => '1',
        'payroll_pagibig_low_limit' => '1500',
        'payroll_pagibig_rate' => '2',
        'payroll_pagibig_max_salary' => '10000',

        /* [monthly pay over, base tax, % of the excess] - BIR table from 2023 */
        'payroll_tax_table' => json_encode([
            [0, 0, 0],
            [20833, 0, 15],
            [33333, 1875, 20],
            [66667, 8541.80, 25],
            [166667, 33541.80, 30],
            [666667, 183541.80, 35]
        ])
    ];
}


/* True once tools/migrate_payroll_deductions.php has run. */
function payrollDeductionsReady(PDO $pdo): bool
{
    static $ready = null;

    if ($ready === null) {
        $ready =
            (int) $pdo->query("
                SELECT COUNT(*)
                FROM sqlite_master
                WHERE type = 'table'
                  AND name = 'payroll_deductions'
            ")->fetchColumn() > 0;
    }

    return $ready;
}


/* Current rates: the saved settings, with the starting values as fallback. */
function payrollRates(PDO $pdo): array
{
    $rates = payrollDefaultSettings() + payrollEmployerDefaultSettings();

    $statement = $pdo->query("
        SELECT setting_key, setting_value
        FROM settings
        WHERE setting_key LIKE 'payroll\_%' ESCAPE '\\'
    ");

    foreach ($statement->fetchAll() as $row) {
        if (array_key_exists($row['setting_key'], $rates)) {
            $rates[$row['setting_key']] = (string) $row['setting_value'];
        }
    }

    $table = json_decode($rates['payroll_tax_table'], true);

    $rates['tax_table'] = is_array($table) && $table !== []
        ? $table
        : json_decode(payrollDefaultSettings()['payroll_tax_table'], true);

    return $rates;
}


/* Which automatic deductions are switched on (type => true/false). */
function payrollAutoTypes(array $rates): array
{
    $enabled = [];

    foreach (PAYROLL_AUTO_KEYS as $type => $key) {
        $enabled[$type] = ($rates['payroll_auto_' . $key] ?? '1') === '1';
    }

    return $enabled;
}


function payrollRound(float $value): float
{
    return round($value + 0.0000001, 2);
}


/* SSS Monthly Salary Credit for one month of pay (used by both shares). */
function payrollSssCredit(float $monthlyPay, array $rates): float
{
    $minimum = (float) $rates['payroll_sss_msc_min'];
    $maximum = (float) $rates['payroll_sss_msc_max'];
    $step = max(1.0, (float) $rates['payroll_sss_msc_step']);

    /* Brackets are centred on each credit: below min + step/2 -> min, ... */
    $credit = $minimum + $step * floor(($monthlyPay - ($minimum - $step / 2)) / $step);

    return max($minimum, min($maximum, $credit));
}


/* SSS employee share for one month of pay. */
function payrollSssShare(float $monthlyPay, array $rates): float
{
    if ($monthlyPay <= 0) {
        return 0.0;
    }

    return payrollRound(payrollSssCredit($monthlyPay, $rates) * (float) $rates['payroll_sss_employee_rate'] / 100);
}


/* PhilHealth employee share for one month of pay. */
function payrollPhilHealthShare(float $monthlyPay, array $rates): float
{
    if ($monthlyPay <= 0) {
        return 0.0;
    }

    $basis = max(
        (float) $rates['payroll_philhealth_floor'],
        min((float) $rates['payroll_philhealth_ceiling'], $monthlyPay)
    );

    return payrollRound($basis * (float) $rates['payroll_philhealth_employee_rate'] / 100);
}


/* Pag-IBIG employee share for one month of pay. */
function payrollPagIbigShare(float $monthlyPay, array $rates): float
{
    if ($monthlyPay <= 0) {
        return 0.0;
    }

    $rate = $monthlyPay <= (float) $rates['payroll_pagibig_low_limit']
        ? (float) $rates['payroll_pagibig_low_rate']
        : (float) $rates['payroll_pagibig_rate'];

    $basis = min((float) $rates['payroll_pagibig_max_salary'], $monthlyPay);

    return payrollRound($basis * $rate / 100);
}


/* Withholding tax for one month of taxable pay (BIR table in the rates). */
function payrollWithholdingTax(float $taxableMonthly, array $rates): float
{
    $tax = 0.0;

    foreach ($rates['tax_table'] as [$over, $base, $percent]) {
        if ($taxableMonthly > (float) $over) {
            $tax = (float) $base + ($taxableMonthly - (float) $over) * (float) $percent / 100;
        }
    }

    return payrollRound(max(0.0, $tax));
}


/*
| Suggested deductions for one new payroll ("monthly total, spread"):
| what is due for the whole month minus what earlier payrolls of the same
| month already deducted. $excludePayrollId leaves one payroll out.
*/
function payrollSuggestDeductions(
    PDO $pdo,
    int $employeeId,
    string $periodEnd,
    float $grossPay,
    ?int $excludePayrollId = null
): array {

    $rates = payrollRates($pdo);
    $enabled = payrollAutoTypes($rates);
    $month = substr($periodEnd, 0, 7);

    $hasStatus =
        in_array(
            'status',
            array_column($pdo->query('PRAGMA table_info(payroll)')->fetchAll(), 'name'),
            true
        );

    $earlier = $pdo->prepare("
        SELECT id, gross_pay
        FROM payroll
        WHERE employee_id = ?
          AND substr(period_end, 1, 7) = ?
          AND id <> ?
          " . ($hasStatus ? "AND status = 'Processed'" : '') . "
    ");
    $earlier->execute([$employeeId, $month, $excludePayrollId ?? 0]);
    $earlierRows = $earlier->fetchAll();

    $grossBefore = 0.0;
    $earlierIds = [];

    foreach ($earlierRows as $row) {
        $grossBefore += (float) $row['gross_pay'];
        $earlierIds[] = (int) $row['id'];
    }

    $already = array_fill_keys(array_keys(PAYROLL_AUTO_KEYS), 0.0);

    if ($earlierIds !== [] && payrollDeductionsReady($pdo)) {
        $placeholders = implode(',', array_fill(0, count($earlierIds), '?'));

        $deducted = $pdo->prepare("
            SELECT deduction_type, SUM(amount) AS total
            FROM payroll_deductions
            WHERE payroll_id IN ({$placeholders})
            GROUP BY deduction_type
        ");
        $deducted->execute($earlierIds);

        foreach ($deducted->fetchAll() as $row) {
            if (isset($already[$row['deduction_type']])) {
                $already[$row['deduction_type']] = (float) $row['total'];
            }
        }
    }

    $monthGross = payrollRound($grossBefore + $grossPay);

    $due = [
        'SSS' => payrollSssShare($monthGross, $rates),
        'PhilHealth' => payrollPhilHealthShare($monthGross, $rates),
        'Pag-IBIG' => payrollPagIbigShare($monthGross, $rates)
    ];

    $lines = [];
    $contributions = 0.0;

    foreach ($due as $type => $monthDue) {
        $amount = $enabled[$type]
            ? max(0.0, payrollRound($monthDue - $already[$type]))
            : 0.0;

        /* Taxable pay leaves out the contributions actually deducted. */
        $contributions += $already[$type] + $amount;

        $lines[$type] = [
            'type' => $type,
            'label' => PAYROLL_DEDUCTION_TYPES[$type],
            'enabled' => $enabled[$type],
            'amount' => $amount,
            'month_due' => $enabled[$type] ? $monthDue : 0.0,
            'already' => $already[$type]
        ];
    }

    $taxable = max(0.0, $monthGross - $contributions);
    $taxDue = $enabled['Withholding Tax'] ? payrollWithholdingTax($taxable, $rates) : 0.0;

    $lines['Withholding Tax'] = [
        'type' => 'Withholding Tax',
        'label' => PAYROLL_DEDUCTION_TYPES['Withholding Tax'],
        'enabled' => $enabled['Withholding Tax'],
        'amount' => $enabled['Withholding Tax']
            ? max(0.0, payrollRound($taxDue - $already['Withholding Tax']))
            : 0.0,
        'month_due' => $taxDue,
        'already' => $already['Withholding Tax']
    ];

    /*
    | Never more than the pay itself. When earlier payrolls of the month had
    | no deductions, the catch-up can be bigger than a short payroll: keep
    | SSS, PhilHealth, Pag-IBIG first, then tax, and the rest is picked up by
    | the next payroll of the same month (it sees less "already deducted").
    */
    $room = $grossPay;

    foreach (array_keys(PAYROLL_AUTO_KEYS) as $type) {
        $wanted = $lines[$type]['amount'];
        $amount = payrollRound(min($wanted, max(0.0, $room)));

        $lines[$type]['capped'] = $amount < $wanted;
        $lines[$type]['wanted'] = $wanted;
        $lines[$type]['amount'] = $amount;

        $room -= $amount;
    }

    return [
        'month' => $month,
        'earlier_payrolls' => count($earlierIds),
        'gross_before' => payrollRound($grossBefore),
        'month_gross' => $monthGross,
        'taxable_month' => payrollRound($taxable),
        'lines' => $lines
    ];
}


/*
|--------------------------------------------------------------------------
| EMPLOYER CONTRIBUTIONS (tools/migrate_employer_contributions.php)
|--------------------------------------------------------------------------
|
| What the shop pays ON TOP of the pay. It is never deducted from the
| employee (net pay does not change); it is a payroll cost in Expenses and
| the Financial Summary, and the payslip shows it as information.
|   SSS         employer rate x the same Monthly Salary Credit
|   SSS EC      Employees' Compensation: a fixed amount per month, lower
|               when the salary credit is below the EC limit
|   PhilHealth  employer rate x the same basis as the employee share
|   Pag-IBIG    employer rate x pay up to the highest fund salary
| Monthly, like the deductions: each payroll records what is still due for
| the month. Each line follows the matching "deduct automatically" switch.
| Lines are kept in payroll_employer_shares; payroll.employer_contributions
| keeps the total.
|
*/

const PAYROLL_EMPLOYER_TYPES = [
    'SSS' => 'SSS (employer share)',
    'SSS EC' => 'SSS EC (Employees\' Compensation)',
    'PhilHealth' => 'PhilHealth (employer share)',
    'Pag-IBIG' => 'Pag-IBIG (employer share)'
];

/* Form keys, and the employee deduction whose switch each line follows. */
const PAYROLL_EMPLOYER_KEYS = [
    'SSS' => 'sss',
    'SSS EC' => 'ec',
    'PhilHealth' => 'philhealth',
    'Pag-IBIG' => 'pagibig'
];

const PAYROLL_EMPLOYER_FOLLOWS = [
    'SSS' => 'SSS',
    'SSS EC' => 'SSS',
    'PhilHealth' => 'PhilHealth',
    'Pag-IBIG' => 'Pag-IBIG'
];


/* Starting values (setting_key => value). Saved by the migration. */
function payrollEmployerDefaultSettings(): array
{
    return [
        'payroll_sss_employer_rate' => '10',
        'payroll_sss_ec_low' => '10',
        'payroll_sss_ec_high' => '30',
        'payroll_sss_ec_limit' => '15000',
        'payroll_philhealth_employer_rate' => '2.5',
        'payroll_pagibig_employer_rate' => '2'
    ];
}


/* True once tools/migrate_employer_contributions.php has run. */
function payrollEmployerReady(PDO $pdo): bool
{
    static $ready = null;

    if ($ready === null) {
        $ready =
            (int) $pdo->query("
                SELECT COUNT(*)
                FROM sqlite_master
                WHERE type = 'table'
                  AND name = 'payroll_employer_shares'
            ")->fetchColumn() > 0;
    }

    return $ready;
}


/* Employer shares for one month of pay (type => amount). */
function payrollEmployerShares(float $monthlyPay, array $rates): array
{
    if ($monthlyPay <= 0) {
        return array_fill_keys(array_keys(PAYROLL_EMPLOYER_TYPES), 0.0);
    }

    $credit = payrollSssCredit($monthlyPay, $rates);

    $philHealthBasis = max(
        (float) $rates['payroll_philhealth_floor'],
        min((float) $rates['payroll_philhealth_ceiling'], $monthlyPay)
    );

    return [
        'SSS' => payrollRound($credit * (float) $rates['payroll_sss_employer_rate'] / 100),
        'SSS EC' => payrollRound(
            $credit < (float) $rates['payroll_sss_ec_limit']
                ? (float) $rates['payroll_sss_ec_low']
                : (float) $rates['payroll_sss_ec_high']
        ),
        'PhilHealth' => payrollRound($philHealthBasis * (float) $rates['payroll_philhealth_employer_rate'] / 100),
        'Pag-IBIG' => payrollRound(
            min((float) $rates['payroll_pagibig_max_salary'], $monthlyPay)
            * (float) $rates['payroll_pagibig_employer_rate'] / 100
        )
    ];
}


/*
| Suggested employer shares for one new payroll: what is due for the month
| minus what earlier processed payrolls of the same month already recorded.
| Not limited by the pay (the shop pays it on top).
*/
function payrollSuggestEmployerShares(
    PDO $pdo,
    int $employeeId,
    string $periodEnd,
    float $grossPay,
    ?int $excludePayrollId = null
): array {

    $rates = payrollRates($pdo);
    $enabled = payrollAutoTypes($rates);
    $month = substr($periodEnd, 0, 7);

    $hasStatus =
        in_array(
            'status',
            array_column($pdo->query('PRAGMA table_info(payroll)')->fetchAll(), 'name'),
            true
        );

    $earlier = $pdo->prepare("
        SELECT id, gross_pay
        FROM payroll
        WHERE employee_id = ?
          AND substr(period_end, 1, 7) = ?
          AND id <> ?
          " . ($hasStatus ? "AND status = 'Processed'" : '') . "
    ");
    $earlier->execute([$employeeId, $month, $excludePayrollId ?? 0]);

    $grossBefore = 0.0;
    $earlierIds = [];

    foreach ($earlier->fetchAll() as $row) {
        $grossBefore += (float) $row['gross_pay'];
        $earlierIds[] = (int) $row['id'];
    }

    $already = array_fill_keys(array_keys(PAYROLL_EMPLOYER_TYPES), 0.0);

    if ($earlierIds !== [] && payrollEmployerReady($pdo)) {
        $placeholders = implode(',', array_fill(0, count($earlierIds), '?'));

        $recorded = $pdo->prepare("
            SELECT share_type, SUM(amount) AS total
            FROM payroll_employer_shares
            WHERE payroll_id IN ({$placeholders})
            GROUP BY share_type
        ");
        $recorded->execute($earlierIds);

        foreach ($recorded->fetchAll() as $row) {
            if (isset($already[$row['share_type']])) {
                $already[$row['share_type']] = (float) $row['total'];
            }
        }
    }

    $due = payrollEmployerShares(payrollRound($grossBefore + $grossPay), $rates);

    $lines = [];
    $total = 0.0;

    foreach (PAYROLL_EMPLOYER_TYPES as $type => $label) {
        $on = $enabled[PAYROLL_EMPLOYER_FOLLOWS[$type]];
        $amount = $on && $grossPay > 0
            ? max(0.0, payrollRound($due[$type] - $already[$type]))
            : 0.0;

        $total += $amount;

        $lines[$type] = [
            'type' => $type,
            'label' => $label,
            'enabled' => $on,
            'amount' => $amount,
            'month_due' => $on ? $due[$type] : 0.0,
            'already' => $already[$type]
        ];
    }

    return [
        'month' => $month,
        'total' => payrollRound($total),
        'lines' => $lines
    ];
}


/*
|--------------------------------------------------------------------------
| WORK SCHEDULES (tools/migrate_employee_schedules.php)
|--------------------------------------------------------------------------
|
| Each employee is Full-time or Part-time, works a number of hours per day
| and has one weekly rest day (6-day week). Process Payroll counts the work
| days in the period and fills in
|   hours worked = work days x hours per day
|                  - days absent x hours per day - hours late / undertime
| which can still be changed by hand. Holidays are not counted separately.
|
*/

/* Type => usual hours per day (only a starting value in the form). */
const PAYROLL_EMPLOYMENT_TYPES = [
    'Full-time' => 8,
    'Part-time' => 4
];

/* Index = day of the week as PHP date('w') and JavaScript getUTCDay(). */
const PAYROLL_WEEKDAYS = [
    'Sunday',
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday'
];


/* True once tools/migrate_employee_schedules.php has run. */
function payrollSchedulesReady(PDO $pdo): bool
{
    static $ready = null;

    if ($ready === null) {
        $ready = in_array(
            'employment_type',
            array_column($pdo->query('PRAGMA table_info(employees)')->fetchAll(), 'name'),
            true
        );
    }

    return $ready;
}


/* Work days from $start to $end (both included), leaving out the rest day. */
function payrollWorkDays(string $start, string $end, int $restDay): int
{
    $day = new DateTimeImmutable($start);
    $last = new DateTimeImmutable($end);
    $count = 0;

    while ($day <= $last) {
        if ((int) $day->format('w') !== $restDay) {
            $count++;
        }

        $day = $day->modify('+1 day');
    }

    return $count;
}


/*
|--------------------------------------------------------------------------
| 13TH MONTH PAY (tools/migrate_thirteenth_month.php)
|--------------------------------------------------------------------------
|
| Philippine law (PD 851): every rank-and-file employee gets 1/12 of the
| basic pay earned in the calendar year, paid by December 24. Here the basic
| pay is the gross pay of the employee's processed payroll whose period
| ends in that year (hours x rate: there is no overtime or holiday pay).
| It can be paid in parts (e.g. half in June); payments are kept in
| thirteenth_month_payments and a mistake is voided, never deleted.
| Tax-free up to PAYROLL_THIRTEENTH_TAX_FREE; no SSS, PhilHealth or
| Pag-IBIG is deducted from it. Employees who left get theirs too.
|
*/

const PAYROLL_THIRTEENTH_TAX_FREE = 90000;


/* True once tools/migrate_thirteenth_month.php has run. */
function payrollThirteenthReady(PDO $pdo): bool
{
    static $ready = null;

    if ($ready === null) {
        $ready =
            (int) $pdo->query("
                SELECT COUNT(*)
                FROM sqlite_master
                WHERE type = 'table'
                  AND name = 'thirteenth_month_payments'
            ")->fetchColumn() > 0;
    }

    return $ready;
}


/*
| One row per employee who earned something in $year or was paid for it:
| basic pay earned, 13th month due (basic / 12), paid, still to pay and
| any amount paid above what is due (after a payroll was voided).
| $employeeId limits it to one employee.
*/
function payrollThirteenthSummary(PDO $pdo, int $year, ?int $employeeId = null): array
{
    $hasStatus = in_array(
        'status',
        array_column($pdo->query('PRAGMA table_info(payroll)')->fetchAll(), 'name'),
        true
    );

    $statement = $pdo->prepare("
        SELECT
            e.id,
            e.employee_code,
            e.first_name,
            e.middle_name,
            e.last_name,
            e.suffix,
            e.position,
            e.status,
            COALESCE((
                SELECT SUM(p.gross_pay)
                FROM payroll p
                WHERE p.employee_id = e.id
                  AND substr(p.period_end, 1, 4) = :year_text
                  " . ($hasStatus ? "AND p.status = 'Processed'" : '') . "
            ), 0) AS basic_pay,
            COALESCE((
                SELECT SUM(t.amount)
                FROM thirteenth_month_payments t
                WHERE t.employee_id = e.id
                  AND t.year = :year
                  AND t.status = 'Paid'
            ), 0) AS paid
        FROM employees e
        " . ($employeeId !== null ? 'WHERE e.id = :employee_id' : '') . "
        ORDER BY
            CASE WHEN e.status = 'Active' THEN 0 ELSE 1 END,
            e.first_name,
            e.last_name
    ");

    $parameters = [
        'year_text' => (string) $year,
        'year' => $year
    ];

    if ($employeeId !== null) {
        $parameters['employee_id'] = $employeeId;
    }

    $statement->execute($parameters);

    $rows = [];

    foreach ($statement->fetchAll() as $row) {
        $basic = payrollRound((float) $row['basic_pay']);
        $paid = payrollRound((float) $row['paid']);

        if ($basic <= 0 && $paid <= 0) {
            continue;
        }

        $due = payrollRound($basic / 12);

        $row['basic_pay'] = $basic;
        $row['due'] = $due;
        $row['paid'] = $paid;
        $row['left'] = max(0.0, payrollRound($due - $paid));
        $row['over'] = max(0.0, payrollRound($paid - $due));

        $rows[] = $row;
    }

    return $rows;
}

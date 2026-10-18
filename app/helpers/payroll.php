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
    $rates = payrollDefaultSettings();

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


/* SSS employee share for one month of pay. */
function payrollSssShare(float $monthlyPay, array $rates): float
{
    if ($monthlyPay <= 0) {
        return 0.0;
    }

    $minimum = (float) $rates['payroll_sss_msc_min'];
    $maximum = (float) $rates['payroll_sss_msc_max'];
    $step = max(1.0, (float) $rates['payroll_sss_msc_step']);

    /* Brackets are centred on each credit: below min + step/2 -> min, ... */
    $credit = $minimum + $step * floor(($monthlyPay - ($minimum - $step / 2)) / $step);
    $credit = max($minimum, min($maximum, $credit));

    return payrollRound($credit * (float) $rates['payroll_sss_employee_rate'] / 100);
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

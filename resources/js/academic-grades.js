const SCALE = 10n ** 32n;
const components = ['prelim', 'midterm', 'semi_final', 'final'];

function number(value, percentage = false) {
    const text = String(value ?? '').trim();
    if (text.length > 30 || ! /^\+?\d+(?:\.\d+)?$/u.test(text)) return null;
    const [whole, fraction = ''] = text.replace(/^\+/u, '').split('.');
    const amount = BigInt(whole) * SCALE + BigInt(fraction.padEnd(32, '0'));
    return percentage && amount > 100n * SCALE ? null : amount;
}

function displayRatio(numerator, denominator = SCALE) {
    if (denominator <= 0n) return 'Not available';
    const cents = (numerator * 100n + denominator / 2n) / denominator;
    return `${cents / 100n}.${String(cents % 100n).padStart(2, '0')}`;
}

function exactUnits(value) {
    const fractional = String(value % SCALE).padStart(32, '0').replace(/0+$/u, '');
    return `${value / SCALE}${fractional ? `.${fractional}` : ''}`;
}

// Share existing input parsing and decimal presentation with the modal editor.
export { number as academicNumber, exactUnits as academicDecimal };

function assess(row) {
    const marks = new Set();
    if (['inc', 'dropped'].includes(row.reported_status)) marks.add(row.reported_status);
    for (const key of [...components, 'average']) {
        const value = String(row[key] ?? '').trim().toUpperCase();
        if (['INC', 'INCOMPLETE'].includes(value)) marks.add('inc');
        if (['DRP', 'DROPPED'].includes(value)) marks.add('dropped');
    }
    const remark = String(row.remarks ?? '').match(/^\s*(INC|INCOMPLETE|DRP|DROPPED)\b/iu);
    if (remark) marks.add(['INC', 'INCOMPLETE'].includes(remark[1].toUpperCase()) ? 'inc' : 'dropped');
    const grades = components.map(key => number(row[key], true));
    const complete = marks.size === 0 && grades.every(grade => grade !== null);
    const average = complete ? grades.reduce((sum, grade) => sum + grade, 0n) / 4n : null;
    const reported = String(row.average ?? '').trim();
    const conflict = marks.size > 1 || !['regular', 'inc', 'dropped', 'unverified'].includes(row.reported_status || 'regular') || (marks.size === 0 && components.some(key => String(row[key] ?? '').trim() !== '' && number(row[key], true) === null))
        || (marks.size === 0 && reported !== '' && (number(reported, true) === null || (average !== null && number(reported, true) !== average)));
    const missing = marks.size === 0 && components.some(key => String(row[key] ?? '').trim() === '');
    const units = number(row.units);
    const numericResult = average === null ? null : (average >= 75n * SCALE ? 'Passed' : 'Failed');
    const included = average !== null && !conflict && row.reported_status !== 'unverified' && units !== null && units > 0n;
    const result = conflict ? 'Requires reconciliation' : (marks.has('inc') ? 'Incomplete / INC' : (marks.has('dropped') ? 'Dropped / DRP' : (missing ? 'Missing grades' : (row.reported_status === 'unverified' ? 'Unverified' : numericResult ?? 'Requires reconciliation'))));
    const reasons = [];
    if (marks.size) reasons.push('INC/dropped marks require academic review; they are not zero.');
    if (missing) reasons.push('All four numeric percentage components are needed.');
    if (conflict) reasons.push('Reported/computed discrepancy or unsupported data requires Registrar reconciliation.');
    if (units === null || units <= 0n) reasons.push('Missing, invalid or zero units: excluded from weighted GWA.');
    if (row.reported_status === 'unverified') reasons.push('Subject explicitly unverified.');
    return { average, units, numericResult, included, result, missing, conflict, marks, reasons, averageDisplay: average === null ? 'Not complete' : displayRatio(average) };
}

export default function academicGrades(rows, emptyRow) {
    return {
        rows, emptyRow,
        subject(row) { return assess(row); },
        get summary() {
            const results = this.rows.filter(row => Object.entries(row).some(([key, value]) => !['grade_id', 'reported_status'].includes(key) && String(value ?? '').trim() !== '') || ['inc', 'dropped', 'unverified'].includes(row.reported_status)).map(assess);
            let numerator = 0n, includedUnits = 0n, recordedUnits = 0n;
            for (const result of results) {
                if (result.units !== null) recordedUnits += result.units;
                if (result.included) { numerator += result.average * result.units; includedUnits += result.units; }
            }
            return {
                subjects: results.length, passed: results.filter(row => row.numericResult === 'Passed').length,
                failed: results.filter(row => row.numericResult === 'Failed').length,
                incomplete: results.filter(row => row.marks.has('inc')).length, dropped: results.filter(row => row.marks.has('dropped')).length,
                missing: results.filter(row => row.missing).length, reconciliation: results.filter(row => row.conflict).length,
                included: results.filter(row => row.included).length,
                units: exactUnits(recordedUnits), includedUnits: exactUnits(includedUnits),
                gwa: includedUnits > 0n ? displayRatio(numerator, includedUnits * SCALE) : 'Not available',
            };
        },
    };
}

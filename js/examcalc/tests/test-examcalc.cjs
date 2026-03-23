/**
 * ExamCalc – Regressionstest
 *
 * Spiegelt die echte evaluateWithMathJs()-Pipeline aus calculator.js:
 *   - Transformationen (log→log10, ln→log, √, π, %)
 *   - DEG-Modus: Trig-Funktionen via Scope-Override
 *   - RAD-Modus: math.js-Defaults
 *   - Epsilon-Cleanup: toPrecision(10)
 *
 * Ausführen: node js/examcalc/tests/test-examcalc.cjs
 */

const path = require('path');
const PLUGIN_ROOT = path.resolve(__dirname, '../../..');
const math = require(path.join(PLUGIN_ROOT, 'js/examcalc/math.min.js'));

// ─── Pipeline (muss mit calculator.js evaluateWithMathJs() synchron bleiben) ───

function transform(expr) {
    let e = expr;
    e = e.replace(/(^|[^A-Za-z0-9_])log\s*\(/g, (m, pre) => pre + 'log10(');
    e = e.replace(/(^|[^A-Za-z0-9_])ln\s*\(/g,  (m, pre) => pre + 'log(');
    e = e.replace(/√\(/g, 'sqrt(');
    e = e.replace(/√(\d+(?:\.\d+)?)/g, (_, n) => `sqrt(${n})`);
    e = e.replace(/π/g, 'pi');
    e = e.replace(/(\d+(?:\.\d+)?)%/g, '($1/100)');
    return e;
}

function epsilonCleanup(value) {
    const EPSILON = 2.220446049250313e-16;
    const THRESHOLD = EPSILON * 1000;
    if (Math.abs(value) < THRESHOLD) return 0;
    const rounded = Math.round(value);
    if (Math.abs(value - rounded) < THRESHOLD) return rounded;
    return parseFloat(value.toPrecision(10));
}

function evalRad(expr) {
    const scope = { pi: Math.PI, e: Math.E };
    return epsilonCleanup(Number(math.evaluate(transform(expr), scope)));
}

function evalDeg(expr) {
    const toRad = x => x * Math.PI / 180;
    const toDeg = x => x * 180 / Math.PI;
    const scope = {
        pi: Math.PI, e: Math.E,
        sin:  x => Math.sin(toRad(x)),
        cos:  x => Math.cos(toRad(x)),
        tan:  x => Math.tan(toRad(x)),
        asin: x => toDeg(Math.asin(x)),
        acos: x => toDeg(Math.acos(x)),
        atan: x => toDeg(Math.atan(x)),
    };
    return epsilonCleanup(Number(math.evaluate(transform(expr), scope)));
}

// ─── Test-Runner ────────────────────────────────────────────────────────────

let ok = 0, fail = 0;

function t(desc, got, exp, tol = 1e-9) {
    const pass = Math.abs(Number(got) - exp) <= tol;
    if (pass) { process.stdout.write(`  ✅ ${desc}\n`); ok++; }
    else       { process.stdout.write(`  ❌ ${desc}: got ${got}, expected ${exp}\n`); fail++; }
}

function tErr(desc, fn) {
    try   { fn(); process.stdout.write(`  ❌ ${desc}: kein Fehler geworfen\n`); fail++; }
    catch { process.stdout.write(`  ✅ ${desc}\n`); ok++; }
}

function section(title) {
    console.log(`\n[${title}]`);
}

// ─── Tests ──────────────────────────────────────────────────────────────────

section('Grundrechenarten');
t('2 + 3',          evalRad('2+3'),       5);
t('10 - 4',         evalRad('10-4'),      6);
t('3 * 4',          evalRad('3*4'),       12);
t('10 / 4',         evalRad('10/4'),      2.5);
t('0 + 0',          evalRad('0+0'),       0);
t('1 - 1',          evalRad('1-1'),       0);
t('-5 + 3',         evalRad('-5+3'),      -2);
t('-3 * -4',        evalRad('-3*-4'),     12);
t('1 / 3',          evalRad('1/3'),       1/3, 1e-9);
t('0.1 + 0.2',      evalRad('0.1+0.2'),  0.3, 1e-9);

section('Operator-Priorität & Klammern');
t('2 + 3 * 4',      evalRad('2+3*4'),         14);
t('(2+3) * 4',      evalRad('(2+3)*4'),        20);
t('10 - 2 * 3',     evalRad('10-2*3'),          4);
t('(10-2) * 3',     evalRad('(10-2)*3'),        24);
t('2^3^1',          evalRad('2^3^1'),            8);
t('((2+3)) * ((4-1))', evalRad('((2+3))*((4-1))'), 15);

section('Potenzen & Wurzeln');
t('2^10',           evalRad('2^10'),       1024);
t('2^0',            evalRad('2^0'),        1);
t('2^-1',           evalRad('2^-1'),       0.5);
t('(-2)^3',         evalRad('(-2)^3'),     -8);
t('4^0.5',          evalRad('4^0.5'),      2);
t('sqrt(9)',        evalRad('sqrt(9)'),     3);
t('sqrt(2)',        evalRad('sqrt(2)'),     Math.sqrt(2), 1e-9);
t('sqrt(0)',        evalRad('sqrt(0)'),     0);
t('√9',             evalRad('√9'),          3);
t('√(4+5)',         evalRad('√(4+5)'),      3);
t('√(2*8)',         evalRad('√(2*8)'),      4);
t('10^(3)',         evalRad('10^(3)'),      1000);
t('10^(0)',         evalRad('10^(0)'),      1);
t('10^(2+1)',       evalRad('10^(2+1)'),   1000);

section('Logarithmen');
t('log(1)',         evalRad('log(1)'),      0);
t('log(10)',        evalRad('log(10)'),     1);
t('log(100)',       evalRad('log(100)'),    2);
t('log(1000)',      evalRad('log(1000)'),   3);
t('log(0.1)',       evalRad('log(0.1)'),   -1, 1e-9);
t('log(10^5)',      evalRad('log(10^(5))'), 5, 1e-9);
t('ln(1)',          evalRad('ln(1)'),       0);
t('ln(e)',          evalRad('ln(e)'),       1, 1e-9);
t('ln(e^2)',        evalRad('ln(e^2)'),     2, 1e-9);
t('ln(sqrt(4))',    evalRad('ln(sqrt(4))'), Math.log(2), 1e-9);
t('exp(0)',         evalRad('exp(0)'),      1);
t('exp(1)',         evalRad('exp(1)'),      Math.E, 1e-9);
t('exp(ln(5))',     evalRad('exp(ln(5))'), 5, 1e-9);

section('Konstanten');
// epsilonCleanup rundet auf 10 signifikante Stellen → Toleranz 1e-9
t('pi',             evalRad('pi'),          Math.PI,   1e-9);
t('e',              evalRad('e'),           Math.E,    1e-9);
t('π',              evalRad('π'),           Math.PI,   1e-9);
t('2*π',            evalRad('2*π'),         2*Math.PI, 1e-9);
t('π^2',            evalRad('π^2'),         Math.PI**2, 1e-9);

section('Trigonometrie – DEG-Modus');
t('sin(0)',         evalDeg('sin(0)'),      0);
t('sin(30)',        evalDeg('sin(30)'),     0.5);
t('sin(45)',        evalDeg('sin(45)'),     Math.SQRT2/2, 1e-9);
t('sin(60)',        evalDeg('sin(60)'),     Math.sqrt(3)/2, 1e-9);
t('sin(90)',        evalDeg('sin(90)'),     1);
t('sin(180)',       evalDeg('sin(180)'),    0, 1e-9);
t('sin(270)',       evalDeg('sin(270)'),   -1, 1e-9);
t('sin(360)',       evalDeg('sin(360)'),    0, 1e-9);
t('sin(-30)',       evalDeg('sin(-30)'),   -0.5);
t('cos(0)',         evalDeg('cos(0)'),      1);
t('cos(60)',        evalDeg('cos(60)'),     0.5);
t('cos(90)',        evalDeg('cos(90)'),     0, 1e-9);
t('cos(180)',       evalDeg('cos(180)'),   -1, 1e-9);
t('tan(0)',         evalDeg('tan(0)'),      0);
t('tan(45)',        evalDeg('tan(45)'),     1);
t('tan(-45)',       evalDeg('tan(-45)'),   -1);

section('Inverse Trigonometrie – DEG-Modus');
t('asin(0)',        evalDeg('asin(0)'),      0);
t('asin(0.5)',      evalDeg('asin(0.5)'),   30, 1e-8);
t('asin(1)',        evalDeg('asin(1)'),     90, 1e-8);
t('asin(-0.5)',     evalDeg('asin(-0.5)'), -30, 1e-8);
t('acos(1)',        evalDeg('acos(1)'),      0, 1e-8);
t('acos(0.5)',      evalDeg('acos(0.5)'),  60, 1e-8);
t('acos(0)',        evalDeg('acos(0)'),    90, 1e-8);
t('atan(0)',        evalDeg('atan(0)'),     0);
t('atan(1)',        evalDeg('atan(1)'),    45, 1e-8);
t('atan(-1)',       evalDeg('atan(-1)'),  -45, 1e-8);

section('Trigonometrie – RAD-Modus');
t('sin(0)',         evalRad('sin(0)'),      0);
t('sin(π/6)',       evalRad('sin(π/6)'),    0.5, 1e-9);
t('sin(π/2)',       evalRad('sin(π/2)'),    1, 1e-9);
t('cos(0)',         evalRad('cos(0)'),      1);
t('cos(π)',         evalRad('cos(π)'),     -1, 1e-9);
t('cos(π/3)',       evalRad('cos(π/3)'),    0.5, 1e-9);
t('tan(π/4)',       evalRad('tan(π/4)'),    1, 1e-9);
t('asin(0.5)',      evalRad('asin(0.5)'), Math.asin(0.5), 1e-9);
t('acos(0.5)',      evalRad('acos(0.5)'), Math.acos(0.5), 1e-9);
t('atan(1)',        evalRad('atan(1)'),   Math.PI/4, 1e-9);

section('Verschachtelte Funktionen');
t('sin(cos(0)) [rad]',      evalRad('sin(cos(0))'),       Math.sin(1), 1e-9);
t('ln(sqrt(4))',             evalRad('ln(sqrt(4))'),       Math.log(2), 1e-9);
t('log(10^2)',               evalRad('log(10^(2))'),       2, 1e-9);
t('sqrt(sin(π/2)) [rad]',   evalRad('sqrt(sin(π/2))'),    1, 1e-9);
t('exp(ln(sqrt(9)))',        evalRad('exp(ln(sqrt(9)))'),  3, 1e-9);
t('log(sqrt(1000))',         evalRad('log(sqrt(1000))'),   1.5, 1e-9);
t('sin(asin(0.5)) [deg]',   evalDeg('sin(asin(0.5))'),    0.5, 1e-9);
t('cos(acos(0.5)) [deg]',   evalDeg('cos(acos(0.5))'),    0.5, 1e-9);
t('asin(sin(30)) [deg]',    evalDeg('asin(sin(30))'),     30, 1e-8);

section('Kehrwert & Quadrat');
t('1/(4)',          evalRad('1/(4)'),       0.25);
t('1/(2+2)',        evalRad('1/(2+2)'),     0.25);
t('1/(0.5)',        evalRad('1/(0.5)'),     2);
t('3^2',            evalRad('3^2'),         9);
t('(-3)^2',         evalRad('(-3)^2'),      9);

section('Fakultät');
t('0!',             evalRad('0!'),          1);
t('1!',             evalRad('1!'),          1);
t('5!',             evalRad('5!'),          120);
t('10!',            evalRad('10!'),         3628800);
t('3! + 2!',        evalRad('3!+2!'),       8);

section('Prozent');
t('50%',            evalRad('50%'),         0.5);
t('100*50%',        evalRad('100*50%'),     50);
t('200*25%',        evalRad('200*25%'),     50);
t('1%',             evalRad('1%'),          0.01);

section('Epsilon-Cleanup (Floating-Point)');
t('sin(30°) → 0.5 exakt',  evalDeg('sin(30)'),  0.5);
t('cos(60°) → 0.5 exakt',  evalDeg('cos(60)'),  0.5);
t('tan(45°) → 1 exakt',    evalDeg('tan(45)'),  1);
t('cos(90°) → 0 exakt',    evalDeg('cos(90)'),  0, 1e-9);
t('sin(180°) → 0 exakt',   evalDeg('sin(180)'), 0, 1e-9);
t('0.1+0.2 → 0.3',         evalRad('0.1+0.2'),  0.3, 1e-9);

section('Große & kleine Zahlen');
t('1e10',           evalRad('1e10'),        1e10);
t('1e-10',          evalRad('1e-10'),       1e-10, 1e-20);
t('2^32',           evalRad('2^32'),        4294967296);
t('sqrt(1e16)',      evalRad('sqrt(1e16)'),  1e8);

section('Fehler-Fälle');
tErr('eval()',       () => math.evaluate('eval(1+1)'));
tErr('unbekannte Funktion foo()', () => math.evaluate('foo(1)'));
tErr('sqrt(-1) als real', () => {
    const r = Number(math.evaluate('sqrt(-1)'));
    if (!isFinite(r) || isNaN(r)) throw new Error('not real');
    // math.js gibt komplexe Zahl zurück — das ist ok, kein harter Fehler
    // Daher: wenn es eine Zahl ist die NaN ist, wirf Fehler
    if (isNaN(r)) throw new Error('NaN');
});
tErr('log(0)',       () => {
    const r = Number(evalRad('log(0)'));
    if (!isFinite(r)) throw new Error('-Infinity');
});
tErr('1/0',         () => {
    const r = Number(evalRad('1/0'));
    if (!isFinite(r)) throw new Error('Infinity');
});

// ─── Ergebnis ───────────────────────────────────────────────────────────────

console.log(`\n${'═'.repeat(45)}`);
console.log(`  ${ok} bestanden  |  ${fail} fehlgeschlagen`);
if (fail === 0) console.log('  🎉 Alle Tests grün!');
else            console.log('  ⚠️  Bitte Fehler prüfen!');
process.exit(fail > 0 ? 1 : 0);

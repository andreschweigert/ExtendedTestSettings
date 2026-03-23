/**
 * calculator.js - Taschenrechner-Logic für ExtendedTestSettings
 */

window.ExamCalc = window.ExamCalc || {};

window.ExamCalc.DEBUG = false; // Debug ein/aus

window.ecLog = function (message) {
    if (window.ExamCalc.DEBUG) {
        console.log(`[ExamCalc] ${message}`);
    }
};

window.ExamCalc.Calculator = class {
    constructor() {
        this.current = '';
        this.lastResult = null;
        this.lastOperator = null;
        this.lastOperand = null;

        this.btn = null;
        this.calc = null;
        this.display = null;

        this.isInitialized = false;

        this.history = [];
        this.maxHistoryItems = 10;
        this.showingFraction = false;
        this.historyVisible = false;
        this.angleMode = 'deg'; // 'deg' oder 'rad'
        this.shiftMode = false; // Für 2nd-Funktionen (arcsin, arccos, arctan)

        // NEU: Speicher-Funktionalität
        this.memory = 0;
        this.memoryIndicator = null;
    }

    /**
     * Initialisiert den Calculator nachdem HTML geladen wurde
     */
    init() {
        this.btn = document.getElementById('calculator-btn');
        this.calc = document.getElementById('calculator');
        this.display = document.getElementById('calc-display');
        this.memoryIndicator = document.getElementById('memory-indicator');

        if (!this.btn || !this.calc || !this.display) {
            console.error("[ExamCalc] Calculator-Elemente nicht gefunden");
            return false;
        }

        this.setupEventListeners();
        this.isInitialized = true;

        if (window.ecLog) {
            window.ecLog('✅ Calculator initialisiert');
        }

        return true;
    }

    /**
     * Event-Listener setup (erweitert)
     */
    setupEventListeners() {
        // Toggle Button
        this.btn.addEventListener('click', () => {
            this.toggle();
        });

        // Alt + R Shortcut
        document.addEventListener('keydown', (e) => {
            if (e.altKey && e.key.toLowerCase() === 'r') {
                e.preventDefault();
                this.toggle();
            }
        });

        // Close Button 
        const closeBtn = this.calc.querySelector('.close-btn');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => this.hide());
        }

        // History Toggle Button
        const historyToggle = document.getElementById('history-toggle');
        if (historyToggle) {
            historyToggle.addEventListener('click', () => this.toggleHistory());
        }

        // Clear History Button
        const clearHistory = document.getElementById('clear-history');
        if (clearHistory) {
            clearHistory.addEventListener('click', () => this.clearHistory());
        }

        // Enter-Taste zum Berechnen (Display ist readonly — kein input-Listener nötig)
        if (this.display) {
            this.display.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.calculate();
                }
            });
        }

        // Calculator Buttons (erweitert für neue Buttons)
        this.calc.querySelectorAll('.buttons button').forEach(button => {
            button.addEventListener('click', (e) => {
                e.preventDefault();

                // hyp-Button hat keine Implementierung — ignorieren
                if (button.id === 'hyp-toggle') return;

                // SPEZIAL-BEHANDLUNG: Toggle Buttons
                if (button.classList.contains('fraction-toggle')) {
                    this.toggleFractionMode();
                    return;
                }

                if (button.classList.contains('angle-toggle')) {
                    this.toggleAngleMode();
                    return;
                }

                if (button.classList.contains('shift-toggle')) {
                    this.toggleShiftMode();
                    return;
                }

                // NEU: Speicher-Buttons
                if (button.classList.contains('memory-btn')) {
                    const action = button.dataset.action;
                    if (action === 'add') this.memoryAdd();
                    else if (action === 'subtract') this.memorySubtract();
                    else if (action === 'recall') this.memoryRecall();
                    else if (action === 'clear') this.memoryClear();
                    return;
                }

                // NEU: Verlaufs-Addition Button
                if (button.classList.contains('history-add-btn')) {
                    this.addHistoryResults();
                    return;
                }

                // Trig-Buttons (berücksichtigen Shift-Modus)
                if (button.classList.contains('trig-btn')) {
                    const func = this.shiftMode ? button.dataset.secondary : button.dataset.primary;
                    this.addFunction(func);
                    if (this.shiftMode) {
                        this.toggleShiftMode(); // Auto-deaktivieren nach Verwendung
                    }
                    return;
                }

                // Funktions-Buttons (ln, log, exp, etc.)
                if (button.classList.contains('func-btn')) {
                    const buttonId = button.id;
                    if (buttonId === 'ln-btn') this.addFunction('ln');
                    else if (buttonId === 'log-btn') this.addFunction('log');
                    else if (buttonId === 'exp-btn') this.addFunction('exp');
                    else if (buttonId === 'pow10-btn') this.addPower10();
                    return;
                }

                // DEBUG: Welcher Button wurde geklickt?
                if (window.ecLog) {
                    window.ecLog(`Button geklickt: "${button.textContent}" | Klassen: ${button.className}`);
                }

                this.handleButtonClick(button.textContent, button.classList);
            });
        });

        //  History Item Clicks
        document.addEventListener('click', (e) => {
            if (e.target.closest('.history-item')) {
                const item = e.target.closest('.history-item');
                const result = item.querySelector('.history-result').textContent.replace('= ', '');
                this.useHistoryResult(result);
            }
        });

        // Drag functionality (basic)
        this.makeCalculatorDraggable();
    }

    /**
     * Calculator anzeigen/verstecken
     */
    toggle() {
        const isVisible = this.calc.style.display === 'block';
        if (isVisible) {
            this.hide();
        } else {
            this.show();
        }
    }

    /**
     * Calculator anzeigen (NEU - separate Methode)
     */
    show() {
        this.calc.style.display = 'block';
        this.calc.classList.remove('with-history');

        if (window.ecLog) {
            window.ecLog('🔄 Calculator angezeigt');
        }
    }

    /**
     * Calculator verstecken (NEU - separate Methode)
     */
    hide() {
        this.calc.style.display = 'none';

        this.historyVisible = false;
        this.calc.classList.remove('with-history');

        const historyPanel = document.getElementById('history-panel');
        const historyToggle = document.getElementById('history-toggle');

        if (historyPanel && historyToggle) {
            historyPanel.classList.add('hidden');
            historyToggle.classList.remove('active');
        }

        if (window.ecLog) {
            window.ecLog('🔄 Calculator versteckt');
        }
    }

    /**
     * Button-Klicks verarbeiten (erweitert mit neuen Funktionen)
     */
    handleButtonClick(value, classList) {
        // DEL Button
        if (classList.contains('del-btn')) {
            this.deleteLastChar();
            return;
        }

        // Spezielle Funktions-Buttons
        if (value === '1/x') {
            this.addReciprocal();
            return;
        }

        if (value === 'x²' || value === 'x^2') {
            this.addSquare();
            return;
        }

        // Standard Button Logic
        switch (value) {
            case '=':
                this.calculate();
                break;
            case 'AC':
                this.clear();
                break;
            case '!':
                this.addFactorial();
                break;
            case '%':
                this.addPercent();
                break;
            default:
                this.addToExpression(value);
                break;
        }
    }

    /**
     * Letztes Zeichen löschen (DEL-Funktion) - erweitert
     */
    deleteLastChar() {
        if (this.current.length > 0) {
            this.current = this.current.slice(0, -1);
            // Display mit Formatierung aktualisieren
            this.display.value = this.formatDisplayExpression(this.current) || '';

            if (window.ecLog) {
                window.ecLog('🗑️ Letztes Zeichen gelöscht');
            }
        }
    }

    /**
     * Epsilon-Cleanup: Bereinigt Rundungsfehler
     * - Werte nahe 0 werden auf 0 gesetzt
     * - Werte nahe ganzen Zahlen werden gerundet
     */
    applyEpsilonCleanup(value) {
        // Machine Epsilon für JavaScript (IEEE 754 Double Precision)
        const EPSILON = 2.220446049250313e-16;

        // Schwellwert: 1000x größer als Machine Epsilon
        // (praktischer Wert für numerische Toleranz)
        const THRESHOLD = EPSILON * 1000;

        // Wenn Wert sehr nahe bei 0 ist → 0
        if (Math.abs(value) < THRESHOLD) {
            if (window.ecLog) {
                window.ecLog(`🔧 Epsilon-Cleanup: ${value} → 0 (unter Schwellwert)`);
            }
            return 0;
        }

        // Wenn Wert sehr nahe bei ganzer Zahl ist → runden
        const rounded = Math.round(value);
        if (Math.abs(value - rounded) < THRESHOLD) {
            if (window.ecLog) {
                window.ecLog(`🔧 Epsilon-Cleanup: ${value} → ${rounded} (nahe ganzer Zahl)`);
            }
            return rounded;
        }

        // Ansonsten: Original-Wert beibehalten
        return value;
    }

    /**
     * Input-Validierung: Schützt vor Code-Injection
     * Multi-Layer Security Ansatz
     */
    validateInput(input) {
        if (!input || input.trim() === '') {
            return true; // Leere Eingabe ist ok
        }

        // Layer 1: Gefährliche Keywords blocken (höchste Priorität)
        const dangerousKeywords = /constructor|__proto__|prototype|eval|function|window|document|alert|console|import|require|this\.|globalThis|process|exec/i;
        if (dangerousKeywords.test(input)) {
            if (window.ecLog) {
                window.ecLog(`🛡️ SECURITY: Gefährliche Keywords blockiert in: "${input}"`);
            }
            return false;
        }

        // Layer 2: Keine String-Injection (Quotes, Backticks, Backslashes)
        if (/[`'"\\]/.test(input)) {
            if (window.ecLog) {
                window.ecLog(`🛡️ SECURITY: String-Injection blockiert in: "${input}"`);
            }
            return false;
        }

        // Layer 3: Keine Semikolons (Code-Chaining verhindern)
        if (/;/.test(input)) {
            if (window.ecLog) {
                window.ecLog(`🛡️ SECURITY: Semikolon blockiert in: "${input}"`);
            }
            return false;
        }

        // Layer 4: Nur erlaubte Zeichen und Funktionsnamen
        // Erlaubte Zeichen: 0-9, +, -, *, /, ^, !, %, ., (, ), π, e, √, Leerzeichen
        // Erlaubte Funktionen: sin, cos, tan, arcsin, arccos, arctan, asin, acos, atan, ln, log, exp, sqrt
        const allowedPattern = /^[0-9+\-*/().πe√^!%\s,]+$|sin|cos|tan|arcsin|arccos|arctan|asin|acos|atan|ln|log|exp|sqrt/;

        // Entferne alle erlaubten Funktionsnamen temporär
        let testInput = input;
        testInput = testInput.replace(/sin|cos|tan|arcsin|arccos|arctan|asin|acos|atan|ln|log|exp|sqrt/g, '');

        // Prüfe ob nur erlaubte Zeichen übrig bleiben
        if (!/^[0-9+\-*/().πe√^!%\s,]*$/.test(testInput)) {
            if (window.ecLog) {
                window.ecLog(`🛡️ SECURITY: Unerlaubte Zeichen blockiert in: "${input}"`);
            }
            return false;
        }

        // Alles ok!
        if (window.ecLog) {
            window.ecLog(`✅ SECURITY: Input validiert: "${input}"`);
        }
        return true;
    }

    /**
     * Auto-Complete: Fehlende schließende Klammern hinzufügen
     * Zählt öffnende und schließende Klammern und ergänzt fehlende )
     */
    autoCompleteParentheses(expression) {
        if (!expression) return expression;

        // Zähle öffnende und schließende Klammern
        let openCount = 0;
        let closeCount = 0;

        for (let char of expression) {
            if (char === '(') openCount++;
            if (char === ')') closeCount++;
        }

        // Berechne wie viele Klammern fehlen
        const missingClosing = openCount - closeCount;

        if (missingClosing > 0) {
            // Füge fehlende schließende Klammern hinzu
            const completed = expression + ')'.repeat(missingClosing);

            if (window.ecLog) {
                window.ecLog(`🔧 Auto-Complete: ${missingClosing} Klammer(n) ergänzt: "${expression}" → "${completed}"`);
            }

            return completed;
        }

        // Keine fehlenden Klammern
        return expression;
    }

    /**
     * Berechnung ausführen (erweitert mit Verlauf)
     */
    calculate() {
        try {
            let expression = this.current;

            // Wenn leer, aber letztes Ergebnis vorhanden
            if (expression === '' && this.lastResult !== null && this.lastOperator) {
                expression = this.lastResult + this.lastOperator + this.lastOperand;
            }

            // KLAMMER-BALANCE: Unbalancierte Ausdrücke ablehnen statt still korrigieren
            const openCount = (expression.match(/\(/g) || []).length;
            const closeCount = (expression.match(/\)/g) || []).length;
            if (openCount !== closeCount) {
                this.display.value = `Fehler: ${openCount - closeCount} Klammer(n) offen`;
                if (window.ecLog) {
                    window.ecLog(`❌ Klammer-Fehler: ${openCount} offen, ${closeCount} geschlossen`);
                }
                return;
            }

            // SICHERHEIT: Input-Validierung VOR eval()
            if (!this.validateInput(expression)) {
                this.current = 'Ungültige Eingabe';
                this.display.value = this.current;
                if (window.ecLog) {
                    window.ecLog(`❌ SECURITY: Berechnung blockiert - ungültige Eingabe`);
                }
                return;
            }

            // Expression für eval() vorbereiten
            const parsed = this.parseExpression(expression);
            let result = eval(parsed);

            // Epsilon-Cleanup anwenden
            result = this.applyEpsilonCleanup(result);

            //  In Verlauf hinzufügen (nur wenn gültiges Ergebnis)
            if (!isNaN(result) && isFinite(result)) {
                this.addToHistory(expression, result);
            }

            this.lastResult = result;
            this.updateDisplay(result);
            this.current = '';

            if (window.ecLog) {
                window.ecLog(`🔢 Berechnung: ${expression} = ${result}`);
            }

        } catch (error) {
            this.current = 'Fehler';
            this.display.value = this.current;

            if (window.ecLog) {
                window.ecLog(`❌ Berechnungsfehler: ${error.message}`);
            }
        }
    }

    /**
     * Expression für JavaScript eval() umwandeln (ERWEITERTE VERSION)
     */
    parseExpression(expression) {
        let parsed = expression;
        if (window.ecLog) window.ecLog(`🔧 Parse Input: "${expression}"`);

        //  Fakultät-Funktion definieren (muss vor anderen Ersetzungen stehen)
        if (!window.factorialFunction) {
            window.factorialFunction = (n) => {
                if (n < 0) return NaN;
                if (n === 0 || n === 1) return 1;
                let result = 1;
                for (let i = 2; i <= n; i++) {
                    result *= i;
                }
                return result;
            };
        }

        //  Fakultät-Operator (!) verarbeiten - ZUERST
        parsed = parsed.replace(/(\d+(?:\.\d+)?)!/g, 'factorialFunction($1)');

        //  Prozent-Operator (%) verarbeiten
        // Pattern: Zahl% wird zu (Zahl/100)
        parsed = parsed.replace(/(\d+(?:\.\d+)?)%/g, '($1/100)');

        //  Kehrwert 1/x - wenn 1/x als Token gefunden wird
        parsed = parsed.replace(/1\/x/g, '(1/x)');

        // e^(...) zu exp(...) konvertieren - MUSS VOR der e→Math.E Ersetzung stehen!
        // Matcht: e^(0.5) → exp(0.5)
        parsed = parsed.replace(/(^|[^A-Za-z0-9_.])e\^\(([^)]+)\)/g, (match, pre, arg) => {
            if (window.ecLog) window.ecLog(`🔧 e^(...)-Ersetzung: "${match.trim()}" → "exp(${arg})"`);
            return `${pre}exp(${arg})`;
        });

        // Konstanten nur als eigenständige Tokens ersetzen (verhindert 1e5 -> 1Math.E5)
        parsed = parsed
            .replace(/(^|[^A-Za-z0-9_.])π(?![A-Za-z0-9_])/g, (_, pre) => pre + Math.PI)
            .replace(/(^|[^A-Za-z0-9_.])e(?![A-Za-z0-9_])/g, (_, pre) => pre + Math.E);

        // √ Wurzel-Funktion (vor anderen Funktionen)
        // WICHTIG: Ohne Klammern ZUERST, damit √2 nicht als √ + 2 geparst wird
        // Ohne Klammern: √2 oder √16 (nimmt die nächste Zahl)
        parsed = parsed.replace(/√(\d+(?:\.\d+)?)/g, (match, num) => {
            if (window.ecLog) window.ecLog(`🔧 √-Ersetzung ohne Klammern: "${match}" → "Math.sqrt(${num})"`);
            return `Math.sqrt(${num})`;
        });
        // Mit Klammern: √(2+3)
        parsed = parsed.replace(/√\(([^)]+)\)/g, (match, arg) => {
            if (window.ecLog) window.ecLog(`🔧 √-Ersetzung mit Klammern: "${match}" → "Math.sqrt(${arg})"`);
            return `Math.sqrt(${arg})`;
        });
        // sqrt() - aber NUR wenn NICHT bereits Math.sqrt() ist
        // Negative Lookbehind: (?<!Math\.) verhindert Doppel-Ersetzung
        parsed = parsed.replace(/(?<!Math\.)sqrt\(([^)]+)\)/g, (match, arg) => {
            if (window.ecLog) window.ecLog(`🔧 sqrt-Ersetzung: "${match}" → "Math.sqrt(${arg})"`);
            return `Math.sqrt(${arg})`;
        });

        // exp() Funktion - Exponentialfunktion
        parsed = parsed.replace(/exp\s*\(([^)]+)\)/g, (m, arg) => `Math.exp(${arg})`);

        //  Natürlicher Logarithmus ln() - mit und ohne Klammern
        parsed = parsed.replace(
            /(^|[^A-Za-z0-9_.])ln\s*\(([^)]+)\)/g,
            (m, pre, arg) => `${pre}Math.log(${arg})`
        );
        // ln ohne Klammern (nimmt nächste Zahl)
        parsed = parsed.replace(
            /(^|[^A-Za-z0-9_.])ln\s+(\d+(?:\.\d+)?)/g,
            (m, pre, num) => `${pre}Math.log(${num})`
        );

        // Logarithmus zur Basis 10 - mit und ohne Klammern
        parsed = parsed.replace(
            /(^|[^A-Za-z0-9_.])log\s*\(([^)]+)\)/g,
            (m, pre, arg) => `${pre}Math.log10(${arg})`
        );
        // log ohne Klammern
        parsed = parsed.replace(
            /(^|[^A-Za-z0-9_.])log\s+(\d+(?:\.\d+)?)/g,
            (m, pre, num) => `${pre}Math.log10(${num})`
        );

        // Winkel-Konvertierung basierend auf Modus
        const angleConversion = this.angleMode === 'rad' ? '' : '*Math.PI/180';
        const inverseAngleConversion = this.angleMode === 'rad' ? '' : '*180/Math.PI';

        // Inverse trigonometrische Funktionen (arcsin, arccos, arctan)
        parsed = parsed.replace(
            /(^|[^A-Za-z0-9_.])(arcsin|asin)\s*\(([^)]+)\)/g,
            (m, pre, fn, arg) => `${pre}(Math.asin(${arg})${inverseAngleConversion})`
        );
        parsed = parsed.replace(
            /(^|[^A-Za-z0-9_.])(arccos|acos)\s*\(([^)]+)\)/g,
            (m, pre, fn, arg) => `${pre}(Math.acos(${arg})${inverseAngleConversion})`
        );
        parsed = parsed.replace(
            /(^|[^A-Za-z0-9_.])(arctan|atan)\s*\(([^)]+)\)/g,
            (m, pre, fn, arg) => `${pre}(Math.atan(${arg})${inverseAngleConversion})`
        );

        // Trig-Funktionen mit beliebigem Exponenten: sin^n(x), cos^n(x), tan^n(x)
        parsed = parsed.replace(
            /(^|[^A-Za-z0-9_.])(sin|cos|tan)\^(\d+)\s*\(([^)]+)\)/g,
            (m, pre, fn, exp, arg) => `${pre}Math.pow(Math.${fn}(${arg}${angleConversion}),${exp})`
        );

        // Normale trigonometrische Funktionen
        parsed = parsed.replace(
            /(^|[^A-Za-z0-9_.])(sin|cos|tan)\s*\(([^)]+)\)/g,
            (m, pre, fn, arg) => `${pre}Math.${fn}(${arg}${angleConversion})`
        );

        // Exponenten-Operator zuletzt
        parsed = parsed.replace(/\^/g, '**');

        if (window.ecLog) window.ecLog(`Parse Output: "${parsed}"`);
        return parsed;
    }


    /**
     * Alles zurücksetzen
     */
    clear() {
        this.current = '';
        this.lastResult = null;
        this.lastOperator = null;
        this.lastOperand = null;
        this.display.value = '';

        if (window.ecLog) {
            window.ecLog('Calculator cleared');
        }
    }

    /**
     * Zu Expression hinzufügen
     */
    addToExpression(value) {
    // Nach einem Ergebnis bei Ziffer-Eingabe neu starten
    if (this.lastResult !== null && this.current === '' && !isNaN(value) && value !== '.') {
        this.lastResult = null;
        this.lastOperator = null;
        this.lastOperand = null;
    }

    // Operator merken
    if (/[\+\-\*\/]/.test(value)) {
        this.lastOperator = value;
        if (this.lastResult !== null && this.current === '') {
            // Nur bei leerem current das letzte Ergebnis verwenden
            this.current = this.lastResult.toString();
        }
    }
    // Operand merken
    else if (!isNaN(parseFloat(value)) || value === '.') {
        this.lastOperand = value;
    }

    this.current += value;
    this.display.value = this.formatDisplayExpression(this.current);
}

    /**
     *  Kehrwert hinzufügen (1/x)
     */
    addReciprocal() {
        if (this.lastResult !== null && this.current === '') {
            // Wenn letztes Ergebnis vorhanden, Kehrwert davon
            const reciprocal = 1 / this.lastResult;
            this.lastResult = reciprocal;
            this.updateDisplay(reciprocal);

            if (window.ecLog) {
                window.ecLog(`🔄 Kehrwert von Ergebnis: 1/${this.lastResult} = ${reciprocal}`);
            }
        } else if (this.current) {
            // Aktuelle Expression in Kehrwert umwandeln
            this.current = `1/(${this.current})`;
            this.display.value = this.formatDisplayExpression(this.current);

            if (window.ecLog) {
                window.ecLog(`🔄 Kehrwert von Expression: ${this.current}`);
            }
        } else {
            // Nichts vorhanden - "1/(" als Eingabe starten
            this.current = '1/(';
            this.display.value = '1/(';

            if (window.ecLog) {
                window.ecLog('🔄 1/( Eingabe gestartet');
            }
        }
    }

    /**
     *  Fakultät hinzufügen (!)
     */
    addFactorial() {
        if (this.lastResult !== null && this.current === '') {
            // Fakultät vom letzten Ergebnis
            try {
                const factorial = window.factorialFunction(Math.floor(this.lastResult));
                this.lastResult = factorial;
                this.updateDisplay(factorial);

                if (window.ecLog) {
                    window.ecLog(`❗ Fakultät von Ergebnis: ${Math.floor(this.lastResult)}! = ${factorial}`);
                }
            } catch (error) {
                this.display.value = 'Fehler';
                if (window.ecLog) {
                    window.ecLog(`❌ Fakultät-Fehler: ${error.message}`);
                }
            }
        } else {
            // Fakultät zur aktuellen Expression hinzufügen
            this.current += '!';
            this.display.value = this.formatDisplayExpression(this.current);

            if (window.ecLog) {
                window.ecLog(`❗ Fakultät hinzugefügt: ${this.current}`);
            }
        }
    }

    /**
     *  Prozent hinzufügen (%)
     */
    addPercent() {
        if (this.lastResult !== null && this.current === '') {
            // Prozent vom letzten Ergebnis
            const percent = this.lastResult / 100;
            this.lastResult = percent;
            this.updateDisplay(percent);

            if (window.ecLog) {
                window.ecLog(`📊 Prozent von Ergebnis: ${this.lastResult}% = ${percent}`);
            }
        } else {
            // Prozent zur aktuellen Expression hinzufügen
            this.current += '%';
            this.display.value = this.formatDisplayExpression(this.current);

            if (window.ecLog) {
                window.ecLog(`📊 Prozent hinzugefügt: ${this.current}`);
            }
        }
    }

    /**
     *  Quadrat hinzufügen (x²)
     */
    addSquare() {
        if (this.lastResult !== null && this.current === '') {
            // Quadrat vom letzten Ergebnis
            const square = Math.pow(this.lastResult, 2);
            this.lastResult = square;
            this.updateDisplay(square);

            if (window.ecLog) {
                window.ecLog(`⏹️ Quadrat von Ergebnis: ${this.lastResult}² = ${square}`);
            }
        } else if (this.current) {
            // Quadrat zur aktuellen Expression hinzufügen
            // Intelligente Erkennung des letzten Elements
            const lastToken = this.extractLastToken(this.current);
            if (lastToken) {
                this.current = lastToken.remaining + `(${lastToken.token})^2`;
            } else {
                this.current = `(${this.current})^2`;
            }
            this.display.value = this.formatDisplayExpression(this.current);

            if (window.ecLog) {
                window.ecLog(`⏹️ Quadrat hinzugefügt: ${this.current}`);
            }
        } else {
            // Nichts vorhanden - Button ignorieren
            return;
        }
    }

    /**
     *  Letzten Token aus Expression extrahieren (für x² Funktion)
     */
    extractLastToken(expression) {
        if (!expression) return null;

        // Patterns für verschiedene Token-Typen (von komplexen zu einfachen)
        const patterns = [
            // Funktionen mit Klammern: sin(30), cos(45), log(10), etc.
            /([a-zA-Z]+\([^)]*\))$/,
            // Geklammerte Ausdrücke: (2+3), (5*6)
            /(\([^)]*\))$/,
            // Konstanten: π, e
            /([πe])$/,
            // Dezimalzahlen: 123.45, .5
            /(\d*\.?\d+)$/,
            // Einzelne Ziffer
            /(\d)$/
        ];

        for (const pattern of patterns) {
            const match = expression.match(pattern);
            if (match) {
                const token = match[1];
                const remaining = expression.slice(0, -token.length);

                if (window.ecLog) {
                    window.ecLog(`🔍 Token für x² gefunden: "${token}" | Rest: "${remaining}"`);
                }

                return { token, remaining };
            }
        }

        return null;
    }

    /**
     *  Expression für Display formatieren (mit Superscript)
     */
    formatDisplayExpression(expression) {
        const superscriptMap = {
            '0': '⁰', '1': '¹', '2': '²', '3': '³', '4': '⁴',
            '5': '⁵', '6': '⁶', '7': '⁷', '8': '⁸', '9': '⁹',
            '+': '⁺', '-': '⁻', '(': '⁽', ')': '⁾', '.': '·'
        };

        let formattedExpression = expression;

        // Exponenten MIT KLAMMERN in Superscript umwandeln: ^(0.5) → ⁽⁰·⁵⁾
        formattedExpression = formattedExpression.replace(/\^\(([^)]+)\)/g, (match, content) => {
            return content.split('').map(char => superscriptMap[char] || char).join('');
        });

        // Exponenten OHNE KLAMMERN in Superscript umwandeln: ^2 → ²
        formattedExpression = formattedExpression.replace(/\^(\d+)/g, (match, exponent) => {
            return exponent.split('').map(char => superscriptMap[char] || char).join('');
        });

        if (window.ecLog && formattedExpression !== expression) {
            window.ecLog(`📝 Display-Format: "${expression}" → "${formattedExpression}"`);
        }

        return formattedExpression;
    }

    /**
     *  Verlauf hinzufügen
     */
    addToHistory(expression, result) {
        const historyItem = {
            expression: expression,
            result: result,
            timestamp: new Date()
        };

        this.history.unshift(historyItem);

        if (this.history.length > this.maxHistoryItems) {
            this.history = this.history.slice(0, this.maxHistoryItems);
        }

        this.updateHistoryDisplay();

        if (window.ecLog) {
            window.ecLog(`📜 Verlauf hinzugefügt: ${expression} = ${result}`);
        }
    }

    /**
     *  Verlauf anzeigen/verstecken
     */
    toggleHistory() {
        this.historyVisible = !this.historyVisible;

        const historyPanel = document.getElementById('history-panel');
        const historyToggle = document.getElementById('history-toggle');

        if (this.historyVisible) {
            this.calc.classList.add('with-history');
        } else {
            this.calc.classList.remove('with-history');
        }

        if (historyPanel && historyToggle) {
            if (this.historyVisible) {
                historyPanel.classList.remove('hidden');
                historyToggle.classList.add('active');
                this.calc.classList.add('with-history');
            } else {
                historyPanel.classList.add('hidden');
                historyToggle.classList.remove('active');
                this.calc.classList.remove('with-history');
            }
        }

        if (window.ecLog) {
            window.ecLog(`📜 Verlauf ${this.historyVisible ? 'angezeigt' : 'versteckt'}`);
        }
    }

    /**
     *  Verlauf löschen
     */
    clearHistory() {
        this.history = [];
        this.updateHistoryDisplay();

        if (window.ecLog) {
            window.ecLog('🗑️ Verlauf geleert');
        }
    }

    /**
     *  Verlauf-Anzeige aktualisieren
     */
    updateHistoryDisplay() {
        const historyList = document.getElementById('history-list');
        if (!historyList) return;

        if (this.history.length === 0) {
            historyList.innerHTML = '<div class="history-empty">Noch keine Rechnungen</div>';
            return;
        }

        let historyHTML = '';
        this.history.forEach((item, index) => {
            const resultDisplay = this.showingFraction && !isNaN(item.result)
                ? this.formatAsFraction(parseFloat(item.result))
                : item.result;

            historyHTML += `
                <div class="history-item" data-index="${index}">
                    <div class="history-expression">${this.escapeHtml(item.expression)}</div>
                    <div class="history-result">= ${resultDisplay}</div>
                </div>
            `;
        });

        historyList.innerHTML = historyHTML;
    }

    /**
     *  Bruch-Modus umschalten
     */
    toggleFractionMode() {
        this.showingFraction = !this.showingFraction;

        if (window.ecLog) {
            window.ecLog(`⅓ toggleFractionMode() aufgerufen - showingFraction: ${this.showingFraction}`);
        }

        const fractionToggle = document.getElementById('fraction-toggle');
        if (fractionToggle) {
            fractionToggle.classList.toggle('active', this.showingFraction);
            fractionToggle.title = this.showingFraction
                ? "Zur Dezimaldarstellung wechseln"
                : "Zur Bruchdarstellung wechseln";
        }

        if (this.lastResult !== null && this.current === '') {
            this.updateDisplay(this.lastResult);
        }

        this.updateHistoryDisplay();

        if (window.ecLog) {
            window.ecLog(`⅓ Bruch-Modus ${this.showingFraction ? 'aktiviert' : 'deaktiviert'}`);
        }
    }

    /**
     *  Winkel-Modus umschalten (DEG/RAD)
     */
    toggleAngleMode() {
        this.angleMode = this.angleMode === 'deg' ? 'rad' : 'deg';

        const angleToggle = document.getElementById('angle-toggle');
        if (angleToggle) {
            angleToggle.textContent = this.angleMode.toUpperCase();
            angleToggle.classList.toggle('active', this.angleMode === 'rad');
            angleToggle.title = this.angleMode === 'deg'
                ? "Zu Radiant wechseln"
                : "Zu Grad wechseln";
        }

        const indicator = document.getElementById('angle-mode-display');
        if (indicator) {
            indicator.textContent = this.angleMode.toUpperCase();
            indicator.classList.toggle('rad-active', this.angleMode === 'rad');
        }

        if (window.ecLog) {
            window.ecLog(`📐 Winkel-Modus: ${this.angleMode.toUpperCase()}`);
        }
    }

    /**
     *  Shift-Modus umschalten (2nd-Funktionen)
     */
    toggleShiftMode() {
        this.shiftMode = !this.shiftMode;

        const shiftToggle = document.getElementById('shift-toggle');
        if (shiftToggle) {
            shiftToggle.classList.toggle('active', this.shiftMode);
        }

        // Trig-Buttons visuell umschalten
        const trigBtns = this.calc.querySelectorAll('.trig-btn');
        trigBtns.forEach(btn => {
            btn.classList.toggle('shift-active', this.shiftMode);
        });

        if (window.ecLog) {
            window.ecLog(`🔄 Shift-Modus ${this.shiftMode ? 'aktiviert' : 'deaktiviert'}`);
        }
    }

    /**
     *  Funktion hinzufügen (ln, log, exp, sin, cos, tan, etc.)
     */
    addFunction(funcName) {
        // Automatisch Klammer öffnen
        this.current += funcName + '(';
        this.display.value = this.formatDisplayExpression(this.current);

        if (window.ecLog) {
            window.ecLog(`📊 Funktion hinzugefügt: ${funcName}(`);
        }
    }

    /**
     *  10^x hinzufügen (PRÄFIX — öffnet Klammer für Exponent-Eingabe)
     */
    addPower10() {
        this.current += '10^(';
        this.display.value = this.formatDisplayExpression(this.current);

        if (window.ecLog) {
            window.ecLog(`🔟 10^( eingefügt`);
        }
    }

    /**
     *  Display aktualisieren (mit Bruch-Support und optimierter Zahlenformatierung)
     */
    updateDisplay(value) {
        if (window.ecLog) {
            window.ecLog(`🖥️ updateDisplay aufgerufen mit: ${value}, showingFraction: ${this.showingFraction}`);
        }

        if (this.showingFraction && !isNaN(value) && value !== 0) {
            const fractionText = this.formatAsFraction(parseFloat(value));
            this.display.value = fractionText;
        } else {
            // Optimierte Zahlenformatierung für bessere Lesbarkeit
            this.display.value = this.formatNumber(value);
        }
    }

    /**
     * Formatiert Zahlen für optimale Display-Darstellung
     * - Entfernt unnötige Dezimalstellen bei sehr großen Zahlen
     * - Nutzt präzise Scientific Notation
     * - Vermeidet Floating-Point Artefakte in der Anzeige
     */
    formatNumber(value) {
        // Wenn kein numerischer Wert, direkt zurückgeben (z.B. "Fehler", "Ungültige Eingabe")
        if (isNaN(value) || !isFinite(value)) {
            return value;
        }

        const num = parseFloat(value);

        // Sehr große oder sehr kleine Zahlen: Scientific Notation mit sinnvoller Präzision
        if (Math.abs(num) >= 1e15 || (Math.abs(num) < 1e-10 && Math.abs(num) > 0)) {
            // Nutze toPrecision für bessere Darstellung
            return num.toPrecision(10);
        }

        // Normale Zahlen: Maximal 12 signifikante Stellen
        // Dies vermeidet Floating-Point Artefakte wie "1.0000000000000001"
        const str = num.toString();

        // Wenn Exponentialschreibweise automatisch verwendet wird, optimiere sie
        if (str.includes('e')) {
            return num.toPrecision(10);
        }

        return str;
    }

    /**
     *  Dezimalzahl als Bruch formatieren
     */
    formatAsFraction(decimal) {
        if (Number.isInteger(decimal)) {
            return decimal.toString();
        }

        const fraction = this.decimalToFraction(decimal);

        if (fraction.whole !== 0) {
            return `${fraction.whole} ${fraction.numerator}/${fraction.denominator}`;
        } else {
            return `${fraction.numerator}/${fraction.denominator}`;
        }
    }

    /**
     *  Dezimalzahl in Bruch umwandeln
     */
    decimalToFraction(decimal) {
        const tolerance = 1.0E-6;
        let numerator = 1;
        let denominator = 1;
        let h1 = 1, h2 = 0, k1 = 0, k2 = 1;
        let b = Math.abs(decimal);

        do {
            const a = Math.floor(b);
            let aux = h1;
            h1 = a * h1 + h2;
            h2 = aux;
            aux = k1;
            k1 = a * k1 + k2;
            k2 = aux;
            b = 1 / (b - a);
        } while (Math.abs(Math.abs(decimal) - h1 / k1) > Math.abs(decimal) * tolerance);

        numerator = h1;
        denominator = k1;

        const whole = Math.floor(Math.abs(decimal));
        if (whole > 0 && Math.abs(decimal) > 1) {
            numerator = Math.abs(numerator) % denominator;
            if (numerator === 0) {
                return { whole: decimal < 0 ? -whole : whole, numerator: 0, denominator: 1 };
            }
            return {
                whole: decimal < 0 ? -whole : whole,
                numerator: numerator,
                denominator: denominator
            };
        }

        return {
            whole: 0,
            numerator: decimal < 0 ? -numerator : numerator,
            denominator: denominator
        };
    }

    /**
     *  Verlauf-Ergebnis verwenden
     */
    useHistoryResult(result) {
        const cleanResult = result.replace(/\s+\d+\/\d+/, '').trim();
        this.current = cleanResult;
        this.display.value = cleanResult;
        this.lastResult = parseFloat(cleanResult);

        if (window.ecLog) {
            window.ecLog(`📜 Verlauf-Ergebnis verwendet: ${cleanResult}`);
        }
    }

    /**
     *  HTML escapen für Sicherheit
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     *  Reset-Funktion (wichtig für Prüfungen!)
     */
    reset() {
        this.clear();
        this.clearHistory();
        this.showingFraction = false;
        this.historyVisible = false;

        const fractionToggle = document.getElementById('fraction-toggle');
        if (fractionToggle) {
            fractionToggle.classList.remove('active');
            fractionToggle.title = "Zur Bruchdarstellung wechseln";
        }

        const historyPanel = document.getElementById('history-panel');
        const historyToggle = document.getElementById('history-toggle');
        if (historyPanel && historyToggle) {
            historyPanel.classList.add('hidden');
            historyToggle.classList.remove('active');
            this.calc.classList.remove('with-history');
        }

        if (window.ecLog) {
            window.ecLog('🔄 Calculator komplett zurückgesetzt');
        }
    }

    /**
     *  Debug-Modus umschalten
     */
    toggleDebug() {
        window.ExamCalc.DEBUG = !window.ExamCalc.DEBUG;
        console.log(`[ExamCalc] Debug ${window.ExamCalc.DEBUG ? 'aktiviert' : 'deaktiviert'}`);
        return window.ExamCalc.DEBUG;
    }

    /**
     * NEU: Speicher-Funktionen
     */

    /**
     * M+ - Zum Speicher hinzufügen
     */
    memoryAdd() {
        const value = this.getCurrentValue();
        if (value !== null) {
            this.memory += value;
            this.updateMemoryIndicator();

            if (window.ecLog) {
                window.ecLog(`💾 M+ : ${value} hinzugefügt → Speicher = ${this.memory}`);
            }
        }
    }

    /**
     * M- - Vom Speicher abziehen
     */
    memorySubtract() {
        const value = this.getCurrentValue();
        if (value !== null) {
            this.memory -= value;
            this.updateMemoryIndicator();

            if (window.ecLog) {
                window.ecLog(`💾 M- : ${value} abgezogen → Speicher = ${this.memory}`);
            }
        }
    }

    /**
     * MR - Speicher abrufen
     */
    memoryRecall() {
        if (this.memory !== 0) {
            this.current = this.memory.toString();
            this.display.value = this.formatDisplayExpression(this.current);
            this.lastResult = this.memory;

            if (window.ecLog) {
                window.ecLog(`💾 MR : Speicher abgerufen = ${this.memory}`);
            }
        }
    }

    /**
     * MC - Speicher löschen
     */
    memoryClear() {
        this.memory = 0;
        this.updateMemoryIndicator();

        if (window.ecLog) {
            window.ecLog('💾 MC : Speicher gelöscht');
        }
    }

    /**
     * Aktuellen Wert ermitteln (Display oder letztes Ergebnis)
     */
    getCurrentValue() {
        if (this.current !== '' && this.current !== 'Fehler') {
            try {
                const parsed = this.parseExpression(this.current);
                return eval(parsed);
            } catch (error) {
                if (window.ecLog) {
                    window.ecLog(`❌ Fehler beim Auswerten: ${error.message}`);
                }
                return null;
            }
        } else if (this.lastResult !== null) {
            return this.lastResult;
        }
        return null;
    }

    /**
     * Speicher-Indikator aktualisieren
     */
    updateMemoryIndicator() {
        if (this.memoryIndicator) {
            if (this.memory !== 0) {
                this.memoryIndicator.textContent = `M: ${this.memory}`;
                this.memoryIndicator.style.display = 'block';
            } else {
                this.memoryIndicator.style.display = 'none';
            }
        }
    }

    /**
     * NEU: Alle Ergebnisse aus dem Verlauf addieren
     */
    addHistoryResults() {
        if (this.history.length === 0) {
            if (window.ecLog) {
                window.ecLog('⚠️ Kein Verlauf vorhanden zum Addieren');
            }
            return;
        }

        // Alle Ergebnisse aus dem Verlauf sammeln
        let sum = 0;
        let expression = '';

        this.history.forEach((item, index) => {
            const result = parseFloat(item.result);
            if (!isNaN(result)) {
                sum += result;
                if (index > 0) {
                    expression += ' + ';
                }
                expression += result;
            }
        });

        // Ergebnis anzeigen
        this.lastResult = sum;
        this.current = '';
        this.updateDisplay(sum);

        // In Verlauf hinzufügen
        this.addToHistory(`Σ(${this.history.length} Werte)`, sum);

        if (window.ecLog) {
            window.ecLog(`➕ Verlaufs-Addition: ${expression} = ${sum}`);
        }
    }

    /**
     * Calculator draggable machen (basic implementation)
     */
    makeCalculatorDraggable() {
        let isDragging = false;
        let startX = 0;
        let startY = 0;
        let startLeft = 0;
        let startTop = 0;

        this.calc.addEventListener('mousedown', (e) => {
            if (e.target.tagName === 'BUTTON' || e.target.tagName === 'INPUT') {
                return;
            }

            if (e.target.classList.contains('close-btn') ||
                e.target.classList.contains('history-btn') ||
                e.target.closest('.history-panel')) {
                return;
            }

            isDragging = true;
            startX = e.clientX;
            startY = e.clientY;

            const rect = this.calc.getBoundingClientRect();
            startLeft = rect.left;
            startTop = rect.top;

            this.calc.style.cursor = 'grabbing';
        });

        document.addEventListener('mousemove', (e) => {
            if (!isDragging) return;

            const deltaX = e.clientX - startX;
            const deltaY = e.clientY - startY;

            this.calc.style.left = (startLeft + deltaX) + 'px';
            this.calc.style.top = (startTop + deltaY) + 'px';
            this.calc.style.right = 'auto';
            this.calc.style.bottom = 'auto';
        });

        document.addEventListener('mouseup', () => {
            if (isDragging) {
                isDragging = false;
                this.calc.style.cursor = '';
            }
        });
    }
};
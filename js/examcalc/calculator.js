/**
 * calculator.js - Taschenrechner-Logic für ExtendedTestSettings (Extended)
 * Update: Support für neuen Header mit Close-Button
 * Erweitert um: Verlauf, Brüche, DEL-Funktion
 */

window.ExamCalc = window.ExamCalc || {};

window.ExamCalc.Calculator = class {
    constructor() {
        this.current = '';
        this.lastResult = null;
        this.lastOperator = null;
        this.lastOperand = null;
        
        this.btn = null;
        this.calc = null;
        this.display = null;
        this.sciButtons = null;
        
        this.isInitialized = false;
        
        // NEU: Verlauf und Bruch-Features
        this.history = [];
        this.maxHistoryItems = 10;
        this.showingFraction = false;
        this.historyVisible = false;
    }

    /**
     * Initialisiert den Calculator nachdem HTML geladen wurde
     */
    init() {
        this.btn = document.getElementById('calculator-btn');
        this.calc = document.getElementById('calculator');
        this.display = document.getElementById('calc-display');
        this.sciButtons = document.querySelectorAll('.sci');

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

        // Close Button (NEU - im Header)
        const closeBtn = this.calc.querySelector('.close-btn');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => this.hide());
        }

        // NEU: History Toggle Button
        const historyToggle = document.getElementById('history-toggle');
        if (historyToggle) {
            historyToggle.addEventListener('click', () => this.toggleHistory());
        }

        // NEU: Clear History Button
        const clearHistory = document.getElementById('clear-history');
        if (clearHistory) {
            clearHistory.addEventListener('click', () => this.clearHistory());
        }

        // Calculator Buttons (erweitert für neue Buttons)
        this.calc.querySelectorAll('.buttons button').forEach(button => {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                
                // SPEZIAL-BEHANDLUNG: Fraction Toggle Button
                if (button.classList.contains('fraction-toggle')) {
                    if (window.ecLog) {
                        window.ecLog('⅓ Fraction-Button über Button-Handler geklickt!');
                    }
                    this.toggleFractionMode();
                    return; // Wichtig: Nicht weiter verarbeiten
                }
                
                // DEBUG: Welcher Button wurde geklickt?
                if (window.ecLog) {
                    window.ecLog(`🔘 Button geklickt: "${button.textContent}" | Klassen: ${button.className}`);
                }
                
                this.handleButtonClick(button.textContent, button.classList);
            });
        });

        // NEU: History Item Clicks
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
        
        if (window.ecLog) {
            window.ecLog('🔄 Calculator angezeigt');
        }
    }

    /**
     * Calculator verstecken (NEU - separate Methode)
     */
    hide() {
        this.calc.style.display = 'none';
        
        if (window.ecLog) {
            window.ecLog('🔄 Calculator versteckt');
        }
    }

    /**
     * Button-Klicks verarbeiten (erweitert)
     */
    handleButtonClick(value, classList) {
        // NEU: DEL Button
        if (classList.contains('del-btn')) {
            this.deleteLastChar();
            return;
        }

        // NEU: Fraction Toggle Button
        if (classList.contains('fraction-toggle')) {
            this.toggleFractionMode();
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
            case 'SCI':
                this.toggleScientific();
                break;
            default:
                this.addToExpression(value);
                break;
        }
    }

    /**
     * NEU: Letztes Zeichen löschen (DEL-Funktion)
     */
    deleteLastChar() {
        if (this.current.length > 0) {
            this.current = this.current.slice(0, -1);
            this.display.value = this.current || '';
            
            if (window.ecLog) {
                window.ecLog('🗑️ Letztes Zeichen gelöscht');
            }
        }
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

            // Expression für eval() vorbereiten
            const parsed = this.parseExpression(expression);
            const result = eval(parsed);
            
            // NEU: In Verlauf hinzufügen
            this.addToHistory(expression, result);
            
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
     * Expression für JavaScript eval() umwandeln
     */
    parseExpression(expression) {
        return expression
            .replace(/π/g, Math.PI)
            .replace(/e(?![a-z])/g, Math.E) // e aber nicht in "exp" etc.
            .replace(/√(\d+\.?\d*)/g, 'Math.sqrt($1)')
            .replace(/√\(([^)]+)\)/g, 'Math.sqrt($1)') // √(expression)
            .replace(/\^/g, '**')
            .replace(/sin\(/g, 'Math.sin(')
            .replace(/cos\(/g, 'Math.cos(')
            .replace(/tan\(/g, 'Math.tan(')
            .replace(/log\(/g, 'Math.log10(')
            .replace(/ln\(/g, 'Math.log(')
            .replace(/x²/g, '**2')
            .replace(/!/g, '') // Factorial - vereinfacht
            .replace(/1\/x/g, '(1/x)');
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
            window.ecLog('🧹 Calculator cleared');
        }
    }

    /**
     * Scientific Mode toggle
     */
    toggleScientific() {
        this.sciButtons.forEach(btn => {
            btn.style.display = btn.style.display === 'none' ? 'inline-block' : 'none';
        });
        
        if (window.ecLog) {
            window.ecLog('🔬 Scientific mode toggled');
        }
    }

    /**
     * Zu Expression hinzufügen
     */
    addToExpression(value) {
        // Operator merken
        if (/[\+\-\*\/]/.test(value)) {
            this.lastOperator = value;
            if (this.lastResult !== null) {
                this.current = this.lastResult.toString();
            }
        } 
        // Operand merken
        else if (!isNaN(parseFloat(value)) || value === '.') {
            this.lastOperand = value;
        }

        this.current += value;
        this.display.value = this.current;
    }

    /**
     * NEU: Verlauf hinzufügen
     */
    addToHistory(expression, result) {
        const historyItem = {
            expression: expression,
            result: result,
            timestamp: new Date()
        };

        this.history.unshift(historyItem);
        
        // Nur die letzten 10 Einträge behalten
        if (this.history.length > this.maxHistoryItems) {
            this.history = this.history.slice(0, this.maxHistoryItems);
        }

        this.updateHistoryDisplay();
        
        if (window.ecLog) {
            window.ecLog(`📜 Verlauf hinzugefügt: ${expression} = ${result}`);
        }
    }

    /**
     * NEU: Verlauf anzeigen/verstecken
     */
    toggleHistory() {
        this.historyVisible = !this.historyVisible;
        
        const historyPanel = document.getElementById('history-panel');
        const historyToggle = document.getElementById('history-toggle');
        
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
     * NEU: Verlauf löschen
     */
    clearHistory() {
        this.history = [];
        this.updateHistoryDisplay();
        
        if (window.ecLog) {
            window.ecLog('🗑️ Verlauf geleert');
        }
    }

    /**
     * NEU: Verlauf-Anzeige aktualisieren
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
     * NEU: Bruch-Modus umschalten
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
                
            if (window.ecLog) {
                window.ecLog(`⅓ Button-Klasse aktualisiert: ${fractionToggle.className}`);
            }
        } else {
            if (window.ecLog) {
                window.ecLog('❌ Fraction-Toggle Button nicht gefunden!');
            }
        }
        
        // Aktuelles Display aktualisieren wenn es ein Ergebnis zeigt
        if (this.lastResult !== null && this.current === '') {
            if (window.ecLog) {
                window.ecLog(`⅓ Aktualisiere Display für lastResult: ${this.lastResult}`);
            }
            this.updateDisplay(this.lastResult);
        } else {
            if (window.ecLog) {
                window.ecLog(`⅓ Kein lastResult zum Aktualisieren (lastResult: ${this.lastResult}, current: "${this.current}")`);
            }
        }
        
        // Verlauf aktualisieren
        this.updateHistoryDisplay();
        
        if (window.ecLog) {
            window.ecLog(`⅓ Bruch-Modus ${this.showingFraction ? 'aktiviert' : 'deaktiviert'}`);
        }
    }

    /**
     * NEU: Display aktualisieren (mit Bruch-Support)
     */
    updateDisplay(value) {
        if (window.ecLog) {
            window.ecLog(`🖥️ updateDisplay aufgerufen mit: ${value}, showingFraction: ${this.showingFraction}`);
        }
        
        if (this.showingFraction && !isNaN(value) && value !== 0) {
            const fractionText = this.formatAsFraction(parseFloat(value));
            this.display.value = fractionText;
            
            if (window.ecLog) {
                window.ecLog(`🖥️ Bruch-Display gesetzt: ${fractionText}`);
            }
        } else {
            this.display.value = value;
            
            if (window.ecLog) {
                window.ecLog(`🖥️ Dezimal-Display gesetzt: ${value}`);
            }
        }
    }

    /**
     * NEU: Dezimalzahl als Bruch formatieren
     */
    formatAsFraction(decimal) {
        if (Number.isInteger(decimal)) {
            return decimal.toString();
        }

        const fraction = this.decimalToFraction(decimal);
        
        if (fraction.whole !== 0) {
            // Gemischte Zahl
            return `${fraction.whole} ${fraction.numerator}/${fraction.denominator}`;
        } else {
            // Einfacher Bruch
            return `${fraction.numerator}/${fraction.denominator}`;
        }
    }

    /**
     * NEU: Dezimalzahl in Bruch umwandeln
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

        // Handle mixed numbers
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
     * NEU: Verlauf-Ergebnis verwenden
     */
    useHistoryResult(result) {
        // Entferne eventuelle Bruch-Formatierung
        const cleanResult = result.replace(/\s+\d+\/\d+/, '').trim();
        this.current = cleanResult;
        this.display.value = cleanResult;
        this.lastResult = parseFloat(cleanResult);
        
        if (window.ecLog) {
            window.ecLog(`📜 Verlauf-Ergebnis verwendet: ${cleanResult}`);
        }
    }

    /**
     * NEU: HTML escapen für Sicherheit
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * NEU: Reset-Funktion (wichtig für Prüfungen!)
     */
    reset() {
        this.clear();
        this.clearHistory();
        this.showingFraction = false;
        this.historyVisible = false;
        
        // UI zurücksetzen
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
     * Calculator draggable machen (basic implementation)
     */
    makeCalculatorDraggable() {
        let isDragging = false;
        let startX = 0;
        let startY = 0;
        let startLeft = 0;
        let startTop = 0;

        this.calc.addEventListener('mousedown', (e) => {
            // Nur bei Klick auf Calculator-Header (nicht Buttons)
            if (e.target.tagName === 'BUTTON' || e.target.tagName === 'INPUT') {
                return;
            }
            
            // GEÄNDERT: Auch nicht bei Close-Button oder History
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
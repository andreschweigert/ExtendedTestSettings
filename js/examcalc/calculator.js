/**
 * calculator.js - Taschenrechner-Logic für ExtendedTestSettings
 * Saubere Trennung von Loader und Calculator-Funktionalität
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
     * Event-Listener setup
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

        // Calculator Buttons
        this.calc.querySelectorAll('button').forEach(button => {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                this.handleButtonClick(button.textContent);
            });
        });

        // Drag functionality (basic)
        this.makeCalculatorDraggable();
    }

    /**
     * Calculator anzeigen/verstecken
     */
    toggle() {
        const isVisible = this.calc.style.display === 'block';
        this.calc.style.display = isVisible ? 'none' : 'block';
        
        if (window.ecLog) {
            window.ecLog(`🔄 Calculator ${isVisible ? 'versteckt' : 'angezeigt'}`);
        }
    }

    /**
     * Button-Klicks verarbeiten
     */
    handleButtonClick(value) {
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
     * Berechnung ausführen
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
            
            this.lastResult = result;
            this.display.value = result;
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
            .replace(/ln\(/g, 'Math.log(');
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
/**
 * calculator-simple.js - Einfacher Taschenrechner (nur Grundrechenarten)
 * Part of ExtendedTestSettings Plugin
 */

window.ExamCalc = window.ExamCalc || {};

window.ExamCalc.DEBUG = false;

window.ecLog = function (message) {
    if (window.ExamCalc.DEBUG) {
        console.log(`[ExamCalc Simple] ${message}`);
    }
};

window.ExamCalc.Calculator = class {
    constructor() {
        this.current = '';
        this.lastResult = null;
        this.btn = null;
        this.calc = null;
        this.display = null;
        this.isInitialized = false;
    }

    init() {
        this.btn = document.getElementById('calculator-btn');
        this.calc = document.getElementById('calculator');
        this.display = document.getElementById('calc-display');

        if (!this.btn || !this.calc || !this.display) {
            console.error("[ExamCalc Simple] Calculator-Elemente nicht gefunden");
            return false;
        }

        this.setupEventListeners();
        this.isInitialized = true;

        if (window.ecLog) {
            window.ecLog('✅ Einfacher Calculator initialisiert');
        }

        return true;
    }

    setupEventListeners() {
        this.btn.addEventListener('click', () => {
            this.toggle();
        });

        document.addEventListener('keydown', (e) => {
            if (e.altKey && e.key.toLowerCase() === 'r') {
                e.preventDefault();
                this.toggle();
            }
        });

        const closeBtn = this.calc.querySelector('.close-btn');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => this.hide());
        }

        this.calc.querySelectorAll('.buttons button').forEach(button => {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                this.handleButtonClick(button.textContent, button.classList);
            });
        });

        this.makeCalculatorDraggable();
    }

    toggle() {
        const isVisible = this.calc.style.display === 'block';
        if (isVisible) {
            this.hide();
        } else {
            this.show();
        }
    }

    show() {
        this.calc.style.display = 'block';
        if (window.ecLog) {
            window.ecLog('🔄 Einfacher Calculator angezeigt');
        }
    }

    hide() {
        this.calc.style.display = 'none';
        if (window.ecLog) {
            window.ecLog('🔄 Einfacher Calculator versteckt');
        }
    }

    handleButtonClick(value, classList) {
        if (classList.contains('del-btn')) {
            this.deleteLastChar();
            return;
        }

        switch (value) {
            case '=':
                this.calculate();
                break;
            case 'AC':
                this.clear();
                break;
            default:
                this.addToExpression(value);
                break;
        }
    }

    deleteLastChar() {
        if (this.current.length > 0) {
            this.current = this.current.slice(0, -1);
            this.display.value = this.current || '';
            if (window.ecLog) {
                window.ecLog('🗑️ Letztes Zeichen gelöscht');
            }
        }
    }

    calculate() {
        try {
            if (this.current === '') return;

            // Nur Grundrechenarten erlauben
            const sanitized = this.current.replace(/[^0-9+\-*/.()]/g, '');
            const result = eval(sanitized);

            this.lastResult = result;
            this.display.value = result;
            this.current = '';

            if (window.ecLog) {
                window.ecLog(`🔢 Berechnung: ${sanitized} = ${result}`);
            }

        } catch (error) {
            this.current = 'Fehler';
            this.display.value = this.current;
            if (window.ecLog) {
                window.ecLog(`❌ Berechnungsfehler: ${error.message}`);
            }
        }
    }

    clear() {
        this.current = '';
        this.lastResult = null;
        this.display.value = '';
        if (window.ecLog) {
            window.ecLog('Calculator cleared');
        }
    }

    addToExpression(value) {
        if (this.lastResult !== null && this.current === '' && !isNaN(value) && value !== '.') {
            this.lastResult = null;
        }

        this.current += value;
        this.display.value = this.current;
    }

    reset() {
        this.clear();
        if (window.ecLog) {
            window.ecLog('🔄 Einfacher Calculator zurückgesetzt');
        }
    }

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
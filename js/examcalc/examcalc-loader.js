/**
 * examcalc-loader.js - Lädt HTML Template und initialisiert Calculator
 * Part of ExtendedTestSettings Plugin
 */

(function() {
    'use strict';

    // Debug-Logging initialisieren falls noch nicht vorhanden
    if (typeof window.ecLog === 'undefined') {
        window.ecLog = function(msg) { 
            console.log('[ExamCalc] ' + msg); 
        };
    }

    /**
     * Calculator HTML Template laden und einfügen
     */
    async function loadCalculatorTemplate() {
    try {
        window.ecLog('🔄 Lade Calculator Template...');

        // Template-Pfad prüfen
        if (!window.ExamCalc || !window.ExamCalc.templatePath) {
            console.error('[ExamCalc] ❌ Kein templatePath gesetzt! window.ExamCalc:', window.ExamCalc);
            throw new Error('Template-Pfad nicht gefunden');
        }

        window.ecLog(`🌐 Versuche Template zu laden von: "${window.ExamCalc.templatePath}"`);

        const response = await fetch(window.ExamCalc.templatePath);

            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const html = await response.text();
            
            // HTML in DOM einfügen
            const wrapper = document.createElement('div');
            wrapper.innerHTML = html.trim();
            document.body.appendChild(wrapper);
            
            window.ecLog('✅ Calculator Template geladen');
            
            return true;
            
        } catch (error) {
            window.ecLog('❌ Fehler beim Laden des Templates: ' + error.message);
            
            // Fallback: Inline HTML verwenden
            return loadCalculatorFallback();
        }
    }

    /**
     * Fallback: Calculator HTML inline erstellen
     */
    function loadCalculatorFallback() {
        window.ecLog('🔄 Verwende Fallback-HTML...');
        
        const calcHTML = `
            <button id="calculator-btn" title="Taschenrechner (Alt + R)">🧮</button>
            <div id="calculator">
                <input type="text" id="calc-display" readonly>
                <div class="buttons">
                    <button>(</button><button>)</button><button>√</button><button>^</button><button>AC</button>
                    <button>7</button><button>8</button><button>9</button><button>/</button><button>%</button>
                    <button>4</button><button>5</button><button>6</button><button>*</button><button>π</button>
                    <button>1</button><button>2</button><button>3</button><button>-</button><button>e</button>
                    <button>0</button><button>.</button><button>=</button><button>+</button><button>SCI</button>
                    <!-- Erweiterte Funktionen -->
                    <button class="sci">sin</button><button class="sci">cos</button><button class="sci">tan</button>
                    <button class="sci">log</button><button class="sci">ln</button>
                </div>
            </div>
        `;
        
        const wrapper = document.createElement('div');
        wrapper.innerHTML = calcHTML.trim();
        document.body.appendChild(wrapper);
        
        window.ecLog('✅ Fallback HTML eingefügt');
        return true;
    }

    /**
     * Calculator initialisieren
     */
    function initializeCalculator() {
        try {
            window.ecLog('🔄 Initialisiere Calculator...');
            
            // Calculator-Klasse prüfen
            if (!window.ExamCalc || !window.ExamCalc.Calculator) {
                throw new Error('Calculator-Klasse nicht gefunden');
            }

            // Calculator-Instanz erstellen und initialisieren  
            const calculator = new window.ExamCalc.Calculator();
            const success = calculator.init();

            if (success) {
                // Global verfügbar machen für Debugging
                window.ExamCalc.instance = calculator;
                window.ecLog('🎉 ExamCalc vollständig initialisiert!');
            } else {
                throw new Error('Calculator-Initialisierung fehlgeschlagen');
            }

        } catch (error) {
            window.ecLog('❌ Fehler bei Calculator-Initialisierung: ' + error.message);
        }
    }

    /**
     * Hauptfunktion: Calculator laden
     */
    async function setupExamCalc(retries = 10) {
    window.ecLog('🚀 ExamCalc Loader gestartet');

    try {
        // Warten bis templatePath verfügbar ist (max. 10 Versuche)
        while ((!window.ExamCalc || !window.ExamCalc.templatePath) && retries > 0) {
            window.ecLog('⏳ Warte auf window.ExamCalc.templatePath...');
            await new Promise(resolve => setTimeout(resolve, 100));
            retries--;
        }

        if (!window.ExamCalc || !window.ExamCalc.templatePath) {
            throw new Error('Template-Pfad ist nicht verfügbar');
        }

        const templateLoaded = await loadCalculatorTemplate();

        if (!templateLoaded) {
            throw new Error('Template konnte nicht geladen werden');
        }

        await new Promise(resolve => setTimeout(resolve, 100));

        initializeCalculator();

    } catch (error) {
        window.ecLog('💥 Kritischer Fehler beim ExamCalc Setup: ' + error.message);
    }
}


    // Event-Listener für Page Load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupExamCalc);
    } else {
        // DOM bereits geladen
        setupExamCalc();
    }

    // Alternative: Window load als Fallback
    window.addEventListener('load', () => {
        // Nur ausführen wenn noch nicht initialisiert
        if (!window.ExamCalc?.instance?.isInitialized) {
            window.ecLog('🔄 Window.load Fallback triggered');
            setupExamCalc();
        }
    });

})();
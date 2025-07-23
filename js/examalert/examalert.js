/**
 * examalert.js - KORRIGIERTE VERSION - Syntax-Fehler behoben
 * Diskrete Toast-Benachrichtigung ohne Ton
 */

(function() {
    'use strict';

    // Minimales Logging (nur in Debug-Modus)
    const DEBUG = false; // Setzen Sie auf true für Debugging
    const log = DEBUG ? (msg) => console.log('[ExamAlert] ' + msg) : () => {};

    class ExamAlertManager {
        constructor() {
            this.config = null;
            this.testEndTime = null;
            this.alertShown = false;
            this.checkInterval = null;
            this.isInitialized = false;
        }

        init() {
            log('Initialisiere ExamAlert...');

            // Konfiguration prüfen
            if (!window.ExamAlert?.config?.enabled) {
                log('ExamAlert nicht aktiviert oder konfiguriert');
                return false;
            }

            this.config = window.ExamAlert.config;
            log(`Konfiguration: ${this.config.alert_time}s vor Ende`);

            // ILIAS Timer-Variablen verwenden
            if (!this.detectILIASTestEnd()) {
                log('ILIAS Testende nicht gefunden');
                return false;
            }

            log(`Testende: ${new Date(this.testEndTime).toLocaleTimeString()}`);
            this.startMonitoring();
            
            this.isInitialized = true;
            return true;
        }

        /**
         * Nutzt ILIAS 9 Timer-System
         */
        detectILIASTestEnd() {
            // Methode 1: ILIAS test_end Variable
            if (typeof window.test_end === 'number' && window.test_end > 0) {
                this.testEndTime = window.test_end * 1000;
                log('Testende via window.test_end gefunden');
                return true;
            }

            // Methode 2: ILIAS Timer-Element auslesen
            const timeElement = document.querySelector('.ilTstWorkingFormInfo_ProcessTimeLeft, .timeleft, [class*="timeleft"]');
            if (timeElement) {
                const timeText = timeElement.textContent?.trim();
                log(`Zeit-Element gefunden: "${timeText}"`);
                
                // Parse "Es verbleiben Ihnen noch X Minuten Y Sekunden"
                const match = timeText.match(/(\d+)\s*(?:Minuten?|min)\s*(?:(?:und\s*)?(\d+)\s*(?:Sekunden?|sec)?)?|(\d+)\s*(?:Sekunden?|sec)/i);
                if (match) {
                    const minutes = parseInt(match[1] || 0);
                    const seconds = parseInt(match[2] || match[3] || 0);
                    const totalSeconds = minutes * 60 + seconds;
                    
                    if (totalSeconds > 0) {
                        this.testEndTime = Date.now() + (totalSeconds * 1000);
                        log(`Testende via Text-Parsing: ${minutes}m ${seconds}s`);
                        return true;
                    }
                }
            }

            // Methode 3: ILIAS versteckte Felder
            const testEndInput = document.querySelector('input[name*="test_end"], input[name*="end_time"]');
            if (testEndInput && testEndInput.value) {
                const endTime = parseInt(testEndInput.value);
                if (endTime > Date.now() / 1000) {
                    this.testEndTime = endTime * 1000;
                    log('Testende via verstecktes Feld gefunden');
                    return true;
                }
            }

            return false;
        }

        startMonitoring() {
            log('Starte Zeit-Monitoring...');
            
            // Prüfe alle 10 Sekunden (ressourcenschonend)
            this.checkInterval = setInterval(() => {
                this.checkTime();
            }, 10000);

            // Sofortige Prüfung
            this.checkTime();

            // Zusätzliche Prüfung 5 Sekunden vor Alert-Zeit
            const preAlertTime = Math.max(5000, (this.config.alert_time - 5) * 1000);
            setTimeout(() => {
                if (!this.alertShown) {
                    this.checkTime();
                    // Dann jede Sekunde prüfen für Präzision
                    if (this.checkInterval) {
                        clearInterval(this.checkInterval);
                    }
                    this.checkInterval = setInterval(() => this.checkTime(), 1000);
                }
            }, this.getTimeUntilAlert() - preAlertTime);
        }

        checkTime() {
            if (this.alertShown) return;

            const now = Date.now();
            const timeRemaining = this.testEndTime - now;
            const secondsRemaining = Math.floor(timeRemaining / 1000);

            // Debug-Log nur alle 60 Sekunden
            if (DEBUG && secondsRemaining % 60 === 0) {
                log(`Noch ${Math.floor(secondsRemaining / 60)}:${(secondsRemaining % 60).toString().padStart(2, '0')}`);
            }

            // Alert anzeigen
            if (secondsRemaining <= this.config.alert_time && secondsRemaining > 0) {
                this.showAlert();
            }

            // Monitoring beenden
            if (secondsRemaining <= 0) {
                this.stopMonitoring();
            }
        }

        getTimeUntilAlert() {
            const now = Date.now();
            const timeRemaining = this.testEndTime - now;
            return timeRemaining - (this.config.alert_time * 1000);
        }

        showAlert() {
            if (this.alertShown) return;
            this.alertShown = true;

            log('Zeige diskrete Zeit-Benachrichtigung');

            // Diskrete Toast-Benachrichtigung von oben
            const toastHTML = `
                <div id="exam-alert-toast" style="
                    position: fixed;
                    top: -100px;
                    left: 50%;
                    transform: translateX(-50%);
                    background: linear-gradient(135deg, #dc3545, #c82333);
                    color: white;
                    padding: 1.5rem 2rem;
                    border-radius: 12px;
                    box-shadow: 0 8px 32px rgba(220, 53, 69, 0.4);
                    z-index: 99999;
                    font-family: system-ui, -apple-system, sans-serif;
                    font-size: 16px;
                    font-weight: 500;
                    text-align: center;
                    min-width: 350px;
                    max-width: 90vw;
                    border: 2px solid rgba(255, 255, 255, 0.2);
                    transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
                    backdrop-filter: blur(10px);
                ">
                    <div style="display: flex; align-items: center; justify-content: center; gap: 12px;">
                        <span style="font-size: 24px;">⏰</span>
                        <div>
                            <div style="font-weight: 600; margin-bottom: 4px;">Zeit-Warnung</div>
                            <div style="font-size: 14px; opacity: 0.9;">
                                ${this.escapeHtml(this.config.alert_message)}
                            </div>
                            <div style="font-size: 13px; margin-top: 8px; padding: 6px 12px; background: rgba(255,255,255,0.2); border-radius: 20px; display: inline-block;">
                                <strong>Noch ${this.config.alert_time} Sekunden</strong>
                            </div>
                        </div>
                        <button onclick="document.getElementById('exam-alert-toast').style.top='-100px'; setTimeout(() => { const toast = document.getElementById('exam-alert-toast'); if(toast) toast.remove(); }, 400);" style="
                            background: rgba(255, 255, 255, 0.2);
                            border: 1px solid rgba(255, 255, 255, 0.3);
                            color: white;
                            width: 28px;
                            height: 28px;
                            border-radius: 50%;
                            cursor: pointer;
                            font-size: 16px;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            margin-left: 8px;
                            transition: all 0.2s;
                            flex-shrink: 0;
                        " onmouseover="this.style.background='rgba(255,255,255,0.3)'" 
                           onmouseout="this.style.background='rgba(255,255,255,0.2)'">×</button>
                    </div>
                </div>

                <style>
                @keyframes slideInFromTop {
                    from {
                        top: -100px;
                        opacity: 0;
                    }
                    to {
                        top: 20px;
                        opacity: 1;
                    }
                }

                @keyframes pulse {
                    0%, 100% { box-shadow: 0 8px 32px rgba(220, 53, 69, 0.4); }
                    50% { box-shadow: 0 8px 32px rgba(220, 53, 69, 0.7); }
                }

                #exam-alert-toast {
                    animation: slideInFromTop 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards,
                               pulse 2s ease-in-out infinite;
                }

                @media (max-width: 480px) {
                    #exam-alert-toast {
                        min-width: auto;
                        max-width: 95vw;
                        padding: 1rem 1.5rem;
                        font-size: 14px;
                    }
                }
                </style>
            `;

            document.body.insertAdjacentHTML('beforeend', toastHTML);

            // Toast automatisch einblenden
            setTimeout(() => {
                const toast = document.getElementById('exam-alert-toast');
                if (toast) {
                    toast.style.top = '20px';
                }
            }, 100);

            // Auto-close nach 20 Sekunden
            setTimeout(() => {
                const toast = document.getElementById('exam-alert-toast');
                if (toast) {
                    toast.style.top = '-100px';
                    setTimeout(() => {
                        if (toast.parentNode) {
                            toast.remove();
                        }
                    }, 400);
                }
            }, 20000);
        }

        stopMonitoring() {
            if (this.checkInterval) {
                clearInterval(this.checkInterval);
                this.checkInterval = null;
            }
            log('Monitoring gestoppt');
        }

        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    }

    // Initialisierung - warte auf ILIAS Timer
    function initExamAlert() {
        // Warte bis ILIAS Timer-Variablen verfügbar sind
        let attempts = 0;
        const maxAttempts = 50; // 5 Sekunden maximal warten

        const tryInit = () => {
            attempts++;
            
            if (window.test_end || document.querySelector('.ilTstWorkingFormInfo_ProcessTimeLeft, .timeleft')) {
                // ILIAS Timer gefunden
                const manager = new ExamAlertManager();
                if (manager.init()) {
                    window.ExamAlert.instance = manager;
                    log('ExamAlert erfolgreich initialisiert');
                } else {
                    log('ExamAlert-Initialisierung fehlgeschlagen');
                }
            } else if (attempts < maxAttempts) {
                // Noch nicht bereit, nochmal versuchen
                setTimeout(tryInit, 100);
            } else {
                log('ILIAS Timer-System nicht gefunden nach 5 Sekunden');
            }
        };

        tryInit();
    }

    // DOM Ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initExamAlert);
    } else {
        initExamAlert();
    }

    // Fallback für window.load
    window.addEventListener('load', () => {
        if (!window.ExamAlert?.instance?.isInitialized) {
            setTimeout(initExamAlert, 500);
        }
    });

})();
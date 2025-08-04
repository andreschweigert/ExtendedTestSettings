# ExtendedTestSettings Plugin für ILIAS

Das Plugin **ExtendedTestSettings** erweitert den ILIAS-Testkontext um zusätzliche Funktionen für Online-Prüfungen. Es dient als zentrales Verwaltungssystem für optionale Prüfungs-Features, die modular integriert und pro Test konfiguriert werden können.

---

## Funktionen

### 1. ExamCalc – Wissenschaftlicher Taschenrechner

Aktiviert einen Taschenrechner, der während einer Prüfung eingeblendet wird.

- Wissenschaftlicher Modus mit:
  - Trigonometrie
  - Wurzeln
  - Potenzen
  - Konstanten wie π und e
- Aktivierung pro Test über Einstellungstab

---

### 2. ExamSymbols – Spezialzeichen-Tastatur

Blendet eine kontext-sensitive Tastatur mit Sonderzeichen ein, die während der Prüfung genutzt werden kann.

- Kompatibel mit:
  - Standard-Eingabefeldern (`<input>`, `<textarea>`)
  - TinyMCE
  - CKEditor 4/5
  - `contentEditable`-Felder
    
---

### 3. ExamGuard – Eingabeeinschränkungen

Sichert den Prüfungsmodus durch Einschränkung von Interaktionen.

- Deaktiviert:
  - Rechtsklick
  - Copy/Paste
  - Tastenkombinationen für Entwickler-Tools

---

### 4. ExamAlert – Zeit-Warnung

Zeigt zeitbasierte Warnungen mit benutzerdefinierten Nachrichten während des Tests an.

- Konfigurierbare Warnzeit: 5 Sekunden bis 1 Stunde vor Testende
- Benutzerdefinierte Nachrichten: Individuelle Warntexte pro Test

---

### 5. ExamChecker

TBA

---

## Installation

1. Das Repository in folgendes Verzeichnis klonen:

   Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/ExtendedTestSettings/


2. composer install --no-dev + Plugin im ILIAS-Pluginmanager installieren und aktivieren.

3. In einem ILIAS-Testobjekt erscheint unter „Einstellungen“ ein neuer Tab: **Zusätzliche Funktionen**  
Dort können die Features testbezogen aktiviert oder deaktiviert werden.


## Kontakt

Andre.Schweigert@fau.de & Cornel.Musielak@fau.de


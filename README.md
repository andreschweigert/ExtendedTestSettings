Achtung - WIP - Benutzen auf eigene Gefahr.

# ExtendedTestSettings Plugin für ILIAS

Das **ExtendedTestSettings** Plugin erweitert den ILIAS-Testkontext um zusätzliche Funktionen für Online-Prüfungen. Es dient als zentrales Verwaltungssystem für optionale Prüfungs-Features, die modular integriert und pro Test konfiguriert werden können.

## Übersicht

Das Plugin bietet fünf spezialisierte Module für verschiedene Aspekte von Online-Prüfungen:

- **ExamCalc**: Wissenschaftlicher Taschenrechner während der Prüfung
- **ExamSymbols**: Sonderzeichen-Palette für Fachbereiche (Mathematik, Sprachen)
- **ExamGuard**: Eingabeschutz und Sicherheitsmaßnahmen
- **ExamAlert**: Zeitbasierte Warnungen vor Prüfungsende
- **ExamChecker**: Automatisierte Überprüfung der Testeinstellungen

Alle Module sind optional und können pro Test individuell aktiviert werden.

## Module im Detail

### ExamCalc - Wissenschaftlicher Taschenrechner

Aktiviert einen wissenschaftlichen Taschenrechner während der Prüfung mit erweiterten Funktionen.

**Funktionen:**
- Grundrechenarten und wissenschaftliche Funktionen
- Trigonometrie (sin, cos, tan)
- Logarithmen (ln, log)
- Wurzeln und Potenzen
- Mathematische Konstanten (π, e)
- Bruchdarstellung
- Berechnungsverlauf mit Wiederverwendung
- Verschiebbare Oberfläche
- Automatische Bereinigung bei Prüfungsende

**Aktivierung:** Pro Test über Einstellungstab

### ExamSymbols - Spezialzeichen-Tastatur

Blendet eine kontextsensitive Tastatur mit Sonderzeichen ein, die während der Prüfung genutzt werden kann.

**Verfügbare Symbol-Vorlagen:**
- **Mathematik/Wissenschaft**: π, √, ∑, ∞, ≈, ≠, ≤, ≥, ±, ∆, ∫, ∂, →, ↔, ∈, ∉, ∅, ², ³, °, griechische Buchstaben (α-ω, Α-Ω)
- **Spanisch**: Vokale mit Akzent (Á, É, Í, Ó, Ú, á, é, í, ó, ú), Ñ, ñ, ¿, ¡
- **Italienisch**: à, è, ì, ò, ù, é, í, ó, À, È, Ì, Ò, Ù, É, Í, Ó
- **Deutsch**: ä, ö, ü, ß, Ä, Ö, Ü
- **Französisch**: à, â, é, è, ê, ë, î, ï, ô, ù, û, ü, ÿ, ç, æ, œ, Großbuchstaben-Varianten
- **Griechisch**: Vollständiges griechisches Alphabet (Klein- und Großbuchstaben)
- **Chemie**: Reaktionspfeile (→, ←, ↔, ⇌), Ladungen (⁺, ⁻, ²⁺, ³⁺), Bindungen (−, ≡, ∼)
- **Physik**: Mathematische Symbole, Vektoren (→, ⃗, ∙, ×), Differentiale (∇, ∆), Konstanten (ℏ, ħ)

**Aktivierung:** Pro Test mit Auswahl der gewünschten Symbol-Vorlage

### ExamGuard - Eingabeeinschränkungen

Sichert den Prüfungsmodus durch Einschränkung von Interaktionen, die die Prüfungsintegrität gefährden könnten.

**Blockierte Aktionen:**
- Rechtsklick-Kontextmenü
- Copy/Paste/Cut (Strg+C/V/X)
- Alles markieren (Strg+A)
- Entwickler-Tools öffnen (F12, Strg+Shift+I/J/C)
- Textauswahl (mit Ausnahmen für Eingabefelder)

**Aktivierung:** Pro Test über Einstellungstab

### ExamAlert - Zeit-Warnung

Zeigt zeitbasierte Warnungen mit benutzerdefinierten Nachrichten während des Tests an.

**Funktionen:**
- Konfigurierbare Warnzeit: 5 Sekunden bis 1 Stunde vor Testende
- Benutzerdefinierte Nachrichten pro Test
- Diskrete Toast-Benachrichtigung von oben
- Live-Countdown mit verbleibender Zeit
- Automatisches Schließen nach 20 Sekunden

**Konfiguration:**
- Warnzeit in Sekunden (5-3600)
- Individuelle Warnnachricht
- Standard: "Achtung: Der Test endet bald!"

**Aktivierung:** Pro Test mit Zeitkonfiguration und Nachrichtentext

### ExamChecker - Prüfungseinstellungen Checker

Analysiert Test-Einstellungen und prüft diese gegen definierte Standards für Präsenz- und Fernprüfungen. Generiert übersichtliche Berichte über die Einhaltung von Prüfungsrichtlinien.

**Funktionen:**
- Auswahl zwischen Präsenz- und Fernprüfung
- Detaillierter Report mit Ampel-System
- Prozentuale Bewertung der Konfiguration
- Hinweise auf zu überprüfende Einstellungen

**Aktivierung:** Immer verfügbar als Analyse-Tool

## Installation

1. Das Repository in folgendes Verzeichnis klonen:
   ```
   Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/ExtendedTestSettings/
   ```

2. Abhängigkeiten installieren:
   ```bash
   composer install --no-dev
   ```

3. Plugin im ILIAS-Pluginmanager installieren und aktivieren.

4. In einem ILIAS-Testobjekt erscheint unter "Einstellungen" ein neuer Tab: **Erweiterte Test-Einstellungen**

Dort können die Features testbezogen aktiviert oder deaktiviert werden.

## Datenbankstruktur

Das Plugin erstellt folgende Tabellen:

- `examcalc_settings`: Taschenrechner-Konfiguration pro Test
- `examsymbols_settings`: Symbol-Vorlagen-Konfiguration pro Test
- `examguard_settings`: Eingabeschutz-Konfiguration pro Test
- `examalert_settings`: Zeit-Warnungen-Konfiguration pro Test

ExamChecker benötigt keine eigene Tabelle, da es nur analysiert.

## Konfiguration neuer Symbol-Vorlagen

Neue Symbol-Vorlagen können in `classes/ExamSymbols/class.ilExamSymbolsConfig.php` hinzugefügt werden:

```php
"neue_vorlage" => [
    "label" => "Neue Vorlage",
    "description" => "Beschreibung der neuen Vorlage",
    "symbols" => [
        "Symbol1", "Symbol2", "Symbol3"
    ]
]
```

## Debugging

Debug-Modus kann in den jeweiligen Handler-Klassen aktiviert werden:

```php
private const DEBUG_ENABLED = true;
```

Dies aktiviert ausführliche Console-Logs für die Fehlerdiagnose.

## Support

Bei Fragen oder Problemen:

**Entwickler:**
- Andre.Schweigert@fau.de
- Cornel.Musielak@fau.de

**Universität:**
Friedrich-Alexander-Universität Erlangen-Nürnberg (FAU)

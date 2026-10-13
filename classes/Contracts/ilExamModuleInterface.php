<?php
/**
 * SPDX-License-Identifier: AGPL-3.0-only
 * Original authors: André Schweigert and Cornel Musielak.
 * See LICENSE and NOTICE for license and attribution terms (AGPLv3, section 7(b)).
 */


/**
 * Interface für alle ExtendedTestSettings-Module (built-in und uploaded)
 *
 * Jedes Modul muss dieses Interface implementieren.
 * Metadaten (Name, Version, Tabellen, ILIAS-Kompatibilität) werden in module.json definiert.
 */
interface ilExamModuleInterface
{
    /**
     * Prüft ob das Modul für einen bestimmten Test aktiviert ist.
     * (test-spezifische Einstellung, nicht globale Aktivierung)
     */
    public function isEnabled(int $ref_id): bool;

    /**
     * Injiziert CSS, JS und HTML-Assets in das Template.
     * Wird nur im Testplayer-Kontext aufgerufen.
     */
    public function injectAssets(ilGlobalTemplateInterface $tpl, int $ref_id): void;

    /**
     * Speichert die test-spezifischen Einstellungen (aus POST-Daten).
     */
    public function saveSettings(int $ref_id, array $post_data): bool;

    /**
     * Gibt ILIAS-Formular-Elemente (ilFormPropertyGUI[]) für die
     * erweiterten Test-Einstellungen zurück.
     */
    public function getFormItems(int $ref_id): array;
}

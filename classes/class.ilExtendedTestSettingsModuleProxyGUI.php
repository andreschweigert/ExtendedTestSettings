<?php

/**
 * Zentraler Dispatcher für alle installierten Modul-GUIs.
 * Ermöglicht das Routing zu Modul-GUI-Klassen ohne Classmap-Neugenerierung.
 *
 * URL-Schema:
 *   ilias.php?...&cmdClass=ilextendedtestsettingsmoduleproxygui
 *           &cmd=<command>&module_id=<module_id>&ref_id=<ref_id>
 *
 * Jedes Modul das eine GUI benötigt, trägt in module.json ein:
 *   "gui_file": "class.ilMyModuleGUI.php"
 *   "gui_class": "ilMyModuleGUI"
 *
 * @ilCtrl_isCalledBy ilExtendedTestSettingsModuleProxyGUI: ilUIPluginRouterGUI
 */
class ilExtendedTestSettingsModuleProxyGUI
{
    private string $plugin_path;

    public function __construct()
    {
        $this->plugin_path = dirname(__DIR__);
    }

    public function executeCommand(): void
    {
        global $DIC;

        $module_id = preg_replace('/[^a-z0-9_]/', '', strtolower($_GET['module_id'] ?? ''));

        if ($module_id === '') {
            $DIC->ui()->mainTemplate()->setOnScreenMessage('failure', 'Kein Modul angegeben.');
            $DIC->ui()->mainTemplate()->printToStdout();
            return;
        }

        $module_dir = $this->plugin_path . '/modules/' . $module_id;
        $json_file  = $module_dir . '/module.json';

        if (!file_exists($json_file)) {
            // Built-in Modul? Dann in classes/ suchen
            $builtin_dir = $this->plugin_path . '/classes/' . ucfirst($module_id);
            $json_file   = $builtin_dir . '/module.json';
            if (!file_exists($json_file)) {
                $DIC->ui()->mainTemplate()->setOnScreenMessage('failure', "Modul '$module_id' nicht gefunden.");
                $DIC->ui()->mainTemplate()->printToStdout();
                return;
            }
            $module_dir = $builtin_dir;
        }

        $module_info = json_decode((string) file_get_contents($json_file), true);
        $gui_file    = $module_info['gui_file']  ?? null;
        $gui_class   = $module_info['gui_class'] ?? null;

        if (!$gui_file || !$gui_class) {
            $DIC->ui()->mainTemplate()->setOnScreenMessage('failure', "Modul '$module_id' hat keine GUI-Klasse definiert.");
            $DIC->ui()->mainTemplate()->printToStdout();
            return;
        }

        // Abhängigkeiten laden
        foreach ($module_info['require_files'] ?? [] as $rel_file) {
            $path = $module_dir . '/' . $rel_file;
            if (file_exists($path)) {
                require_once $path;
            }
        }

        $handler_path = $module_dir . '/' . $gui_file;
        if (!file_exists($handler_path)) {
            $DIC->ui()->mainTemplate()->setOnScreenMessage('failure', "GUI-Datei nicht gefunden: $gui_file");
            $DIC->ui()->mainTemplate()->printToStdout();
            return;
        }

        require_once $handler_path;

        if (!class_exists($gui_class)) {
            $DIC->ui()->mainTemplate()->setOnScreenMessage('failure', "GUI-Klasse nicht gefunden: $gui_class");
            $DIC->ui()->mainTemplate()->printToStdout();
            return;
        }

        $gui = new $gui_class();
        $gui->executeCommand();
    }
}

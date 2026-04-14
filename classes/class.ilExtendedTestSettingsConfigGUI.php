<?php

/**
 * Plugin Configuration GUI for ExtendedTestSettings
 * 
 * @ilCtrl_isCalledBy ilExtendedTestSettingsConfigGUI: ilObjComponentSettingsGUI
 */
class ilExtendedTestSettingsConfigGUI extends ilPluginConfigGUI
{
    protected ilCtrl $ctrl;
    protected ilGlobalTemplateInterface $tpl;
    protected ilLanguage $lng;
    protected ilTabsGUI $tabs;
    protected ilSetting $settings;

    public function __construct()
    {
        global $DIC;
        $this->ctrl = $DIC->ctrl();
        $this->tpl = $DIC->ui()->mainTemplate();
        $this->lng = $DIC->language();
        $this->tabs = $DIC->tabs();

        // Plugin-spezifische Settings
        $this->settings = new ilSetting('extendedtestsettings');
    }

    /**
     * performCommand - mit ilRepositorySearchGUI Integration
     */
    public function performCommand(string $cmd): void
    {
        global $DIC, $rbacsystem;

        // Auth-Prüfung für alle Commands
        if (!$DIC->user()->getId() || $DIC->user()->isAnonymous()) {
            $DIC->ui()->mainTemplate()->setOnScreenMessage(
                'failure',
                'Zugriff verweigert: Sie müssen angemeldet sein.'
            );
            $DIC->ctrl()->redirectToURL('./login.php');
            return;
        }

        if (!$rbacsystem->checkAccess("write", SYSTEM_FOLDER_ID)) {
            $DIC->ui()->mainTemplate()->setOnScreenMessage(
                'failure',
                'Zugriff verweigert: Administrator-Rechte erforderlich.'
            );
            $DIC->ctrl()->redirectToURL('./ilias.php?baseClass=ilDashboardGUI');
            return;
        }

        $this->setTabs();

        switch ($cmd) {
            case 'configure':
            case 'showGeneral':
                $this->showGeneral();
                break;

            case 'showStatistics':
                $this->showStatistics();
                break;

            case 'showExamChecker':
                $this->showExamChecker();
                break;

            case 'saveExamChecker':
                $this->saveExamChecker();
                break;

            case 'cleanupOrphanedEntries':
                $this->cleanupOrphanedEntries();
                break;

            case 'previewCleanup':
                $this->previewCleanup();
                break;

            case 'saveGeneralSettings':
                $this->saveGeneralSettings();
                break;

            case 'showUserSearch':
                $this->showUserSearch();
                break;

            case 'addSingleUser':
                $this->addSingleUser();
                break;

            case 'removeUser':
                $this->removeUser();
                break;

            // --- Module-Verwaltung ---
            case 'showModules':
                $this->showModules();
                break;

            case 'toggleModule':
                $this->toggleModule();
                break;

            case 'uploadModule':
                $this->uploadModule();
                break;

            case 'uninstallModule':
                $this->uninstallModule();
                break;

            case 'updateModule':
                $this->updateModule();
                break;

            case 'doUpdateModule':
                $this->doUpdateModule();
                break;

            case 'saveModuleRepoUrl':
                $this->saveModuleRepoUrl();
                break;

            default:
                $this->showGeneral();
                break;
        }
    }


    /**
     * Setup Tabs
     */
    private function setTabs(): void
    {
        $this->tabs->addTab(
            'general',
            'Allgemein',
            $this->ctrl->getLinkTarget($this, 'showGeneral')
        );

        $this->tabs->addTab(
            'modules',
            'Module',
            $this->ctrl->getLinkTarget($this, 'showModules')
        );

        $this->tabs->addTab(
            'statistics',
            'Statistiken',
            $this->ctrl->getLinkTarget($this, 'showStatistics')
        );

        $this->tabs->addTab(
            'examchecker',
            'ExamChecker',
            $this->ctrl->getLinkTarget($this, 'showExamChecker')
        );
    }

    /**
     * Tab 1: Allgemein
     */
    protected function showGeneral(): void
    {
        $this->tabs->activateTab('general');
        $this->tpl->setContent($this->renderGeneralTab());
    }

    private function renderGeneralTab(): string
    {
        $form        = $this->buildGeneralSettingsForm();
        $users_panel = $this->renderAuthorizedUsersPanel();
        return $this->renderPluginHeader() . $form->getHTML() . $users_panel;
    }

    private function renderPluginHeader(): string
    {
        global $DIC;
        $factory  = $DIC->ui()->factory();
        $renderer = $DIC->ui()->renderer();
        return $renderer->render(
            $factory->messageBox()->info(
                '⚙️ ExtendedTestSettings · ILIAS 9 Plugin · Erweiterte Prüfungseinstellungen für Tests'
            )
        );
    }

    private function buildGeneralSettingsForm(): ilPropertyFormGUI
    {
        $form = new ilPropertyFormGUI();
        $form->setFormAction($this->ctrl->getLinkTarget($this, 'saveGeneralSettings'));
        $form->setTitle('Tab-Sichtbarkeit in Test-Einstellungen');

        $visibility = $this->settings->get('tab_visibility', 'course_admins');

        $radio = new ilRadioGroupInputGUI('Sichtbarkeit', 'tab_visibility');
        $radio->setValue($visibility);
        $radio->setInfo('Bestimmen Sie, wer den Tab "Erweiterte Test-Einstellungen" in Tests sehen kann.');

        $opt_admins = new ilRadioOption(
            'Nur System-Administratoren',
            'admins_only',
            'Nur ILIAS System-Administratoren können den Tab sehen und verwenden.'
        );
        $radio->addOption($opt_admins);

        $opt_course = new ilRadioOption(
            'Kurs-Administratoren + System-Administratoren',
            'course_admins',
            'Kurs-Administratoren, Test-Besitzer und System-Administratoren können den Tab sehen.'
        );
        $radio->addOption($opt_course);

        $opt_specific = new ilRadioOption(
            'Nur ausgewählte Benutzer + System-Administratoren',
            'specific_users',
            'Nur explizit berechtigte Benutzer und System-Administratoren können den Tab sehen.'
        );
        $radio->addOption($opt_specific);

        $form->addItem($radio);

        // --- ExamAlert Auto-Aktivierung ---
        $section_alert = new ilFormSectionHeaderGUI();
        $section_alert->setTitle('⏰ ExamAlert Auto-Aktivierung');
        $form->addItem($section_alert);

        $auto_val   = $this->settings->get('examalert_auto_activate', '1');
        $radio_auto = new ilRadioGroupInputGUI('Standard für neue Tests', 'examalert_auto_activate');
        $radio_auto->setValue($auto_val);
        $radio_auto->setInfo(
            'Diese Einstellung betrifft nur neue Tests. '
            . 'Bereits existierende Tests behalten ihre aktuellen Einstellungen. '
            . 'Administratoren können ExamAlert jederzeit in den Test-Einstellungen manuell aktivieren oder deaktivieren.'
        );

        $opt_auto_on = new ilRadioOption(
            'Aktiviert: Jeder neue Test bekommt automatisch ExamAlert aktiviert (30s Warnung vor Ende)',
            '1',
            'Neue Tests erhalten automatisch eine 30-Sekunden-Warnung vor Testende.'
        );
        $radio_auto->addOption($opt_auto_on);

        $opt_auto_off = new ilRadioOption(
            'Deaktiviert: ExamAlert muss für jeden Test manuell in den Einstellungen aktiviert werden',
            '0',
            'ExamAlert ist bei neuen Tests standardmäßig deaktiviert.'
        );
        $radio_auto->addOption($opt_auto_off);

        $form->addItem($radio_auto);

        $form->addCommandButton('saveGeneralSettings', 'Einstellungen speichern');

        return $form;
    }

    /**
     * Zeigt die berechtigten Benutzer als ILIAS UI Panel mit Item-Liste
     */
    private function renderAuthorizedUsersPanel(): string
    {
        global $DIC;
        $factory  = $DIC->ui()->factory();
        $renderer = $DIC->ui()->renderer();

        $authorized_users = $this->getAuthorizedUsers();
        $add_user_url     = $this->ctrl->getLinkTarget($this, 'showUserSearch');
        $add_button       = $factory->button()->standard('Benutzer hinzufügen', $add_user_url);

        if (empty($authorized_users)) {
            $panel = $factory->panel()->standard(
                'Berechtigte Benutzer (0)',
                [
                    $factory->messageBox()->info('Noch keine Benutzer hinzugefügt.'),
                    $add_button,
                ]
            );
            return $renderer->render($panel);
        }

        $items  = [];
        $modals = [];

        foreach ($authorized_users as $user) {
            $fullname = trim($user['firstname'] . ' ' . $user['lastname']) ?: $user['login'];
            $login    = $user['login'];
            $avatar   = $factory->symbol()->avatar()->letter($login);

            $this->ctrl->setParameter($this, 'user_id', (int) $user['user_id']);
            $remove_url = $this->ctrl->getLinkTarget($this, 'removeUser');
            $this->ctrl->setParameter($this, 'user_id', '');

            $modal = $factory->modal()->interruptive(
                'Benutzer entfernen',
                'Benutzer @' . htmlspecialchars($login) . ' wirklich aus der Berechtigungsliste entfernen?',
                $remove_url
            );
            $modals[] = $modal;

            $item = $factory->item()->standard($fullname)
                ->withLeadAvatar($avatar)
                ->withDescription('@' . $login)
                ->withProperties([
                    'Hinzugefügt von' => htmlspecialchars($user['added_by_name']),
                    'Datum'           => htmlspecialchars($user['added_at_formatted']),
                ])
                ->withActions(
                    $factory->dropdown()->standard([
                        $factory->button()->shy('Entfernen', $modal->getShowSignal()),
                    ])
                );

            $items[] = $item;
        }

        $item_group = $factory->item()->group(
            count($authorized_users) . ' Benutzer berechtigt',
            $items
        );

        $panel = $factory->panel()->standard(
            'Berechtigte Benutzer (' . count($authorized_users) . ')',
            $item_group
        );

        return $renderer->render(array_merge([$panel, $add_button], $modals));
    }


    /**
     * Zeigt die Benutzersuche an
     */
    public function showUserSearch(): void
    {
        global $DIC;
        $db = $DIC->database();

        $this->tabs->activateTab('general');

        $search_query = $_POST['search_query'] ?? $_GET['search_query'] ?? '';

        $form = new ilPropertyFormGUI();
        $form->setFormAction($this->ctrl->getLinkTarget($this, 'showUserSearch'));
        $form->setTitle('Benutzer suchen und hinzufügen');

        $search_input = new ilTextInputGUI('Suchbegriff', 'search_query');
        $search_input->setValue($search_query);
        $search_input->setInfo(
            'Login, Vor- oder Nachname. Mindestens 2 Zeichen. '
            . 'Bereits berechtigte Benutzer werden ausgeblendet.'
        );
        $search_input->setSize(40);
        $form->addItem($search_input);

        $form->addCommandButton('showUserSearch', 'Suchen');
        $form->addCommandButton('showGeneral', 'Zurück');

        global $DIC;
        $factory  = $DIC->ui()->factory();
        $renderer = $DIC->ui()->renderer();

        $results_html = '';
        if (!empty($search_query) && strlen($search_query) >= 2) {
            $results_html = $this->renderUserSearchResults($db, $search_query, $factory, $renderer);
        } elseif (!empty($search_query)) {
            $results_html = $renderer->render(
                $factory->messageBox()->info('Bitte mindestens 2 Zeichen eingeben.')
            );
        }

        $this->tpl->setContent($form->getHTML() . $results_html);
    }

    /**
     * Rendert die Suchergebnisse als ILIAS Item-Liste
     * @param mixed $factory
     * @param mixed $renderer
     */
    private function renderUserSearchResults($db, string $search_query, $factory, $renderer): string
    {
        $search_pattern = '%' . $search_query . '%';
        $sql = "
            SELECT u.usr_id, u.login, u.firstname, u.lastname, u.email
            FROM usr_data u
            WHERE (u.login LIKE %s OR u.firstname LIKE %s OR u.lastname LIKE %s)
            AND u.usr_id NOT IN (SELECT user_id FROM extendedtest_users)
            AND u.usr_id > 13
            ORDER BY u.lastname, u.firstname
            LIMIT 50
        ";
        $result = $db->queryF(
            $sql,
            ['text', 'text', 'text'],
            [$search_pattern, $search_pattern, $search_pattern]
        );

        if ($db->numRows($result) === 0) {
            return $renderer->render(
                $factory->messageBox()->info(
                    'Keine Benutzer gefunden für "' . htmlspecialchars($search_query) . '".'
                )
            );
        }

        $items = [];
        while ($row = $db->fetchAssoc($result)) {
            $fullname = trim($row['firstname'] . ' ' . $row['lastname']) ?: $row['login'];
            $login    = $row['login'];
            $email    = $row['email'] ?? '';
            $avatar   = $factory->symbol()->avatar()->letter($login);

            $this->ctrl->setParameter($this, 'user_id', (int) $row['usr_id']);
            $this->ctrl->setParameter($this, 'search_query', $search_query);
            $add_url = $this->ctrl->getLinkTarget($this, 'addSingleUser');
            $this->ctrl->setParameter($this, 'user_id', '');
            $this->ctrl->setParameter($this, 'search_query', '');

            $add_btn = $factory->button()->standard('Hinzufügen', $add_url);

            $item = $factory->item()->standard(htmlspecialchars($fullname))
                ->withLeadAvatar($avatar)
                ->withDescription('@' . htmlspecialchars($login))
                ->withProperties(['E-Mail' => htmlspecialchars($email)])
                ->withMainAction($add_btn);

            $items[] = $item;
        }

        $count       = count($items);
        $item_group  = $factory->item()->group($count . ' Benutzer gefunden', $items);
        $panel       = $factory->panel()->standard('Suchergebnisse', $item_group);

        return $renderer->render($panel);
    }

    /**
     * Fügt einen einzelnen Benutzer hinzu
     */
    public function addSingleUser(): void
    {
        global $DIC;
        $db = $DIC->database();

        $user_id      = (int) ($_POST['user_id'] ?? $_GET['user_id'] ?? 0);
        $search_query = $_POST['search_query'] ?? $_GET['search_query'] ?? '';

        if ($user_id <= 0) {
            $this->tpl->setOnScreenMessage('failure', 'Ungültige Benutzer-ID');
            $this->ctrl->redirect($this, 'showUserSearch');
            return;
        }

        try {
            $current_user_id = $DIC->user()->getId();

            // Prüfen ob User bereits existiert
            $check = $db->queryF(
                "SELECT user_id FROM extendedtest_users WHERE user_id = %s",
                ['integer'],
                [$user_id]
            );

            if ($db->numRows($check) > 0) {
                $this->tpl->setOnScreenMessage('info', 'ℹ️ Benutzer war bereits berechtigt.');
            } else {
                // Benutzer-Name holen
                $user_result = $db->queryF(
                    "SELECT login, firstname, lastname FROM usr_data WHERE usr_id = %s",
                    ['integer'],
                    [$user_id]
                );

                $user_name = '';
                if ($user_row = $db->fetchAssoc($user_result)) {
                    $user_name = trim($user_row['firstname'] . ' ' . $user_row['lastname']) . ' (@' . $user_row['login'] . ')';
                }

                // Benutzer hinzufügen
                $db->insert('extendedtest_users', [
                    'user_id' => ['integer', $user_id],
                    'added_by' => ['integer', $current_user_id],
                    'added_at' => ['timestamp', date('Y-m-d H:i:s')]
                ]);

                $this->tpl->setOnScreenMessage('success', "✅ Benutzer $user_name wurde erfolgreich hinzugefügt.");
            }
        } catch (Exception $e) {
            error_log("[ExtendedTestSettings] Fehler beim Hinzufügen: " . $e->getMessage());
            $this->tpl->setOnScreenMessage('failure', '❌ Fehler: ' . $e->getMessage());
        }

        // Zurück zur Suche mit Query
        if (!empty($search_query)) {
            $this->ctrl->setParameter($this, 'search_query', $search_query);
            $_POST['search_query'] = $search_query;
            $this->showUserSearch();
        } else {
            $this->ctrl->redirect($this, 'showGeneral');
        }
    }

    /**
     * Callback für die User-Suche - fügt ausgewählte Benutzer hinzu
     * Diese Methode wird von ilRepositorySearchGUI aufgerufen
     */
    public function addUsersFromSearch(array $users): bool
    {
        global $DIC;
        $db = $DIC->database();

        if (empty($users)) {
            $this->tpl->setOnScreenMessage('info', 'Keine Benutzer ausgewählt.');
            $this->ctrl->redirect($this, 'showGeneral');
            return false;
        }

        $current_user_id = $DIC->user()->getId();
        $added_count = 0;
        $already_exists = 0;

        try {
            foreach ($users as $user_id) {
                $user_id = (int) $user_id;

                if ($user_id <= 0) {
                    continue;
                }

                // Prüfen ob User bereits existiert
                $check = $db->queryF(
                    "SELECT user_id FROM extendedtest_users WHERE user_id = %s",
                    ['integer'],
                    [$user_id]
                );

                if ($db->numRows($check) > 0) {
                    $already_exists++;
                    continue;
                }

                // Benutzer hinzufügen
                $db->insert('extendedtest_users', [
                    'user_id' => ['integer', $user_id],
                    'added_by' => ['integer', $current_user_id],
                    'added_at' => ['timestamp', date('Y-m-d H:i:s')]
                ]);

                $added_count++;
            }

            // Success-Message
            $messages = [];
            if ($added_count > 0) {
                $messages[] = "✅ $added_count Benutzer erfolgreich hinzugefügt.";
            }

            if ($already_exists > 0) {
                $messages[] = "ℹ️ $already_exists Benutzer waren bereits berechtigt.";
            }

            if (!empty($messages)) {
                $this->tpl->setOnScreenMessage('success', implode(' ', $messages));
            }
        } catch (Exception $e) {
            error_log("[ExtendedTestSettings] Fehler beim Hinzufügen: " . $e->getMessage());
            $this->tpl->setOnScreenMessage(
                'failure',
                '❌ Fehler beim Hinzufügen der Benutzer: ' . $e->getMessage()
            );
            return false;
        }

        $this->ctrl->redirect($this, 'showGeneral');
        return true;
    }

    /**
     * Lädt berechtigte Benutzer aus der Datenbank
     */
    private function getAuthorizedUsers(): array
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $query = "
            SELECT 
                eu.user_id,
                u.login,
                u.firstname,
                u.lastname,
                eu.added_at,
                adder.login as added_by_login,
                adder.firstname as added_by_firstname,
                adder.lastname as added_by_lastname
            FROM extendedtest_users eu
            JOIN usr_data u ON eu.user_id = u.usr_id
            LEFT JOIN usr_data adder ON eu.added_by = adder.usr_id
            ORDER BY u.lastname, u.firstname
        ";

            $result = $db->query($query);
            $users = [];

            while ($row = $db->fetchAssoc($result)) {
                $added_by_name = trim($row['added_by_firstname'] . ' ' . $row['added_by_lastname']);
                if (empty($added_by_name)) {
                    $added_by_name = $row['added_by_login'] ?? 'System';
                }

                $users[] = [
                    'user_id' => $row['user_id'],
                    'login' => $row['login'],
                    'firstname' => $row['firstname'],
                    'lastname' => $row['lastname'],
                    'added_at' => $row['added_at'],
                    'added_at_formatted' => $this->formatDateTime($row['added_at']),
                    'added_by_name' => $added_by_name
                ];
            }

            return $users;
        } catch (Exception $e) {
            error_log("[ExtendedTestSettings] Fehler beim Laden berechtigter Benutzer: " . $e->getMessage());
            return [];
        }
    }

    /**
     * AJAX: Benutzer suchen
     */
    public function searchUsersAjax(): void
    {
        global $DIC;
        $db = $DIC->database();

        header('Content-Type: application/json');

        $query = $_GET['q'] ?? '';

        if (strlen($query) < 2) {
            echo json_encode(['users' => []]);
            exit;
        }

        try {
            // Suche nach Login oder Name
            $search_pattern = '%' . $db->escape($query, true) . '%';

            $sql = "
            SELECT u.usr_id as user_id, u.login, u.firstname, u.lastname
            FROM usr_data u
            WHERE (
                u.login LIKE " . $db->quote($search_pattern, 'text') . "
                OR u.firstname LIKE " . $db->quote($search_pattern, 'text') . "
                OR u.lastname LIKE " . $db->quote($search_pattern, 'text') . "
            )
            AND u.usr_id NOT IN (SELECT user_id FROM extendedtest_users)
            AND u.usr_id > 13
            ORDER BY u.lastname, u.firstname
            LIMIT 20
        ";

            $result = $db->query($sql);
            $users = [];

            while ($row = $db->fetchAssoc($result)) {
                $users[] = [
                    'user_id' => (int)$row['user_id'],
                    'login' => $row['login'],
                    'firstname' => $row['firstname'],
                    'lastname' => $row['lastname'],
                    'fullname' => trim($row['firstname'] . ' ' . $row['lastname'])
                ];
            }

            echo json_encode(['users' => $users]);
        } catch (Exception $e) {
            error_log("[ExtendedTestSettings] Fehler bei User-Suche: " . $e->getMessage());
            echo json_encode(['users' => [], 'error' => $e->getMessage()]);
        }

        exit;
    }

    /**
     * AJAX: Benutzer hinzufügen
     */
    public function addUser(): void
    {
        global $DIC;
        $db = $DIC->database();

        header('Content-Type: application/json');

        $user_id = (int)($_GET['user_id'] ?? 0);

        if ($user_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Ungültige Benutzer-ID']);
            exit;
        }

        try {
            $current_user_id = $DIC->user()->getId();

            // Prüfen ob User bereits existiert
            $check = $db->queryF(
                "SELECT user_id FROM extendedtest_users WHERE user_id = %s",
                ['integer'],
                [$user_id]
            );

            if ($db->numRows($check) > 0) {
                echo json_encode(['success' => false, 'message' => 'Benutzer bereits berechtigt']);
                exit;
            }

            // Benutzer hinzufügen
            $db->insert('extendedtest_users', [
                'user_id' => ['integer', $user_id],
                'added_by' => ['integer', $current_user_id],
                'added_at' => ['timestamp', date('Y-m-d H:i:s')]
            ]);

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log("[ExtendedTestSettings] Fehler beim Hinzufügen: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }

        exit;
    }

    /**
     * Entfernt einen Benutzer aus der Berechtigungsliste
     */
    /**
     * Entfernt einen Benutzer aus der Berechtigungsliste
     */
    public function removeUser(): void
    {
        global $DIC;
        $db = $DIC->database();

        // user_id kann per POST (Formular) oder GET (Interruptive Modal URL) kommen
        $user_id = (int) ($_POST['user_id'] ?? $_GET['user_id'] ?? 0);

        if ($user_id <= 0) {
            $this->tpl->setOnScreenMessage('failure', 'Ungültige Benutzer-ID');
            $this->ctrl->redirect($this, 'showGeneral');
            return;
        }

        try {
            // Benutzer-Name für Message holen
            $result = $db->queryF(
                "SELECT u.login, u.firstname, u.lastname 
             FROM extendedtest_users eu
             JOIN usr_data u ON eu.user_id = u.usr_id
             WHERE eu.user_id = %s",
                ['integer'],
                [$user_id]
            );

            $user_name = '';
            if ($row = $db->fetchAssoc($result)) {
                $user_name = trim($row['firstname'] . ' ' . $row['lastname']) . ' (@' . $row['login'] . ')';
            }

            // Löschen
            $db->manipulateF(
                "DELETE FROM extendedtest_users WHERE user_id = %s",
                ['integer'],
                [$user_id]
            );

            $this->tpl->setOnScreenMessage(
                'success',
                "Benutzer $user_name wurde entfernt."
            );
        } catch (Exception $e) {
            error_log("[ExtendedTestSettings] Fehler beim Entfernen: " . $e->getMessage());
            $this->tpl->setOnScreenMessage(
                'failure',
                'Fehler beim Entfernen: ' . $e->getMessage()
            );
        }

        $this->ctrl->redirect($this, 'showGeneral');
    }



    /**
     * Speichert die allgemeinen Einstellungen (erweitert)
     */
    protected function saveGeneralSettings(): void
    {
        try {
            // Tab-Sichtbarkeit
            $tab_visibility = $_POST['tab_visibility'] ?? 'course_admins';
            if (!in_array($tab_visibility, ['admins_only', 'course_admins', 'specific_users'])) {
                $tab_visibility = 'course_admins';
            }
            $this->settings->set('tab_visibility', $tab_visibility);

            // ExamAlert Auto-Aktivierung
            $examalert_auto = ($_POST['examalert_auto_activate'] ?? '1') === '0' ? '0' : '1';
            $this->settings->set('examalert_auto_activate', $examalert_auto);

            $this->tpl->setOnScreenMessage('success', 'Einstellungen gespeichert.');
        } catch (Exception $e) {
            $this->tpl->setOnScreenMessage(
                'failure',
                '❌ Fehler beim Speichern: ' . $e->getMessage()
            );
        }

        $this->showGeneral();
    }

    /**
     * Tab 2: Statistiken  
     */
    protected function showStatistics(): void
    {
        $this->tabs->activateTab('statistics');
        $this->tpl->setContent($this->renderStatisticsTab());
    }

    private function renderStatisticsTab(): string
    {
        global $DIC;
        $factory  = $DIC->ui()->factory();
        $renderer = $DIC->ui()->renderer();

        $stats         = $this->getPluginStatistics();
        $module_status = $this->getModuleStatus();

        // Statistiken als CharacteristicValue-Listing
        $stats_items = [
            '🧮 Taschenrechner aktiv'          => ($stats['examcalc_enabled_tests'] ?? 0)   . ' Tests',
            '📝 Sonderzeichen aktiv'            => ($stats['examsymbols_enabled_tests'] ?? 0) . ' Tests',
            '🔒 Prüfungsschutz aktiv'           => ($stats['examguard_enabled_tests'] ?? 0)  . ' Tests',
            '⏰ Zeit-Warnung aktiv'             => ($stats['examalert_enabled_tests'] ?? 0)  . ' Tests',

        ];
        $stats_panel = $factory->panel()->standard(
            'Plugin-Statistiken',
            $factory->listing()->characteristicValue()->text($stats_items)
        );

        // Module als Report-Panel — Anzahl aktiver Tests direkt im Sub-Panel-Titel sichtbar
        $sub_panels = [];
        foreach ($module_status as $module => $data) {
            if ($data['exists']) {
                $detailed_stats = $this->getDetailedModuleStats($module);
                $count          = count($detailed_stats);
                $status_label   = $count > 0
                    ? $count . ($count === 1 ? ' Test aktiv' : ' Tests aktiv')
                    : 'Kein Test aktiv';
                $content = $this->buildModuleDetailsContent($module, $detailed_stats, $factory);
            } else {
                $status_label = 'Nicht gefunden';
                $content      = [$factory->messageBox()->info('Modul-Datei nicht gefunden.')];
            }
            $title        = ($data['icon'] ?? '') . ' ' . $data['title'] . '  —  ' . $status_label;
            $sub_panels[] = $factory->panel()->sub($title, $content);
        }
        $modules_panel = $factory->panel()->report('Modul-Status', $sub_panels);

        // Wartung / Bereinigung
        $cleanup_url = $this->ctrl->getLinkTarget($this, 'cleanupOrphanedEntries');
        $preview_url = $this->ctrl->getLinkTarget($this, 'previewCleanup');
        $cleanup_panel = $factory->panel()->standard(
            'Wartung',
            [
                $factory->messageBox()->info(
                    'Entfernt Einstellungen von Tests, die in ILIAS gelöscht wurden.'
                ),
                $factory->button()->standard('Vorschau anzeigen', $preview_url),
                $factory->button()->standard('Jetzt bereinigen', $cleanup_url),
            ]
        );

        return $this->renderPluginHeader()
            . $renderer->render([$stats_panel, $modules_panel, $cleanup_panel]);
    }

    /**
     * Baut den Inhalt eines Modul-Sub-Panels (Detailliste der aktiven Tests)
     * @param mixed $factory
     */
    private function buildModuleDetailsContent(string $module, array $tests, $factory): array
    {
        $module_names = [
            'examcalc'    => 'Taschenrechner',
            'examsymbols' => 'Sonderzeichen',
            'examguard'   => 'Prüfungsschutz',
            'examalert'   => 'Zeit-Warnung',
        ];
        $module_name = $module_names[$module] ?? ucfirst($module);
        $count       = count($tests);

        if ($count === 0) {
            return [$factory->messageBox()->info('Keine aktiven Tests für dieses Modul gefunden.')];
        }

        $items = [];
        foreach ($tests as $test) {
            $test_url = 'ilias.php?ref_id=' . (int) $test['ref_id'] . '&baseClass=ilRepositoryGUI';
            $link     = $factory->link()->standard(htmlspecialchars($test['title']), $test_url);

            $items[] = $factory->item()->standard($link)
                ->withDescription(htmlspecialchars($test['owner_name']))
                ->withProperties([
                    'Erstellt' => htmlspecialchars($test['created']),
                    'Test-ID'  => (string) (int) $test['test_id'],
                ]);
        }

        return [
            $factory->item()->group(
                $count . ($count === 1 ? ' Test verwendet ' : ' Tests verwenden ') . $module_name,
                $items
            ),
        ];
    }



    /**
     * ILIAS 9 kompatible Plugin-Statistiken
     */
    private function getPluginStatistics(): array
    {
        global $DIC;
        $db = $DIC->database();

        $stats = [
            'examcalc_enabled_tests'    => 0,
            'examsymbols_enabled_tests'  => 0,
            'examguard_enabled_tests'    => 0,
            'examalert_enabled_tests'    => 0,

        ];

        try {
            // ExamCalc - nur aktive Tests
            if ($db->tableExists('examcalc_settings')) {
                $result = $db->query("
                    SELECT COUNT(*) as cnt 
                    FROM examcalc_settings s
                    JOIN object_data od ON s.test_id = od.obj_id
                    JOIN object_reference ref ON od.obj_id = ref.obj_id
                    WHERE s.enabled = 1 AND od.type = 'tst' AND ref.deleted IS NULL
                ");
                if ($row = $db->fetchAssoc($result)) {
                    $stats['examcalc_enabled_tests'] = (int) $row['cnt'];
                }
            }

            // ExamSymbols - nur aktive Tests
            if ($db->tableExists('examsymbols_settings')) {
                $result = $db->query("
                    SELECT COUNT(*) as cnt 
                    FROM examsymbols_settings s
                    JOIN object_data od ON s.test_id = od.obj_id
                    JOIN object_reference ref ON od.obj_id = ref.obj_id
                    WHERE s.enabled = 1 AND od.type = 'tst' AND ref.deleted IS NULL
                ");
                if ($row = $db->fetchAssoc($result)) {
                    $stats['examsymbols_enabled_tests'] = (int) $row['cnt'];
                }
            }

            // ExamGuard - nur aktive Tests
            if ($db->tableExists('examguard_settings')) {
                $result = $db->query("
                    SELECT COUNT(*) as cnt 
                    FROM examguard_settings s
                    JOIN object_data od ON s.test_id = od.obj_id
                    JOIN object_reference ref ON od.obj_id = ref.obj_id
                    WHERE s.enabled = 1 AND od.type = 'tst' AND ref.deleted IS NULL
                ");
                if ($row = $db->fetchAssoc($result)) {
                    $stats['examguard_enabled_tests'] = (int) $row['cnt'];
                }
            }

            // ExamAlert - nur aktive Tests
            if ($db->tableExists('examalert_settings')) {
                $result = $db->query("
                    SELECT COUNT(*) as cnt 
                    FROM examalert_settings s
                    JOIN object_data od ON s.test_id = od.obj_id
                    JOIN object_reference ref ON od.obj_id = ref.obj_id
                    WHERE s.enabled = 1 AND od.type = 'tst' AND ref.deleted IS NULL
                ");
                if ($row = $db->fetchAssoc($result)) {
                    $stats['examalert_enabled_tests'] = (int) $row['cnt'];
                }
            }

        } catch (Exception $e) {
            error_log("[ExtendedTestSettings] Fehler bei Statistik-Abfrage: " . $e->getMessage());
        }

        return $stats;
    }





    /**
     * Lädt detaillierte Statistiken für ein Modul
     */
    private function getDetailedModuleStats(string $module): array
    {
        global $DIC;
        $db = $DIC->database();

        $table_map = [
            'examcalc' => 'examcalc_settings',
            'examsymbols' => 'examsymbols_settings',
            'examguard' => 'examguard_settings',
            'examalert' => 'examalert_settings',
            'examchecker' => null // ExamChecker hat keine Settings-Tabelle
        ];

        $table = $table_map[$module] ?? null;
        if (!$table || !$db->tableExists($table)) {
            return [];
        }

        try {
            // Query für Test-Details mit Eigentümer-Info
            $query = "
                SELECT 
                    s.test_id,
                    od.title as test_title,
                    ref.ref_id,
                    usr.login as owner_login,
                    usr.firstname,
                    usr.lastname,
                    od.create_date,
                    od.last_update
                FROM $table s
                JOIN object_data od ON s.test_id = od.obj_id
                JOIN object_reference ref ON od.obj_id = ref.obj_id AND ref.deleted IS NULL
                LEFT JOIN usr_data usr ON od.owner = usr.usr_id
                WHERE s.enabled = 1
                ORDER BY od.title ASC
            ";

            $result = $db->query($query);
            $tests = [];

            while ($row = $db->fetchAssoc($result)) {
                $owner_name = trim($row['firstname'] . ' ' . $row['lastname']);
                if (empty($owner_name)) {
                    $owner_name = $row['owner_login'] ?? 'Unbekannt';
                }

                $tests[] = [
                    'test_id' => $row['test_id'],
                    'ref_id' => $row['ref_id'],
                    'title' => $row['test_title'],
                    'owner_login' => $row['owner_login'],
                    'owner_name' => $owner_name,
                    'created' => $this->formatDate($row['create_date']),
                    'updated' => $this->formatDateTime($row['last_update'])
                ];
            }

            return $tests;
        } catch (Exception $e) {
            error_log("[ExtendedTestSettings] Fehler bei Modul-Details für $module: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Formatiert ein Datum sicher
     */
    private function formatDate($date_value): string
    {
        if (empty($date_value)) {
            return '';
        }

        // Wenn es bereits ein Unix-Timestamp (Integer) ist
        if (is_numeric($date_value) && (int)$date_value == $date_value) {
            return date('d.m.Y', (int)$date_value);
        }

        // Wenn es ein Datums-String ist (DATETIME, DATE etc.)
        try {
            $timestamp = strtotime($date_value);
            if ($timestamp !== false) {
                return date('d.m.Y', $timestamp);
            }
        } catch (Exception $e) {
            error_log("[ExtendedTestSettings] Fehler beim Formatieren des Datums: " . $e->getMessage());
        }

        return '';
    }

    /**
     * Formatiert ein Datum+Zeit sicher
     */
    private function formatDateTime($datetime_value): string
    {
        if (empty($datetime_value)) {
            return '';
        }

        // Wenn es bereits ein Unix-Timestamp (Integer) ist
        if (is_numeric($datetime_value) && (int)$datetime_value == $datetime_value) {
            return date('d.m.Y H:i', (int)$datetime_value);
        }

        // Wenn es ein Datums-String ist (DATETIME, DATE etc.)
        try {
            $timestamp = strtotime($datetime_value);
            if ($timestamp !== false) {
                return date('d.m.Y H:i', $timestamp);
            }
        } catch (Exception $e) {
            error_log("[ExtendedTestSettings] Fehler beim Formatieren der DateTime: " . $e->getMessage());
        }

        return '';
    }

    /**
     * Rendert die Detail-Ansicht für ein Modul
     */




    private function getModuleStatus(): array
    {
        $modules = [
            'examcalc' => [
                'title' => 'Wissenschaftlicher Taschenrechner',
                'icon' => '🧮',
                'class' => 'ilExamCalcHandler'
            ],
            'examsymbols' => [
                'title' => 'Sonderzeichen-Palette',
                'icon' => '📝',
                'class' => 'ilExamSymbolsHandler'
            ],
            'examguard' => [
                'title' => 'Prüfungsschutz',
                'icon' => '🛡️',
                'class' => 'ilExamGuardHandler'
            ],
            'examalert' => [
                'title' => 'Zeit-Warnung',
                'icon' => '⏰',
                'class' => 'ilExamAlertHandler'
            ],
            'examchecker' => [
                'title' => 'Prüfungs-Checker',
                'icon' => '🔍',
                'class' => 'ilExamCheckerHandler'
            ]
        ];

        $modules_dir = "./Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/ExtendedTestSettings/modules";

        foreach ($modules as $key => &$module) {
            $handler_file = "$modules_dir/$key/class.{$module['class']}.php";
            $module['exists'] = file_exists($handler_file);
        }

        return $modules;
    }

    /**
     * Tab 3: ExamChecker
     */
    protected function showExamChecker(): void
    {
        $this->tabs->activateTab('examchecker');
        $this->tpl->setContent($this->renderExamCheckerTab());
    }

    private function renderExamCheckerTab(): string
    {
        $form = $this->buildExamCheckerForm();
        return $this->renderPluginHeader() . $form->getHTML();
    }

    private function buildExamCheckerForm(): ilPropertyFormGUI
    {
        $form = new ilPropertyFormGUI();
        $form->setFormAction($this->ctrl->getLinkTarget($this, 'saveExamChecker'));
        $form->setTitle('ExamChecker-Vorgaben konfigurieren');
        $form->setDescription(
            'Bestimmen Sie, welche Einstellungen bei der Prüfung von Tests als verpflichtend gelten sollen.'
        );

        $praesenz_settings = $this->getExamCheckerSettings('praesenz');
        $fern_settings     = $this->getExamCheckerSettings('fern');

        $section_p = new ilFormSectionHeaderGUI();
        $section_p->setTitle('Präsenzprüfungen');
        $form->addItem($section_p);

        $custom_p = new ilCustomInputGUI('Verpflichtende Checks für Prüfungen vor Ort', '');
        $custom_p->setHtml($this->renderCheckboxGroup('praesenz', $praesenz_settings));
        $form->addItem($custom_p);

        $section_f = new ilFormSectionHeaderGUI();
        $section_f->setTitle('Fernprüfungen');
        $form->addItem($section_f);

        $custom_f = new ilCustomInputGUI('Verpflichtende Checks für Online-Prüfungen', '');
        $custom_f->setHtml($this->renderCheckboxGroup('fern', $fern_settings));
        $form->addItem($custom_f);

        $form->addCommandButton('saveExamChecker', 'Vorgaben speichern');

        return $form;
    }

    private function renderCheckboxGroup(string $exam_type, array $current_settings): string
    {
        $checkboxes      = '';
        $check_definitions = $this->getCheckDefinitions();

        foreach ($check_definitions as $key => $definition) {
            if (!in_array($exam_type, $definition['relevant_for'])) {
                continue;
            }

            $checked  = in_array($key, $current_settings) ? 'checked' : '';
            $name     = 'checks_' . $exam_type . '[]';
            $title    = htmlspecialchars($definition['title']);
            $desc     = htmlspecialchars($definition['description']);
            $input_id = 'check_' . $exam_type . '_' . $key;

            $checkboxes .= '<div class="mb-2">'
                . '<div class="form-check">'
                . '<input class="form-check-input" type="checkbox" name="' . $name . '"'
                . ' value="' . $key . '" id="' . $input_id . '" ' . $checked . '>'
                . '<label class="form-check-label" for="' . $input_id . '">'
                . '<span class="fw-semibold">' . $title . '</span>'
                . '<div class="form-text">' . $desc . '</div>'
                . '</label></div></div>';
        }

        return $checkboxes ?: '<p class="text-muted fst-italic">Keine relevanten Checks für diesen Prüfungstyp.</p>';
    }

    private function getCheckDefinitions(): array
    {
        return [
            'fragenpools_verwenden' => [
                'title' => 'Fragenpools verwenden',
                'description' => 'Test muss Fragenpools nutzen',
                'relevant_for' => ['praesenz', 'fern']
            ],
            'datenschutz' => [
                'title' => 'Datenschutz (Testergebnisse mit Namen)',
                'description' => 'Anonymität muss deaktiviert sein',
                'relevant_for' => ['praesenz', 'fern']
            ],
            'online' => [
                'title' => 'Test ist online geschaltet',
                'description' => 'Test muss online verfügbar sein',
                'relevant_for' => ['praesenz', 'fern']
            ],
            'passwort' => [
                'title' => 'Testpasswort gesetzt',
                'description' => 'Ein Passwort muss konfiguriert sein',
                'relevant_for' => ['praesenz', 'fern']
            ],
            'nur_ausgewaehlte_teilnehmer' => [
                'title' => 'Nur ausgewählte Teilnehmer (deaktiviert)',
                'description' => 'Offener Zugang für alle Teilnehmer',
                'relevant_for' => ['praesenz', 'fern']
            ],
            'testdurchlaeufe_begrenzen' => [
                'title' => 'Testdurchläufe auf 1 begrenzt',
                'description' => 'Anzahl der Versuche muss 1 sein',
                'relevant_for' => ['praesenz', 'fern']
            ],
            'wartezeit_erzwingen' => [
                'title' => 'Wartezeit zwischen Durchläufen (deaktiviert)',
                'description' => 'Keine Wartezeit zwischen Versuchen',
                'relevant_for' => ['praesenz', 'fern']
            ],
            'pruefungsansicht' => [
                'title' => 'Prüfungsansicht (Kiosk-Modus)',
                'description' => 'Kiosk-Modus muss aktiviert sein',
                'relevant_for' => ['praesenz', 'fern']
            ],
            'automatisches_speichern' => [
                'title' => 'Automatisches Speichern (30s)',
                'description' => 'Auto-Save alle 30 Sekunden',
                'relevant_for' => ['praesenz', 'fern']
            ],
            'direkte_rueckmeldung' => [
                'title' => 'Direkte Rückmeldung (deaktiviert)',
                'description' => 'Sofortige Antwortbewertung ausgeschaltet',
                'relevant_for' => ['praesenz', 'fern']
            ],
            'fragen_markieren' => [
                'title' => 'Fragen markieren aktiviert',
                'description' => 'Teilnehmer können Fragen markieren',
                'relevant_for' => ['praesenz', 'fern']
            ],
            'eigenstaendigkeitserklaerung' => [
                'title' => 'Eigenständigkeitserklärung',
                'description' => 'Nur für Fernprüfungen erforderlich',
                'relevant_for' => ['fern']
            ],
            'bearbeitungsdauer' => [
                'title' => 'Bearbeitungsdauer begrenzen',
                'description' => 'Zeitlimit muss aktiviert sein',
                'relevant_for' => ['praesenz', 'fern']
            ],
            'weiterleitung' => [
                'title' => 'Weiterleitung zu logout.php',
                'description' => 'Nur bei Präsenzprüfungen mit Kiosk',
                'relevant_for' => ['praesenz']
            ],
            'bewertungssystem' => [
                'title' => 'Teilweise richtige Antworten bewerten',
                'description' => 'Teilpunkte für unvollständige Antworten',
                'relevant_for' => ['praesenz', 'fern']
            ],
            'negative_punkte' => [
                'title' => 'Negative Punkte (deaktiviert)',
                'description' => 'Keine Punktabzüge für falsche Antworten',
                'relevant_for' => ['praesenz', 'fern']
            ],
            'besten_durchlauf_bewerten' => [
                'title' => 'Besten Testdurchlauf bewerten',
                'description' => 'Beste Leistung zählt',
                'relevant_for' => ['praesenz', 'fern']
            ],
            'keine_vorbedingungen' => [
                'title' => 'Keine Vorbedingungen gesetzt',
                'description' => 'Test ist ohne Vorbedingungen frei zugänglich',
                'relevant_for' => ['praesenz', 'fern']
            ],
            'direkte_notenuebermittlung' => [
                'title' => 'Direkte Notenübermittlung (deaktiviert)',
                'description' => 'Noten werden nicht sofort an Teilnehmer übermittelt',
                'relevant_for' => ['praesenz', 'fern']
            ],
            'zeitlich_begrenzte_verfuegbarkeit' => [
                'title' => 'Zeitlich begrenzte Verfügbarkeit (deaktiviert)',
                'description' => 'Test hat keine zusätzliche Kurs-Verfügbarkeitsbegrenzung',
                'relevant_for' => ['praesenz', 'fern']
            ]
        ];
    }

    private function getExamCheckerSettings(string $exam_type): array
    {
        $settings_string = $this->settings->get("examchecker_standards_$exam_type", '');
        return empty($settings_string) ? $this->getDefaultSettings($exam_type) : explode(',', $settings_string);
    }

    private function getDefaultSettings(string $exam_type): array
    {
        // Standard-Aktivierungen basierend auf deinem ExamChecker-Code
        $defaults = [
            'fragenpools_verwenden',
            'datenschutz',
            'online',
            'passwort',
            'nur_ausgewaehlte_teilnehmer',
            'testdurchlaeufe_begrenzen',
            'wartezeit_erzwingen',
            'pruefungsansicht',
            'automatisches_speichern',
            'direkte_rueckmeldung',
            'fragen_markieren',
            'bewertungssystem',
            'negative_punkte',
            'besten_durchlauf_bewerten'
        ];

        if ($exam_type === 'fern') {
            $defaults[] = 'eigenstaendigkeitserklaerung';
            $defaults[] = 'bearbeitungsdauer';
        }

        if ($exam_type === 'praesenz') {
            $defaults[] = 'weiterleitung';
        }

        return $defaults;
    }

    /**
     * ExamChecker Settings speichern
     */
    protected function saveExamChecker(): void
    {
        try {
            $post = $_POST;
            $valid_keys = array_keys($this->getCheckDefinitions());

            // Präsenz-Einstellungen (nur bekannte Check-Keys erlauben)
            $praesenz_checks = array_intersect($post['checks_praesenz'] ?? [], $valid_keys);
            $this->settings->set('examchecker_standards_praesenz', implode(',', $praesenz_checks));

            // Fernprüfungs-Einstellungen (nur bekannte Check-Keys erlauben)
            $fern_checks = array_intersect($post['checks_fern'] ?? [], $valid_keys);
            $this->settings->set('examchecker_standards_fern', implode(',', $fern_checks));

            $this->tpl->setOnScreenMessage('success', '✅ ExamChecker-Vorgaben erfolgreich gespeichert.');
        } catch (Exception $e) {
            $this->tpl->setOnScreenMessage('failure', '❌ Fehler beim Speichern: ' . $e->getMessage());
        }

        $this->showExamChecker();
    }

    /**
     * Vorschau der zu bereinigenden Einträge
     */
    protected function previewCleanup(): void
    {
        $this->tabs->activateTab('statistics');

        try {
            $orphaned_entries = $this->getOrphanedEntries();
            $preview_html = $this->renderCleanupPreview($orphaned_entries);
            $this->tpl->setContent($preview_html);
        } catch (Exception $e) {
            $this->tpl->setOnScreenMessage('failure', '❌ Fehler bei der Vorschau: ' . $e->getMessage());
            $this->showStatistics();
        }
    }

    /**
     * Findet verwaiste Einträge (ohne zu löschen)
     */
    private function getOrphanedEntries(): array
    {
        global $DIC;
        $db = $DIC->database();
        $orphaned = [];

        $tables = ['examcalc_settings', 'examsymbols_settings', 'examguard_settings', 'examalert_settings'];

        foreach ($tables as $table) {
            if (!$db->tableExists($table)) continue;

            try {
                // Finde verwaiste Einträge mit Details
                $query = "
                    SELECT s.test_id, 
                           COALESCE(od.title, 'Test gelöscht') as test_title,
                           CASE 
                               WHEN od.obj_id IS NULL THEN 'Nicht gefunden'
                               WHEN ref.deleted IS NOT NULL THEN 'Gelöscht'
                               ELSE 'Aktiv'
                           END as status
                    FROM $table s
                    LEFT JOIN object_data od ON s.test_id = od.obj_id AND od.type = 'tst'
                    LEFT JOIN object_reference ref ON od.obj_id = ref.obj_id AND ref.deleted IS NULL
                    WHERE ref.obj_id IS NULL OR od.obj_id IS NULL
                ";

                $result = $db->query($query);
                $entries = [];

                while ($row = $db->fetchAssoc($result)) {
                    $entries[] = [
                        'test_id' => $row['test_id'],
                        'title' => $row['test_title'],
                        'status' => $row['status']
                    ];
                }

                if (!empty($entries)) {
                    $orphaned[$table] = $entries;
                }
            } catch (Exception $e) {
                error_log("[ExtendedTestSettings] Fehler bei Vorschau für $table: " . $e->getMessage());
            }
        }

        return $orphaned;
    }

    /**
     * Rendert die Bereinigungsvorschau
     */
    private function renderCleanupPreview(array $orphaned_entries): string
    {
        global $DIC;
        $factory  = $DIC->ui()->factory();
        $renderer = $DIC->ui()->renderer();

        $back_url    = $this->ctrl->getLinkTarget($this, 'showStatistics');
        $cleanup_url = $this->ctrl->getLinkTarget($this, 'cleanupOrphanedEntries');
        $back_button = $factory->button()->standard('Zurück zu Statistiken', $back_url);

        if (empty($orphaned_entries)) {
            return $renderer->render([
                $back_button,
                $factory->messageBox()->success('Keine verwaisten Einträge – alles in Ordnung.'),
            ]);
        }

        $total_count = array_sum(array_map('count', $orphaned_entries));
        $table_names = [
            'examcalc_settings'    => 'Taschenrechner',
            'examsymbols_settings' => 'Sonderzeichen',
            'examguard_settings'   => 'Prüfungsschutz',
            'examalert_settings'   => 'Zeit-Warnung',
        ];

        $sub_panels = [];
        foreach ($orphaned_entries as $table => $entries) {
            $table_name = $table_names[$table] ?? $table;
            $count      = count($entries);

            $items = [];
            foreach ($entries as $entry) {
                $key         = htmlspecialchars($entry['title']) . '  (ID ' . (int) $entry['test_id'] . ')';
                $items[$key] = htmlspecialchars($entry['status']);
            }

            $sub_panels[] = $factory->panel()->sub(
                $table_name . ' (' . $count . ' Einträge)',
                $factory->listing()->descriptive($items)
            );
        }

        $confirm_modal = $factory->modal()->interruptive(
            'Bereinigung bestätigen',
            $total_count . ' verwaiste Einträge werden unwiderruflich gelöscht. Fortfahren?',
            $cleanup_url
        );
        $confirm_button = $factory->button()->standard(
            $total_count . ' Einträge jetzt löschen',
            $confirm_modal->getShowSignal()
        );

        return $renderer->render([
            $back_button,
            $factory->messageBox()->confirmation(
                $total_count . ' verwaiste Einträge gefunden. Bereinigung empfohlen.'
            ),
            $factory->panel()->report('Details', $sub_panels),
            $confirm_button,
            $confirm_modal,
        ]);
    }

    /**
     * Verwaiste Einträge bereinigen (ILIAS 9 kompatibel)
     */
    protected function cleanupOrphanedEntries(): void
    {
        try {
            // Erst Vorschau sammeln für Meldung
            $orphaned_before = $this->getOrphanedEntries();
            $total_before = array_sum(array_map('count', $orphaned_before));

            // Bereinigung durchführen
            $cleaned = $this->performCleanup();

            if ($cleaned > 0) {
                $this->tpl->setOnScreenMessage('success', "✅ $cleaned verwaiste Einträge wurden erfolgreich bereinigt.");
            } else {
                $this->tpl->setOnScreenMessage('info', "ℹ️ Keine verwaisten Einträge gefunden - alles sauber!");
            }
        } catch (Exception $e) {
            $this->tpl->setOnScreenMessage('failure', '❌ Fehler bei der Bereinigung: ' . $e->getMessage());
        }

        $this->showStatistics();
    }

    /**
     * Führt die eigentliche Bereinigung durch
     */
    private function performCleanup(): int
    {
        global $DIC;
        $db = $DIC->database();
        $cleaned = 0;

        try {
            $tables = ['examcalc_settings', 'examsymbols_settings', 'examguard_settings', 'examalert_settings'];

            foreach ($tables as $table) {
                if (!$db->tableExists($table))
                    continue;

                // KORRIGIERT: Prüfe auf aktive Tests (nicht gelöschte)
                $query = "
                    DELETE FROM $table 
                    WHERE test_id NOT IN (
                        SELECT DISTINCT od.obj_id 
                        FROM object_data od
                        JOIN object_reference ref ON od.obj_id = ref.obj_id 
                        WHERE od.type = 'tst' AND ref.deleted IS NULL
                    )
                ";

                $affected = $db->manipulate($query);
                $cleaned += $affected;

                if ($affected > 0) {
                    error_log("[ExtendedTestSettings] $affected verwaiste Einträge aus $table entfernt");
                }
            }
        } catch (Exception $e) {
            error_log("[ExtendedTestSettings] Fehler bei Cleanup: " . $e->getMessage());
            throw $e;
        }

        return $cleaned;
    }

    // =========================================================================
    // Module-Verwaltung
    // =========================================================================

    private function getRegistry(): ilModuleRegistry
    {
        require_once __DIR__ . '/Module/ilModuleRegistry.php';
        return new ilModuleRegistry($this->getPluginObject()->getDirectory());
    }

    private function getInstaller(): ilModuleInstaller
    {
        require_once __DIR__ . '/Module/ilModuleInstaller.php';
        return new ilModuleInstaller($this->getPluginObject()->getDirectory());
    }

    /**
     * Tab: Module-Übersicht mit Upload-Formular
     */
    protected function showModules(): void
    {
        $this->tabs->activateTab('modules');
        $this->tpl->setContent($this->renderModulesTab());
    }

    private function renderModulesTab(): string
    {
        global $DIC;
        $factory  = $DIC->ui()->factory();
        $renderer = $DIC->ui()->renderer();
        $registry = $this->getRegistry();

        $html = $renderer->render(
            $factory->messageBox()->info(
                'Module erweitern das Plugin um zusätzliche Funktionen. '
                . 'Built-in Module können aktiviert/deaktiviert werden. '
                . 'Neue Module können als ZIP-Paket hochgeladen werden.'
            )
        );

        // ---- Repo-URL-Einstellung ----
        $html .= $this->renderRepoUrlForm();

        // ---- Modulliste (ggf. mit Update-Check) ----
        $modules    = $registry->getAllModuleInfos();
        $repo_url   = trim((string) $this->settings->get('module_repo_url', ''));
        $repo_branch = trim((string) $this->settings->get('module_repo_branch', 'ilias9'));
        $with_check = $repo_url !== '';

        if ($with_check) {
            require_once __DIR__ . '/Module/ilModuleUpdateChecker.php';
            $checker = new ilModuleUpdateChecker($repo_url, $repo_branch);
            $modules = $checker->checkAll($modules);
        }

        if (empty($modules)) {
            $html .= $renderer->render($factory->messageBox()->info('Keine Module gefunden.'));
        } else {
            $rows = '';
            foreach ($modules as $info) {
                $id      = htmlspecialchars($info['id'], ENT_QUOTES);
                $label   = htmlspecialchars($info['label'] ?? $info['id'], ENT_QUOTES);
                $version = htmlspecialchars($info['version'] ?? '–', ENT_QUOTES);
                $compat  = htmlspecialchars(($info['ilias_min'] ?? '?') . '–' . ($info['ilias_max'] ?? '?'), ENT_QUOTES);
                $builtin = $info['builtin'] ? '<span class="badge text-bg-secondary">Built-in</span>' : '<span class="badge text-bg-primary">Installiert</span>';
                $active  = $info['active'];

                // Version-Spalte mit Update-Badge
                $version_cell = $version;
                if (!($info['builtin'] ?? true)) {
                    if (!empty($info['update_available'])) {
                        $latest = htmlspecialchars($info['latest_version'], ENT_QUOTES);
                        $version_cell = "$version <span class='badge text-bg-warning'>Update: v$latest</span>";
                    } elseif (isset($info['latest_version'])) {
                        $version_cell = "$version <span class='badge text-bg-success'>Aktuell</span>";
                    } elseif (isset($info['update_error'])) {
                        $err = htmlspecialchars($info['update_error'], ENT_QUOTES);
                        $version_cell = "$version <span class='badge text-bg-secondary' title='$err'>?</span>";
                    }
                }

                $toggle_label = $active ? 'Deaktivieren' : 'Aktivieren';
                $toggle_class = $active ? 'btn btn-sm btn-warning' : 'btn btn-sm btn-success';
                $toggle_url   = $this->ctrl->getFormAction($this, 'toggleModule');
                $toggle_active_val = $active ? '0' : '1';
                $id_escaped = htmlspecialchars($id, ENT_QUOTES);

                $uninstall_btn = '';
                $update_btn    = '';
                if (!$info['builtin']) {
                    $uninstall_url = $this->ctrl->getFormAction($this, 'uninstallModule');
                    $uninstall_btn = "<form method=\"post\" action=\"$uninstall_url\" class=\"d-inline ms-1\" onsubmit=\"return confirm('Modul $label wirklich deinstallieren? Alle Daten werden gelöscht.')\"><input type=\"hidden\" name=\"module_id\" value=\"$id_escaped\"><button type=\"submit\" class=\"btn btn-sm btn-danger\">Deinstallieren</button></form>";

                    if (!empty($info['update_available']) && !empty($info['download_url'])) {
                        $update_url = $this->ctrl->getLinkTarget($this, 'updateModule')
                            . '&module_id=' . urlencode($id)
                            . '&download_url=' . urlencode($info['download_url'])
                            . '&latest_version=' . urlencode($info['latest_version']);
                        $update_btn = "<a href=\"$update_url\" class=\"btn btn-sm btn-primary ms-1\">⬆ Update auf v{$info['latest_version']}</a>";
                    }
                }

                $status_badge = $active
                    ? '<span class="badge text-bg-success">Aktiv</span>'
                    : '<span class="badge text-bg-danger">Inaktiv</span>';

                $desc = htmlspecialchars($info['description'] ?? '', ENT_QUOTES);

                $rows .= "
                <tr>
                    <td><strong>$label</strong><br><small class='text-muted'>$desc</small></td>
                    <td>$version_cell</td>
                    <td>ILIAS $compat</td>
                    <td>$builtin</td>
                    <td>$status_badge</td>
                    <td>
                        <form method=\"post\" action=\"$toggle_url\" class=\"d-inline\"><input type=\"hidden\" name=\"module_id\" value=\"$id_escaped\"><input type=\"hidden\" name=\"active\" value=\"$toggle_active_val\"><button type=\"submit\" class=\"$toggle_class\">$toggle_label</button></form>
                        $update_btn
                        $uninstall_btn
                    </td>
                </tr>";
            }

            $html .= "
            <table class='table table-bordered table-hover mt-3'>
                <thead class='table-light'>
                    <tr>
                        <th>Modul</th>
                        <th>Version</th>
                        <th>Kompatibilität</th>
                        <th>Typ</th>
                        <th>Status</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>$rows</tbody>
            </table>";
        }

        // ---- Upload-Formular ----
        // getLinkTarget() hängt rtoken automatisch an die URL — kein hidden field nötig
        $upload_url = $this->ctrl->getLinkTarget($this, 'uploadModule');

        $modules_dir  = $this->getPluginObject()->getDirectory() . '/modules';
        $writable     = is_writable($modules_dir);
        $writable_msg = $writable
            ? ''
            : "<div class='alert alert-danger mt-2'>⚠️ Das Verzeichnis <code>modules/</code> ist nicht schreibbar. Bitte auf dem Server <code>chown www-data: modules/</code> ausführen.</div>";

        $disabled = $writable ? '' : ' disabled';

        $html .= "
        <div class='card mt-4'>
            <div class='card-header'><strong>Neues Modul installieren</strong></div>
            <div class='card-body'>
                $writable_msg
                <p class='text-muted'>
                    Laden Sie ein Modul-ZIP-Paket hoch. Das Paket muss eine <code>module.json</code> enthalten.
                    Maximale Größe: 20 MB. Erlaubte Dateitypen: .php, .js, .css, .sql, .json, .html
                </p>
                <form method='post' action='$upload_url' enctype='multipart/form-data'>
                    <div class='mb-3'>
                        <input type='file' name='module_zip' class='form-control' accept='.zip' required>
                    </div>
                    <button type='submit' class='btn btn-primary'$disabled>Modul hochladen und installieren</button>
                </form>
            </div>
        </div>";

        return $html;
    }

    /**
     * Modul aktivieren / deaktivieren
     */
    protected function toggleModule(): void
    {
        $module_id = (string) ($_POST['module_id'] ?? '');
        $active    = (bool) (int) ($_POST['active'] ?? 0);

        if ($module_id === '') {
            $this->ctrl->redirect($this, 'showModules');
            return;
        }

        $registry = $this->getRegistry();

        if (!$registry->moduleExists($module_id)) {
            $this->tpl->setOnScreenMessage('failure', "Modul '$module_id' nicht gefunden.");
            $this->ctrl->redirect($this, 'showModules');
            return;
        }

        $registry->setModuleActive($module_id, $active);

        $msg = $active
            ? "Modul '$module_id' wurde aktiviert."
            : "Modul '$module_id' wurde deaktiviert. PHP lädt den Modul-Code nicht mehr.";

        $this->tpl->setOnScreenMessage('success', $msg);
        $this->ctrl->redirect($this, 'showModules');
    }

    /**
     * ZIP hochladen und installieren
     */
    protected function uploadModule(): void
    {
        $this->tabs->activateTab('modules');

        $file = $_FILES['module_zip'] ?? null;

        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $errors = [
                UPLOAD_ERR_INI_SIZE  => 'Datei überschreitet upload_max_filesize.',
                UPLOAD_ERR_FORM_SIZE => 'Datei überschreitet MAX_FILE_SIZE.',
                UPLOAD_ERR_NO_FILE   => 'Keine Datei ausgewählt.',
            ];
            $msg = $errors[$file['error'] ?? UPLOAD_ERR_NO_FILE] ?? 'Upload-Fehler.';
            $this->tpl->setOnScreenMessage('failure', $msg);
            $this->ctrl->redirect($this, 'showModules');
            return;
        }

        try {
            $installer  = $this->getInstaller();
            $module_info = $installer->installFromZip($file['tmp_name']);

            // Modul direkt aktivieren nach Installation
            $registry = $this->getRegistry();
            $registry->setModuleActive($module_info['id'], true);

            $this->tpl->setOnScreenMessage(
                'success',
                "Modul '{$module_info['label']}' (v{$module_info['version']}) erfolgreich installiert und aktiviert."
            );
        } catch (RuntimeException $e) {
            error_log('[ExtendedTestSettings] uploadModule Fehler: ' . $e->getMessage());
            $this->tpl->setOnScreenMessage('failure', 'Installation fehlgeschlagen: ' . $e->getMessage());
        } catch (Throwable $e) {
            error_log('[ExtendedTestSettings] uploadModule unerwarteter Fehler: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            $this->tpl->setOnScreenMessage('failure', 'Unerwarteter Fehler: ' . $e->getMessage());
        }

        $this->ctrl->redirect($this, 'showModules');
    }

    /**
     * Modul deinstallieren (nur nicht-built-in)
     */
    protected function uninstallModule(): void
    {
        $module_id = (string) ($_POST['module_id'] ?? '');

        if ($module_id === '') {
            $this->ctrl->redirect($this, 'showModules');
            return;
        }

        $registry = $this->getRegistry();
        $infos    = $registry->getAllModuleInfos();
        $info     = null;

        foreach ($infos as $m) {
            if ($m['id'] === $module_id) {
                $info = $m;
                break;
            }
        }

        if ($info === null) {
            $this->tpl->setOnScreenMessage('failure', "Modul '$module_id' nicht gefunden.");
            $this->ctrl->redirect($this, 'showModules');
            return;
        }

        if ($info['builtin']) {
            $this->tpl->setOnScreenMessage('failure', 'Built-in Module können nicht deinstalliert werden.');
            $this->ctrl->redirect($this, 'showModules');
            return;
        }

        try {
            $installer = $this->getInstaller();
            $installer->uninstallModule($module_id, $info);

            $this->tpl->setOnScreenMessage('success', "Modul '$module_id' wurde deinstalliert.");
        } catch (RuntimeException $e) {
            $this->tpl->setOnScreenMessage('failure', 'Deinstallation fehlgeschlagen: ' . $e->getMessage());
        }

        $this->ctrl->redirect($this, 'showModules');
    }

    /**
     * Rendert das Formular zur Konfiguration der Modul-Repository-URL.
     */
    private function renderRepoUrlForm(): string
    {
        $repo_url    = htmlspecialchars($this->settings->get('module_repo_url', ''), ENT_QUOTES);
        $repo_branch = htmlspecialchars($this->settings->get('module_repo_branch', 'ilias9'), ENT_QUOTES);
        $save_url    = $this->ctrl->getLinkTarget($this, 'saveModuleRepoUrl');

        return "
        <div class='card mt-3 mb-3'>
            <div class='card-header'><strong>Modul-Repository</strong></div>
            <div class='card-body'>
                <p class='text-muted mb-2'>
                    GitHub-Repository mit den Modul-ZIPs. Die ZIPs müssen der Namenskonvention
                    <code>ModulName_v1.0.0.zip</code> folgen.<br>
                    Beispiel: <code>https://github.com/andreschweigert/ExtendedTestSettings-Modules</code>
                </p>
                <form method='post' action='$save_url'>
                    <div class='d-flex gap-2 align-items-center mb-2'>
                        <label style='min-width:80px;'>Repo-URL</label>
                        <input type='text' name='module_repo_url' value='$repo_url'
                               class='form-control' placeholder='https://github.com/user/Repo' style='max-width:520px;'>
                    </div>
                    <div class='d-flex gap-2 align-items-center mb-2'>
                        <label style='min-width:80px;'>Branch</label>
                        <input type='text' name='module_repo_branch' value='$repo_branch'
                               class='form-control' placeholder='ilias9' style='max-width:200px;'>
                    </div>
                    <button type='submit' class='btn btn-secondary'>Speichern</button>
                </form>
            </div>
        </div>";
    }

    /**
     * Speichert die Modul-Repository-Einstellungen.
     */
    protected function saveModuleRepoUrl(): void
    {
        $url    = trim($_POST['module_repo_url'] ?? '');
        $branch = trim($_POST['module_repo_branch'] ?? 'ilias9');

        if ($url !== '' && !str_starts_with($url, 'https://github.com/')) {
            $this->tpl->setOnScreenMessage('failure', 'Bitte eine gültige GitHub-URL eingeben (https://github.com/...).');
            $this->ctrl->redirect($this, 'showModules');
            return;
        }

        $this->settings->set('module_repo_url', $url);
        $this->settings->set('module_repo_branch', $branch ?: 'ilias9');
        $this->tpl->setOnScreenMessage('success', 'Repository-Einstellungen gespeichert.');
        $this->ctrl->redirect($this, 'showModules');
    }

    /**
     * Zeigt eine Bestätigungsseite vor dem Update — mit Ladeanimation beim Absenden.
     */
    protected function updateModule(): void
    {
        $this->tabs->activateTab('modules');

        $module_id      = preg_replace('/[^a-z0-9_]/', '', strtolower($_GET['module_id'] ?? ''));
        $download_url   = $_GET['download_url'] ?? '';
        $latest_version = htmlspecialchars($_GET['latest_version'] ?? '', ENT_QUOTES);

        if ($module_id === '' || $download_url === '') {
            $this->ctrl->redirect($this, 'showModules');
            return;
        }

        if (!$this->isAllowedDownloadUrl($download_url)) {
            $this->tpl->setOnScreenMessage('failure', 'Ungültige Download-URL.');
            $this->ctrl->redirect($this, 'showModules');
            return;
        }

        $do_url     = $this->ctrl->getLinkTarget($this, 'doUpdateModule');
        $back_url   = $this->ctrl->getLinkTarget($this, 'showModules');
        $module_esc = htmlspecialchars($module_id, ENT_QUOTES);
        $dl_esc     = htmlspecialchars($download_url, ENT_QUOTES);

        $html = "
        <div class='card mt-3' style='max-width:600px;'>
            <div class='card-header'><strong>Modul-Update: $module_esc</strong></div>
            <div class='card-body'>
                <p>Das Modul wird auf <strong>v$latest_version</strong> aktualisiert:</p>
                <ol>
                    <li>ZIP herunterladen von GitHub</li>
                    <li>Bestehendes Modul ersetzen</li>
                    <li>Datenbank-Tabellen aktualisieren (falls nötig)</li>
                    <li>Modul aktiv lassen</li>
                </ol>
                <div id='ets-update-spinner' style='display:none;' class='alert alert-info'>
                    ⏳ Update wird durchgeführt — bitte warten…
                </div>
                <form method='post' action='$do_url' id='ets-update-form'>
                    <input type='hidden' name='module_id'    value='$module_esc'>
                    <input type='hidden' name='download_url' value='$dl_esc'>
                    <button type='submit' class='btn btn-primary' id='ets-update-btn'>
                        Update jetzt installieren
                    </button>
                    <a href='$back_url' class='btn btn-secondary ms-2'>Abbrechen</a>
                </form>
            </div>
        </div>
        <script>
        document.getElementById('ets-update-form').addEventListener('submit', function() {
            document.getElementById('ets-update-spinner').style.display = 'block';
            document.getElementById('ets-update-btn').disabled = true;
            document.getElementById('ets-update-btn').textContent = 'Bitte warten…';
        });
        </script>";

        $this->tpl->setContent($html);
    }

    /**
     * Führt das eigentliche Update durch (POST von der Bestätigungsseite).
     */
    protected function doUpdateModule(): void
    {
        $module_id    = preg_replace('/[^a-z0-9_]/', '', strtolower($_POST['module_id'] ?? ''));
        $download_url = $_POST['download_url'] ?? '';

        if ($module_id === '' || $download_url === '') {
            $this->ctrl->redirect($this, 'showModules');
            return;
        }

        if (!$this->isAllowedDownloadUrl($download_url)) {
            $this->tpl->setOnScreenMessage('failure', 'Ungültige Download-URL.');
            $this->ctrl->redirect($this, 'showModules');
            return;
        }

        $this->tabs->activateTab('modules');
        $back_url = $this->ctrl->getLinkTarget($this, 'showModules');

        $log   = [];
        $error = null;

        try {
            $log[] = '⬇️ ZIP wird heruntergeladen…';
            require_once __DIR__ . '/Module/ilModuleUpdateChecker.php';
            $repo_url = trim((string) $this->settings->get('module_repo_url', ''));
            $branch   = trim((string) $this->settings->get('module_repo_branch', 'ilias9'));
            $checker  = new ilModuleUpdateChecker($repo_url, $branch);
            $tmp_file = $checker->downloadZip($download_url);
            $log[]    = '✅ ZIP heruntergeladen (' . round(filesize($tmp_file) / 1024) . ' KB)';

            $log[] = '📦 Modul wird installiert…';
            $installer   = $this->getInstaller();
            $module_info = $installer->installFromZip($tmp_file);
            @unlink($tmp_file);
            $log[] = "✅ Modul '{$module_info['label']}' v{$module_info['version']} installiert";

            $log[] = '🔄 Modul wird aktiviert…';
            $registry = $this->getRegistry();
            $registry->setModuleActive($module_id, true);
            $log[] = '✅ Modul aktiv';

        } catch (RuntimeException $e) {
            $error = $e->getMessage();
            $log[] = '❌ Fehler: ' . htmlspecialchars($error);
        } catch (Throwable $e) {
            error_log('[ExtendedTestSettings] doUpdateModule Fehler: ' . $e->getMessage());
            $error = $e->getMessage();
            $log[] = '❌ Unerwarteter Fehler: ' . htmlspecialchars($error);
        }

        $log_html   = implode('<br>', array_map('htmlspecialchars', $log));
        // letzte Zeile schon escaped — rebuild ohne doppeltes escaping
        $log_items  = array_map(fn($l) => "<li>$l</li>", $log);
        $log_list   = implode('', $log_items);
        $status     = $error === null ? 'success' : 'danger';
        $status_txt = $error === null ? '✅ Update erfolgreich abgeschlossen' : '❌ Update fehlgeschlagen';

        $html = "
        <div class='card mt-3' style='max-width:600px;'>
            <div class='card-header'><strong>Update-Protokoll</strong></div>
            <div class='card-body'>
                <ul class='list-unstyled mb-3'>$log_list</ul>
                <div class='alert alert-$status'>$status_txt</div>
                <a href='$back_url' class='btn btn-primary'>Zurück zur Modulübersicht</a>
            </div>
        </div>";

        $this->tpl->setContent($html);
    }

    /**
     * Prüft ob eine Download-URL aus dem konfigurierten GitHub-Repo stammt.
     */
    private function isAllowedDownloadUrl(string $url): bool
    {
        if (!str_starts_with($url, 'https://')) {
            return false;
        }

        $repo_url = trim((string) $this->settings->get('module_repo_url', ''));
        if ($repo_url === '') {
            return false;
        }

        $allowed_host = parse_url($repo_url, PHP_URL_HOST);
        $actual_host  = parse_url($url, PHP_URL_HOST);

        if ($allowed_host === null || $actual_host !== $allowed_host) {
            return false;
        }

        $repo_path = rtrim(parse_url($repo_url, PHP_URL_PATH) ?? '', '/');
        $url_path  = parse_url($url, PHP_URL_PATH) ?? '';

        return $repo_path !== '' && str_starts_with($url_path, $repo_path . '/');
    }
}


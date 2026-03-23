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

            // Validierung
            if (!in_array($tab_visibility, ['admins_only', 'course_admins', 'specific_users'])) {
                $tab_visibility = 'course_admins';
            }

            $this->settings->set('tab_visibility', $tab_visibility);

            $visibility_text = [
                'admins_only'    => 'Nur System-Administratoren',
                'course_admins'  => 'Kurs-Administratoren + System-Administratoren',
                'specific_users' => 'Nur ausgewählte Benutzer + System-Administratoren'
            ];

            $this->tpl->setOnScreenMessage(
                'success',
                '✅ Einstellungen gespeichert.<br>' .
                    '• Tab-Sichtbarkeit: ' . $visibility_text[$tab_visibility]
            );
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
            '🧮 Taschenrechner aktiv'     => (string) ($stats['examcalc_enabled_tests'] ?? 0),
            '📝 Sonderzeichen aktiv'       => (string) ($stats['examsymbols_enabled_tests'] ?? 0),
            '🔒 Prüfungsschutz aktiv'      => (string) ($stats['examguard_enabled_tests'] ?? 0),
            '⏰ Zeit-Warnung aktiv'        => (string) ($stats['examalert_enabled_tests'] ?? 0),
            '📊 Tests gesamt konfiguriert' => (string) ($stats['total_configured_tests'] ?? 0),
        ];
        $stats_panel = $factory->panel()->standard(
            'Plugin-Statistiken',
            $factory->listing()->characteristicValue()->text($stats_items)
        );

        // Module als Report-Panel mit Sub-Panels
        $sub_panels = [];
        foreach ($module_status as $module => $data) {
            $status_label = $data['exists'] ? 'Verfügbar' : 'Nicht gefunden';
            $title        = ($data['icon'] ?? '') . ' ' . $data['title'] . '  —  ' . $status_label;

            if ($data['exists']) {
                $detailed_stats = $this->getDetailedModuleStats($module);
                $content        = $this->buildModuleDetailsContent($module, $detailed_stats, $factory);
            } else {
                $content = [$factory->messageBox()->info('Modul-Datei nicht gefunden.')];
            }
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
            $key         = htmlspecialchars($test['title']) . '  (ID ' . (int) $test['test_id'] . ')';
            $items[$key] = htmlspecialchars($test['owner_name']) . '  —  ' . htmlspecialchars($test['created']);
        }

        return [
            $factory->messageBox()->info($count . ' Tests verwenden ' . $module_name . '.'),
            $factory->listing()->descriptive($items),
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
            'examcalc_enabled_tests' => 0,
            'examsymbols_enabled_tests' => 0,
            'examguard_enabled_tests' => 0,
            'examalert_enabled_tests' => 0,
            'total_configured_tests' => 0
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

            // Totale konfigurierte Tests (mindestens ein Feature aktiviert) - nur aktive
            $union_queries = [];
            if ($db->tableExists('examcalc_settings')) {
                $union_queries[] = "
                    SELECT s.test_id 
                    FROM examcalc_settings s
                    JOIN object_data od ON s.test_id = od.obj_id
                    JOIN object_reference ref ON od.obj_id = ref.obj_id
                    WHERE s.enabled = 1 AND od.type = 'tst' AND ref.deleted IS NULL
                ";
            }
            if ($db->tableExists('examsymbols_settings')) {
                $union_queries[] = "
                    SELECT s.test_id 
                    FROM examsymbols_settings s
                    JOIN object_data od ON s.test_id = od.obj_id
                    JOIN object_reference ref ON od.obj_id = ref.obj_id
                    WHERE s.enabled = 1 AND od.type = 'tst' AND ref.deleted IS NULL
                ";
            }
            if ($db->tableExists('examguard_settings')) {
                $union_queries[] = "
                    SELECT s.test_id 
                    FROM examguard_settings s
                    JOIN object_data od ON s.test_id = od.obj_id
                    JOIN object_reference ref ON od.obj_id = ref.obj_id
                    WHERE s.enabled = 1 AND od.type = 'tst' AND ref.deleted IS NULL
                ";
            }
            if ($db->tableExists('examalert_settings')) {
                $union_queries[] = "
                    SELECT s.test_id 
                    FROM examalert_settings s
                    JOIN object_data od ON s.test_id = od.obj_id
                    JOIN object_reference ref ON od.obj_id = ref.obj_id
                    WHERE s.enabled = 1 AND od.type = 'tst' AND ref.deleted IS NULL
                ";
            }

            if (!empty($union_queries)) {
                $union_query = implode(" UNION ", $union_queries);
                $result = $db->query("SELECT COUNT(DISTINCT test_id) as cnt FROM ($union_query) as combined");
                if ($row = $db->fetchAssoc($result)) {
                    $stats['total_configured_tests'] = (int) $row['cnt'];
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

        $plugin_dir = "./Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/ExtendedTestSettings/classes";

        foreach ($modules as $key => &$module) {
            $subdir = ucfirst($key);
            if ($key === 'examchecker') $subdir = 'ExamChecker';
            if ($key === 'examcalc') $subdir = 'ExamCalc';
            if ($key === 'examsymbols') $subdir = 'ExamSymbols';
            if ($key === 'examguard') $subdir = 'ExamGuard';
            if ($key === 'examalert') $subdir = 'ExamAlert';

            $handler_file = "$plugin_dir/$subdir/class.{$module['class']}.php";
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

            // Präsenz-Einstellungen
            $praesenz_checks = $post['checks_praesenz'] ?? [];
            $this->settings->set('examchecker_standards_praesenz', implode(',', $praesenz_checks));

            // Fernprüfungs-Einstellungen  
            $fern_checks = $post['checks_fern'] ?? [];
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
}

<?php

/**
 * Plugin Configuration GUI for ExtendedTestSettings
 *
 * CSRF-Modell (siehe .dev/security/SICHERHEITSREGELN.md, R4):
 * ILIAS hängt einen CSRF-Token nur an URLs aus getFormAction() an (dort steht
 * cmd=post) und validiert auch nur dort. Ein <form method="post"> auf ein
 * getLinkTarget()-Ziel ist für ilCtrl KEIN POST — dort gäbe es weder Token noch
 * Prüfung. Deshalb gilt hier:
 *   - jede zustandsändernde Aktion wird mit getFormAction() erzeugt
 *   - die Handler erzwingen zusätzlich serverseitig POST (requirePost()),
 *     unabhängig von ilCtrl
 *   - keine $_GET-Fallbacks in schreibenden Handlern
 *
 * ilCtrlSecurityInterface deklariert die Commands zusätzlich explizit. Wirksam
 * wird die GET-Seite davon erst nach einem Setup-Lauf "build-artifacts"
 * (Services/UICore/artifacts/ctrl_security.php) — die Absicherung oben hängt
 * bewusst nicht davon ab.
 *
 * @ilCtrl_isCalledBy ilExtendedTestSettingsConfigGUI: ilObjComponentSettingsGUI
 */
class ilExtendedTestSettingsConfigGUI extends ilPluginConfigGUI implements ilCtrlSecurityInterface
{
    /**
     * Zustandsändernde Commands. Werden über getFormAction() erzeugt und von
     * requirePost() zusätzlich auf POST festgenagelt.
     */
    /** Session-Schlüssel für die serverseitig gemerkte Update-Erwartung (C-09). */
    private const SESSION_PENDING_UPDATE = 'ets_pending_update';

    private const WRITE_COMMANDS = [
        'saveGeneralSettings',
        'saveExamChecker',
        'addSingleUser',
        'removeUser',
        'cleanupOrphanedEntries',
        'toggleModule',
        'uploadModule',
        'uninstallModule',
        'doUpdateModule',
        'saveModuleRepoUrl',
        'importDevices',
        'saveKeymap',
        'checkModuleUpdates',
    ];
    /** Farbpunkte je Entwicklungsstand (Definition: modules-zip/docs/entwicklungsstand.md) */
    private const MATURITY_DOTS = [
        'konzept'       => '⚪',
        'experimentell' => '🟠',
        'entwicklung'   => '🟡',
        'test'          => '🔵',
        'pilot'         => '🟣',
        'erprobt'       => '🟢',
        'eingestellt'   => '🔴',
    ];

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
     * Löst eine Sprachvariable des Plugins auf (Kurzform für getPluginObject()->txt()).
     */
    private function txt(string $key): string
    {
        return $this->getPluginObject()->txt($key);
    }

    /**
     * @inheritDoc
     * Alle schreibenden Commands sind als GET unsicher.
     */
    public function getUnsafeGetCommands(): array
    {
        return self::WRITE_COMMANDS;
    }

    /**
     * @inheritDoc
     * Kein POST-Command darf ohne Token durchgehen.
     */
    public function getSafePostCommands(): array
    {
        return [];
    }

    /**
     * Erzwingt POST für zustandsändernde Commands — serverseitig und unabhängig
     * von ilCtrl bzw. vom Zustand des ctrl_security-Artefakts.
     *
     * @return bool true, wenn weitergearbeitet werden darf
     */
    private function requirePost(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            return true;
        }

        error_log('[ExtendedTestSettings] Schreibender Command ohne POST abgewiesen: '
            . ($_GET['cmd'] ?? $_GET['fallbackCmd'] ?? '?'));

        $this->tpl->setOnScreenMessage('failure', $this->txt('invalid_request_method'));
        $this->ctrl->redirect($this, 'showGeneral');

        return false;
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
                $this->txt('access_denied_login')
            );
            $DIC->ctrl()->redirectToURL('./login.php');
            return;
        }

        if (!$rbacsystem->checkAccess("write", SYSTEM_FOLDER_ID)) {
            $DIC->ui()->mainTemplate()->setOnScreenMessage(
                'failure',
                $this->txt('access_denied_admin')
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

            case 'checkModuleUpdates':
                $this->checkModuleUpdates();
                break;

            case 'debugNetworkCheck':
                $this->debugNetworkCheck();
                break;

            case 'showProtocol':
                $this->showProtocol();
                break;

            case 'exportProtocolCsv':
                $this->exportProtocolCsv();
                break;

            case 'showDevices':
                $this->showDevices();
                break;

            case 'importDevices':
                $this->importDevices();
                break;

            case 'showNetQos':
                $this->showNetQos();
                break;

            case 'exportNetQosCsv':
                $this->exportNetQosCsv();
                break;

            case 'showKeymap':
                $this->showKeymap();
                break;

            case 'saveKeymap':
                $this->saveKeymap();
                break;

            case 'showArchiver':
                $this->showArchiver();
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
            $this->txt('tab_general'),
            $this->ctrl->getLinkTarget($this, 'showGeneral')
        );

        $this->tabs->addTab(
            'modules',
            $this->txt('tab_modules'),
            $this->ctrl->getLinkTarget($this, 'showModules')
        );

        $this->tabs->addTab(
            'statistics',
            $this->txt('tab_statistics'),
            $this->ctrl->getLinkTarget($this, 'showStatistics')
        );

        // Modul-spezifische Tabs nur, wenn das jeweilige Modul global aktiv ist
        $registry = $this->getRegistry();

        if ($registry->isModuleActive('examchecker')) {
            $this->tabs->addTab(
                'examchecker',
                $this->txt('tab_examchecker'),
                $this->ctrl->getLinkTarget($this, 'showExamChecker')
            );
        }

        if ($registry->isModuleActive('examlogger')) {
            $this->tabs->addTab(
                'protocol',
                $this->txt('tab_protocol'),
                $this->ctrl->getLinkTarget($this, 'showProtocol')
            );
        }

        // Geräte-Inventar ist Plugin-Infrastruktur (etest_hosts), kein Modul-Tab
        $this->tabs->addTab(
            'devices',
            $this->txt('tab_devices'),
            $this->ctrl->getLinkTarget($this, 'showDevices')
        );

        if ($registry->isModuleActive('examnetqos')) {
            $this->tabs->addTab(
                'netqos',
                $this->txt('tab_netqos'),
                $this->ctrl->getLinkTarget($this, 'showNetQos')
            );
        }

        if ($registry->isModuleActive('examkeymap')) {
            $this->tabs->addTab(
                'keymap',
                $this->txt('tab_keymap'),
                $this->ctrl->getLinkTarget($this, 'showKeymap')
            );
        }

        if ($registry->isModuleActive('examarchiver')) {
            $this->tabs->addTab(
                'archiver',
                $this->txt('tab_archiver'),
                $this->ctrl->getLinkTarget($this, 'showArchiver')
            );
        }
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
        global $DIC;
        $factory  = $DIC->ui()->factory();
        $renderer = $DIC->ui()->renderer();

        $form = $this->buildGeneralSettingsForm();
        [$users_panel, $users_modals] = $this->buildAuthorizedUsersSubPanel($factory);

        $dev_panel = $factory->panel()->standard(
            $this->txt('dev_settings'),
            [
                $factory->legacy(
                    '<p>' . $this->txt('dev_settings_info') . '</p>'
                    . $form->getHTML()
                ),
                $users_panel,
            ]
        );

        return $this->renderPluginIntro()
            . $renderer->render(array_merge([$dev_panel], $users_modals));
    }

    /**
     * Einleitungs-Panel für den Allgemein-Tab:
     * Was macht das Plugin, wie wird es verwendet, Dokumentation und Kontakt.
     */
    private function renderPluginIntro(): string
    {
        global $DIC;
        $factory  = $DIC->ui()->factory();
        $renderer = $DIC->ui()->renderer();

        $repo_url = 'https://github.com/andreschweigert/ExtendedTestSettings-Modules';

        $intro = $factory->legacy($this->txt('intro_about_text'));

        $usage = $factory->listing()->descriptive([
            $this->txt('intro_usage_manage_title')  => $this->txt('intro_usage_manage_desc'),
            $this->txt('intro_usage_pertest_title') => $this->txt('intro_usage_pertest_desc'),
        ]);

        $support = $factory->legacy(
            sprintf($this->txt('intro_support_text'), $repo_url)
        );

        return $renderer->render([
            $factory->panel()->standard($this->txt('intro_panel_about'), [$intro, $usage]),
            $factory->panel()->standard($this->txt('intro_panel_support'), [$support]),
        ]);
    }

    private function buildGeneralSettingsForm(): ilPropertyFormGUI
    {
        $form = new ilPropertyFormGUI();
        $form->setFormAction($this->ctrl->getFormAction($this, 'saveGeneralSettings'));

        // --- Tab-Sichtbarkeit ---
        $section_visibility = new ilFormSectionHeaderGUI();
        $section_visibility->setTitle($this->txt('visibility_section'));
        $form->addItem($section_visibility);

        $visibility = $this->settings->get('tab_visibility', 'course_admins');

        $radio = new ilRadioGroupInputGUI($this->txt('visibility_label'), 'tab_visibility');
        $radio->setValue($visibility);
        $radio->setInfo($this->txt('visibility_info'));

        $opt_admins = new ilRadioOption(
            $this->txt('visibility_admins_only'),
            'admins_only',
            $this->txt('visibility_admins_only_info')
        );
        $radio->addOption($opt_admins);

        $opt_course = new ilRadioOption(
            $this->txt('visibility_course_admins'),
            'course_admins',
            $this->txt('visibility_course_admins_info')
        );
        $radio->addOption($opt_course);

        $opt_specific = new ilRadioOption(
            $this->txt('visibility_specific_users'),
            'specific_users',
            $this->txt('visibility_specific_users_info')
        );
        $radio->addOption($opt_specific);

        $form->addItem($radio);

        // --- ExamAlert Auto-Aktivierung ---
        $section_alert = new ilFormSectionHeaderGUI();
        $section_alert->setTitle($this->txt('examalert_auto_section'));
        $form->addItem($section_alert);

        $auto_val   = $this->settings->get('examalert_auto_activate', '1');
        $radio_auto = new ilRadioGroupInputGUI($this->txt('examalert_auto_label'), 'examalert_auto_activate');
        $radio_auto->setValue($auto_val);
        $radio_auto->setInfo($this->txt('examalert_auto_info'));

        $opt_auto_on = new ilRadioOption(
            $this->txt('examalert_auto_on'),
            '1',
            $this->txt('examalert_auto_on_info')
        );
        $radio_auto->addOption($opt_auto_on);

        $opt_auto_off = new ilRadioOption(
            $this->txt('examalert_auto_off'),
            '0',
            $this->txt('examalert_auto_off_info')
        );
        $radio_auto->addOption($opt_auto_off);

        $form->addItem($radio_auto);

        $form->addCommandButton('saveGeneralSettings', $this->txt('save_settings'));

        return $form;
    }

    /**
     * Baut die berechtigten Benutzer als ILIAS UI Sub-Panel (für den Dev-Einstellungen-Block).
     *
     * @param mixed $factory
     * @return array{0: \ILIAS\UI\Component\Panel\Sub, 1: array} [Sub-Panel, Modals]
     */
    private function buildAuthorizedUsersSubPanel($factory): array
    {
        $authorized_users = $this->getAuthorizedUsers();
        $add_user_url     = $this->ctrl->getLinkTarget($this, 'showUserSearch');
        $add_button       = $factory->button()->standard($this->txt('add_user'), $add_user_url);

        if (empty($authorized_users)) {
            $panel = $factory->panel()->sub(
                sprintf($this->txt('authorized_users_title'), 0),
                [
                    $factory->messageBox()->info($this->txt('no_users_yet')),
                    $add_button,
                ]
            );
            return [$panel, []];
        }

        $items  = [];
        $modals = [];

        foreach ($authorized_users as $user) {
            $fullname = trim($user['firstname'] . ' ' . $user['lastname']) ?: $user['login'];
            $login    = $user['login'];
            $avatar   = $factory->symbol()->avatar()->letter($login);

            $this->ctrl->setParameter($this, 'user_id', (int) $user['user_id']);
            $remove_url = $this->ctrl->getFormAction($this, 'removeUser');
            $this->ctrl->setParameter($this, 'user_id', '');

            $modal = $factory->modal()->interruptive(
                $this->txt('remove_user_title'),
                sprintf($this->txt('remove_user_confirm'), htmlspecialchars($login)),
                $remove_url
            )->withActionButtonLabel($this->txt('remove'));
            $modals[] = $modal;

            $item = $factory->item()->standard($fullname)
                ->withLeadAvatar($avatar)
                ->withDescription('@' . $login)
                ->withProperties([
                    $this->txt('prop_added_by') => htmlspecialchars($user['added_by_name']),
                    $this->txt('prop_date')     => htmlspecialchars($user['added_at_formatted']),
                ])
                ->withActions(
                    $factory->dropdown()->standard([
                        $factory->button()->shy($this->txt('remove'), $modal->getShowSignal()),
                    ])
                );

            $items[] = $item;
        }

        $item_group = $factory->item()->group(
            sprintf($this->txt('users_authorized_count'), count($authorized_users)),
            $items
        );

        $panel = $factory->panel()->sub(
            sprintf($this->txt('authorized_users_title'), count($authorized_users)),
            [$item_group, $add_button]
        );

        return [$panel, $modals];
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
        $form->setTitle($this->txt('user_search_title'));

        $search_input = new ilTextInputGUI($this->txt('search_term'), 'search_query');
        $search_input->setValue($search_query);
        $search_input->setInfo($this->txt('search_term_info'));
        $search_input->setSize(40);
        $form->addItem($search_input);

        $form->addCommandButton('showUserSearch', $this->txt('search'));
        $form->addCommandButton('showGeneral', $this->txt('back'));

        global $DIC;
        $factory  = $DIC->ui()->factory();
        $renderer = $DIC->ui()->renderer();

        $results_html = '';
        if (!empty($search_query) && strlen($search_query) >= 2) {
            $results_html = $this->renderUserSearchResults($db, $search_query, $factory, $renderer);
        } elseif (!empty($search_query)) {
            $results_html = $renderer->render(
                $factory->messageBox()->info($this->txt('search_min_chars'))
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
                    sprintf($this->txt('no_users_found'), htmlspecialchars($search_query))
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
            $add_url = $this->ctrl->getFormAction($this, 'addSingleUser');
            $this->ctrl->setParameter($this, 'user_id', '');
            $this->ctrl->setParameter($this, 'search_query', '');

            $add_btn = $factory->button()->standard($this->txt('add'), $add_url);

            $item = $factory->item()->standard(htmlspecialchars($fullname))
                ->withLeadAvatar($avatar)
                ->withDescription('@' . htmlspecialchars($login))
                ->withProperties([$this->txt('prop_email') => htmlspecialchars($email)])
                ->withMainAction($add_btn);

            $items[] = $item;
        }

        $count       = count($items);
        $item_group  = $factory->item()->group(sprintf($this->txt('users_found_count'), $count), $items);
        $panel       = $factory->panel()->standard($this->txt('search_results'), $item_group);

        return $renderer->render($panel);
    }

    /**
     * Fügt einen einzelnen Benutzer hinzu
     */
    public function addSingleUser(): void
    {
        global $DIC;
        $db = $DIC->database();

        // Bewusste Ausnahme von der POST-Regel: Das Hinzufügen wird aus der
        // Trefferliste heraus als Link ausgelöst. Die URL stammt aus
        // getFormAction() und trägt den rtoken — CSRF ist damit geschlossen,
        // auch wenn der Klick technisch ein GET ist.
        $user_id      = (int) ($_POST['user_id'] ?? $_GET['user_id'] ?? 0);
        $search_query = $_POST['search_query'] ?? $_GET['search_query'] ?? '';

        if ($user_id <= 0) {
            $this->tpl->setOnScreenMessage('failure', $this->txt('invalid_user_id'));
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
                $this->tpl->setOnScreenMessage('info', $this->txt('user_already_authorized'));
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

                $this->tpl->setOnScreenMessage('success', sprintf($this->txt('user_added'), $user_name));
            }
        } catch (Exception $e) {
            error_log("[ExtendedTestSettings] Fehler beim Hinzufügen: " . $e->getMessage());
            $this->tpl->setOnScreenMessage('failure', sprintf($this->txt('error_generic'), $e->getMessage()));
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
            $this->tpl->setOnScreenMessage('info', $this->txt('no_users_selected'));
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
                $messages[] = sprintf($this->txt('users_added_count'), $added_count);
            }

            if ($already_exists > 0) {
                $messages[] = sprintf($this->txt('users_already_count'), $already_exists);
            }

            if (!empty($messages)) {
                $this->tpl->setOnScreenMessage('success', implode(' ', $messages));
            }
        } catch (Exception $e) {
            error_log("[ExtendedTestSettings] Fehler beim Hinzufügen: " . $e->getMessage());
            $this->tpl->setOnScreenMessage(
                'failure',
                sprintf($this->txt('error_adding_users'), $e->getMessage())
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
     * Entfernt einen Benutzer aus der Berechtigungsliste
     */
    /**
     * Entfernt einen Benutzer aus der Berechtigungsliste
     */
    public function removeUser(): void
    {
        if (!$this->requirePost()) {
            return;
        }

        global $DIC;
        $db = $DIC->database();

        // user_id kann per POST (Formular) oder GET (Interruptive Modal URL) kommen
        // ilCtrl-Parameter stehen im Query-String der Formular-Action; das
        // Interruptive Modal postet nur darauf. Das ist unkritisch, weil
        // requirePost() POST erzwingt und ilCtrl den rtoken dieser URL prüft.
        $user_id = (int) ($_POST['user_id'] ?? $_GET['user_id'] ?? 0);

        if ($user_id <= 0) {
            $this->tpl->setOnScreenMessage('failure', $this->txt('invalid_user_id'));
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
                sprintf($this->txt('user_removed'), $user_name)
            );
        } catch (Exception $e) {
            error_log("[ExtendedTestSettings] Fehler beim Entfernen: " . $e->getMessage());
            $this->tpl->setOnScreenMessage(
                'failure',
                sprintf($this->txt('error_removing'), $e->getMessage())
            );
        }

        $this->ctrl->redirect($this, 'showGeneral');
    }



    /**
     * Speichert die allgemeinen Einstellungen (erweitert)
     */
    protected function saveGeneralSettings(): void
    {
        if (!$this->requirePost()) {
            return;
        }

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

            $this->tpl->setOnScreenMessage('success', $this->txt('settings_saved'));
        } catch (Exception $e) {
            $this->tpl->setOnScreenMessage(
                'failure',
                sprintf($this->txt('error_saving'), $e->getMessage())
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

        // Dynamisch: nur global aktive Module
        $active = array_filter(
            $this->getRegistry()->getAllModuleInfos(),
            static fn(array $info): bool => !empty($info['active'])
        );

        if (empty($active)) {
            [$panel, $modals] = $this->buildMaintenancePanel($factory);
            return $renderer->render(array_merge(
                [$factory->messageBox()->info($this->txt('stats_no_active_module')), $panel],
                $modals
            ));
        }

        ilAccordionGUI::addJavaScript();

        $accordion = new ilAccordionGUI();
        $accordion->setId('ets_stats_accordion');
        $accordion->setBehaviour(ilAccordionGUI::ALL_CLOSED);
        $accordion->setAllowMultiOpened(true);

        $any = false;
        foreach ($active as $info) {
            $table = $this->getModuleSettingsTable($info);

            // Module ohne test-spezifische Settings-Tabelle nicht anzeigen
            if ($table === null) {
                continue;
            }

            $label = (string) ($info['label'] ?? $info['id']);
            $tests = $this->getModuleTests($table);
            $count = count($tests);
            $count_text = $count === 1
                ? $this->txt('stats_test_one')
                : sprintf($this->txt('stats_test_many'), $count);
            $header = htmlspecialchars(
                $label . ' — ' . $count_text,
                ENT_QUOTES
            );

            if ($count > 0) {
                $links = [];
                foreach ($tests as $test) {
                    $url     = 'ilias.php?ref_id=' . $test['ref_id'] . '&baseClass=ilRepositoryGUI';
                    $links[] = $renderer->render($factory->link()->standard($test['title'], $url));
                }
                $content = $renderer->render($factory->listing()->unordered($links));
            } else {
                $content = $renderer->render(
                    $factory->messageBox()->info($this->txt('stats_no_test_uses'))
                );
            }

            $accordion->addItem($header, $content);
            $any = true;
        }

        if (!$any) {
            [$panel, $modals] = $this->buildMaintenancePanel($factory);
            return $renderer->render(array_merge(
                [$factory->messageBox()->info($this->txt('stats_no_module_settings')), $panel],
                $modals
            ));
        }

        [$panel, $modals] = $this->buildMaintenancePanel($factory);

        return '<h3>' . $this->txt('stats_module_usage') . '</h3>'
            . $accordion->getHTML()
            . $renderer->render(array_merge([$panel], $modals));
    }

    /**
     * Wartungs-Panel (Bereinigung verwaister Einträge).
     *
     * Die Bereinigung löscht über alle Modultabellen hinweg und läuft deshalb
     * über ein Interruptive Modal — das postet auf die getFormAction()-URL und
     * ist damit CSRF-geschützt und bestätigungspflichtig (R4).
     *
     * @param mixed $factory
     * @return array{0: mixed, 1: array} [Panel, Modals]
     */
    private function buildMaintenancePanel($factory): array
    {
        $modal = $factory->modal()->interruptive(
            $this->txt('cleanup_confirm_title'),
            $this->txt('cleanup_confirm_blind'),
            $this->ctrl->getFormAction($this, 'cleanupOrphanedEntries')
        )->withActionButtonLabel($this->txt('cleanup_action'));

        $panel = $factory->panel()->standard(
            $this->txt('maintenance'),
            [
                $factory->messageBox()->info($this->txt('maintenance_info')),
                $factory->button()->standard(
                    $this->txt('preview_show'),
                    $this->ctrl->getLinkTarget($this, 'previewCleanup')
                ),
                $factory->button()->standard(
                    $this->txt('cleanup_now'),
                    $modal->getShowSignal()
                ),
            ]
        );

        return [$panel, [$modal]];
    }

    /**
     * Ermittelt die konventionsbasierte Settings-Tabelle eines Moduls:
     * die erste in module.json deklarierte *_settings-Tabelle, die existiert.
     */
    private function getModuleSettingsTable(array $info): ?string
    {
        global $DIC;
        $db = $DIC->database();

        foreach (($info['tables'] ?? []) as $table) {
            $table = (string) $table;
            if (preg_match('/^[a-z0-9_]+_settings$/', $table) && $db->tableExists($table)) {
                return $table;
            }
        }

        return null;
    }

    /**
     * Liefert die aktiven Tests eines Moduls (Titel + ref_id) anhand seiner Settings-Tabelle.
     * Hat die Tabelle eine enabled-Spalte, zählen nur aktivierte Einträge,
     * sonst gilt das Vorhandensein eines Eintrags als aktiv.
     */
    private function getModuleTests(string $table): array
    {
        global $DIC;
        $db = $DIC->database();

        if (!preg_match('/^[a-z0-9_]+_settings$/', $table)
            || !$db->tableExists($table)
            || !$db->tableColumnExists($table, 'test_id')
        ) {
            return [];
        }

        $where = $db->tableColumnExists($table, 'enabled') ? 'AND s.enabled = 1' : '';

        try {
            $result = $db->query("
                SELECT s.test_id, od.title AS test_title, ref.ref_id
                FROM $table s
                JOIN object_data od ON s.test_id = od.obj_id AND od.type = 'tst'
                JOIN object_reference ref ON od.obj_id = ref.obj_id AND ref.deleted IS NULL
                WHERE 1 = 1 $where
                ORDER BY od.title ASC
            ");

            $tests = [];
            while ($row = $db->fetchAssoc($result)) {
                $tests[] = [
                    'ref_id' => (int) $row['ref_id'],
                    'title'  => (string) $row['test_title'],
                ];
            }
            return $tests;
        } catch (Exception $e) {
            error_log("[ExtendedTestSettings] Fehler bei Modul-Statistik für $table: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Alle Settings-Tabellen aller installierten Module (für die Bereinigung),
     * gemappt auf den Modul-Namen für die Anzeige.
     *
     * @return array<string,string> table => label
     */
    private function getCleanupTables(): array
    {
        $map = [];
        foreach ($this->getRegistry()->getAllModuleInfos() as $info) {
            $table = $this->getModuleSettingsTable($info);
            if ($table !== null && !isset($map[$table])) {
                $map[$table] = (string) ($info['label'] ?? $info['id']);
            }
        }

        return $map;
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
        return $form->getHTML();
    }

    private function buildExamCheckerForm(): ilPropertyFormGUI
    {
        $form = new ilPropertyFormGUI();
        $form->setFormAction($this->ctrl->getFormAction($this, 'saveExamChecker'));
        $form->setTitle($this->txt('examchecker_title'));
        $form->setDescription($this->txt('examchecker_desc'));

        $praesenz_settings = $this->getExamCheckerSettings('praesenz');
        $fern_settings     = $this->getExamCheckerSettings('fern');

        $section_p = new ilFormSectionHeaderGUI();
        $section_p->setTitle($this->txt('examchecker_praesenz'));
        $form->addItem($section_p);
        $form->addItem(
            $this->buildCheckGroupInput('praesenz', $praesenz_settings, $this->txt('examchecker_praesenz_group'))
        );

        $section_f = new ilFormSectionHeaderGUI();
        $section_f->setTitle($this->txt('examchecker_fern'));
        $form->addItem($section_f);
        $form->addItem(
            $this->buildCheckGroupInput('fern', $fern_settings, $this->txt('examchecker_fern_group'))
        );

        $form->addCommandButton('saveExamChecker', $this->txt('examchecker_save'));

        return $form;
    }

    /**
     * Baut die Checkbox-Gruppe der verpflichtenden Checks eines Prüfungstyps
     * als ILIAS-Formularkomponente (postet als checks_<typ>[]).
     */
    private function buildCheckGroupInput(string $exam_type, array $current_settings, string $title): ilCheckboxGroupInputGUI
    {
        $group = new ilCheckboxGroupInputGUI($title, 'checks_' . $exam_type);

        foreach ($this->getCheckDefinitions() as $key => $definition) {
            if (!in_array($exam_type, $definition['relevant_for'], true)) {
                continue;
            }
            $group->addOption(
                new ilCheckboxOption($definition['title'], $key, $definition['description'])
            );
        }

        $group->setValue($current_settings);

        return $group;
    }

    private function getCheckDefinitions(): array
    {
        return [
            'fragenpools_verwenden' => [
                'title' => $this->txt('check_fragenpools_verwenden'),
                'description' => $this->txt('check_fragenpools_verwenden_info'),
                'relevant_for' => ['praesenz', 'fern']
            ],
            'datenschutz' => [
                'title' => $this->txt('check_datenschutz'),
                'description' => $this->txt('check_datenschutz_info'),
                'relevant_for' => ['praesenz', 'fern']
            ],
            'online' => [
                'title' => $this->txt('check_online'),
                'description' => $this->txt('check_online_info'),
                'relevant_for' => ['praesenz', 'fern']
            ],
            'passwort' => [
                'title' => $this->txt('check_passwort'),
                'description' => $this->txt('check_passwort_info'),
                'relevant_for' => ['praesenz', 'fern']
            ],
            'nur_ausgewaehlte_teilnehmer' => [
                'title' => $this->txt('check_nur_ausgewaehlte_teilnehmer'),
                'description' => $this->txt('check_nur_ausgewaehlte_teilnehmer_info'),
                'relevant_for' => ['praesenz', 'fern']
            ],
            'testdurchlaeufe_begrenzen' => [
                'title' => $this->txt('check_testdurchlaeufe_begrenzen'),
                'description' => $this->txt('check_testdurchlaeufe_begrenzen_info'),
                'relevant_for' => ['praesenz', 'fern']
            ],
            'wartezeit_erzwingen' => [
                'title' => $this->txt('check_wartezeit_erzwingen'),
                'description' => $this->txt('check_wartezeit_erzwingen_info'),
                'relevant_for' => ['praesenz', 'fern']
            ],
            'pruefungsansicht' => [
                'title' => $this->txt('check_pruefungsansicht'),
                'description' => $this->txt('check_pruefungsansicht_info'),
                'relevant_for' => ['praesenz', 'fern']
            ],
            'automatisches_speichern' => [
                'title' => $this->txt('check_automatisches_speichern'),
                'description' => $this->txt('check_automatisches_speichern_info'),
                'relevant_for' => ['praesenz', 'fern']
            ],
            'direkte_rueckmeldung' => [
                'title' => $this->txt('check_direkte_rueckmeldung'),
                'description' => $this->txt('check_direkte_rueckmeldung_info'),
                'relevant_for' => ['praesenz', 'fern']
            ],
            'fragen_markieren' => [
                'title' => $this->txt('check_fragen_markieren'),
                'description' => $this->txt('check_fragen_markieren_info'),
                'relevant_for' => ['praesenz', 'fern']
            ],
            'eigenstaendigkeitserklaerung' => [
                'title' => $this->txt('check_eigenstaendigkeitserklaerung'),
                'description' => $this->txt('check_eigenstaendigkeitserklaerung_info'),
                'relevant_for' => ['fern']
            ],
            'bearbeitungsdauer' => [
                'title' => $this->txt('check_bearbeitungsdauer'),
                'description' => $this->txt('check_bearbeitungsdauer_info'),
                'relevant_for' => ['praesenz', 'fern']
            ],
            'weiterleitung' => [
                'title' => $this->txt('check_weiterleitung'),
                'description' => $this->txt('check_weiterleitung_info'),
                'relevant_for' => ['praesenz']
            ],
            'bewertungssystem' => [
                'title' => $this->txt('check_bewertungssystem'),
                'description' => $this->txt('check_bewertungssystem_info'),
                'relevant_for' => ['praesenz', 'fern']
            ],
            'negative_punkte' => [
                'title' => $this->txt('check_negative_punkte'),
                'description' => $this->txt('check_negative_punkte_info'),
                'relevant_for' => ['praesenz', 'fern']
            ],
            'besten_durchlauf_bewerten' => [
                'title' => $this->txt('check_besten_durchlauf_bewerten'),
                'description' => $this->txt('check_besten_durchlauf_bewerten_info'),
                'relevant_for' => ['praesenz', 'fern']
            ],
            'keine_vorbedingungen' => [
                'title' => $this->txt('check_keine_vorbedingungen'),
                'description' => $this->txt('check_keine_vorbedingungen_info'),
                'relevant_for' => ['praesenz', 'fern']
            ],
            'direkte_notenuebermittlung' => [
                'title' => $this->txt('check_direkte_notenuebermittlung'),
                'description' => $this->txt('check_direkte_notenuebermittlung_info'),
                'relevant_for' => ['praesenz', 'fern']
            ],
            'zeitlich_begrenzte_verfuegbarkeit' => [
                'title' => $this->txt('check_zeitlich_begrenzte_verfuegbarkeit'),
                'description' => $this->txt('check_zeitlich_begrenzte_verfuegbarkeit_info'),
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
        if (!$this->requirePost()) {
            return;
        }

        try {
            $post = $_POST;
            $valid_keys = array_keys($this->getCheckDefinitions());

            // Präsenz-Einstellungen (nur bekannte Check-Keys erlauben)
            $praesenz_checks = array_intersect($post['checks_praesenz'] ?? [], $valid_keys);
            $this->settings->set('examchecker_standards_praesenz', implode(',', $praesenz_checks));

            // Fernprüfungs-Einstellungen (nur bekannte Check-Keys erlauben)
            $fern_checks = array_intersect($post['checks_fern'] ?? [], $valid_keys);
            $this->settings->set('examchecker_standards_fern', implode(',', $fern_checks));

            $this->tpl->setOnScreenMessage('success', $this->txt('examchecker_saved'));
        } catch (Exception $e) {
            $this->tpl->setOnScreenMessage('failure', sprintf($this->txt('error_saving'), $e->getMessage()));
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
            $this->tpl->setOnScreenMessage('failure', sprintf($this->txt('error_preview'), $e->getMessage()));
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

        $tables = array_keys($this->getCleanupTables());

        foreach ($tables as $table) {
            if (!$db->tableExists($table) || !$db->tableColumnExists($table, 'test_id')) {
                continue;
            }

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
        $cleanup_url = $this->ctrl->getFormAction($this, 'cleanupOrphanedEntries');
        $back_button = $factory->button()->standard($this->txt('back_to_statistics'), $back_url);

        if (empty($orphaned_entries)) {
            return $renderer->render([
                $back_button,
                $factory->messageBox()->success($this->txt('cleanup_none')),
            ]);
        }

        $total_count = array_sum(array_map('count', $orphaned_entries));
        $table_names = $this->getCleanupTables();

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
                sprintf($this->txt('cleanup_table_entries'), $table_name, $count),
                $factory->listing()->descriptive($items)
            );
        }

        $confirm_modal = $factory->modal()->interruptive(
            $this->txt('cleanup_confirm_title'),
            sprintf($this->txt('cleanup_confirm_text'), $total_count),
            $cleanup_url
        )->withActionButtonLabel($this->txt('cleanup_action'));
        $confirm_button = $factory->button()->standard(
            sprintf($this->txt('cleanup_delete_now'), $total_count),
            $confirm_modal->getShowSignal()
        );

        return $renderer->render([
            $back_button,
            $factory->messageBox()->confirmation(
                sprintf($this->txt('cleanup_found'), $total_count)
            ),
            $factory->panel()->report($this->txt('details'), $sub_panels),
            $confirm_button,
            $confirm_modal,
        ]);
    }

    /**
     * Verwaiste Einträge bereinigen (ILIAS 9 kompatibel)
     */
    protected function cleanupOrphanedEntries(): void
    {
        if (!$this->requirePost()) {
            return;
        }

        try {
            // Erst Vorschau sammeln für Meldung
            $orphaned_before = $this->getOrphanedEntries();
            $total_before = array_sum(array_map('count', $orphaned_before));

            // Bereinigung durchführen
            $cleaned = $this->performCleanup();

            if ($cleaned > 0) {
                $this->tpl->setOnScreenMessage('success', sprintf($this->txt('cleanup_done'), $cleaned));
            } else {
                $this->tpl->setOnScreenMessage('info', $this->txt('cleanup_none_found'));
            }
        } catch (Exception $e) {
            $this->tpl->setOnScreenMessage('failure', sprintf($this->txt('error_cleanup'), $e->getMessage()));
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
            $tables = array_keys($this->getCleanupTables());

            foreach ($tables as $table) {
                if (!$db->tableExists($table) || !$db->tableColumnExists($table, 'test_id')) {
                    continue;
                }

                // Prüfe auf aktive Tests (nicht gelöschte)
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
        return ilModuleRegistry::forPlugin($this->getPluginObject());
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
            $factory->messageBox()->info($this->txt('modules_intro'))
        );

        // ---- Upload-Formular (oben) ----
        $modules_dir = $this->getPluginObject()->getDirectory() . '/modules';
        if (is_writable($modules_dir)) {
            $html .= $this->buildUploadForm()->getHTML();
        } else {
            $html .= $renderer->render(
                $factory->messageBox()->failure($this->txt('modules_dir_not_writable'))
            );
        }

        // ---- Modulliste (Update-Daten aus Session, kein Auto-Fetch) ----
        $modules    = $registry->getAllModuleInfos();
        $repo_url   = trim((string) $this->settings->get('module_repo_url', ''));
        $with_check = $repo_url !== '';

        if ($with_check) {
            $cached = $_SESSION['ets_update_cache'] ?? [];
            foreach ($modules as &$info) {
                if (!($info['builtin'] ?? true) && isset($cached[$info['id']])) {
                    $info = array_merge($info, $cached[$info['id']]);
                }
            }
            unset($info);
        }

        $components = [];

        // ---- Update-Check-Buttons (nur bei konfiguriertem Repository) ----
        if ($with_check) {
            $components[] = $factory->button()->standard(
                $this->txt('modules_check_updates'),
                $this->ctrl->getFormAction($this, 'checkModuleUpdates')
            );
            $components[] = $factory->button()->standard(
                $this->txt('modules_network_diag'),
                $this->ctrl->getLinkTarget($this, 'debugNetworkCheck')
            );
        }

        // ---- Modul-Liste als Item-Group (dezente Trennlinien zwischen den Modulen) ----
        if (empty($modules)) {
            $components[] = $factory->messageBox()->info($this->txt('modules_none'));
        } else {
            $items  = [];
            $modals = [];
            foreach ($modules as $info) {
                [$item, $item_modals] = $this->buildModuleItem($info, $factory);
                $items[] = $item;
                $modals  = array_merge($modals, $item_modals);
            }
            $group = $factory->item()->group(sprintf($this->txt('modules_group_count'), count($items)), $items);
            $components[] = $factory->panel()->standard($this->txt('tab_modules'), $group);
            $components   = array_merge($components, $modals);
        }

        $html .= $renderer->render($components);

        // ---- Repository-Einstellungen (unten) ----
        $html .= $this->buildRepoForm()->getHTML();

        return $html;
    }

    /**
     * Baut ein einzelnes Modul-Listen-Item samt zugehöriger Aktions-Modals.
     *
     * @param mixed $factory
     * @return array{0: \ILIAS\UI\Component\Item\Item, 1: array}
     */
    private function buildModuleItem(array $info, $factory): array
    {
        $id      = (string) $info['id'];
        $label   = $info['label'] ?? $id;
        $version = $info['version'] ?? '–';
        $compat  = ($info['ilias_min'] ?? '?') . '–' . ($info['ilias_max'] ?? '?');
        $active  = (bool) ($info['active'] ?? false);
        $builtin = (bool) ($info['builtin'] ?? false);

        // Version-Property (links): Version · ILIAS-Range · ggf. Update-Hinweis
        $version_text = 'v' . $version . ' · ILIAS ' . $compat;
        if (!$builtin) {
            if (!empty($info['update_available'])) {
                $version_text .= ' · ' . sprintf($this->txt('module_update_badge'), $info['latest_version'] ?? '?');
            } elseif (isset($info['update_error'])) {
                $version_text .= ' · ' . $this->txt('module_update_check_failed');
            }
        }

        $desc = trim((string) ($info['description'] ?? ''));

        $modals  = [];
        $actions = [];

        // Update-Aktion (manuell angehängte Parameter, ohne ctrl-Parameter)
        if (!$builtin && !empty($info['update_available']) && !empty($info['download_url'])) {
            $update_url = $this->ctrl->getLinkTarget($this, 'updateModule')
                . '&module_id=' . urlencode($id)
                . '&download_url=' . urlencode($info['download_url'])
                . '&latest_version=' . urlencode($info['latest_version']);
            $update_action = $factory->button()->shy(sprintf($this->txt('module_update_badge'), $info['latest_version']), $update_url);
        } else {
            $update_action = null;
        }

        // Aktivieren / Deaktivieren via Interruptive Modal (POST)
        $this->ctrl->setParameter($this, 'module_id', $id);
        $this->ctrl->setParameter($this, 'active', $active ? '0' : '1');
        $toggle_target = $this->ctrl->getFormAction($this, 'toggleModule');
        $this->ctrl->setParameter($this, 'active', null);

        $toggle_modal = $factory->modal()->interruptive(
            $active ? $this->txt('module_deactivate_title') : $this->txt('module_activate_title'),
            $active
                ? sprintf($this->txt('module_deactivate_confirm'), $label)
                : sprintf($this->txt('module_activate_confirm'), $label),
            $toggle_target
        )->withActionButtonLabel($active ? $this->txt('deactivate') : $this->txt('activate'));
        $modals[]  = $toggle_modal;
        $actions[] = $factory->button()->shy(
            $active ? $this->txt('deactivate') : $this->txt('activate'),
            $toggle_modal->getShowSignal()
        );

        if ($update_action !== null) {
            $actions[] = $update_action;
        }

        // Deinstallieren via Interruptive Modal (POST), nur externe Module
        if (!$builtin) {
            $uninstall_target = $this->ctrl->getFormAction($this, 'uninstallModule');
            $uninstall_modal  = $factory->modal()->interruptive(
                $this->txt('module_uninstall_title'),
                sprintf($this->txt('module_uninstall_confirm'), $label),
                $uninstall_target
            )->withActionButtonLabel($this->txt('uninstall'));
            $modals[]  = $uninstall_modal;
            $actions[] = $factory->button()->shy($this->txt('uninstall'), $uninstall_modal->getShowSignal());
        }

        $this->ctrl->setParameter($this, 'module_id', null);

        $props = [
            $this->txt('prop_version') => $version_text,
        ];
        $maturity = (string) ($info['maturity'] ?? '');
        if ($maturity !== '') {
            $dot       = self::MATURITY_DOTS[$maturity] ?? '';
            $label_txt = isset(self::MATURITY_DOTS[$maturity])
                ? $this->txt('maturity_' . $maturity)
                : $maturity;
            $props[$this->txt('prop_maturity')] = trim($dot . ' ' . $label_txt);
        }
        $props[$this->txt('prop_status')] = $active ? $this->txt('status_active') : $this->txt('status_inactive');

        $item = $factory->item()->standard($label)
            ->withDescription($desc)
            ->withProperties($props)
            ->withActions($factory->dropdown()->standard($actions));

        return [$item, $modals];
    }

    /**
     * Upload-Formular für neue Modul-ZIPs.
     */
    private function buildUploadForm(): ilPropertyFormGUI
    {
        $form = new ilPropertyFormGUI();
        $form->setTitle($this->txt('module_install_title'));
        $form->setFormAction($this->ctrl->getFormAction($this, 'uploadModule'));

        $file = new ilFileInputGUI($this->txt('module_zip_label'), 'module_zip');
        $file->setSuffixes(['zip']);
        $file->setRequired(true);
        $file->setInfo($this->txt('module_zip_info'));
        $form->addItem($file);

        $form->addCommandButton('uploadModule', $this->txt('module_upload_btn'));

        return $form;
    }

    /**
     * Formular für die Modul-Repository-Einstellungen.
     */
    private function buildRepoForm(): ilPropertyFormGUI
    {
        $form = new ilPropertyFormGUI();
        $form->setTitle($this->txt('repo_title'));
        $form->setDescription($this->txt('repo_desc'));
        $form->setFormAction($this->ctrl->getFormAction($this, 'saveModuleRepoUrl'));

        $url = new ilTextInputGUI($this->txt('repo_url_label'), 'module_repo_url');
        $url->setValue((string) $this->settings->get('module_repo_url', ''));
        $url->setInfo($this->txt('repo_url_info'));
        $form->addItem($url);

        $branch = new ilTextInputGUI($this->txt('repo_branch_label'), 'module_repo_branch');
        $branch->setValue((string) $this->settings->get('module_repo_branch', 'ilias9'));
        $form->addItem($branch);

        $proxy = new ilTextInputGUI($this->txt('repo_proxy_label'), 'module_repo_proxy');
        $proxy->setValue((string) $this->settings->get('module_repo_proxy', ''));
        $proxy->setInfo($this->txt('repo_proxy_info'));
        $form->addItem($proxy);

        $form->addCommandButton('saveModuleRepoUrl', $this->txt('save'));

        return $form;
    }

    /**
     * Modul aktivieren / deaktivieren
     */
    protected function toggleModule(): void
    {
        if (!$this->requirePost()) {
            return;
        }

        // ilCtrl-Parameter stehen im Query-String der Formular-Action; das
        // Interruptive Modal postet nur darauf. Das ist unkritisch, weil
        // requirePost() POST erzwingt und ilCtrl den rtoken dieser URL prüft.
        $module_id = (string) ($_POST['module_id'] ?? $_GET['module_id'] ?? '');
        $active    = (bool) (int) ($_POST['active'] ?? $_GET['active'] ?? 0);

        if ($module_id === '') {
            $this->ctrl->redirect($this, 'showModules');
            return;
        }

        $registry = $this->getRegistry();

        if (!$registry->moduleExists($module_id)) {
            $this->tpl->setOnScreenMessage('failure', sprintf($this->txt('module_not_found'), $module_id));
            $this->ctrl->redirect($this, 'showModules');
            return;
        }

        $registry->setModuleActive($module_id, $active);

        $msg = $active
            ? sprintf($this->txt('module_activated'), $module_id)
            : sprintf($this->txt('module_deactivated'), $module_id);

        $this->tpl->setOnScreenMessage('success', $msg);
        $this->ctrl->redirect($this, 'showModules');
    }

    /**
     * ZIP hochladen und installieren
     */
    protected function uploadModule(): void
    {
        if (!$this->requirePost()) {
            return;
        }

        $this->tabs->activateTab('modules');

        $file = $_FILES['module_zip'] ?? null;

        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $errors = [
                UPLOAD_ERR_INI_SIZE  => $this->txt('upload_err_ini_size'),
                UPLOAD_ERR_FORM_SIZE => $this->txt('upload_err_form_size'),
                UPLOAD_ERR_NO_FILE   => $this->txt('upload_err_no_file'),
            ];
            $msg = $errors[$file['error'] ?? UPLOAD_ERR_NO_FILE] ?? $this->txt('upload_err_generic');
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
                sprintf($this->txt('module_installed'), $module_info['label'], $module_info['version'])
            );
        } catch (RuntimeException $e) {
            error_log('[ExtendedTestSettings] uploadModule Fehler: ' . $e->getMessage());
            $this->tpl->setOnScreenMessage('failure', sprintf($this->txt('module_install_failed'), $e->getMessage()));
        } catch (Throwable $e) {
            error_log('[ExtendedTestSettings] uploadModule unerwarteter Fehler: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            $this->tpl->setOnScreenMessage('failure', sprintf($this->txt('error_unexpected'), $e->getMessage()));
        }

        $this->ctrl->redirect($this, 'showModules');
    }

    /**
     * Modul deinstallieren (nur nicht-built-in)
     */
    protected function uninstallModule(): void
    {
        if (!$this->requirePost()) {
            return;
        }

        // ilCtrl-Parameter stehen im Query-String der Formular-Action; das
        // Interruptive Modal postet nur darauf. Das ist unkritisch, weil
        // requirePost() POST erzwingt und ilCtrl den rtoken dieser URL prüft.
        $module_id = (string) ($_POST['module_id'] ?? $_GET['module_id'] ?? '');

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
            $this->tpl->setOnScreenMessage('failure', sprintf($this->txt('module_not_found'), $module_id));
            $this->ctrl->redirect($this, 'showModules');
            return;
        }

        if ($info['builtin']) {
            $this->tpl->setOnScreenMessage('failure', $this->txt('module_builtin_no_uninstall'));
            $this->ctrl->redirect($this, 'showModules');
            return;
        }

        try {
            $installer = $this->getInstaller();
            $installer->uninstallModule($module_id, $info);

            $this->tpl->setOnScreenMessage('success', sprintf($this->txt('module_uninstalled'), $module_id));
        } catch (RuntimeException $e) {
            // Bei fehlgeschlagener Datenbereinigung bleibt das Modul absichtlich
            // installiert, damit die Deinstallation wiederholt werden kann.
            error_log('[ExtendedTestSettings] uninstallModule fehlgeschlagen: ' . $e->getMessage());
            $this->tpl->setOnScreenMessage('failure', sprintf($this->txt('module_uninstall_failed'), $e->getMessage()));
        } catch (Throwable $e) {
            error_log('[ExtendedTestSettings] uninstallModule unerwarteter Fehler: ' . $e->getMessage());
            $this->tpl->setOnScreenMessage('failure', sprintf($this->txt('module_uninstall_failed'), $e->getMessage()));
        }

        $this->ctrl->redirect($this, 'showModules');
    }

    /**
     * Speichert die Modul-Repository-Einstellungen.
     */
    protected function saveModuleRepoUrl(): void
    {
        if (!$this->requirePost()) {
            return;
        }

        $url    = trim($_POST['module_repo_url'] ?? '');
        $branch = trim($_POST['module_repo_branch'] ?? 'ilias9');
        $proxy  = trim($_POST['module_repo_proxy'] ?? '');

        if ($url !== '' && !str_starts_with($url, 'https://github.com/')) {
            $this->tpl->setOnScreenMessage('failure', $this->txt('repo_invalid_github_url'));
            $this->ctrl->redirect($this, 'showModules');
            return;
        }

        if ($proxy !== '' && !preg_match('#^https?://.+:\d+$#', $proxy)) {
            $this->tpl->setOnScreenMessage('failure', $this->txt('repo_invalid_proxy'));
            $this->ctrl->redirect($this, 'showModules');
            return;
        }

        $this->settings->set('module_repo_url', $url);
        $this->settings->set('module_repo_branch', $branch ?: 'ilias9');
        $this->settings->set('module_repo_proxy', $proxy);
        $this->tpl->setOnScreenMessage('success', $this->txt('repo_saved'));
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
        $version_raw    = preg_replace('/[^0-9.]/', '', (string) ($_GET['latest_version'] ?? ''));
        $latest_version = htmlspecialchars($version_raw, ENT_QUOTES);

        $repo_url       = trim((string) $this->settings->get('module_repo_url', ''));
        $url_allowed    = $this->isAllowedDownloadUrl($download_url);
        $back_url       = $this->ctrl->getLinkTarget($this, 'showModules');

        // Kein Debug-Panel im Produktivpfad (R6): Requestdaten und interne
        // Konfiguration gehören nicht in die Oberfläche. Diagnose läuft über
        // den Tab "Netzwerk-Diagnose" und das PHP-Errorlog.
        if ($module_id === '' || $download_url === '') {
            error_log('[ExtendedTestSettings] updateModule ohne module_id/download_url aufgerufen');
            $this->tpl->setOnScreenMessage('failure', $this->txt('update_missing_params'));
            $this->ctrl->redirect($this, 'showModules');
            return;
        }

        if (!$url_allowed) {
            error_log('[ExtendedTestSettings] updateModule: Download-URL abgelehnt (Host/Pfad ausserhalb des konfigurierten Repositorys)');
            $this->tpl->setOnScreenMessage('failure', $this->txt('invalid_download_url'));
            $this->ctrl->redirect($this, 'showModules');
            return;
        }

        // Erwartung serverseitig festhalten: doUpdateModule vergleicht das
        // heruntergeladene Artefakt strikt dagegen, bevor es installiert wird (R13).
        ilSession::set(self::SESSION_PENDING_UPDATE, [
            'module_id'    => $module_id,
            'version'      => $version_raw,
            'download_url' => $download_url,
        ]);

        $do_url     = $this->ctrl->getFormAction($this, 'doUpdateModule');
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
                    Update wird durchgeführt — bitte warten…
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
        if (!$this->requirePost()) {
            return;
        }

        $module_id    = preg_replace('/[^a-z0-9_]/', '', strtolower($_POST['module_id'] ?? ''));
        $download_url = $_POST['download_url'] ?? '';

        if ($module_id === '' || $download_url === '') {
            $this->ctrl->redirect($this, 'showModules');
            return;
        }

        if (!$this->isAllowedDownloadUrl($download_url)) {
            $this->tpl->setOnScreenMessage('failure', $this->txt('invalid_download_url'));
            $this->ctrl->redirect($this, 'showModules');
            return;
        }

        // Erwartung aus der Bestätigungsseite — einmalig verwendbar (R13).
        $pending = ilSession::get(self::SESSION_PENDING_UPDATE);
        ilSession::clear(self::SESSION_PENDING_UPDATE);

        if (!is_array($pending)
            || ($pending['module_id'] ?? '') !== $module_id
            || ($pending['download_url'] ?? '') !== $download_url) {
            error_log('[ExtendedTestSettings] doUpdateModule: Request passt nicht zur bestätigten Update-Erwartung');
            $this->tpl->setOnScreenMessage('failure', $this->txt('update_expectation_mismatch'));
            $this->ctrl->redirect($this, 'showModules');
            return;
        }

        $expected_version = (string) ($pending['version'] ?? '');

        $this->tabs->activateTab('modules');
        $back_url = $this->ctrl->getLinkTarget($this, 'showModules');

        $log   = [];
        $error = null;

        try {
            $log[] = 'ZIP wird heruntergeladen…';
            require_once __DIR__ . '/Module/ilModuleUpdateChecker.php';
            $repo_url = trim((string) $this->settings->get('module_repo_url', ''));
            $branch   = trim((string) $this->settings->get('module_repo_branch', 'ilias9'));
            $proxy    = trim((string) $this->settings->get('module_repo_proxy', ''));
            $checker  = new ilModuleUpdateChecker($repo_url, $branch, 60, $proxy);
            $tmp_file = $checker->downloadZip($download_url);
            $log[]    = 'ZIP heruntergeladen (' . round(filesize($tmp_file) / 1024) . ' KB)';

            // Vor dem Schreiben prüfen, WAS da heruntergeladen wurde. Der Installer
            // installiert unter der ID aus dem ZIP — ohne diesen Abgleich könnte
            // ein anderes Modul installiert werden, während die UI Erfolg für das
            // angeforderte meldet.
            $installer = $this->getInstaller();
            $manifest  = $installer->readManifestFromZip($tmp_file);

            if (($manifest['id'] ?? '') !== $module_id) {
                @unlink($tmp_file);
                throw new RuntimeException(sprintf(
                    $this->txt('update_id_mismatch'),
                    $module_id,
                    (string) ($manifest['id'] ?? '?')
                ));
            }

            if ($expected_version !== '' && (string) ($manifest['version'] ?? '') !== $expected_version) {
                @unlink($tmp_file);
                throw new RuntimeException(sprintf(
                    $this->txt('update_version_mismatch'),
                    $expected_version,
                    (string) ($manifest['version'] ?? '?')
                ));
            }

            $log[] = 'Artefakt geprüft (ID und Version stimmen überein)';

            $log[] = 'Modul wird installiert…';
            $module_info = $installer->installFromZip($tmp_file);
            @unlink($tmp_file);
            $log[] = "Modul '{$module_info['label']}' v{$module_info['version']} installiert";

            $log[] = 'Modul wird aktiviert…';
            $registry = $this->getRegistry();
            $registry->setModuleActive($module_id, true);
            $log[] = 'Modul aktiv';

            // Session-Cache für dieses Modul zurücksetzen, damit kein veraltetes Update-Badge erscheint
            if (isset($_SESSION['ets_update_cache'][$module_id])) {
                unset($_SESSION['ets_update_cache'][$module_id]);
            }

        } catch (RuntimeException $e) {
            $error = $e->getMessage();
            $log[] = 'Fehler: ' . htmlspecialchars($error);
        } catch (Throwable $e) {
            error_log('[ExtendedTestSettings] doUpdateModule Fehler: ' . $e->getMessage());
            $error = $e->getMessage();
            $log[] = 'Unerwarteter Fehler: ' . htmlspecialchars($error);
        }

        $log_html   = implode('<br>', array_map('htmlspecialchars', $log));
        // letzte Zeile schon escaped — rebuild ohne doppeltes escaping
        $log_items  = array_map(fn($l) => "<li>$l</li>", $log);
        $log_list   = implode('', $log_items);
        $status     = $error === null ? 'success' : 'danger';
        $status_txt = $error === null ? 'Update erfolgreich abgeschlossen' : 'Update fehlgeschlagen';

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
     * Update-Check auf expliziten Klick — speichert Ergebnis in Session.
     */
    protected function checkModuleUpdates(): void
    {
        $repo_url   = trim((string) $this->settings->get('module_repo_url', ''));
        $repo_branch = trim((string) $this->settings->get('module_repo_branch', 'ilias9'));

        if ($repo_url === '') {
            $this->tpl->setOnScreenMessage('info', $this->txt('repo_not_configured'));
            $this->ctrl->redirect($this, 'showModules');
            return;
        }

        require_once __DIR__ . '/Module/ilModuleUpdateChecker.php';
        $registry    = $this->getRegistry();
        $modules     = $registry->getAllModuleInfos();
        $repo_proxy  = trim((string) $this->settings->get('module_repo_proxy', ''));
        $checker     = new ilModuleUpdateChecker($repo_url, $repo_branch, 8, $repo_proxy);
        $checked     = $checker->checkAll($modules);

        $cache = [];
        $updates = 0;
        foreach ($checked as $info) {
            if ($info['builtin'] ?? true) {
                continue;
            }
            $entry = [];
            foreach (['update_available', 'latest_version', 'download_url', 'update_error'] as $k) {
                if (isset($info[$k])) {
                    $entry[$k] = $info[$k];
                }
            }
            $cache[$info['id']] = $entry;
            if (!empty($info['update_available'])) {
                $updates++;
            }
        }

        $_SESSION['ets_update_cache'] = $cache;

        if ($updates > 0) {
            $this->tpl->setOnScreenMessage('success', sprintf($this->txt('updates_available'), $updates));
        } else {
            $this->tpl->setOnScreenMessage('info', $this->txt('modules_up_to_date'));
        }

        $this->ctrl->redirect($this, 'showModules');
    }

    /**
     * Netzwerk-Diagnose: testet GitHub-API-Erreichbarkeit mit kurzem Timeout,
     * zeigt PHP-Konfiguration und pro-Modul-Ergebnis — ohne zu hängen.
     */
    protected function debugNetworkCheck(): void
    {
        $this->tabs->activateTab('modules');

        $repo_url    = trim((string) $this->settings->get('module_repo_url', ''));
        $repo_branch = trim((string) $this->settings->get('module_repo_branch', 'ilias9'));
        $repo_proxy  = trim((string) $this->settings->get('module_repo_proxy', ''));
        $back_url    = $this->ctrl->getLinkTarget($this, 'showModules');

        $rows = '';

        // 1. PHP-Konfiguration
        $allow_fopen    = ini_get('allow_url_fopen') ? 'an' : 'aus';
        $sock_timeout   = ini_get('default_socket_timeout');
        $max_exec       = ini_get('max_execution_time');
        $php_version    = PHP_VERSION;
        $ini_set_works  = '?';
        $prev = ini_set('default_socket_timeout', '3');
        $after = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', $prev !== false ? $prev : $sock_timeout);
        $ini_set_works  = ($after === '3') ? 'ja' : 'nein (gesperrt)';

        $rows .= "<tr class='table-secondary'><th colspan='2'>PHP-Konfiguration</th></tr>";
        $rows .= "<tr><td>PHP-Version</td><td>$php_version</td></tr>";
        $rows .= "<tr><td>allow_url_fopen</td><td>$allow_fopen</td></tr>";
        $rows .= "<tr><td>default_socket_timeout</td><td>{$sock_timeout}s</td></tr>";
        $rows .= "<tr><td>ini_set() überschreibbar?</td><td>$ini_set_works</td></tr>";
        $rows .= "<tr><td>max_execution_time</td><td>{$max_exec}s</td></tr>";
        $rows .= "<tr><td>Repo-URL</td><td>" . htmlspecialchars($repo_url) . "</td></tr>";
        $rows .= "<tr><td>Branch</td><td>" . htmlspecialchars($repo_branch) . "</td></tr>";
        $rows .= "<tr><td>HTTP-Proxy</td><td>" . ($repo_proxy !== '' ? htmlspecialchars($repo_proxy) : '<em>nicht konfiguriert</em>') . "</td></tr>";

        // 2. cURL-Verbindungstest (berücksichtigt Proxy, 3s Timeout)
        $curl_avail = function_exists('curl_init') ? 'verfügbar' : 'nicht installiert';
        $rows .= "<tr class='table-secondary'><th colspan='2'>cURL-Verbindungstest via " . ($repo_proxy !== '' ? htmlspecialchars($repo_proxy) : 'direkt') . " (3s Timeout)</th></tr>";
        $rows .= "<tr><td>cURL-Extension</td><td>$curl_avail</td></tr>";

        if (function_exists('curl_init')) {
            $test_urls = [
                'api.github.com'            => 'https://api.github.com',
                'raw.githubusercontent.com' => 'https://raw.githubusercontent.com',
            ];
            foreach ($test_urls as $label => $test_url) {
                $ch = curl_init($test_url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 3,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_NOBODY         => true,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_FOLLOWLOCATION => false,
                ]);
                if ($repo_proxy !== '') {
                    curl_setopt($ch, CURLOPT_PROXY, $repo_proxy);
                }
                $t0       = microtime(true);
                curl_exec($ch);
                $dur      = round((microtime(true) - $t0) * 1000) . ' ms';
                $http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err      = curl_error($ch);
                curl_close($ch);

                if ($err === '' && $http > 0) {
                    $rows .= "<tr><td>$label</td><td>erreichbar — HTTP $http ($dur)</td></tr>";
                } else {
                    $rows .= "<tr><td>$label</td><td>" . htmlspecialchars($err ?: 'Fehler') . " ($dur)</td></tr>";
                }
            }
        }

        // 3. Pro-Modul API-Test (nur wenn TCP ok)
        if ($repo_url !== '') {
            require_once __DIR__ . '/Module/ilModuleUpdateChecker.php';
            $registry = $this->getRegistry();
            $modules  = $registry->getAllModuleInfos();
            $external = array_filter($modules, fn($m) => !($m['builtin'] ?? true));

            $rows .= "<tr class='table-secondary'><th colspan='2'>API-Test pro Modul (Timeout: 5s)</th></tr>";

            $repo_proxy = trim((string) $this->settings->get('module_repo_proxy', ''));
            foreach ($external as $info) {
                $checker = new ilModuleUpdateChecker($repo_url, $repo_branch, 5, $repo_proxy);
                $t0      = microtime(true);
                $checked = $checker->checkOne($info);
                $dur     = round((microtime(true) - $t0) * 1000) . ' ms';
                $id      = htmlspecialchars($info['id']);

                if (isset($checked['update_error'])) {
                    $rows .= "<tr><td>$id</td><td>" . htmlspecialchars($checked['update_error']) . " ($dur)</td></tr>";
                } elseif (!empty($checked['update_available'])) {
                    $v = htmlspecialchars($checked['latest_version']);
                    $rows .= "<tr><td>$id</td><td>Update auf v$v verfügbar ($dur)</td></tr>";
                } else {
                    $v = htmlspecialchars($checked['latest_version'] ?? '?');
                    $rows .= "<tr><td>$id</td><td>Aktuell (latest: v$v) ($dur)</td></tr>";
                }
            }

            if (empty($external)) {
                $rows .= "<tr><td colspan='2'>Keine externen Module installiert.</td></tr>";
            }
        }

        $html = "
        <div class='card mt-3 border-warning' style='max-width:700px;'>
            <div class='card-header bg-warning text-dark'><strong>Netzwerk-Diagnose</strong></div>
            <div class='card-body p-0'>
                <table class='table table-sm table-bordered mb-0' style='font-family:monospace;font-size:0.85em;'>
                    $rows
                </table>
            </div>
        </div>
        <div class='mt-3'><a href='$back_url' class='btn btn-secondary'>Zurück</a></div>";

        $this->tpl->setContent($html);
    }

    /**
     * Prüft ob eine Download-URL aus dem konfigurierten GitHub-Repo stammt.
     * Erlaubt neben github.com auch raw.githubusercontent.com als Download-Host.
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

        $repo_host   = parse_url($repo_url, PHP_URL_HOST);
        $actual_host = parse_url($url, PHP_URL_HOST);

        if ($repo_host === null || $actual_host === null) {
            return false;
        }

        // GitHub liefert Downloads von raw.githubusercontent.com
        $allowed_hosts = [$repo_host];
        if ($repo_host === 'github.com') {
            $allowed_hosts[] = 'raw.githubusercontent.com';
        }

        if (!in_array($actual_host, $allowed_hosts, true)) {
            return false;
        }

        $repo_path = rtrim(parse_url($repo_url, PHP_URL_PATH) ?? '', '/');
        $url_path  = parse_url($url, PHP_URL_PATH) ?? '';

        return $repo_path !== '' && str_starts_with($url_path, $repo_path . '/');
    }

    // -------------------------------------------------------------------------
    // Netzwerk-Qualität-Tab (ExamNetQos-Modul)
    // -------------------------------------------------------------------------

    /**
     * Lädt die Admin-GUI-Klasse eines Moduls für einen ConfigGUI-Tab.
     *
     * Ersetzt die früheren isExamXxxInstalled()-Prüfungen: file_exists() sagt nur,
     * dass eine Datei existiert — nicht, dass das Modul aktiviert ist. Modulcode
     * wird ausschließlich über die Registry aufgelöst (Regel R3), Pfade per
     * realpath() in den Modulordner gezwungen (Regel R11).
     *
     * $gui_file/$gui_class dürfen explizit gesetzt werden, weil ExamKeymap seine
     * Admin-GUI nicht im Manifest führt.
     *
     * @return string|null Klassenname oder null (fehlt, inaktiv oder nicht ladbar)
     */
    private function loadModuleGuiClass(string $module_id, ?string $gui_file = null, ?string $gui_class = null): ?string
    {
        require_once __DIR__ . '/Module/ilModuleManifest.php';

        $info = $this->getRegistry()->getActiveModuleInfo($module_id);
        if ($info === null) {
            return null;
        }

        $gui_file  = $gui_file  ?? ($info['gui_file']  ?? null);
        $gui_class = $gui_class ?? ($info['gui_class'] ?? null);

        if (empty($gui_file) || empty($gui_class)) {
            return null;
        }

        foreach ($info['require_files'] ?? [] as $rel_path) {
            $dep_path = ilModuleManifest::resolveFile($info['path'], (string) $rel_path);
            if ($dep_path !== null) {
                require_once $dep_path;
            }
        }

        $gui_path = ilModuleManifest::resolveFile($info['path'], (string) $gui_file);
        if ($gui_path === null) {
            error_log("[ExtendedTestSettings] Admin-GUI von '$module_id' nicht auflösbar: $gui_file");
            return null;
        }

        require_once $gui_path;

        return class_exists($gui_class) ? $gui_class : null;
    }

    /**
     * Rendert eine "Modul nicht installiert/aktiviert"-Meldung als ILIAS MessageBox.
     */
    private function renderModuleMissing(string $message): string
    {
        global $DIC;
        return $DIC->ui()->renderer()->render(
            $DIC->ui()->factory()->messageBox()->failure($message)
        );
    }

    protected function showNetQos(): void
    {
        $this->tabs->activateTab('netqos');

        $class = $this->loadModuleGuiClass('examnetqos');
        if ($class === null) {
            $this->tpl->setContent($this->renderModuleMissing($this->txt('module_examnetqos_missing')));
            return;
        }

        $view = new $class();
        $view->setChartCmd('showNetQos');
        $view->setExportCmd('exportNetQosCsv');
        $view->showChart();
    }

    protected function exportNetQosCsv(): void
    {
        $class = $this->loadModuleGuiClass('examnetqos');
        if ($class === null) {
            return;
        }

        $view = new $class();
        $view->exportCsv();
    }

    // Protokoll-Tab (ExamLogger-Modul)
    // -------------------------------------------------------------------------

    protected function showProtocol(): void
    {
        $this->tabs->activateTab('protocol');

        $class = $this->loadModuleGuiClass('examlogger');
        if ($class === null) {
            $this->tpl->setContent($this->renderModuleMissing($this->txt('module_examlogger_missing')));
            return;
        }

        $view = new $class();
        $view->setFormCmd('showProtocol');
        $view->setExportCmd('exportProtocolCsv');
        $view->executeCommand();
    }

    protected function exportProtocolCsv(): void
    {
        $class = $this->loadModuleGuiClass('examlogger');
        if ($class === null) {
            return;
        }

        $view = new $class();
        $view->setFormCmd('showProtocol');
        $view->setExportCmd('exportProtocolCsv');
        $view->executeCommand();
    }

    protected function showDevices(): void
    {
        $this->tabs->activateTab('devices');
        $this->tpl->setContent($this->renderDevicesView());
    }

    protected function importDevices(): void
    {
        if (!$this->requirePost()) {
            return;
        }

        $this->tabs->activateTab('devices');

        [$msg, $type] = $this->handleDevicesCsvImport();
        $this->tpl->setOnScreenMessage($type, $msg);
        $this->tpl->setContent($this->renderDevicesView());
    }

    private function renderDevicesView(): string
    {
        global $DIC;
        $db = $DIC->database();

        $importUrl = $this->ctrl->getFormAction($this, 'importDevices');

        $html  = '<div style="padding:15px;background:#f9f9f9;border:1px solid #ddd;border-radius:4px;margin-bottom:20px;">';
        $html .= '<h4 style="margin-top:0;">' . $this->txt('devices_import_title') . '</h4>';
        $html .= '<p style="color:#666;font-size:13px;margin-bottom:10px;">';
        $html .= $this->txt('devices_import_format_label') . ' <code>ip_addr;hostname;ili_nr;koffer;geraet_nr;import_note</code>';
        $html .= ' &nbsp;' . $this->txt('devices_import_format_hint');
        $html .= '</p>';
        $html .= '<form method="post" action="' . htmlspecialchars($importUrl) . '" enctype="multipart/form-data">';
        $html .= '<div style="display:flex;align-items:center;gap:12px;">';
        $html .= '<input type="file" name="geraete_csv" accept=".csv" class="form-control" style="max-width:420px;">';
        $html .= '<button type="submit" class="btn btn-primary btn-sm">' . $this->txt('devices_btn_import') . '</button>';
        $html .= '</div>';
        $html .= '</form>';
        $html .= '</div>';

        if (!$db->tableExists('etest_hosts')) {
            $html .= '<div class="alert alert-warning">' . $this->txt('devices_table_missing') . '</div>';
            return $html;
        }

        $result  = $db->query('SELECT ip_addr, hostname, ili_nr, koffer, geraet_nr, import_note FROM etest_hosts ORDER BY koffer, geraet_nr');
        $devices = [];
        while ($row = $db->fetchAssoc($result)) {
            $devices[] = $row;
        }

        $html .= '<p>' . sprintf($this->txt('devices_count'), count($devices)) . '</p>';

        if (empty($devices)) {
            $html .= '<div class="alert alert-info">' . $this->txt('devices_none') . '</div>';
            return $html;
        }

        $html .= '<table class="table table-striped table-condensed" style="font-size:13px;">';
        $html .= '<thead><tr><th>' . $this->txt('devices_th_koffer') . '</th><th>' . $this->txt('devices_th_geraet') . '</th><th>' . $this->txt('devices_th_ili') . '</th><th>' . $this->txt('devices_th_ip') . '</th><th>' . $this->txt('devices_th_hostname') . '</th><th>' . $this->txt('devices_th_note') . '</th></tr></thead><tbody>';
        foreach ($devices as $dev) {
            $noteStyle = $dev['import_note'] !== '' ? 'color:#bbb;font-size:12px;' : '';
            $html .= '<tr>'
                   . '<td><strong>' . htmlspecialchars($dev['koffer']) . '</strong></td>'
                   . '<td>' . htmlspecialchars($dev['geraet_nr']) . '</td>'
                   . '<td>' . htmlspecialchars($dev['ili_nr']) . '</td>'
                   . '<td style="font-size:12px;color:#666;">' . htmlspecialchars($dev['ip_addr']) . '</td>'
                   . '<td style="font-size:12px;color:#888;">' . htmlspecialchars($dev['hostname']) . '</td>'
                   . '<td style="' . $noteStyle . '">' . htmlspecialchars($dev['import_note']) . '</td>'
                   . '</tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }

    private function handleDevicesCsvImport(): array
    {
        global $DIC;
        $db = $DIC->database();

        $errorCode = (int)($_FILES['geraete_csv']['error'] ?? -1);
        if (empty($_FILES['geraete_csv']['tmp_name']) || $errorCode !== UPLOAD_ERR_OK) {
            return [sprintf($this->txt('devices_import_no_file'), $errorCode), 'failure'];
        }

        $content = file_get_contents($_FILES['geraete_csv']['tmp_name']);
        if ($content === false || $content === '') {
            return [$this->txt('devices_import_empty'), 'failure'];
        }
        // UTF-8 BOM entfernen
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
        }

        $lines = preg_split('/\r?\n/', trim($content));
        if (empty($lines)) {
            return [$this->txt('devices_import_empty'), 'failure'];
        }

        $header   = str_getcsv(array_shift($lines), ';');
        $expected = ['ip_addr', 'hostname', 'ili_nr', 'koffer', 'geraet_nr', 'import_note'];
        if (array_map('trim', array_slice($header, 0, 6)) !== $expected) {
            return [sprintf($this->txt('devices_import_bad_format'), implode(';', $expected)), 'failure'];
        }

        if (!$db->tableExists('etest_hosts')) {
            return [$this->txt('devices_table_missing'), 'failure'];
        }

        $count   = 0;
        $skipped = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            $cols     = str_getcsv($line, ';');
            $ip       = trim($cols[0] ?? '');
            $hostname = trim($cols[1] ?? '');
            $ili_nr   = trim($cols[2] ?? '');
            $koffer   = trim($cols[3] ?? '');
            $geraet   = trim($cols[4] ?? '');
            $note     = trim($cols[5] ?? '');

            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                $skipped++;
                continue;
            }

            $db->replace('etest_hosts',
                ['ip_addr' => ['text', $ip]],
                [
                    'hostname'    => ['text', $hostname],
                    'ili_nr'      => ['text', $ili_nr],
                    'koffer'      => ['text', $koffer],
                    'geraet_nr'   => ['text', $geraet],
                    'import_note' => ['text', $note],
                ]
            );
            $count++;
        }

        $msg = sprintf($this->txt('devices_import_done'), $count);
        if ($skipped > 0) {
            $msg .= sprintf($this->txt('devices_import_skipped'), $skipped);
        }
        return [$msg, 'success'];
    }

    // -------------------------------------------------------------------------
    // Tastatur-Layout-Tab (ExamKeymap-Modul)
    // -------------------------------------------------------------------------

    /** ExamKeymap führt seine Admin-GUI nicht im Manifest — daher explizit benannt. */
    private function loadKeymapGuiClass(): ?string
    {
        return $this->loadModuleGuiClass(
            'examkeymap',
            'class.ilExamKeymapAdminGUI.php',
            'ilExamKeymapAdminGUI'
        );
    }

    protected function showKeymap(): void
    {
        $this->tabs->activateTab('keymap');

        $class = $this->loadKeymapGuiClass();
        if ($class === null) {
            $this->tpl->setContent($this->renderModuleMissing($this->txt('module_examkeymap_missing')));
            return;
        }

        $view = new $class();
        $view->setSaveCmd('saveKeymap');
        $this->passFormActionTo($view, 'saveKeymap');
        $view->showEditor();
    }

    /**
     * Übergibt einer Modul-Admin-GUI die CSRF-geschützte Formular-Action des
     * zugehörigen Kern-Commands — sofern das Modul den Setter bereits kennt.
     *
     * Hintergrund: Module wie ExamKeymap bauen ihre Formular-Action bisher selbst
     * aus buildBaseUrl() . '&cmd=' . $saveCmd zusammen; darin steckt kein rtoken.
     * Der Kern kann das nicht allein reparieren — requirePost() verhindert
     * immerhin GET. Sobald das Modul setSaveUrl() implementiert, greift auch der
     * Token, ohne dass hier nochmal etwas geändert werden muss.
     *
     * @param object $view
     */
    private function passFormActionTo($view, string $cmd): void
    {
        if (method_exists($view, 'setSaveUrl')) {
            $view->setSaveUrl($this->ctrl->getFormAction($this, $cmd));
        }
    }

    protected function saveKeymap(): void
    {
        if (!$this->requirePost()) {
            return;
        }

        $this->tabs->activateTab('keymap');

        $class = $this->loadKeymapGuiClass();
        if ($class === null) {
            return;
        }

        $view = new $class();
        $view->setSaveCmd('saveKeymap');
        $this->passFormActionTo($view, 'saveKeymap');

        [$msg, $type] = $view->handleSave();
        $this->tpl->setOnScreenMessage($type, $msg);
        $view->showEditor();
    }

    // -------------------------------------------------------------------------
    // Archiv-Tab (ExamArchiver-Modul)
    // -------------------------------------------------------------------------

    protected function showArchiver(): void
    {
        $this->tabs->activateTab('archiver');

        $class = $this->loadModuleGuiClass('examarchiver');
        if ($class === null) {
            $this->tpl->setContent($this->renderModuleMissing($this->txt('module_examarchiver_missing')));
            return;
        }

        $view = new $class();
        $this->tpl->setContent($view->renderAdminView());
    }
}


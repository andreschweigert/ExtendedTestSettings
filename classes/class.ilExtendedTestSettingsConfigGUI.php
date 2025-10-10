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
     * Tab 1: Allgemein (erstmal leer)
     */
    protected function showGeneral(): void
    {
        $this->tabs->activateTab('general');
        $this->tpl->setContent($this->renderGeneralTab());
    }

    private function renderGeneralTab(): string
    {
        $form_action = $this->ctrl->getLinkTarget($this, 'saveGeneralSettings');

        // Aktuelle Einstellungen laden
        $visibility = $this->settings->get('tab_visibility', 'course_admins');
        $admins_only = ($visibility === 'admins_only') ? 'checked' : '';
        $course_admins = ($visibility === 'course_admins') ? 'checked' : '';
        $specific_users = ($visibility === 'specific_users') ? 'checked' : '';

        // Berechtigte Benutzer laden
        $authorized_users = $this->getAuthorizedUsers();
        $users_list_html = $this->renderAuthorizedUsersList($authorized_users);

        // Link für User-Suche
        $add_user_link = $this->ctrl->getLinkTarget($this, 'showUserSearch');

        return '
<div style="padding: 2rem;">
    <form method="post" action="' . $form_action . '" id="general-settings-form">
        <div style="background: white; border: 1px solid #e9ecef; border-radius: 0.5rem; padding: 2rem; margin-bottom: 2rem;">
            <h3 style="margin-top: 0; color: #495057;">🔒 Tab-Sichtbarkeit in Test-Einstellungen</h3>
            <p style="color: #6c757d; margin-bottom: 1.5rem;">
                Bestimmen Sie, wer den Tab "Erweiterte Test-Einstellungen" in Tests sehen kann.
            </p>
            
            <div style="background: #f8f9fa; padding: 1.5rem; border-radius: 0.375rem;">
                <div style="margin-bottom: 1rem;">
                    <label style="display: flex; align-items: flex-start; gap: 0.5rem; cursor: pointer; margin-bottom: 0.75rem;">
                        <input type="radio" name="tab_visibility" value="admins_only" ' . $admins_only . ' style="margin-top: 0.25rem;" onchange="toggleUserManagement(false)">
                        <div>
                            <strong>🔴 Nur System-Administratoren</strong>
                            <div style="color: #6c757d; font-size: 0.85rem;">
                                Nur ILIAS System-Administratoren können den Tab sehen und verwenden
                            </div>
                        </div>
                    </label>
                    
                    <label style="display: flex; align-items: flex-start; gap: 0.5rem; cursor: pointer; margin-bottom: 0.75rem;">
                        <input type="radio" name="tab_visibility" value="course_admins" ' . $course_admins . ' style="margin-top: 0.25rem;" onchange="toggleUserManagement(false)">
                        <div>
                            <strong>🟢 Kurs-Administratoren + System-Administratoren</strong>
                            <div style="color: #6c757d; font-size: 0.85rem;">
                                Kurs-Administratoren, Test-Besitzer und System-Administratoren können den Tab sehen
                            </div>
                        </div>
                    </label>
                    
                    <label style="display: flex; align-items: flex-start; gap: 0.5rem; cursor: pointer;">
                        <input type="radio" name="tab_visibility" value="specific_users" ' . $specific_users . ' style="margin-top: 0.25rem;" onchange="toggleUserManagement(true)">
                        <div>
                            <strong>🔵 Nur ausgewählte Benutzer + System-Administratoren</strong>
                            <div style="color: #6c757d; font-size: 0.85rem;">
                                Nur explizit berechtigte Benutzer und System-Administratoren können den Tab sehen
                            </div>
                        </div>
                    </label>
                </div>
            </div>
        </div>
        
        <!-- Benutzerverwaltung -->
        <div id="user-management-section" style="background: white; border: 1px solid #e9ecef; border-radius: 0.5rem; padding: 2rem; margin-bottom: 2rem; ' . ($visibility !== 'specific_users' ? 'opacity: 0.5; pointer-events: none;' : '') . '">
            <h3 style="margin-top: 0; color: #495057; display: flex; align-items: center; gap: 0.5rem;">
                <span>👥</span> Berechtigte Benutzer verwalten
            </h3>
            <p style="color: #6c757d; margin-bottom: 1.5rem;">
                Fügen Sie einzelne Benutzer hinzu, die Zugriff auf die erweiterten Test-Einstellungen erhalten sollen.
            </p>
            
            <!-- Benutzer hinzufügen Button -->
            <div style="background: #f8f9fa; padding: 1.25rem; border-radius: 0.375rem; margin-bottom: 1.5rem;">
                <a href="' . $add_user_link . '" 
                   class="btn btn-primary" 
                   style="display: inline-block; background: #007bff; color: white; text-decoration: none; padding: 0.75rem 1.5rem; border-radius: 0.375rem; font-weight: 500; transition: all 0.2s;">
                    ➕ Benutzer hinzufügen
                </a>
                <p style="margin: 0.75rem 0 0 0; color: #6c757d; font-size: 0.85rem;">
                    Öffnet die ILIAS Benutzersuche zur Auswahl
                </p>
            </div>
            
            <!-- Liste berechtigter Benutzer -->
            <div>
                <h4 style="color: #495057; margin-bottom: 1rem;">
                    Aktuell berechtigt (' . count($authorized_users) . '):
                </h4>
                <div id="authorized-users-list">
                    ' . $users_list_html . '
                </div>
            </div>
        </div>
        
        <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid #e9ecef;">
            <button type="submit" style="background: #007bff; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 0.375rem; font-weight: 500; cursor: pointer;">
                💾 Einstellungen speichern
            </button>
        </div>
    </form>
</div>

<style>
.btn:hover {
    background: #0056b3 !important;
    transform: translateY(-1px);
    box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075);
}
</style>

<script>
function toggleUserManagement(enabled) {
    const section = document.getElementById("user-management-section");
    if (section) {
        if (enabled) {
            section.style.opacity = "1";
            section.style.pointerEvents = "auto";
        } else {
            section.style.opacity = "0.5";
            section.style.pointerEvents = "none";
        }
    }
}

function removeUserConfirm(userId, login) {
    if (confirm("Benutzer @" + login + " wirklich entfernen?")) {
        const form = document.createElement("form");
        form.method = "POST";
        form.action = "' . $this->ctrl->getLinkTarget($this, 'removeUser') . '";
        
        const input = document.createElement("input");
        input.type = "hidden";
        input.name = "user_id";
        input.value = userId;
        
        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
    }
}

// Initial state
document.addEventListener("DOMContentLoaded", function() {
    const specificUsersRadio = document.querySelector(\'input[value="specific_users"]\');
    if (specificUsersRadio) {
        toggleUserManagement(specificUsersRadio.checked);
    }
});
</script>
';
    }

    /**
     * Rendert die Liste der berechtigten Benutzer
     */
    private function renderAuthorizedUsersList(array $users): string
    {
        if (empty($users)) {
            return '<div style="text-align: center; padding: 2rem; background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 0.375rem; color: #6c757d;">
            Noch keine Benutzer hinzugefügt.
        </div>';
        }

        $html = '<div style="border: 1px solid #e9ecef; border-radius: 0.375rem; overflow: hidden;">';

        foreach ($users as $user) {
            $user_id = $user['user_id'];
            $login = htmlspecialchars($user['login']);
            $fullname = htmlspecialchars($user['firstname'] . ' ' . $user['lastname']);
            $added_by = htmlspecialchars($user['added_by_name']);
            $added_at = htmlspecialchars($user['added_at_formatted']);

            $html .= '
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.75rem 1rem; border-bottom: 1px solid #e9ecef; background: white;" data-user-id="' . $user_id . '">
            <div style="flex: 1;">
                <div style="font-weight: 500; color: #495057;">' . $fullname . '</div>
                <div style="font-size: 0.85rem; color: #6c757d;">@' . $login . '</div>
                <div style="font-size: 0.75rem; color: #999; margin-top: 0.25rem;">
                    Hinzugefügt von ' . $added_by . ' am ' . $added_at . '
                </div>
            </div>
            <button type="button" 
                    onclick="removeUserConfirm(' . $user_id . ', \'' . addslashes($login) . '\')" 
                    style="background: #dc3545; color: white; border: none; padding: 0.375rem 0.75rem; border-radius: 0.375rem; cursor: pointer; font-size: 0.85rem;">
                🗑️ Entfernen
            </button>
        </div>';
        }

        $html .= '</div>';

        return $html;
    }



    /**
     * JavaScript für User-Suche
     */
    private function getUserSearchScript(): string
    {
        $search_ajax_url = $this->ctrl->getLinkTarget($this, 'searchUsersAjax', '', true);
        $add_user_url = $this->ctrl->getLinkTarget($this, 'addUser', '', true);
        $remove_user_url = $this->ctrl->getLinkTarget($this, 'removeUser', '', true);

        return '
<script>
let searchTimeout = null;

function searchUsers() {
    const input = document.getElementById("user-search-input");
    const query = input.value.trim();
    const resultsDiv = document.getElementById("user-search-results");
    
    if (query.length < 2) {
        resultsDiv.innerHTML = \'<div style="padding: 0.5rem; color: #6c757d; font-size: 0.85rem;">Mindestens 2 Zeichen eingeben...</div>\';
        return;
    }
    
    resultsDiv.innerHTML = \'<div style="padding: 0.5rem; color: #6c757d;">🔍 Suche...</div>\';
    
    fetch("' . $search_ajax_url . '&q=" + encodeURIComponent(query))
        .then(response => response.json())
        .then(data => {
            if (data.users && data.users.length > 0) {
                let html = \'<div style="border: 1px solid #dee2e6; border-radius: 0.375rem; max-height: 200px; overflow-y: auto; background: white;">\';
                data.users.forEach(user => {
                    html += `
                        <div style="padding: 0.5rem 0.75rem; border-bottom: 1px solid #e9ecef; display: flex; justify-content: space-between; align-items: center; cursor: pointer;" 
                             onmouseover="this.style.backgroundColor=\'#f8f9fa\'" 
                             onmouseout="this.style.backgroundColor=\'white\'">
                            <div>
                                <div style="font-weight: 500;">${user.fullname}</div>
                                <div style="font-size: 0.85rem; color: #6c757d;">@${user.login}</div>
                            </div>
                            <button type="button" 
                                    onclick="addUser(${user.user_id}, \'${user.login}\', \'${user.fullname}\'); event.stopPropagation();" 
                                    style="background: #28a745; color: white; border: none; padding: 0.25rem 0.5rem; border-radius: 0.25rem; cursor: pointer; font-size: 0.8rem;">
                                ➕ Hinzufügen
                            </button>
                        </div>`;
                });
                html += \'</div>\';
                resultsDiv.innerHTML = html;
            } else {
                resultsDiv.innerHTML = \'<div style="padding: 0.5rem; color: #6c757d; font-size: 0.85rem;">Keine Benutzer gefunden.</div>\';
            }
        })
        .catch(error => {
            resultsDiv.innerHTML = \'<div style="padding: 0.5rem; color: #dc3545;">Fehler bei der Suche.</div>\';
            console.error("Search error:", error);
        });
}

// Auto-search beim Tippen
document.addEventListener("DOMContentLoaded", function() {
    const input = document.getElementById("user-search-input");
    if (input) {
        input.addEventListener("input", function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                if (this.value.trim().length >= 2) {
                    searchUsers();
                }
            }, 300);
        });
        
        input.addEventListener("keypress", function(e) {
            if (e.key === "Enter") {
                e.preventDefault();
                searchUsers();
            }
        });
    }
});

function addUser(userId, login, fullname) {
    fetch("' . $add_user_url . '&user_id=" + userId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Reload page to show updated list
                window.location.reload();
            } else {
                alert("Fehler: " + (data.message || "Benutzer konnte nicht hinzugefügt werden"));
            }
        })
        .catch(error => {
            alert("Fehler beim Hinzufügen des Benutzers");
            console.error("Add user error:", error);
        });
}

function removeUserConfirm(userId, login) {
    if (confirm("Benutzer @" + login + " wirklich entfernen?")) {
        fetch("' . $remove_user_url . '&user_id=" + userId)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Remove from DOM
                    const userDiv = document.querySelector(`[data-user-id="${userId}"]`);
                    if (userDiv) {
                        userDiv.style.transition = "opacity 0.3s";
                        userDiv.style.opacity = "0";
                        setTimeout(() => userDiv.remove(), 300);
                    }
                } else {
                    alert("Fehler: " + (data.message || "Benutzer konnte nicht entfernt werden"));
                }
            })
            .catch(error => {
                alert("Fehler beim Entfernen des Benutzers");
                console.error("Remove user error:", error);
            });
    }
}
</script>';
    }


    /**
     * Zeigt die ILIAS User-Suche an
     */
    public function showUserSearch(): void
    {
        global $DIC;
        $db = $DIC->database();

        $this->tabs->activateTab('general');

        $search_query = $_POST['search_query'] ?? $_GET['search_query'] ?? '';
        $users_html = '';

        if (!empty($search_query) && strlen($search_query) >= 2) {
            // Benutzer suchen - ILIAS 9 kompatibel mit prepared statements
            $search_pattern = '%' . $search_query . '%';

            $sql = "
            SELECT u.usr_id, u.login, u.firstname, u.lastname, u.email
            FROM usr_data u
            WHERE (
                u.login LIKE %s
                OR u.firstname LIKE %s
                OR u.lastname LIKE %s
            )
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

            if ($db->numRows($result) > 0) {
                $users_html = '<div style="background: white; border: 1px solid #e9ecef; border-radius: 0.375rem; margin-top: 1.5rem; overflow: hidden;">';
                $users_html .= '<div style="padding: 0.75rem 1rem; background: #f8f9fa; border-bottom: 1px solid #e9ecef; font-weight: 500; color: #495057;">
                Gefundene Benutzer (' . $db->numRows($result) . '):
            </div>';

                while ($row = $db->fetchAssoc($result)) {
                    $fullname = htmlspecialchars(trim($row['firstname'] . ' ' . $row['lastname']));
                    $login = htmlspecialchars($row['login']);
                    $email = htmlspecialchars($row['email'] ?? '');

                    $users_html .= '
                <div style="padding: 0.75rem 1rem; border-bottom: 1px solid #e9ecef; display: flex; justify-content: space-between; align-items: center;">
                    <div style="flex: 1;">
                        <div style="font-weight: 500; color: #495057;">' . $fullname . '</div>
                        <div style="font-size: 0.85rem; color: #6c757d;">@' . $login . ($email ? ' · ' . $email : '') . '</div>
                    </div>
                    <form method="post" action="' . $this->ctrl->getLinkTarget($this, 'addSingleUser') . '" style="margin: 0;">
                        <input type="hidden" name="user_id" value="' . $row['usr_id'] . '">
                        <input type="hidden" name="search_query" value="' . htmlspecialchars($search_query) . '">
                        <button type="submit" style="background: #28a745; color: white; border: none; padding: 0.5rem 1rem; border-radius: 0.375rem; cursor: pointer; font-size: 0.85rem; font-weight: 500; transition: all 0.2s;">
                            ➕ Hinzufügen
                        </button>
                    </form>
                </div>';
                }

                $users_html .= '</div>';
            } else {
                $users_html = '<div style="text-align: center; padding: 2rem; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 0.375rem; color: #856404; margin-top: 1.5rem;">
                ℹ️ Keine Benutzer gefunden für "' . htmlspecialchars($search_query) . '"
            </div>';
            }
        } elseif (!empty($search_query)) {
            $users_html = '<div style="text-align: center; padding: 2rem; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 0.375rem; color: #856404; margin-top: 1.5rem;">
            ℹ️ Bitte mindestens 2 Zeichen eingeben
        </div>';
        }

        $back_url = $this->ctrl->getLinkTarget($this, 'showGeneral');

        $html = '
    <div style="padding: 2rem;">
        <div style="margin-bottom: 1.5rem;">
            <a href="' . $back_url . '" style="color: #007bff; text-decoration: none; font-weight: 500;">← Zurück zur Konfiguration</a>
        </div>
        
        <div style="background: white; border: 1px solid #e9ecef; border-radius: 0.5rem; padding: 2rem; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075);">
            <h3 style="margin-top: 0; color: #495057; display: flex; align-items: center; gap: 0.5rem;">
                <span style="font-size: 1.5rem;">👥</span> Benutzer suchen und hinzufügen
            </h3>
            <p style="color: #6c757d; margin-bottom: 1.5rem;">
                Suchen Sie nach Login, Vor- oder Nachnamen. Bereits berechtigte Benutzer werden automatisch ausgeblendet.
            </p>
            
            <form method="post" action="' . $this->ctrl->getLinkTarget($this, 'showUserSearch') . '" id="user-search-form">
                <div style="display: flex; gap: 0.5rem;">
                    <input type="text" 
                           name="search_query" 
                           id="search-input"
                           value="' . htmlspecialchars($search_query) . '"
                           placeholder="Login, Vorname oder Nachname eingeben..." 
                           style="flex: 1; padding: 0.75rem 1rem; border: 1px solid #ced4da; border-radius: 0.375rem; font-size: 1rem;"
                           autofocus>
                    <button type="submit" style="background: #007bff; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 0.375rem; cursor: pointer; font-weight: 500; font-size: 1rem; transition: all 0.2s; white-space: nowrap;">
                        🔍 Suchen
                    </button>
                </div>
                <div style="margin-top: 0.5rem; font-size: 0.85rem; color: #6c757d;">
                    💡 Tipp: Mindestens 2 Zeichen eingeben für beste Ergebnisse
                </div>
            </form>
            
            ' . $users_html . '
        </div>
    </div>
    
    <style>
    button:hover {
        background: #0056b3 !important;
        transform: translateY(-1px);
        box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075);
    }
    
    #search-input:focus {
        outline: none;
        border-color: #007bff;
        box-shadow: 0 0 0 0.2rem rgba(0,123,255,0.25);
    }
    </style>
    
    <script>
    // Enter-Taste im Suchfeld
    document.getElementById("search-input").addEventListener("keypress", function(e) {
        if (e.key === "Enter") {
            e.preventDefault();
            document.getElementById("user-search-form").submit();
        }
    });
    </script>';

        $this->tpl->setContent($html);
    }

    /**
     * Fügt einen einzelnen Benutzer hinzu
     */
    public function addSingleUser(): void
    {
        global $DIC;
        $db = $DIC->database();

        $user_id = (int)($_POST['user_id'] ?? 0);
        $search_query = $_POST['search_query'] ?? '';

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
    public function removeUser(): void
    {
        global $DIC;
        $db = $DIC->database();

        $user_id = (int)($_POST['user_id'] ?? 0);

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
                "✅ Benutzer $user_name wurde entfernt."
            );
        } catch (Exception $e) {
            error_log("[ExtendedTestSettings] Fehler beim Entfernen: " . $e->getMessage());
            $this->tpl->setOnScreenMessage(
                'failure',
                '❌ Fehler beim Entfernen: ' . $e->getMessage()
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
            $tab_visibility = $_POST['tab_visibility'] ?? 'course_admins';

            // Validierung
            if (!in_array($tab_visibility, ['admins_only', 'course_admins', 'specific_users'])) {
                $tab_visibility = 'course_admins';
            }

            $this->settings->set('tab_visibility', $tab_visibility);

            $visibility_text = [
                'admins_only' => 'Nur System-Administratoren',
                'course_admins' => 'Kurs-Administratoren + System-Administratoren',
                'specific_users' => 'Nur ausgewählte Benutzer + System-Administratoren'
            ];

            $this->tpl->setOnScreenMessage(
                'success',
                '✅ Einstellungen gespeichert. Tab-Sichtbarkeit: ' . $visibility_text[$tab_visibility]
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
        // Plugin-Statistiken laden (ILIAS 9 kompatibel)
        $stats = $this->getPluginStatistics();

        // Modulstatus prüfen
        $module_status = $this->getModuleStatus();

        $stats_html = $this->renderStatisticsCards($stats);
        $modules_html = $this->renderModuleStatusCards($module_status);
        $cleanup_html = $this->renderCleanupSection();

        return "
        <div style='padding: 1.5rem;'>
            <div style='margin-bottom: 2rem;'>
                <h3 style='color: #495057; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;'>
                    📊 Plugin-Statistiken
                </h3>
                $stats_html
            </div>
            
            <div style='margin-bottom: 2rem;'>
                <h3 style='color: #495057; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;'>
                    🔧 Module-Status
                </h3>
                $modules_html
            </div>
            
            <div>
                <h3 style='color: #495057; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;'>
                    🧹 Wartung
                </h3>
                $cleanup_html
            </div>
        </div>";
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

    private function renderStatisticsCards(array $stats): string
    {
        if (empty($stats)) {
            return '<div style="text-align: center; padding: 2rem; background: #f8f9fa; border-radius: 0.5rem; color: #6c757d;">
                Keine Statistiken verfügbar.
            </div>';
        }

        $cards = '';
        $stat_configs = [
            'examcalc_enabled_tests' => ['title' => 'Taschenrechner', 'icon' => '🧮', 'color' => '#007bff'],
            'examsymbols_enabled_tests' => ['title' => 'Sonderzeichen', 'icon' => '📝', 'color' => '#28a745'],
            'examguard_enabled_tests' => ['title' => 'Prüfungsschutz', 'icon' => '🛡️', 'color' => '#dc3545'],
            'examalert_enabled_tests' => ['title' => 'Zeit-Warnung', 'icon' => '⏰', 'color' => '#fd7e14'],
            'total_configured_tests' => ['title' => 'Gesamt konfiguriert', 'icon' => '📋', 'color' => '#6f42c1']
        ];

        foreach ($stat_configs as $key => $config) {
            $value = $stats[$key] ?? 0;
            $cards .= "
            <div style='background: white; border: 1px solid #e9ecef; border-radius: 0.5rem; padding: 1.5rem; text-align: center; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075);'>
                <div style='font-size: 2.5rem; margin-bottom: 0.5rem;'>{$config['icon']}</div>
                <div style='font-size: 2rem; font-weight: bold; color: {$config['color']}; margin-bottom: 0.25rem;'>$value</div>
                <div style='color: #6c757d; font-size: 0.9rem;'>{$config['title']}</div>
            </div>";
        }

        return "<div style='display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem;'>$cards</div>";
    }

    private function renderModuleStatusCards(array $module_status): string
    {
        $cards = '';

        foreach ($module_status as $module => $data) {
            $status_icon = $data['exists'] ? '✅' : '❌';
            $status_text = $data['exists'] ? 'Verfügbar' : 'Nicht gefunden';
            $status_color = $data['exists'] ? '#28a745' : '#dc3545';

            // Detaillierte Statistiken laden wenn Modul verfügbar
            $details_html = '';
            if ($data['exists']) {
                $detailed_stats = $this->getDetailedModuleStats($module);
                $details_html = $this->renderModuleDetails($module, $detailed_stats);
                $click_cursor = 'cursor: pointer;';
                $expand_icon = '<span style="color: #6c757d; font-size: 0.8rem;">▼ Details</span>';
            } else {
                $click_cursor = '';
                $expand_icon = '';
            }

            $cards .= "
            <div class='module-card' data-module='$module' style='background: white; border: 1px solid #e9ecef; border-radius: 0.5rem; overflow: hidden;'>
                <div class='module-header' style='padding: 1rem; display: flex; align-items: center; gap: 1rem; $click_cursor transition: background-color 0.15s;' " . ($data['exists'] ? "onclick='toggleModuleDetails(\"$module\")'" : "") . ">
                    <div style='font-size: 1.5rem;'>{$data['icon']}</div>
                    <div style='flex: 1;'>
                        <div style='font-weight: 500; color: #495057;'>{$data['title']}</div>
                        <div style='font-size: 0.85rem; color: #6c757d;'>{$data['class']}</div>
                    </div>
                    <div style='display: flex; align-items: center; gap: 0.5rem; color: $status_color; font-weight: 500;'>
                        <span>$status_icon</span>
                        <span>$status_text</span>
                    </div>
                    <div class='expand-icon' id='expand-$module'>$expand_icon</div>
                </div>
                
                <div class='module-details' id='details-$module' style='display: none; padding: 0 1rem 1rem 1rem; border-top: 1px solid #e9ecef; background: #f8f9fa;'>
                    $details_html
                </div>
            </div>";
        }

        $javascript = $this->getModuleDetailsJavaScript();

        return "<div style='display: grid; gap: 0.75rem;'>$cards</div>$javascript";
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
    private function renderModuleDetails(string $module, array $tests): string
    {
        if (empty($tests)) {
            return '<p style="color: #6c757d; font-style: italic; margin: 1rem 0;">Keine aktiven Tests gefunden.</p>';
        }

        $module_names = [
            'examcalc' => 'Taschenrechner',
            'examsymbols' => 'Sonderzeichen',
            'examguard' => 'Prüfungsschutz',
            'examalert' => 'Zeit-Warnung'
        ];

        $module_name = $module_names[$module] ?? ucfirst($module);
        $count = count($tests);

        $html = "
        <div style='margin: 1rem 0 0.5rem 0;'>
            <strong style='color: #495057;'>$count Tests verwenden $module_name:</strong>
        </div>
        
        <div style='max-height: 300px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 0.375rem; background: white;'>
            <table style='width: 100%; border-collapse: collapse;'>
                <thead style='background: #f8f9fa; border-bottom: 1px solid #dee2e6;'>
                    <tr>
                        <th style='padding: 0.5rem; text-align: left; font-size: 0.85rem; color: #495057; border-right: 1px solid #dee2e6;'>Test</th>
                        <th style='padding: 0.5rem; text-align: left; font-size: 0.85rem; color: #495057; border-right: 1px solid #dee2e6;'>Eigentümer</th>
                        <th style='padding: 0.5rem; text-align: left; font-size: 0.85rem; color: #495057;'>Erstellt</th>
                    </tr>
                </thead>
                <tbody>";

        foreach ($tests as $test) {
            $test_url = "ilias.php?ref_id={$test['ref_id']}&baseClass=ilRepositoryGUI";
            $test_title = htmlspecialchars($test['title']);
            $owner_name = htmlspecialchars($test['owner_name']);
            $owner_login = htmlspecialchars($test['owner_login']);
            $created = htmlspecialchars($test['created']);

            $html .= "
                <tr style='border-bottom: 1px solid #e9ecef;'>
                    <td style='padding: 0.5rem; border-right: 1px solid #e9ecef;'>
                        <a href='$test_url' target='_blank' style='color: #007bff; text-decoration: none; font-weight: 500;'>
                            $test_title
                        </a>
                        <div style='font-size: 0.75rem; color: #6c757d;'>ID: {$test['test_id']}</div>
                    </td>
                    <td style='padding: 0.5rem; border-right: 1px solid #e9ecef;'>
                        <div style='font-weight: 500; color: #495057;'>$owner_name</div>
                        <div style='font-size: 0.75rem; color: #6c757d;'>@$owner_login</div>
                    </td>
                    <td style='padding: 0.5rem;'>
                        <div style='font-size: 0.85rem; color: #495057;'>$created</div>
                    </td>
                </tr>";
        }

        $html .= "
                </tbody>
            </table>
        </div>";

        return $html;
    }

    /**
     * JavaScript für Module-Details Accordion
     */
    private function getModuleDetailsJavaScript(): string
    {
        return "
        <script>
        function toggleModuleDetails(module) {
            const detailsDiv = document.getElementById('details-' + module);
            const expandIcon = document.getElementById('expand-' + module);
            const header = detailsDiv.parentElement.querySelector('.module-header');
            
            if (detailsDiv.style.display === 'none' || detailsDiv.style.display === '') {
                // Öffnen
                detailsDiv.style.display = 'block';
                expandIcon.innerHTML = '<span style=\"color: #6c757d; font-size: 0.8rem;\">▲ Schließen</span>';
                header.style.backgroundColor = '#f8f9fa';
            } else {
                // Schließen
                detailsDiv.style.display = 'none';
                expandIcon.innerHTML = '<span style=\"color: #6c757d; font-size: 0.8rem;\">▼ Details</span>';
                header.style.backgroundColor = 'white';
            }
        }
        
        // Hover-Effekte für klickbare Karten
        document.addEventListener('DOMContentLoaded', function() {
            const headers = document.querySelectorAll('.module-header[onclick]');
            headers.forEach(header => {
                header.addEventListener('mouseenter', function() {
                    if (this.style.backgroundColor !== 'rgb(248, 249, 250)') {
                        this.style.backgroundColor = '#f8f9fa';
                    }
                });
                
                header.addEventListener('mouseleave', function() {
                    const detailsDiv = this.parentElement.querySelector('.module-details');
                    if (detailsDiv.style.display !== 'block') {
                        this.style.backgroundColor = 'white';
                    }
                });
            });
        });
        </script>
        
        <style>
        .module-card .module-header[onclick]:hover {
            background-color: #f8f9fa !important;
        }
        
        .module-details table a:hover {
            text-decoration: underline !important;
        }
        </style>";
    }

    private function renderCleanupSection(): string
    {
        $cleanup_url = $this->ctrl->getLinkTarget($this, 'cleanupOrphanedEntries');
        $preview_url = $this->ctrl->getLinkTarget($this, 'previewCleanup');

        return "
        <div style='background: #fff3cd; border: 1px solid #ffeeba; border-radius: 0.5rem; padding: 1.5rem;'>
            <div style='display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;'>
                <div style='font-size: 1.5rem;'>🧹</div>
                <div>
                    <div style='font-weight: 500; color: #856404;'>Verwaiste Einträge bereinigen</div>
                    <div style='font-size: 0.9rem; color: #856404;'>Entfernt Einstellungen von Tests, die gelöscht wurden.</div>
                </div>
            </div>
            
            <div style='display: flex; gap: 0.5rem;'>
                <button onclick=\"window.location.href='$preview_url'\" 
                        style='background: #6c757d; color: white; border: none; padding: 0.5rem 1rem; border-radius: 0.375rem; cursor: pointer; font-size: 0.9rem;'>
                    👁️ Vorschau
                </button>
                <button onclick=\"if(confirm('Wirklich verwaiste Einträge löschen?')) { window.location.href='$cleanup_url'; }\" 
                        style='background: #856404; color: white; border: none; padding: 0.5rem 1rem; border-radius: 0.375rem; cursor: pointer;'>
                    🗑️ Jetzt bereinigen
                </button>
            </div>
        </div>";
    }

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
        $form_action = $this->ctrl->getLinkTarget($this, 'saveExamChecker');

        // Aktuelle Einstellungen laden
        $praesenz_settings = $this->getExamCheckerSettings('praesenz');
        $fern_settings = $this->getExamCheckerSettings('fern');

        $praesenz_html = $this->renderCheckboxGroup('praesenz', $praesenz_settings);
        $fern_html = $this->renderCheckboxGroup('fern', $fern_settings);

        return "
        <div style='padding: 1.5rem;'>
            <div style='margin-bottom: 2rem; padding: 1rem; background: #e7f3ff; border: 1px solid #b3d9ff; border-radius: 0.5rem;'>
                <div style='display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;'>
                    <span style='font-size: 1.25rem;'>🔍</span>
                    <strong style='color: #0056b3;'>ExamChecker-Vorgaben konfigurieren</strong>
                </div>
                <p style='margin: 0; color: #0056b3; font-size: 0.9rem;'>
                    Bestimmen Sie, welche Einstellungen bei der Prüfung von Tests als verpflichtend gelten sollen.
                </p>
            </div>

            <form method='post' action='$form_action'>
                <div style='display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2rem;'>
                    
                    <div style='background: white; border: 1px solid #e9ecef; border-radius: 0.5rem; padding: 1.5rem;'>
                        <h3 style='color: #495057; margin: 0 0 1rem 0; display: flex; align-items: center; gap: 0.5rem;'>
                            <span>📍</span> Präsenzprüfungen
                        </h3>
                        <p style='color: #6c757d; font-size: 0.9rem; margin-bottom: 1.25rem;'>
                            Verpflichtende Checks für Prüfungen vor Ort:
                        </p>
                        $praesenz_html
                    </div>

                    <div style='background: white; border: 1px solid #e9ecef; border-radius: 0.5rem; padding: 1.5rem;'>
                        <h3 style='color: #495057; margin: 0 0 1rem 0; display: flex; align-items: center; gap: 0.5rem;'>
                            <span>🏠</span> Fernprüfungen
                        </h3>
                        <p style='color: #6c757d; font-size: 0.9rem; margin-bottom: 1.25rem;'>
                            Verpflichtende Checks für Online-Prüfungen:
                        </p>
                        $fern_html
                    </div>
                </div>

                <div style='text-align: right; padding-top: 1rem; border-top: 1px solid #e9ecef;'>
                    <button type='submit' style='background: #007bff; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 0.375rem; font-weight: 500; cursor: pointer;'>
                        💾 Vorgaben speichern
                    </button>
                </div>
            </form>
        </div>

        <style>
        button:hover {
            background: #0056b3 !important;
            transform: translateY(-1px);
            box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075);
        }
        </style>";
    }

    private function renderCheckboxGroup(string $exam_type, array $current_settings): string
    {
        $checkboxes = '';
        $check_definitions = $this->getCheckDefinitions();

        foreach ($check_definitions as $key => $definition) {
            // Prüfen ob dieser Check für den Prüfungstyp relevant ist
            $relevant_for_type = in_array($exam_type, $definition['relevant_for']);
            if (!$relevant_for_type) continue;

            $checked = in_array($key, $current_settings) ? 'checked' : '';
            $name = "checks_{$exam_type}[]";

            $checkboxes .= "
            <div style='margin-bottom: 0.75rem;'>
                <label style='display: flex; align-items: flex-start; gap: 0.5rem; cursor: pointer; padding: 0.5rem; border-radius: 0.25rem; transition: background-color 0.15s;' 
                       onmouseover='this.style.backgroundColor=\"#f8f9fa\"' 
                       onmouseout='this.style.backgroundColor=\"transparent\"'>
                    <input type='checkbox' name='$name' value='$key' $checked style='margin-top: 0.125rem; transform: scale(1.1);'>
                    <div>
                        <div style='font-weight: 500; color: #495057; font-size: 0.9rem;'>{$definition['title']}</div>
                        <div style='color: #6c757d; font-size: 0.8rem; margin-top: 0.25rem;'>{$definition['description']}</div>
                    </div>
                </label>
            </div>";
        }

        return $checkboxes ?: '<p style="color: #6c757d; font-style: italic;">Keine relevanten Checks für diesen Prüfungstyp.</p>';
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
        $back_url = $this->ctrl->getLinkTarget($this, 'showStatistics');
        $cleanup_url = $this->ctrl->getLinkTarget($this, 'cleanupOrphanedEntries');

        $total_count = array_sum(array_map('count', $orphaned_entries));

        if (empty($orphaned_entries)) {
            return "
            <div style='padding: 2rem;'>
                <div style='margin-bottom: 2rem;'>
                    <a href='$back_url' style='color: #007bff; text-decoration: none;'>← Zurück zu Statistiken</a>
                </div>
                
                <div style='text-align: center; padding: 3rem; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 0.5rem;'>
                    <div style='font-size: 3rem; margin-bottom: 1rem; color: #155724;'>✅</div>
                    <h3 style='color: #155724; margin-bottom: 1rem;'>Keine verwaisten Einträge</h3>
                    <p style='color: #155724; margin: 0;'>
                        Alle Plugin-Einstellungen gehören zu aktiven Tests.
                    </p>
                </div>
            </div>";
        }

        $table_names = [
            'examcalc_settings' => 'Taschenrechner',
            'examsymbols_settings' => 'Sonderzeichen',
            'examguard_settings' => 'Prüfungsschutz',
            'examalert_settings' => 'Zeit-Warnung'
        ];

        $details_html = '';
        foreach ($orphaned_entries as $table => $entries) {
            $table_name = $table_names[$table] ?? $table;
            $count = count($entries);

            $details_html .= "
            <div style='margin-bottom: 2rem; background: white; border: 1px solid #e9ecef; border-radius: 0.5rem; overflow: hidden;'>
                <div style='background: #f8d7da; padding: 1rem; border-bottom: 1px solid #f5c6cb;'>
                    <h4 style='margin: 0; color: #721c24;'>$table_name ($count Einträge)</h4>
                </div>
                <div style='max-height: 200px; overflow-y: auto;'>
                    <table style='width: 100%; border-collapse: collapse;'>
                        <thead style='background: #f8f9fa;'>
                            <tr>
                                <th style='padding: 0.5rem; text-align: left; border-bottom: 1px solid #dee2e6;'>Test-ID</th>
                                <th style='padding: 0.5rem; text-align: left; border-bottom: 1px solid #dee2e6;'>Test-Name</th>
                                <th style='padding: 0.5rem; text-align: left; border-bottom: 1px solid #dee2e6;'>Status</th>
                            </tr>
                        </thead>
                        <tbody>";

            foreach ($entries as $entry) {
                $status_color = $entry['status'] === 'Gelöscht' ? '#dc3545' : '#6c757d';
                $details_html .= "
                    <tr style='border-bottom: 1px solid #e9ecef;'>
                        <td style='padding: 0.5rem;'>{$entry['test_id']}</td>
                        <td style='padding: 0.5rem;'>" . htmlspecialchars($entry['title']) . "</td>
                        <td style='padding: 0.5rem; color: $status_color; font-weight: 500;'>{$entry['status']}</td>
                    </tr>";
            }

            $details_html .= "
                        </tbody>
                    </table>
                </div>
            </div>";
        }

        return "
        <div style='padding: 2rem;'>
            <div style='margin-bottom: 2rem;'>
                <a href='$back_url' style='color: #007bff; text-decoration: none;'>← Zurück zu Statistiken</a>
            </div>
            
            <div style='background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 0.5rem; padding: 1.5rem; margin-bottom: 2rem;'>
                <div style='display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;'>
                    <div style='font-size: 2rem; color: #721c24;'>⚠️</div>
                    <div>
                        <h3 style='margin: 0; color: #721c24;'>Bereinigungsvorschau</h3>
                        <p style='margin: 0.5rem 0 0 0; color: #721c24;'>
                            <strong>$total_count verwaiste Einträge</strong> werden beim Bereinigen entfernt.
                        </p>
                    </div>
                </div>
                
                <button onclick=\"if(confirm('$total_count Einträge werden unwiderruflich gelöscht. Fortfahren?')) { window.location.href='$cleanup_url'; }\" 
                        style='background: #dc3545; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 0.375rem; font-weight: 500; cursor: pointer;'>
                    🗑️ $total_count Einträge jetzt löschen
                </button>
            </div>
            
            $details_html
        </div>";
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

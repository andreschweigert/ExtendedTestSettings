<#1>
<?php
/**
 * SPDX-License-Identifier: AGPL-3.0-only
 * Original authors: André Schweigert and Cornel Musielak.
 * See LICENSE and NOTICE for license and attribution terms (AGPLv3, section 7(b)).
 */

/**
 * ExtendedTestSettings Plugin Database Installation
 * Creates tables for exam modules
 */
global $DIC;
$db = $DIC->database();

// ExamCalc Settings Table
if (!$db->tableExists('examcalc_settings')) {
    $fields = array(
        'test_id' => array(
            'type' => 'integer',
            'length' => 4,
            'notnull' => true
        ),
        'enabled' => array(
            'type' => 'integer',
            'length' => 1,
            'notnull' => true,
            'default' => 0
        ),
        'created_at' => array(
            'type' => 'timestamp',
            'notnull' => false
        ),
        'updated_at' => array(
            'type' => 'timestamp',
            'notnull' => false
        )
    );
    
    $db->createTable('examcalc_settings', $fields);
    $db->addPrimaryKey('examcalc_settings', array('test_id'));
}
?>
<#2>
<?php
global $DIC;
$db = $DIC->database();

// ExamGuard Settings Table
if (!$db->tableExists('examguard_settings')) {
    $fields = array(
        'test_id' => array(
            'type' => 'integer',
            'length' => 4,
            'notnull' => true
        ),
        'enabled' => array(
            'type' => 'integer',
            'length' => 1,
            'notnull' => true,
            'default' => 0
        ),
        'exit_prevention' => array(
            'type' => 'integer',
            'length' => 1,
            'notnull' => true,
            'default' => 1
        ),
        'created_at' => array(
            'type' => 'timestamp',
            'notnull' => false
        ),
        'updated_at' => array(
            'type' => 'timestamp',
            'notnull' => false
        )
    );
    
    $db->createTable('examguard_settings', $fields);
    $db->addPrimaryKey('examguard_settings', array('test_id'));
}
?>
<#3>
<?php
global $DIC;
$db = $DIC->database();

// ExamSymbols Settings Table
if (!$db->tableExists('examsymbols_settings')) {
    $fields = array(
        'test_id' => array(
            'type' => 'integer',
            'length' => 4,
            'notnull' => true
        ),
        'enabled' => array(
            'type' => 'integer',
            'length' => 1,
            'notnull' => true,
            'default' => 0
        ),
        'template' => array(
            'type' => 'text',
            'length' => 255,
            'notnull' => false,
            'default' => 'math'
        ),
        'created_at' => array(
            'type' => 'timestamp',
            'notnull' => false
        ),
        'updated_at' => array(
            'type' => 'timestamp',
            'notnull' => false
        )
    );
    
    $db->createTable('examsymbols_settings', $fields);
    $db->addPrimaryKey('examsymbols_settings', array('test_id'));
}
?>
<#4>
<?php
global $DIC;
$db = $DIC->database();

// ExamAlert Settings Table
if (!$db->tableExists('examalert_settings')) {
    $fields = array(
        'test_id' => array(
            'type' => 'integer',
            'length' => 4,
            'notnull' => true
        ),
        'enabled' => array(
            'type' => 'integer',
            'length' => 1,
            'notnull' => true,
            'default' => 0
        ),
        'alert_time' => array(
            'type' => 'integer',
            'length' => 4,
            'notnull' => true,
            'default' => 30
        ),
        'alert_message' => array(
            'type' => 'text',
            'length' => 1000,
            'notnull' => false,
            'default' => 'Achtung: Der Test endet bald!'
        ),
        'created_at' => array(
            'type' => 'timestamp',
            'notnull' => false
        ),
        'updated_at' => array(
            'type' => 'timestamp',
            'notnull' => false
        )
    );
    
    $db->createTable('examalert_settings', $fields);
    $db->addPrimaryKey('examalert_settings', array('test_id'));
}
?>
<#5>
<?php
global $DIC;
$db = $DIC->database();


if ($db->tableExists('examcalc_settings')) {
    if (!$db->tableColumnExists('examcalc_settings', 'calculator_mode')) {
        $db->addTableColumn('examcalc_settings', 'calculator_mode', array(
            'type' => 'text',
            'length' => 20,
            'notnull' => true,
            'default' => 'simple'
        ));
    }
}
?>
<#6>
<?php
global $DIC;
$db = $DIC->database();

// Tabelle für berechtigte Benutzer (Authorized Users)
if (!$db->tableExists('extendedtest_users')) {
    $fields = array(
        'user_id' => array(
            'type' => 'integer',
            'length' => 4,
            'notnull' => true
        ),
        'added_by' => array(
            'type' => 'integer',
            'length' => 4,
            'notnull' => true
        ),
        'added_at' => array(
            'type' => 'timestamp',
            'notnull' => true
        )
    );
    
    $db->createTable('extendedtest_users', $fields);
    $db->addPrimaryKey('extendedtest_users', array('user_id'));
    $db->addIndex('extendedtest_users', array('added_by'), 'i1');
}
?>
<#7>
<?php
global $DIC;
$db = $DIC->database();

if (!$db->tableExists('etest_hosts')) {
    $db->createTable('etest_hosts', array(
        'ip_addr' => array(
            'type' => 'text',
            'length' => 45,
            'notnull' => true,
            'default' => ''
        ),
        'hostname' => array(
            'type' => 'text',
            'length' => 255,
            'notnull' => true,
            'default' => ''
        ),
        'ili_nr' => array(
            'type' => 'text',
            'length' => 20,
            'notnull' => true,
            'default' => ''
        ),
        'koffer' => array(
            'type' => 'text',
            'length' => 20,
            'notnull' => true,
            'default' => ''
        ),
        'geraet_nr' => array(
            'type' => 'text',
            'length' => 20,
            'notnull' => true,
            'default' => ''
        ),
        'import_note' => array(
            'type' => 'text',
            'length' => 255,
            'notnull' => true,
            'default' => ''
        )
    ));
    $db->addPrimaryKey('etest_hosts', array('ip_addr'));
    $db->addIndex('etest_hosts', array('ili_nr'), 'h1');
    $db->addIndex('etest_hosts', array('koffer'), 'h2');
}
?>

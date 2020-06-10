<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * A scheduled task for scripted database integrations.
 *
 * @package    local_moduleorganise - template
 * @copyright  2016 ROelmann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moduleorganise\task;
use stdClass;

defined('MOODLE_INTERNAL') || die;
require_once($CFG->dirroot.'/local/extdb/classes/task/extdb.php');

/**
 * A scheduled task for scripted external database integrations.
 *
 * @copyright  2016 ROelmann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moduleorganise extends \core\task\scheduled_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('pluginname', 'local_moduleorganise');
    }

    /**
     * Run sync.
     */
    public function execute() {

        global $CFG, $DB;

        $externaldb = new \local_extdb\extdb();
        $name = $externaldb->get_name();

        $externaldbtype = $externaldb->get_config('dbtype');
        $externaldbhost = $externaldb->get_config('dbhost');
        $externaldbname = $externaldb->get_config('dbname');
        $externaldbencoding = $externaldb->get_config('dbencoding');
        $externaldbsetupsql = $externaldb->get_config('dbsetupsql');
        $externaldbsybasequoting = $externaldb->get_config('dbsybasequoting');
        $externaldbdebugdb = $externaldb->get_config('dbdebugdb');
        $externaldbuser = $externaldb->get_config('dbuser');
        $externaldbpassword = $externaldb->get_config('dbpass');
        $tablecat = get_string('remotetablecat', 'local_moduleorganise');
        $tablecrs = get_string('remotetablecrs', 'local_moduleorganise');

        // Database connection and setup checks.
        // Check connection and label Db/Table in cron output for debugging if required.
        if (!$externaldbtype) {
            echo 'Database not defined.<br>';
            return 0;
        } else {
            echo 'Database: ' . $externaldbtype . '<br>';
        }
        // Check remote assessments table - usr_data_assessments.
        if (!$tablecat) {
            echo 'Categories Table not defined.<br>';
            return 0;
        } else {
            echo 'Categories Table: ' . $tablecat . '<br>';
        }
        echo 'Starting connection...<br>';

        // Report connection error if occurs.
        if (!$extdb = $externaldb->db_init(
            $externaldbtype,
            $externaldbhost,
            $externaldbuser,
            $externaldbpassword,
            $externaldbname)) {
            echo 'Error while communicating with external database <br>';
            return 1;
        }

        // Get category page details to ensure consistency with structure.
        //
        // Read data from table.
        $sql = $externaldb->db_get_sql($tablecat, array(), array(), true);
        if ($rs = $extdb->Execute($sql)) {
            if (!$rs->EOF) {
                while ($fields = $rs->FetchRow()) {
                    $fields = array_change_key_case($fields, CASE_LOWER);
                    $fields = $externaldb->db_decode($fields);
                    $datacat = $fields; // Swap naming from template to meaningful/readable.

                    /* Get course record for each data category. */
                    $catidnumber = 'CRS-' . $datacat['category_idnumber'];
                    if ($DB->get_record('course',
                        array('idnumber' => $catidnumber)) &&
                        $datacat['category_idnumber'] !== '' ) {
                        $thiscourse = $DB->get_record('course',
                            array('idnumber' => $catidnumber));
                        /* Check any changes. */
                        $updated = 0;
                        // Check fullname.
                        if ($thiscourse->fullname !== $datacat['category_name']) {
                            $updated++;
                            $thiscourse->fullname = $datacat['category_name'];
                        }
                        // Check shortname.
                        if ($thiscourse->shortname !== $catidnumber) {
                            $updated++;
                            $thiscourse->shortname = $catidnumber;
                        }
                        // Get category id for the relevant category idnumber - this is what is needed in the table.
                        if ($DB->get_record('course_categories',
                            array('idnumber' => $datacat['category_idnumber'])) ) {
                            $category = $DB->get_record('course_categories',
                                array('idnumber' => $datacat['category_idnumber']));
                            // Check if category id has changed.
                            if ($thiscourse->category !== $category->id) {
                                $updated++;
                                $thiscourse->category = $category->id;
                            }
                        }
                        // Update course record - only if changes present.
                        if ($updated > 0 ) {
                            $DB->update_record('course', $thiscourse);
                        }
                    }
                }
            }
            $rs->Close();
        } else {
            // Report error if required.
            $extdb->Close();
            echo 'Error reading data from the external course table<br>';
            return 4;
        }

        // Get course page details to ensure consistency with SITS.
        // Read data from table.
        $sql = $externaldb->db_get_sql($tablecrs, array(), array(), true);
        if ($rs = $extdb->Execute($sql)) {
            if (!$rs->EOF) {
                while ($fields = $rs->FetchRow()) {
                    $fields = array_change_key_case($fields, CASE_LOWER);
                    $fields = $externaldb->db_decode($fields);
                    $datacourse = $fields; // Swap naming from template to meaningful/readable.

                    /* Get course record for each data course. */
                    if ($DB->get_record('course',
                        array('idnumber' => $datacourse['course_idnumber'])) &&
                        $datacourse['course_idnumber'] !== '' ) {
                        $thiscourse = $DB->get_record('course',
                            array('idnumber' => $datacourse['course_idnumber']));
                        /* Check any changes. */
                        $updated = 0;
                        // Check fullname.
                        echo $thiscourse->fullname. '  :  '. $datacourse['course_fullname']."\n";
                        if (strpos($datacourse['course_fullname'], 'Unknown semester') !== false) {
                            $sn = $datacourse['course_shortname'];
                            $snparts = explode('_', $sn);
                            $num = count($snparts);
                            echo $num."\n";
                            $pagenamecohort = '';
                            for ($i = 1; $i < $num - 1; $i++) {
                                $pagenamecohort .= $snparts[$i].'_';
                            }
                            $pagenamecohort = substr($pagenamecohort, 0, strlen($pagenamecohort) - 1);
                            echo $pagenamecohort."\n";
                            $datacourse['course_fullname'] = str_ireplace("Unknown semester",
                                $pagenamecohort, $datacourse['course_fullname']);
                        }
                        echo $datacourse['course_fullname']."\n";

                        if ($thiscourse->fullname !== $datacourse['course_fullname']) {
                            $updated++;
                            $thiscourse->fullname = $datacourse['course_fullname'];
                        }
                        echo $thiscourse->fullname."\n\n";
                        // Check shortname.
                        if ($thiscourse->shortname !== $datacourse['course_shortname']) {
                            $updated++;
                            $thiscourse->shortname = $datacourse['course_shortname'];
                        }
                        // Check startdate. Staff can bring it forward not delay it.
                        if ($thiscourse->startdate > $datacourse['course_startdate']) {
                            $updated++;
                            $thiscourse->startdate = $datacourse['course_startdate'];
                        }
                        // Get category id for the relevant category idnumber - this is what is needed in the table.
                        if ($DB->get_record('course_categories',
                            array('idnumber' => $datacourse['category_idnumber'])) ) {
                            $category = $DB->get_record('course_categories',
                                array('idnumber' => $datacourse['category_idnumber']));
                            // Check if category id has changed.
                            if ($thiscourse->category !== $category->id) {
                                $updated++;
                                $thiscourse->category = $category->id;
                            }
                        }
                        // Update course record - only if changes present.
                        echo 'Updated:'.$updated."\n";
                        if ($updated > 0 ) {
                            $DB->update_record('course', $thiscourse);
                        }
                    }
                }
            }
            $rs->Close();
        } else {
            // Report error if required.
            $extdb->Close();
            echo 'Error reading data from the external course table<br>';
            return 4;
        }

        // Free memory.
        $extdb->Close();

        /**************************************************************
         * Force setting for course_contacts block if not already set *
         * ************************************************************/
//        echo ' Setting course contact settings<br>';
//        $newconfig = 'Tzo4OiJzdGRDbGFzcyI6NDI6e3M6NToiZW1haWwiO3M6MToiMSI7czo3OiJtZXNzYWdlIjtzOjE6IjEiO3M6NToicGhvbmUiO3M6MToiMCI7czo2OiJzb3J0YnkiO3M6MToiMCI7czo3OiJpbmhlcml0IjtzOjE6IjAiO3M6Nzoicm9sZV8yNiI7czoxOiIwIjtzOjc6InJvbGVfMzQiO3M6MToiMCI7czo3OiJyb2xlXzMwIjtzOjE6IjEiO3M6Nzoicm9sZV8zMiI7czoxOiIxIjtzOjc6InJvbGVfNTMiO3M6MToiMCI7czo3OiJyb2xlXzUwIjtzOjE6IjAiO3M6Nzoicm9sZV80MCI7czoxOiIwIjtzOjc6InJvbGVfMzciO3M6MToiMCI7czo3OiJyb2xlXzQyIjtzOjE6IjAiO3M6Nzoicm9sZV80NCI7czoxOiIwIjtzOjc6InJvbGVfNDciO3M6MToiMCI7czo3OiJyb2xlXzE1IjtzOjE6IjAiO3M6Njoicm9sZV8zIjtzOjE6IjAiO3M6Njoicm9sZV85IjtzOjE6IjAiO3M6Nzoicm9sZV8xMiI7czoxOiIwIjtzOjc6InJvbGVfNTEiO3M6MToiMCI7czo3OiJyb2xlXzYzIjtzOjE6IjAiO3M6Nzoicm9sZV83NCI7czoxOiIwIjtzOjc6InJvbGVfNTUiO3M6MToiMCI7czoxMToiZGVzY3JpcHRpb24iO3M6MToiMCI7czoxMToidXNlX2FsdG5hbWUiO3M6MToiMCI7czo3OiJyb2xlXzc2IjtzOjE6IjAiO3M6Nzoicm9sZV85MCI7czoxOiIwIjtzOjc6InJvbGVfODgiO3M6MToiMCI7czo3OiJyb2xlXzg5IjtzOjE6IjAiO3M6Nzoicm9sZV84NyI7czoxOiIwIjtzOjc6InJvbGVfODIiO3M6MToiMCI7czo3OiJyb2xlXzc4IjtzOjE6IjAiO3M6Nzoicm9sZV84MSI7czoxOiIwIjtzOjc6InJvbGVfNzciO3M6MToiMCI7czo3OiJyb2xlXzkxIjtzOjE6IjAiO3M6Nzoicm9sZV85MiI7czoxOiIwIjtzOjc6InJvbGVfOTMiO3M6MToiMCI7czo3OiJyb2xlXzk1IjtzOjE6IjAiO3M6Nzoicm9sZV85NiI7czoxOiIwIjtzOjc6InJvbGVfOTciO3M6MToiMCI7czo3OiJyb2xlXzk4IjtzOjE6IjAiO30=';
//        $sqlcontacts = "UPDATE {block_instances} SET configdata = '" . $newconfig . "'";
        // Set config where currently empty.
//        $DB->execute($sqlcontacts, array('blockname' => 'course_contacts', 'configdata' => ''));
        // Set config where currently old value.
//        $DB->execute($sqlcontacts, array('blockname' => 'course_contacts', 'configdata' => 'Tzo4OiJzdGRDbGFzcyI6MjQ6e3M6NToiZW1haWwiO3M6MToiMSI7czo3OiJtZXNzYWdlIjtzOjE6IjEiO3M6NToicGhvbmUiO3M6MToiMCI7czo2OiJzb3J0YnkiO3M6MToiMCI7czo3OiJpbmhlcml0IjtzOjE6IjAiO3M6Nzoicm9sZV8yNiI7czoxOiIwIjtzOjc6InJvbGVfMzQiO3M6MToiMCI7czo3OiJyb2xlXzMwIjtzOjE6IjEiO3M6Nzoicm9sZV8zMiI7czoxOiIwIjtzOjc6InJvbGVfNTMiO3M6MToiMCI7czo3OiJyb2xlXzUwIjtzOjE6IjAiO3M6Nzoicm9sZV80MCI7czoxOiIwIjtzOjc6InJvbGVfMzciO3M6MToiMCI7czo3OiJyb2xlXzQyIjtzOjE6IjAiO3M6Nzoicm9sZV80NCI7czoxOiIwIjtzOjc6InJvbGVfNDciO3M6MToiMCI7czo3OiJyb2xlXzE1IjtzOjE6IjAiO3M6Njoicm9sZV8zIjtzOjE6IjEiO3M6Njoicm9sZV85IjtzOjE6IjAiO3M6Nzoicm9sZV8xMiI7czoxOiIwIjtzOjc6InJvbGVfNTEiO3M6MToiMCI7czo3OiJyb2xlXzYzIjtzOjE6IjAiO3M6Nzoicm9sZV83NCI7czoxOiIwIjtzOjc6InJvbGVfNTUiO3M6MToiMCI7fQ=='));
    }

}

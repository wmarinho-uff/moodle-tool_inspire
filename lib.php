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
 * This page lists public api for tool_monitor plugin.
 *
 * @package    tool_inspire
 * @copyright  2017 David Monllao {@link http://www.davidmonllao.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

/**
 * This function extends the navigation with the tool items
 *
 * @param navigation_node $navigation The navigation node to extend
 * @param stdClass        $course     The course to object for the tool
 * @param context         $context    The context of the course
 */
function tool_inspire_extend_navigation_course($navigation, $course, $context) {
    global $DB;

    if (has_capability('tool/inspire:listinsights', $context)) {

        $cache = \cache::make('tool_inspire', 'modelswithpredictions');
        $modelids = $cache->get($context->id);
        if ($modelids === false) {
            // Fill the cache.
            $models = \tool_inspire\manager::get_all_models(true, true, $context);
            $modelids = array_keys($models);
            $cache->set($context->id, $modelids);
        }

        if (!empty($modelids)) {
            $url = new moodle_url('/admin/tool/inspire/insights.php', array('contextid' => $context->id));
            $settingsnode = navigation_node::create(get_string('insights', 'tool_inspire'), $url, navigation_node::TYPE_SETTING,
                null, null, new pix_icon('i/settings', ''));
            $reportnode = $navigation->get('coursereports');
            if (isset($settingsnode) && !empty($reportnode)) {
                $reportnode->add_node($settingsnode);
            }
        }
    }
}

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

namespace qbehaviour_queued_for_aitext;

/**
 * Helper used by qtype_aitext to decide whether to select this behaviour.
 *
 * Lives under classes/ so it is autoloaded only when the plugin is installed,
 * which lets qtype_aitext test for it with class_exists().
 *
 * @package    qbehaviour_queued_for_aitext
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {
    /**
     * Whether new attempts at deferred AI Text questions should be graded by a background task.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return (bool) get_config('qbehaviour_queued_for_aitext', 'enabled');
    }
}

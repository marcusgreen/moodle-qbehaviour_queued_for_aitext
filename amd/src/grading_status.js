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
 * Show asynchronous AI grading progress and reload the page when it has finished.
 *
 * @module     qbehaviour_queued_for_aitext/grading_status
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {getString} from 'core/str';
import {add as addToast} from 'core/toast';

/** @var {number} Milliseconds between status checks. */
const POLL_INTERVAL = 10000;

/** @var {number} Milliseconds to show the "grading finished" toast before reloading. */
const RELOAD_DELAY = 3000;

/** @var {number} Stop polling after this long, in case cron is not running. */
const MAX_POLL_TIME = 30 * 60 * 1000;

/** @var {Set<number>} Usages already being watched, as the renderer calls init once per question. */
const watched = new Set();

/**
 * Fetch the grading status for a usage.
 *
 * @param {number} qubaid
 * @returns {Promise<{total: number, pending: number}>}
 */
const fetchStatus = (qubaid) => Ajax.call([{
    methodname: 'qbehaviour_queued_for_aitext_get_grading_status',
    args: {qubaid},
}])[0];

/**
 * Create the progress banner above the first question.
 *
 * @returns {HTMLElement|null}
 */
const createBanner = () => {
    const firstquestion = document.querySelector('.que');
    if (!firstquestion) {
        return null;
    }
    const banner = document.createElement('div');
    banner.className = 'alert alert-info qbehaviour_queued_for_aitext-status';
    banner.setAttribute('role', 'status');
    banner.setAttribute('aria-live', 'polite');
    firstquestion.parentNode.insertBefore(banner, firstquestion);
    return banner;
};

/**
 * Update the banner text.
 *
 * @param {HTMLElement} banner
 * @param {{total: number, pending: number}} status
 * @param {boolean} notify whether the student will be notified when grading has finished.
 */
const showStatus = async(banner, status, notify) => {
    let text = await getString('aigradingprogress', 'qbehaviour_queued_for_aitext', {
        graded: status.total - status.pending,
        total: status.total,
    });
    if (notify) {
        text += ' ' + await getString('aigradingnotify', 'qbehaviour_queued_for_aitext');
    }
    banner.textContent = text;
};

/**
 * Start watching a usage.
 *
 * @param {number} qubaid the question usage id.
 * @param {boolean} notify whether the student will be notified when grading has finished.
 */
export const init = (qubaid, notify = false) => {
    if (watched.has(qubaid)) {
        return;
    }
    watched.add(qubaid);

    const banner = createBanner();
    const started = Date.now();

    const poll = async() => {
        let status;
        try {
            status = await fetchStatus(qubaid);
        } catch (e) {
            // Stop quietly. The per-question notices still tell the student to come back later.
            return;
        }
        if (status.pending === 0) {
            if (banner) {
                banner.className = 'alert alert-success qbehaviour_queued_for_aitext-status';
                banner.textContent = await getString('aigradingfinished', 'qbehaviour_queued_for_aitext');
            }
            await addToast(await getString('aigradingtoast', 'qbehaviour_queued_for_aitext'), {type: 'success'});
            // Leave the toast up long enough to read before the page reloads with the grades.
            setTimeout(() => window.location.reload(), RELOAD_DELAY);
            return;
        }
        if (banner) {
            await showStatus(banner, status, notify);
        }
        if (Date.now() - started < MAX_POLL_TIME) {
            setTimeout(poll, POLL_INTERVAL);
        }
    };

    poll();
};

<?php

/**
 *
 * PHP version 8.2+
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999-2026 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
 */

declare(strict_types=1);

namespace AA\Mail;

use AA\IO\DB\DB_AA;
use AA_Logentry;
use AA_Logentry_Em;

/** An earlier bulk email of a slice with the same subject and body to the same group.
 *  Once queued, a bulk email cannot be recalled, so admin/write_mail.php asks before sending
 *  such a copy - the EMAIL_SENT log row it writes is the record of the send.
 */
final class RepeatedBulkMail
{
    /** The latest such send since $since, or null.
     *  @param string $module_id  the slice that sends (long id)
     *  @param string $user       who sends now - a stored search is a position in the list of its user,
     *                            so a numeric $group compares only with the sends of the same user
     *  @param string $group      recipients as the EMAIL_SENT selector names them (stored search, AA_ALL, LIST, ...)
     *  @return array{time: int, users: int}|null
     */
    public static function lastSend(string $module_id, string $user, string $group, string $subject, string $body, int $since): ?array
    {
        $where = [['type', 'EMAIL_SENT'], ['time', $since, '>='], ['selector', $group]];
        if (ctype_digit($group)) {
            $where[] = ['user', $user];
        }
        $sends = [];
        foreach (DB_AA::select([], 'SELECT time, params FROM log', $where, ['time-']) as $row) {
            $entry = AA_Logentry::factoryByString((string) $row['params']);
            if (($entry instanceof AA_Logentry_Em) && ctype_digit($entry->mail_id) && ((int) $entry->users > 0)) {
                $sends[(int) $entry->mail_id] = ['time' => (int) $row['time'], 'users' => (int) $entry->users];
            }
        }
        if (!$sends) {
            return null;
        }
        // the subject as the column collation compares it (ignoring case is fine here); the body exactly
        $mails = DB_AA::select(['id' => 'body'], 'SELECT id, subject, body FROM email', [['id', array_keys($sends), 'i'], ['owner_module_id', $module_id, 'l'], ['subject', $subject]]);
        foreach ($sends as $mail_id => $send) {
            if (isset($mails[$mail_id]) && ((string) $mails[$mail_id] === $body)) {
                return $send;
            }
        }
        return null;
    }
}

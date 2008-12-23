<?php
/*
 * Laconica - a distributed open-source microblogging tool
 * Copyright (C) 2008, Controlez-Vous, Inc.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

if (!defined('LACONICA')) { exit(1); }

class SupAction extends Action {
    
    function handle($args)
    {
        
        parent::handle($args);
        
        $seconds = $this->trimmed('seconds');
        
        if (!$seconds) {
            $seconds = 15;
        }

        $updates = $this->get_updates($seconds);
        
        header('Content-Type: application/json; charset=utf-8');
        
        print json_encode(array('updated_time' => date('c'),
                                'since_time' => date('c', time() - $seconds),
                                'available_periods' => $this->available_periods(),
                                'period' => $seconds,
                                'updates' => $updates));
    }
    
    function available_periods()
    {
        static $periods = array(86400, 43200, 21600, 7200,
                                3600, 1800,    600, 300, 120,
                                60, 30, 15); 
        $available = array();
        foreach ($periods as $period) {
            $available[$period] = common_local_url('sup',
                                                   array('seconds' => $period));
        }
        
        return $available;
    }
    
    function get_updates($seconds)
    {
        $notice = new Notice();

        # XXX: cache this. Depends on how big this protocol becomes;
        # Re-doing this query every 15 seconds isn't the end of the world.

        $notice->query('SELECT profile_id, max(id) AS max_id ' .
                       'FROM notice ' .
                       'WHERE created > (now() - ' . $seconds . ') ' .
                       'GROUP BY profile_id');
        
        $updates = array();
        
        while ($notice->fetch()) {
            $updates[] = array($notice->profile_id, $notice->max_id);
        }
        
        return $updates;
    }
    
    function is_readonly()
    {
        return true;
    }
}
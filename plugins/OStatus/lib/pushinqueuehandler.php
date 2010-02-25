<?php
/*
 * StatusNet - the distributed open-source microblogging tool
 * Copyright (C) 2010, StatusNet, Inc.
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

/**
 * Process a feed distribution POST from a PuSH hub.
 * @package FeedSub
 * @author Brion Vibber <brion@status.net>
 */

class PushInQueueHandler extends QueueHandler
{
    function transport()
    {
        return 'pushin';
    }

    function handle($data)
    {
        assert(is_array($data));

        $feedsub_id = $data['feedsub_id'];
        $post = $data['post'];
        $hmac = $data['hmac'];

        $feedsub = FeedSub::staticGet('id', $feedsub_id);
        if ($feedsub) {
            $feedsub->receive($post, $hmac);
        } else {
            common_log(LOG_ERR, "Discarding POST to unknown feed subscription id $feedsub_id");
        }
        return true;
    }
}

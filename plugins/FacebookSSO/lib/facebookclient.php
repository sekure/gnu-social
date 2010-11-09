<?php
/**
 * StatusNet, the distributed open-source microblogging tool
 *
 * Class for communicating with Facebook
 *
 * PHP version 5
 *
 * LICENCE: This program is free software: you can redistribute it and/or modify
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
 *
 * @category  Plugin
 * @package   StatusNet
 * @author    Craig Andrews <candrews@integralblue.com>
 * @author    Zach Copley <zach@status.net>
 * @copyright 2009-2010 StatusNet, Inc.
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link      http://status.net/
 */

if (!defined('STATUSNET')) {
    exit(1);
}

/**
 * Class for communication with Facebook
 *
 * @category Plugin
 * @package  StatusNet
 * @author   Zach Copley <zach@status.net>
 * @license  http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link     http://status.net/
 */
class Facebookclient
{
    protected $facebook      = null; // Facebook Graph client obj
    protected $flink         = null; // Foreign_link StatusNet -> Facebook
    protected $notice        = null; // The user's notice
    protected $user          = null; // Sender of the notice

    function __construct($notice)
    {
        $this->facebook = self::getFacebook();
        $this->notice   = $notice;

        $this->flink = Foreign_link::getByUserID(
            $notice->profile_id,
            FACEBOOK_SERVICE
        );

        $this->user = $this->flink->getUser();
    }

    /*
     * Get an instance of the Facebook Graph SDK object
     *
     * @param string $appId     Application
     * @param string $secret    Facebook API secret
     *
     * @return Facebook A Facebook SDK obj
     */
    static function getFacebook($appId = null, $secret = null)
    {
        // Check defaults and configuration for application ID and secret
        if (empty($appId)) {
            $appId = common_config('facebook', 'appid');
        }

        if (empty($secret)) {
            $secret = common_config('facebook', 'secret');
        }

        // If there's no app ID and secret set in the local config, look
        // for a global one
        if (empty($appId) || empty($secret)) {
            $appId  = common_config('facebook', 'global_appid');
            $secret = common_config('facebook', 'global_secret');
        }

        return new Facebook(
            array(
               'appId'  => $appId,
               'secret' => $secret,
               'cookie' => true
            )
        );
    }

    /*
     * Broadcast a notice to Facebook
     *
     * @param Notice $notice    the notice to send
     */
    static function facebookBroadcastNotice($notice)
    {
        common_debug('Facebook broadcast');
        $client = new Facebookclient($notice);
        return $client->sendNotice();
    }

    /*
     * Should the notice go to Facebook?
     */
    function isFacebookBound() {

        if (empty($this->flink)) {
            common_log(
                LOG_WARN,
                sprintf(
                    "No Foreign_link to Facebook for the author of notice %d.",
                    $this->notice->id
                ),
                __FILE__
            );
            return false;
        }

        // Avoid a loop
        if ($this->notice->source == 'Facebook') {
            common_log(
                LOG_INFO,
                sprintf(
                    'Skipping notice %d because its source is Facebook.',
                    $this->notice->id
                ),
                __FILE__
            );
            return false;
        }

        // If the user does not want to broadcast to Facebook, move along
        if (!($this->flink->noticesync & FOREIGN_NOTICE_SEND == FOREIGN_NOTICE_SEND)) {
            common_log(
                LOG_INFO,
                sprintf(
                    'Skipping notice %d because user has FOREIGN_NOTICE_SEND bit off.',
                    $this->notice->id
                ),
                __FILE__
            );
            return false;
        }

        // If it's not a reply, or if the user WANTS to send @-replies,
        // then, yeah, it can go to Facebook.
        if (!preg_match('/@[a-zA-Z0-9_]{1,15}\b/u', $this->notice->content) ||
            ($this->flink->noticesync & FOREIGN_NOTICE_SEND_REPLY)) {
            return true;
        }

        return false;
    }

    /*
     * Determine whether we should send this notice using the Graph API or the
     * old REST API and then dispatch
     */
    function sendNotice()
    {
        // If there's nothing in the credentials field try to send via
        // the Old Rest API

        if ($this->isFacebookBound()) {
            common_debug("notice is facebook bound", __FILE__);
            if (empty($this->flink->credentials)) {
                $this->sendOldRest();
            } else {

                // Otherwise we most likely have an access token
                $this->sendGraph();
            }

        } else {
            common_debug(
                sprintf(
                    "Skipping notice %d - not bound for Facebook",
                    $this->notice->id,
                    __FILE__
                )
            );
        }
    }

    /*
     * Send a notice to Facebook using the Graph API
     */
    function sendGraph()
    {
        try {

            $fbuid = $this->flink->foreign_id;

            common_debug(
                sprintf(
                    "Attempting use Graph API to post notice %d as a stream item for %s (%d), fbuid %s",
                    $this->notice->id,
                    $this->user->nickname,
                    $this->user->id,
                    $fbuid
                ),
                __FILE__
            );

            $params = array(
                'access_token' => $this->flink->credentials,
                'message'      => $this->notice->content
            );

            $attachments = $this->notice->attachments();

            if (!empty($attachments)) {

                // We can only send one attachment with the Graph API

                $first = array_shift($attachments);

                if (substr($first->mimetype, 0, 6) == 'image/'
                    || in_array(
                        $first->mimetype,
                        array('application/x-shockwave-flash', 'audio/mpeg' ))) {

                   $params['picture'] = $first->url;
                   $params['caption'] = 'Click for full size';
                   $params['source']  = $first->url;
                }

            }

            $result = $this->facebook->api(
                sprintf('/%s/feed', $fbuid), 'post', $params
            );

        } catch (FacebookApiException $e) {
            return $this->handleFacebookError($e);
        }

        return true;
    }

    /*
     * Send a notice to Facebook using the deprecated Old REST API. We need this
     * for backwards compatibility. Users who signed up for Facebook bridging
     * using the old Facebook Canvas application do not have an OAuth 2.0
     * access token.
     */
    function sendOldRest()
    {
        try {

            $canPublish = $this->checkPermission('publish_stream');
            $canUpdate  = $this->checkPermission('status_update');

            // We prefer to use stream.publish, because it can handle
            // attachments and returns the ID of the published item

            if ($canPublish == 1) {
                $this->restPublishStream();
            } else if ($canUpdate == 1) {
                // as a last resort we can just update the user's "status"
                $this->restStatusUpdate();
            } else {

                $msg = 'Not sending notice %d to Facebook because user %s '
                     . '(%d), fbuid %s,  does not have \'status_update\' '
                     . 'or \'publish_stream\' permission.';

                common_log(
                    LOG_WARNING,
                    sprintf(
                        $msg,
                        $this->notice->id,
                        $this->user->nickname,
                        $this->user->id,
                        $this->flink->foreign_id
                    ),
                    __FILE__
                );
            }

        } catch (FacebookApiException $e) {
            return $this->handleFacebookError($e);
        }

        return true;
    }

    /*
     * Query Facebook to to see if a user has permission
     *
     *
     *
     * @param $permission the permission to check for - must be either
     *                    public_stream or status_update
     *
     * @return boolean result
     */
    function checkPermission($permission)
    {
        if (!in_array($permission, array('publish_stream', 'status_update'))) {
             throw new ServerException("No such permission!");
        }

        $fbuid = $this->flink->foreign_id;

        common_debug(
            sprintf(
                'Checking for %s permission for user %s (%d), fbuid %s',
                $permission,
                $this->user->nickname,
                $this->user->id,
                $fbuid
            ),
            __FILE__
        );

        $hasPermission = $this->facebook->api(
            array(
                'method'   => 'users.hasAppPermission',
                'ext_perm' => $permission,
                'uid'      => $fbuid
            )
        );

        if ($hasPermission == 1) {

            common_debug(
                sprintf(
                    '%s (%d), fbuid %s has %s permission',
                    $permission,
                    $this->user->nickname,
                    $this->user->id,
                    $fbuid
                ),
                __FILE__
            );

            return true;

        } else {

            $logMsg = '%s (%d), fbuid $fbuid does NOT have %s permission.'
                    . 'Facebook returned: %s';

            common_debug(
                sprintf(
                    $logMsg,
                    $this->user->nickname,
                    $this->user->id,
                    $permission,
                    $fbuid,
                    var_export($result, true)
                ),
                __FILE__
            );

            return false;

        }
    }

    /*
     * Handle a Facebook API Exception
     *
     * @param FacebookApiException $e the exception
     *
     */
    function handleFacebookError($e)
    {
        $fbuid  = $this->flink->foreign_id;
        $errmsg = $e->getMessage();
        $code   = $e->getCode();

        // The Facebook PHP SDK seems to always set the code attribute
        // of the Exception to 0; they put the real error code in
        // the message. Gar!
        if ($code == 0) {
            preg_match('/^\(#(?<code>\d+)\)/', $errmsg, $matches);
            $code = $matches['code'];
        }

        // XXX: Check for any others?
        switch($code) {
         case 100: // Invalid parameter
            $msg = 'Facebook claims notice %d was posted with an invalid '
                 . 'parameter (error code 100 - %s) Notice details: '
                 . '[nickname=%s, user id=%d, fbuid=%d, content="%s"]. '
                 . 'Dequeing.';
            common_log(
                LOG_ERR, sprintf(
                    $msg,
                    $this->notice->id,
                    $errmsg,
                    $this->user->nickname,
                    $this->user->id,
                    $fbuid,
                    $this->notice->content
                ),
                __FILE__
            );
            return true;
            break;
         case 200: // Permissions error
         case 250: // Updating status requires the extended permission status_update
            $this->disconnect();
            return true; // dequeue
            break;
         case 341: // Feed action request limit reached
                $msg = '%s (userid=%d, fbuid=%d) has exceeded his/her limit '
                     . 'for posting notices to Facebook today. Dequeuing '
                     . 'notice %d';
                common_log(
                    LOG_INFO, sprintf(
                        $msg,
                        $user->nickname,
                        $user->id,
                        $fbuid,
                        $this->notice->id
                    ),
                    __FILE__
                );
            // @fixme: We want to rety at a later time when the throttling has expired
            // instead of just giving up.
            return true;
            break;
         default:
            $msg = 'Facebook returned an error we don\'t know how to deal with '
                 . 'when posting notice %d. Error code: %d, error message: "%s"'
                 . ' Notice details: [nickname=%s, user id=%d, fbuid=%d, '
                 . 'notice content="%s"]. Dequeing.';
            common_log(
                LOG_ERR, sprintf(
                    $msg,
                    $this->notice->id,
                    $code,
                    $errmsg,
                    $this->user->nickname,
                    $this->user->id,
                    $fbuid,
                    $this->notice->content
                ),
                __FILE__
            );
            return true; // dequeue
            break;
        }
    }

    /*
     * Publish a notice to Facebook as a status update
     *
     * This is the least preferable way to send a notice to Facebook because
     * it doesn't support attachments and the API method doesn't return
     * the ID of the post on Facebook.
     *
     */
    function restStatusUpdate()
    {
        $fbuid = $this->flink->foreign_id;

        common_debug(
            sprintf(
                "Attempting to post notice %d as a status update for %s (%d), fbuid %s",
                $this->notice->id,
                $this->user->nickname,
                $this->user->id,
                $fbuid
            ),
            __FILE__
        );

        $result = $this->facebook->api(
            array(
                'method'               => 'users.setStatus',
                'status'               => $this->notice->content,
                'status_includes_verb' => true,
                'uid'                  => $fbuid
            )
        );

        common_log(
            LOG_INFO,
            sprintf(
                "Posted notice %s as a status update for %s (%d), fbuid %s",
                $this->notice->id,
                $this->user->nickname,
                $this->user->id,
                $fbuid
            ),
            __FILE__
        );

    }

    /*
     * Publish a notice to a Facebook user's stream using the old REST API
     */
    function restPublishStream()
    {
        $fbuid = $this->flink->foreign_id;

        common_debug(
            sprintf(
                'Attempting to post notice %d as stream item for %s (%d) fbuid %s',
                $this->notice->id,
                $this->user->nickname,
                $this->user->id,
                $fbuid
            ),
            __FILE__
        );

        $fbattachment = $this->formatAttachments();

        $result = $this->facebook->api(
            array(
                'method'     => 'stream.publish',
                'message'    => $this->notice->content,
                'attachment' => $fbattachment,
                'uid'        => $fbuid
            )
        );

        common_log(
            LOG_INFO,
            sprintf(
                'Posted notice %d as a %s for %s (%d), fbuid %s',
                $this->notice->id,
                empty($fbattachment) ? 'stream item' : 'stream item with attachment',
                $this->user->nickname,
                $this->user->id,
                $fbuid
            ),
            __FILE__
        );

    }

    /*
     * Format attachments for the old REST API stream.publish method
     *
     * Note: Old REST API supports multiple attachments per post
     *
     */
    function formatAttachments()
    {
        $attachments = $this->notice->attachments();

        $fbattachment          = array();
        $fbattachment['media'] = array();

        foreach($attachments as $attachment)
        {
            if($enclosure = $attachment->getEnclosure()){
                $fbmedia = $this->getFacebookMedia($enclosure);
            }else{
                $fbmedia = $this->getFacebookMedia($attachment);
            }
            if($fbmedia){
                $fbattachment['media'][]=$fbmedia;
            }else{
                $fbattachment['name'] = ($attachment->title ?
                                      $attachment->title : $attachment->url);
                $fbattachment['href'] = $attachment->url;
            }
        }
        if(count($fbattachment['media'])>0){
            unset($fbattachment['name']);
            unset($fbattachment['href']);
        }
        return $fbattachment;
    }

    /**
     * given a File objects, returns an associative array suitable for Facebook media
     */
    function getFacebookMedia($attachment)
    {
        $fbmedia    = array();

        if (strncmp($attachment->mimetype, 'image/', strlen('image/')) == 0) {
            $fbmedia['type']         = 'image';
            $fbmedia['src']          = $attachment->url;
            $fbmedia['href']         = $attachment->url;
        } else if ($attachment->mimetype == 'audio/mpeg') {
            $fbmedia['type']         = 'mp3';
            $fbmedia['src']          = $attachment->url;
        }else if ($attachment->mimetype == 'application/x-shockwave-flash') {
            $fbmedia['type']         = 'flash';

            // http://wiki.developers.facebook.com/index.php/Attachment_%28Streams%29
            // says that imgsrc is required... but we have no value to put in it
            // $fbmedia['imgsrc']='';

            $fbmedia['swfsrc']       = $attachment->url;
        }else{
            return false;
        }
        return $fbmedia;
    }

    /*
     * Disconnect a user from Facebook by deleting his Foreign_link.
     * Notifies the user his account has been disconnected by email.
     */
    function disconnect()
    {
        $fbuid = $this->flink->foreign_id;

        common_log(
            LOG_INFO,
            sprintf(
                'Removing Facebook link for %s (%d), fbuid %s',
                $this->user->nickname,
                $this->user->id,
                $fbuid
            ),
            __FILE__
        );

        $result = $this->flink->delete();

        if (empty($result)) {
            common_log(
                LOG_ERR,
                sprintf(
                    'Could not remove Facebook link for %s (%d), fbuid %s',
                    $this->user->nickname,
                    $this->user->id,
                    $fbuid
                ),
                __FILE__
            );
            common_log_db_error($flink, 'DELETE', __FILE__);
        }

        // Notify the user that we are removing their Facebook link

        $result = $this->mailFacebookDisconnect();

        if (!$result) {

            $msg = 'Unable to send email to notify %s (%d), fbuid %s '
                 . 'about his/her Facebook link being removed.';

            common_log(
                LOG_WARNING,
                sprintf(
                    $msg,
                    $this->user->nickname,
                    $this->user->id,
                    $fbuid
                ),
                __FILE__
            );
        }
    }

    /**
     * Send a mail message to notify a user that her Facebook link
     * has been terminated.
     *
     * @return boolean success flag
     */
    function mailFacebookDisconnect()
    {
        $profile = $user->getProfile();

        $siteName = common_config('site', 'name');

        common_switch_locale($user->language);

        $subject = sprintf(
            _m('Your Facebook connection has been removed'),
            $siteName
        );

        $msg = <<<BODY
Hi, %1$s. We're sorry to inform you we are unable to publish your notice to
Facebook, and have removed the connection between your %2$s account and Facebook.

This may have happened because you have removed permission for %2$s to post on
your behalf, or perhaps you have deactivated your Facebook account. You can
reconnect your %s account to Facebook at any time by logging in with Facebook
again.
BODY;
        $body = sprintf(
            _m($msg),
            $this->user->nickname,
            $siteName
        );

        common_switch_locale();

        return mail_to_user($this->user, $subject, $body);
    }

}

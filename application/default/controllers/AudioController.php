<?php

/*
 *   Members page. Used to renew subscription.
 *
 *
 *     Author: Alex Scott
 *      Email: alex@cgi-central.net
 *        Web: http://www.cgi-central.net
 *    Details: Member display page
 *    FileName $RCSfile$
 *    Release: 5.5.4 ($Revision: 5371 $)
 *
 * Please direct bug reports,suggestions or feedback to the cgi-central forums.
 * http://www.cgi-central.net/forum/
 *
 * aMember PRO is a commercial software. Any distribution is strictly prohibited.
 *
 */

include_once 'MediaController.php';

class AudioController extends MediaController
{
    protected $type = 'audio';
    function getFlowplayerParams(ResourceAbstractFile $media)
    {
        $config = $this->getPlayerConfig($media);
        $view = new Am_View;
        $params = array(
            'key' => $this->getDi()->config->get('flowplayer_license'),
            'height' => 30,
            'width' => 500,
            'plugins' => array(
                'controls' => array(
                    'fullscreen' => false,
                    'height' => 30,
                    'autoHide' => false
                ),
                'audio' => array(
                    'url' => $view->_scriptJs("flowplayer/flowplayer.audio.swf"),
                )
            ),
            'clip' => array(
                'autoPlay' => (isset($config['autoPlay']) && $config['autoPlay']) ? true : false,
                'provider' => 'audio'
            )
        );

        return $params;
    }

    public function getJWPlayerParams(ResourceAbstractFile $media)
    {
        return array(
            'key' => $this->getDi()->config->get('jwplayer_license'),
            'height' => 30,
            'width' => 500
        );

    }


}
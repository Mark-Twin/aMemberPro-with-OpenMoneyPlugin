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

class VideoController extends MediaController
{
    protected $type = 'video';

    function getFlowplayerParams(ResourceAbstractFile $media)
    {
        $config = $this->getPlayerConfig($media);

        if ($media->poster_id || !empty($config['poster_id']))
            $config['autoPlay'] = true;

        $params = array (
            'key' => $this->getDi()->config->get('flowplayer_license'),
            'height' => @$config['height'],
            'width' => @$config['width'],
            'clip' => array(
                    'autoPlay' => (isset($config['autoPlay']) && $config['autoPlay']) ? true : false,
                    'autoBuffering' => (isset($config['autoBuffering']) && $config['autoBuffering']) ? true : false,
                    'bufferLength' => isset($config['bufferLength']) ? $config['bufferLength'] : 3,
                    'scaling' => isset($config['scaling']) ? $config['scaling'] : 'scale'
                )
        );
        $position_map = array(
            'top-right' => array('top' => 20, 'right' => 20),
            'top-left' => array('top' => 20, 'left' => 20),
            'bottom-right' => array('bottom' => 20, 'right' => 20),
            'bottom-left' => array('bottom' => 20, 'left' => 20),
        );
        if (!empty($config['logo_id'])) {
            $logo = $this->getDi()->uploadTable->load($config['logo_id'], false);
            $logo_url = $logo ? $this->getDi()->url('upload/get/' . preg_replace('/^\./', '', $logo->path),false) : '';
            $params['logo'] = array_merge(array(
                'url' => $logo_url,
                'fullscreenOnly' => false,
            ), $position_map[!empty($config['logo_position']) ? $config['logo_position'] : 'top-right']);
        }

        if ($media->cc_id) {
            $cc = $this->getDi()->uploadTable->load($media->cc_id, false);
            $cc_url = $this->getDi()->url('upload/get/' . preg_replace('/^\./', '', $cc->path),false);
            $cc_postion = !empty($config['cc_position']) ? $config['cc_position'] : 'top';

            //switch position of button depending of subtitles position
            //(we need opposite position to avoid messy)
            $cc_button_token = $cc_postion == 'top' ? 'bottom' : 'top';
            $params['plugins']['captions'] = array(
                'url' => "flowplayer.captions.swf",
                'captionTarget' => 'content',
                'button' => array(
                    $cc_button_token => 30
                )
            );
            $params['clip']['captionUrl'] = $cc_url;
            $params['plugins']['content']['url'] = "flowplayer.content.swf";

            $params['plugins']['content'][$cc_postion] = 30;
        }
        return $params;
    }

    public function getJWPlayerParams(ResourceAbstractFile $media)
    {
        $config = $this->getPlayerConfig($media);
        if ($media->poster_id || !empty($config['poster_id']))
            $config['autoPlay'] = true;

        switch(@$config['scaling']){
            case 'orig' :   $stretching = 'none'; break;
            case 'fit'  :   $stretching = 'uniform'; break;
            case 'scale' : $stretching = 'exactfit'; break;
            default: $stretching = 'uniform';

        }

        $params = array(
            'key' => $this->getDi()->config->get('jwplayer_license'),
            'height' => @$config['height'],
            'width' => @$config['width'],
            'autostart' => @$config['autoPlay'],
            'stretching' => $stretching
        );

        if (!empty($config['logo_id'])) {
            $logo = $this->getDi()->uploadTable->load($config['logo_id'], false);
            $logo_url = $logo ? $this->getDi()->url('upload/get/' . preg_replace('/^\./', '', $logo->path),false) : '';
            $params['logo'] = array(
                'file' => $logo_url,
                'postion' => !empty($config['logo_position']) ? $config['logo_position'] : 'top-right'
            );
        }
        return $params;
    }
}
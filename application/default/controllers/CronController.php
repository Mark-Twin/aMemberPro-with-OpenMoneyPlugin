<?php 
/** 
*
*     Author: Alex Scott
*      Email: alex@cgi-central.net
*        Web: http://www.cgi-central.net
*    Details: Cron run file
*    FileName $RCSfile$
*    Release: 5.5.4 ($Revision$)
*
* Please direct bug reports,suggestions or feedback to the cgi-central forums.
* http://www.cgi-central.net/forum/
*                                                                          
* aMember PRO is a commercial software. Any distribution is strictly prohibited.
*                                                                                 
*/

class CronController extends Am_Mvc_Controller
{
    public function init()
    {
        parent::init();
        set_time_limit(0);
        ignore_user_abort(true);
        ini_set('memory_limit', AM_HEAVY_MEMORY_LIMIT);
    }

    function indexAction()
    {
        $this->getDi()->session->writeClose();
        Am_Cron::checkCron();
    }
}
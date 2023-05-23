<?php
/**
*  aMember Pro Config File
*
*     Author: Alex Scott
*      Email: alex@cgi-central.net
*        Web: http://www.amember.com/
*    FileName $RCSfile$
*    Release: 5.5.4 ($Revision$)
*
* Please direct bug reports,suggestions or feedback to the cgi-central forums.
* http://www.cgi-central.net/forums
*
* aMember PRO is a commercial software. Any distribution is strictly prohibited.
*/


return array(
    'db' => array(
        'mysql' => array(
            'db'    => '@DB_MYSQL_DB@',
            'user'  => '@DB_MYSQL_USER@',
            'pass'  => '@DB_MYSQL_PASS@',
            'host'  => '@DB_MYSQL_HOST@',
            'prefix' => '@DB_MYSQL_PREFIX@',
            'port'  => '@DB_MYSQL_PORT@',
        ),
    ),
);

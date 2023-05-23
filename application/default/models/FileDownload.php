<?php

/**
 * Class represents records from table file_download
 * @property int $download_id
 * @property int $file_id
 * @property int $user_id
 * @property datetime $dattm
 * @see Am_Table
 */
class FileDownload extends Am_Record
{

}

class FileDownloadTable extends Am_Table
{
    const PERIOD_HOUR = 3600;
    const PERIOD_DAY = 86400;
    const PERIOD_WEEK = 634800;
    const PERIOD_MONTH = 2592000;
    const PERIOD_YEAR = 31536000;
    const PERIOD_ALL = -1;
    const TOLERANCE = 60; //seconds

    protected $_key = 'download_id';
    protected $_table = '?_file_download';
    protected $_recordClass = 'FileDownload';

    function checkLimits(User $user, File $file)
    {
        if (!$file->download_limit)
            return true; //no limits at all

        list($limit, $period) = explode(':', $file->download_limit);
        $cond = array(
            'user_id' => $user->pk(),
            'file_id' => $file->pk()
        );
        if ($period != self::PERIOD_ALL)
        {
            $begin_date = $this->getDi()->dateTime;
            $begin_date->modify('-' . $period . ' seconds');
            $begin_date = $begin_date->format('Y-m-d H:i:s');
            $cond[] = array('dattm', '>', $begin_date);
        }
        $count = $this->countBy($cond);

        return $limit > $count;
    }

    function logDownload(User $user, File $file, $ip = '')
    {
        $begin_date = $this->getDi()->dateTime;
        $begin_date->modify('-' . self::TOLERANCE . ' seconds');
        $begin_date = $begin_date->format('Y-m-d H:i:s');
        $count = $this->countBy(array(
                'user_id' => $user->pk(),
                'file_id' => $file->pk(),
                array('dattm', '>', $begin_date)
            ));

        if (!$count)
        {
            $this->insert(array(
                'user_id' => $user->pk(),
                'file_id' => $file->pk(),
                'dattm' => $this->getDi()->sqlDateTime,
                'remote_addr' => $ip
            ));
        }
    }

    function selectLast($num, $dateThreshold = null)
    {
        return $this->selectObjects("SELECT t.dattm, f.title, f.file_id, t.user_id,
            u.login, u.email, CONCAT(u.name_f, ' ', u.name_l) AS name, u.name_f, u.name_l
            FROM ?_file_download t
            LEFT JOIN ?_user u ON t.user_id = u.user_id
            LEFT JOIN ?_file f ON t.file_id = f.file_id
            {WHERE t.dattm > ?}
            ORDER BY t.dattm DESC LIMIT ?d",
            $dateThreshold ?: DBSIMPLE_SKIP, $num);
    }
}
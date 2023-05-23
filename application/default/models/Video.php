<?php
/**
 * Class represents records from table files
 * "path" field may contain numeric id - from the uploads table
 * {autogenerated}
 * @property int $video_id 
 * @property string $title 
 * @property string $desc 
 * @property string $path 
 * @property int $size 
 * @property string $display_type 
 * @property datetime $dattm 
 * @property bool $hide 
 * @see Am_Table
 */
class Video extends ResourceAbstractFile {
    public function getUrl()
    {
        $type = $this->mime == 'audio/mpeg' ? 'audio' : 'video';
        return $this->getDi()->url("$type/p/id/" . $this->video_id,null,false);
    }
}

class VideoTable extends ResourceAbstractTable {
    protected $_key = 'video_id';
    protected $_table = '?_video';
    protected $_recordClass = 'Video';

    public function getAccessType()
    {
        return ResourceAccess::VIDEO;
    }
    public function getAccessTitle()
    {
        return ___('Video');
    }
    public function getPageId()
    {
        return 'video';
    }
}

<?php

class_exists('Am_Form', true);

/**
 * Storage plugins manager and common functions
 */
class Am_Plugins_Storage extends Am_Plugins
{
    public function splitPath($path)
    {
        if (ctype_digit((string)$path))
            return array('upload', $path, array());
        @list($id, $path) = explode('::', $path, 2);
        $id = filterId($id);
        @list($path, $query) = explode('?', $path, 2);
        if (strlen($query))
        {
            parse_str($query, $q);
            $query = $q;
        }
        return array($id, $path, $query);
    }

    /**
     * @param string $path storage file path
     * @param null|Am_Storage[] if specified choose file from specified plugins only
     * @return Am_Storage_File|null */
    function getFile($path, array $selectedPlugins = null)
    {
        list($id, $path) = $this->splitPath($path);
        if ($selectedPlugins === null)
        {
            $pl = $this->loadGet($id);
        } else {
            $pl = null;
            foreach ($selectedPlugins as $p)
                if ($p->getId() == $id)
                {
                    $pl = $p; break;
                }
        }
        if (!$pl) return null;
        return $pl->get($path);
    }
}

/**
 * Abstract file storage
 * @package Am_Storage
 */
abstract class Am_Storage extends Am_Plugin
{
    protected $_idPrefix = 'Am_Storage_';
    protected $prefix;
    protected $_configPrefix = 'storage.';

    public function setPrefix($prefix)
    {
        $this->prefix = $prefix;
    }
    /**
     * @return Am_Storage_File|null
     * @throws Am_Exception
     */
    abstract function get($path);

    /** @return Am_Storage_File[] by given path and prefix*/
    abstract function getItems($path, array & $actions);

    /** @return string */
    abstract function getDescription();

    /** @return time-limited access url for $file */
    public function getUrl(Am_Storage_File $file, $exptime, $force_download = true) {}

    /**
     * @param Am_Storage_Item|string $itemOrPath
     * @return string|null return local filepath if possible
     */
    function getLocalPath($itemOrPath)
    {
    }

    /**
     * strip last folder from the path
     * /test/dir1/ -> /test/
     * /test/dir1  -> /test
     * /dir1/      -> /dir1/
     * /dir1       -> /dir1
     * @param string $path
     */
    public function parentPath($path)
    {
        $x = preg_split('|/|', $path, null, PREG_SPLIT_DELIM_CAPTURE);
        foreach (array_reverse(array_keys($x)) as $i)
        {
            if (strlen($x[$i])) {
                unset($x[$i]);
                break;
            }
        }
        return implode('/', $x);
    }

    function assertFileInsideFolder($file, $folder)
    {
        // skip realpath for unit testing
        if (!((strpos($file, 'vfs://')===0) && (strpos($folder, 'vfs://')===0)))
        {
            $file = realpath($file);
            $folder = realpath($folder);
        }
        if (!$file)
            throw new Am_Exception_InternalError("File does not exists in " . __METHOD__);
        if (!$folder)
            throw new Am_Exception_InternalError("File does not exists in " . __METHOD__);
        if (strpos($file, $folder)!==0)
            throw new Am_Exception_InternalError("File [$file] is not inside folder [$folder] in " . __METHOD__);
    }

    public function action(array $query, $path, $url, Am_Mvc_Request $request, Am_Mvc_Response $response)
    {
    }

    public function getPath($path)
    {
        return $this->getId() . '::' . $path;
    }

    public function isLocal()
    {
        return true;
    }
}

/**
 * Storage item to display
 * @package Am_Storage
 */
abstract class Am_Storage_Item
{
    protected $name, $path, $description;

    function getName() { return $this->name; }

    /** @return string path inside given storage relative to storage root */
    function getPath() { return $this->path; }

    function getDescription() { return $this->description; }

    static public function _cmpName(Am_Storage_Item $a, Am_Storage_Item $b)
    {
        if (($a instanceof Am_Storage_Folder) && !($b instanceof Am_Storage_Folder))
            return -1;
        if (($b instanceof Am_Storage_Folder) && !($a instanceof Am_Storage_Folder))
            return 1;
        return strcmp($a->getName(), $b->getName());
    }
}

class Am_Storage_File extends Am_Storage_Item
{
    protected $storage;
    protected $name, $size, $path, $mime = "", $description = "";

    public function __construct(Am_Storage $storage, $name, $size, $path, $mime, $description)
    {
        $this->storage = $storage;
        $this->name = $name;
        $this->size = $size;
        $this->path = $path;
        $this->mime = $mime;
        $this->description = $description;
    }

    public function info($secure = false)
    {
        return array(
            'name' => $this->getName(),
            'size_readable' => Am_Storage_File::getSizeReadable($this->getSize()),
            'upload_id' => $secure ? Am_Form_Element_Upload::signValue($this->storage->getPath($this->getPath())) : $this->storage->getPath($this->getPath()),
            'mime' => $this->getMime(),
            'ok' => true,
        );
    }

    /** @return string protected expiration url */
    public function getUrl($exptime = null, $force_download = true)
    {
        return $this->storage->getUrl($this, $exptime, $force_download);
    }

    /** @return resource */
    public function getStream()
    {
        return $this->storage->getStream($this);
    }

    /** @return int size in bytes */
    public function getSize()
    {
        return $this->size;
    }

    public function getMime()
    {
        return $this->mime;
    }

    public function getStorageId()
    {
        return $this->storage->getId();
    }

    public function getLocalPath() { return $this->storage->getLocalPath($this); }

    static public function getSizeReadable($bytes)
    {
        $size = $bytes;
        $units = explode(' ','B KB MB GB TB PB');
        for ($i = 0; $size > 1024; $i++) {
            $size /= 1024;
        }
        return round($size, 2) . ' ' . $units[$i];
    }

    static public function getSizeBytes($size)
    {
        $bytes = $size;
        if (preg_match('/^(\d+)(.)$/', $size, $matches)) {
            switch ($matches[2]) {
                case 'G':
                case 'g':
                    $bytes = $matches[1] * 1024 * 1024 * 1024;
                    break;
                case 'M':
                case 'm':
                    $bytes = $matches[1] * 1024 * 1024;
                    break;
                case 'K':
                case 'k':
                    $bytes = $matches[1] * 1024;
                    break;
            }
        }
        return $bytes;
    }
}

class Am_Storage_Folder extends Am_Storage_Item
{
    protected $storage;
    protected $name;

    public function __construct(Am_Storage $storage, $name, $path)
    {
        $this->storage = $storage;
        $this->name = $name;
        $this->path = $path;
    }
}

class Am_Storage_Action_Upload extends Am_Storage_Item
{
    protected $name = 'Upload';

    public function __construct(Am_Storage $storage, $path, $html)
    {
        $this->path = $path;
        $this->html = $html;
    }
    function render()
    {
        return $this->html;
    }
}

class Am_Storage_Action_CreateFolder extends Am_Storage_Item
{
    protected $name = 'Create Folder';
}

class Am_Storage_Action_Refresh extends Am_Storage_Item
{
    protected $name = 'Refresh';
}

class Am_Storage_Action_DeleteFile extends Am_Storage_Item
{
    protected $name = 'Delete';
}

////////////// Storage classes ////////////////////////////////////////

/**
 * Serve files uploaded via FTP to amember/data/upload/ folder
 */
class Am_Storage_Disk extends Am_Storage
{
    protected $root;

    public function init()
    {
        parent::init();
        $this->root = Am_Di::getInstance()->upload_dir_disk;
        if (file_exists($this->root))
            $this->root = realpath($this->root);
    }
    public function setRoot($dir)
    {
        $this->root = (string)$dir;
    }

    function getDescription()
    {
        return !file_exists($this->root) ?
            ___("Error : upload folder [%s] does not exists", $this->root) :
            ___("Upload files using FTP client to [%s]", $this->root);
    }

    /**
     * @return string normalized relPath
     */
    public function relPath($absPath)
    {
        $ret = str_replace($this->root, '', $absPath);
        return $ret;
    }

    public function absPath($relPath)
    {
        return realpath($this->root . $relPath);
    }

    public function checkPath($path)
    {
        if (strpos($path, $this->root)!==0)
        {
            $path = Am_Html::escape($path);
            $root = Am_Html::escape($this->root);
            throw new Am_Exception_Security("[$path] requested is not inside [$root] root path");
        }
    }

    public function getItems($path, array &$actions)
    {
        $items = array();
        $path = $this->getDi()->security->filterFilename($path, true);

        $it = new DirectoryIterator($this->root . '/' . $path);

        if ($path && count($it)) // path specified and exists
        {
            $items[] = new Am_Storage_Folder($this, '..',
                    $this->parentPath($path));
        }

        foreach ($it as $r)
        {
            if ($r->isDot()) continue;
            if ($r->isDir() && $r->isReadable())
                $items[] = new Am_Storage_Folder($this, $r->getFilename(),
                    trim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $it->getFilename() // path without prefix
                    );
        }
        $it = new DirectoryIterator($this->root . '/' . $path);
        foreach ($it as $r)
        {
            if ($r->isDot()) continue;
            if ($r->isFile() && $r->isReadable())
                $items[] = new Am_Storage_File($this, $r->getFilename(), $r->getSize(),
                    trim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $it->getFilename(), // path without prefix
                    Upload::getMimeType($r->getFilename()), null);
        }

        usort($items, array('Am_Storage_Item', '_cmpName'));
        return $items;
    }

    public function get($path)
    {
        $path = $this->getDi()->security->filterFilename($path, true);

        $fn = $this->getLocalPath($path);
        $this->assertFileInsideFolder($fn, $this->root);
        if (!file_exists($fn))
            throw new Am_Exception_InternalError("File does not exists [disk::$path]");

        return new Am_Storage_File($this, basename($path),
                filesize($fn), $path, null, null);
    }

    public function getLocalPath($itemOrPath)
    {
        $path = $itemOrPath;
        if ($path instanceof Am_Storage_Item)
            $path = $path->getPath();
        $fn = $this->root . DIRECTORY_SEPARATOR. $path;
        return $fn;
    }
}

/**
 * Serve files upload via aMember web interface
 */
class Am_Storage_Upload extends Am_Storage
{
    protected $root = null;

    public function init()
    {
        $this->root = $this->getDi()->upload_dir;
    }

    public function setRoot($dir)
    {
        $this->root = (string)$dir;
    }

    protected function getQuery()
    {
        $q = new Am_Query($this->getDi()->uploadTable);
        $q->addOrder('name');
        if ($this->prefix)
            $q->addWhere('prefix=?', $this->prefix);
        return $q;
    }

    public function getDescription()
    {
        return ___("Files uploaded via aMember web interface");
    }

    public function getItems($path, array & $actions)
    {
        $actions[] = new Am_Storage_Action_DeleteFile($this, '');

        $q = $this->getQuery();
        $st = $q->query();
        $db = $this->getDi()->db;
        $items = array();
        while ($r = $db->fetchRow($st))
        {
            $u = $this->getDi()->uploadTable->createRecord($r);
            /* @var $u Upload */
            $items[] = new Am_Storage_File($this, $u->getName(),
                $u->getSize(), $u->pk(),
                $u->mime, $u->desc);
        }
        return $items;
    }

    public function get($path)
    {
        $upload = $this->getDi()->uploadTable->load((int)$path);
        $ret = new Am_Storage_File($this, $upload->name, $upload->getSize(),
            $upload->pk(), $upload->mime, $upload->desc);
        $ret->_localFn = $upload->getFullPath();
        $this->assertFileInsideFolder($ret->_localFn, $this->root);
        return $ret;
    }

    public function getLocalPath($itemOrPath)
    {
        if ($itemOrPath instanceof Am_Storage_Item) {
            return $itemOrPath->_localFn;
        } else {
            $upload = $this->getDi()->uploadTable->load((int)$path);
            return $upload->getFullPath();
        }
    }

    public function getPath($path)
    {
        return $path;
    }

    protected function renderWarning($usage, $url)
    {
        $str = '';
        foreach ($usage as $msg) {
            if ($msg['link']) {
                $str .= sprintf('<li><a href="%s" target="_blank">%s</a></li>',
                        $this->getDi()->surl($msg['link']), Am_Html::escape($msg['title']));
            } else {
                $str .= sprintf('<li>%s</li>',
                    Am_Html::escape($msg['title']));
            }
        }

        $url = Am_Html::escape($url);
        $s = ___('Unable to delete this file as it is used for:');
        return <<<CUT
<div class="info">
<p>$s</p>
<ul class="list">
   $str
</ul>
</div>
<p><a id="delete-back-link" href="$url">Back To Grid</a></p>
<script type="text/javascript">
jQuery(document)
.off('click',"#delete-back-link")
.on('click',"#delete-back-link", function(){
        jQuery(this).closest('.filesmanager-container').load(jQuery(this).attr('href'));
        return false;
})
</script>
CUT;
    }

    public function action(array $query, $path, $url, Am_Mvc_Request $request, Am_Mvc_Response $response)
    {
        switch ($query['action'])
        {
            case 'delete-file':
                $upload = $this->getDi()->uploadTable->load($query['path']);
                $usage = $this->getDi()->uploadTable->findUsage($upload);
                if (!empty($usage)) {
                    $response->setBody($this->renderWarning($usage, $url));
                } else {
                    $upload->delete();
                    $response->setRedirect($url);
                }
                break;
            default:
                throw new Am_Exception_InputError('unknown action!');
        }
    }
}

//////////////////////////// UI Elements //////////////////////////////////////

/**
 * Render grid to select storage files
 * @package Am_Storage
 */
class Am_Storage_Grid
{
    /** @var Am_Storage */
    protected $storage;
    /** @var Am_Mvc_Request */
    protected $request;
    /** @var Am_Mvc_Response */
    protected $response;
    /** @var Am_Storage[] plugins to select */
    protected $plugins = array();
    protected $secure = false;

    function __construct(Am_Storage $storage, Am_Mvc_Request $request, Am_Mvc_Response $response, array $plugins)
    {
        $this->storage = $storage;
        $this->request = $request;
        $this->response = $response;
        $this->plugins = $plugins;
    }

    function render($path, Am_View $view)
    {
        $urlPath = $this->request->get('path', 'upload::');

        $list = array();
        foreach ($this->plugins as $pl)
        {
            $o = new stdclass;
            $o->title = $pl->getTitle();
            $o->link = $this->getUrl($pl->getPath(null));
            $list[$pl->getId()] = $o;
        }
        $view->plugins = $list;
        $view->description = $this->storage->getDescription();
        $view->active_plugin = $this->storage->getId();
        $view->path = $path;
        $view->currentUrl = $this->getUrl($path);

        $items = $actions = array();
        foreach ($this->storage->getItems($path, $actions) as $item)
        {
            switch (true)
            {
                case $item instanceof Am_Storage_File:
                    $item->_data_info = $item->info($this->secure);
                    $item->_link = $this->getUrl($this->storage->getPath($item->getPath()));
                    $items[] = $item;
                    break;
                case $item instanceof Am_Storage_Folder:
                    $item->_link = $this->getUrl($this->storage->getPath($item->getPath()));
                    $items[] = $item;
                    break;
            }
        }
        foreach ($actions as $item)
        {
            switch (true)
            {
                case $item instanceof Am_Storage_Action_Upload:
                    $item->_link = $this->getUrl($urlPath . '?action=upload');
                    $view->upload = $item;
                    break;
                case $item instanceof Am_Storage_Action_CreateFolder:
                    $item->_link = $this->getUrl($urlPath . '?action=create-folder');
                    $view->createfolder = $item;
                    break;
                case $item instanceof Am_Storage_Action_Refresh:
                    $item->_link = $this->getUrl($urlPath . '?action=refresh');
                    $view->refresh = $item;
                    break;
                case $item instanceof Am_Storage_Action_DeleteFile:
                    $item->_link = $this->getUrl($urlPath . '?action=delete-file&path=__PATH__');
                    $view->deletefile = $item;
                    break;
                default:
                    $actions[] = $item;
            }
        }
        $view->actions = $actions;
        $view->items = $items;

        $output = $view->render('admin/_storage-grid.phtml');
        $this->response->appendBody($output);
    }

    public function action(array $query, $path, Am_View $view)
    {
        $this->storage->action($query, $path, $this->getUrl($this->storage->getId() . '::' . $path), $this->request, $this->response);
    }

    public function getUrl($path)
    {
        $get = $this->request->getQuery();
        $get['path'] = $path;
        return Am_Di::getInstance()->url('admin-upload/grid', $get, false);
    }

    public function setSecure($secure)
    {
        $this->secure = $secure;
    }
}
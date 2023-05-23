<?php
/*
*
*
*     Author: Alex Scott
*      Email: alex@cgi-central.net
*        Web: http://www.cgi-central.net
*    Details: Admin Access log
*    FileName $RCSfile$
*    Release: 5.5.4 ($Revision: 4961 $)
*
* Please direct bug reports,suggestions or feedback to the cgi-central forums.
* http://www.cgi-central.net/forum/
*
* aMember PRO is a commercial software. Any distribution is strictly prohibited.
*
*/

class AdminDirbrowserController extends Am_Mvc_Controller
{
    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission("grid_content");
    }
    /*
     * Does not allow to list directories upper than CHROOT
     * list CHROOT dir if tried to list dir outside of CHROOT
     * should be without trailing slash
     *
     * @example /home/user/htdocs
     */
    protected $chroot;

    public function init()
    {
        parent::init();
        try {
            $options = $this->getDi()->getParameter('dirbrowser');
            if (!empty($options['chroot'])) {
                $this->chroot = $options['chroot'];
            }
        } catch (Exception $e) {}
    }

    public function indexAction()
    {
        $dirOrig = $this->getRequest()->getParam('dir', $this->getDi()->root_dir);
        $dirOrig = is_dir($dirOrig) ? $dirOrig : $this->getDi()->root_dir;

        $selected = $this->getRequest()->getParam('selected', false);

        $dir = ($selected) ? dirname($dirOrig) : $dirOrig;
        $dir = realpath($dir);

        if (!is_dir($dir)) {
            $dir = $this->getDi()->root_dir;
        }

        if (!$this->checkChRoot($dir)) {
            $dir = $this->chroot;
        }

        $dirList = $this->getDirList($dir);

        if ($selected) {
            foreach ($dirList as $k => $dirDescription) {
                if ($dirDescription['path'] == $dirOrig) {
                    $dirList[$k]['selected'] = true;
                    break;
                }
            }
        }

        $currentDir = $this->getCurrentDir($dir);
        $prevDir = $this->getPrevDir($dir);

        $result = array(
            'dirList' => $dirList,
            'currentDir' => $currentDir,
            'prevDir' => $prevDir,
            'separator' => DIRECTORY_SEPARATOR
        );

        echo json_encode($result);
    }

    protected function checkChRoot($dir)
    {
        if (!is_null($this->chroot) &&
            strpos($dir, $this->chroot)!==0) {
            return false;
        } else {
            return true;
        }
    }

    protected function getCurrentDir($dir)
    {
        $result = array();
        $dirParts = explode(DIRECTORY_SEPARATOR, $dir);

        $path = array();
        foreach ($dirParts as $part) {
            $path[]= $part;

            $part_path = implode(DIRECTORY_SEPARATOR, $path);
            $dir = array (
                'name' => $part,
                'path' => ($this->checkChRoot($part_path) ? $part_path : null )
            );
           $result[] = $dir;
        }

        return $result;
    }

    protected function getPrevDir($dir)
    {
        $prevDir = null;

        $prevDirPath = dirname($dir);

        //root of file system
        if ($prevDirPath == $dir) return null;

        $dirParts = explode(DIRECTORY_SEPARATOR, $prevDirPath);

        $prevDirName = end($dirParts);

        if (is_dir( $prevDirPath ) ) {
            $prevDir = array (
                'name' => $prevDirName,
                'path' => ($this->checkChRoot($prevDirPath) ? $prevDirPath : null)
            );
        }

        return $prevDir;
    }

    protected function getDirList($dir)
    {
        $result = array();
        $dirName = $dir;

        $dirHandler = opendir($dirName);
        while(false !== ($fn = readdir($dirHandler))) {
            if (is_dir($dirName . DIRECTORY_SEPARATOR . $fn) &&
                    !in_array($fn, array('..', '.'))) {

                $result[] = $this->getDirRecord($dirName, $fn);
            }
        }
        closedir($dirHandler);
        usort($result, function($a, $b) {return strcmp($a["name"], $b["name"]);});

        return $result;
    }

    protected function getDirRecord($dirName, $fn)
    {
        $stat = stat($dirName . DIRECTORY_SEPARATOR . $fn);

        return array(
            'name' => $fn,
            'path' => $dirName . DIRECTORY_SEPARATOR . $fn,
            'url' => $this->guessUrl($dirName . DIRECTORY_SEPARATOR . $fn),
            'perm' => $this->formatPermissions($stat['mode']),
            'created' => $this->formatDate($stat['ctime']),
            'selected' => false
        );
    }

    public function guessUrl($dir)
    {
        $documentRootFixed = str_replace('/', DIRECTORY_SEPARATOR, $_SERVER['DOCUMENT_ROOT']);
        //FirePHP::getInstance(true)->log($documentRootFixed , 'DOCUMENT ROOT');
        //FirePHP::getInstance(true)->log($dir , 'dir');
        //check if it is possible to calculate url
        if (strpos($dir, $documentRootFixed) !== 0) return false;

        $rootUrlMeta = parse_url(ROOT_URL);

        //combine url
        return sprintf('%s://%s%s/%s',
            $rootUrlMeta['scheme'],
            $rootUrlMeta['host'],
            (isset($rootUrlMeta['port']) ? ':' . $rootUrlMeta['port'] : ''),
            trim(str_replace(DIRECTORY_SEPARATOR, '/', str_replace($documentRootFixed, '', $dir)), '/'));

    }

    protected function formatPermissions($p)
    {
        $res = '';
        $res .= ($p & 256) ? 'r' : '-';
        $res .= ($p & 128) ? 'w' : '-';
        $res .= ($p & 64) ?  'x' : '-';
        $res .= ' ';
        $res .= ($p & 32) ?  'r' : '-';
        $res .= ($p & 16) ?  'w' : '-';
        $res .= ($p & 8) ?   'x' : '-';
        $res .= ' ';
        $res .= ($p & 4) ?   'r' : '-';
        $res .= ($p & 2) ?   'w' : '-';
        $res .= ($p & 1) ?   'x' : '-';
        return $res;
    }

    protected function formatDate($tm)
    {
        return amDate($tm);
    }
}

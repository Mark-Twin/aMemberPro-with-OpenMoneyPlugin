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


class FileController extends Am_Mvc_Controller {

    const CD_INLINE = 'inline';
    const CD_ATTACHMENT = 'attachment';

    public function downloadAction()
    {
       return $this->_getFile(self::CD_ATTACHMENT);
    }

    public function getAction()
    {
       return $this->_getFile(self::CD_INLINE);
    }

    protected function _getFile($attachment)
    {
        $path = $this->getParam('path');
        if ($path[0] != '.') {$path = '.' . $path;}
        $file = $this->getDi()->uploadTable->findFirstByPath($path);
        if (!$file) {
            throw new Am_Exception_InputError('File Not Found');
        }
        if (!$this->getDi()->uploadAcl->checkPermission($file,
                    Am_Upload_Acl::ACCESS_READ,
                    $this->getDi()->auth->getUser())) {

            throw new Am_Exception_AccessDenied;
        }
        return $this->pushFile($file, $attachment);
    }

    protected function pushFile(Upload $file, $attachment=self::CD_INLINE)
    {
        $this->_helper->sendFile($file->getFullPath(), $file->getType(), array(
            'disposition' => $attachment,
            'filename' => $file->getName(),
            'cache' => array(
                'max-age' => 3600
            )
        ));
    }
}
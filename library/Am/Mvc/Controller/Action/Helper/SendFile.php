<?php
// alex@cgi-central.net - W4 have changed just prefix from Noginn_ to Am_ to
// do not create another folders tree. Thanks Tom for great code!
/**
 * Sends a file for download
 *
 * @copyright Copyright (c) 2009 Tom Graham (http://www.noginn.com)
 * @license http://www.opensource.org/licenses/mit-license.php
 * @package Am_Mvc_Controller
 */
class Am_Mvc_Controller_Action_Helper_SendFile extends Zend_Controller_Action_Helper_Abstract
{
    /**
     * Set cache headers
     *
     * @param array $options
     */
    public function setCacheHeaders($options)
    {
        $response = $this->getResponse();

        $cacheControl = array();
        if (isset($options['public']) && $options['public']) {
            $cacheControl[] = 'public';
        }
        if (isset($options['no-cache']) && $options['no-cache']) {
            $cacheControl[] = 'no-cache';
        }
        if (isset($options['no-store']) && $options['no-store']) {
            $cacheControl[] = 'no-store';
        }
        if (isset($options['must-revalidate']) && $options['must-revalidate']) {
            $cacheControl[] = 'must-revalidate';
        }
        if (isset($options['proxy-validation']) && $options['proxy-validation']) {
            $cacheControl[] = 'proxy-validation';
        }
        if (isset($options['max-age'])) {
            $cacheControl[] = 'max-age=' . (int) $options['max-age'];
            $response->setHeader('Expires', gmdate('r', time() + $options['max-age']), true);
        }
        if (isset($options['s-maxage'])) {
            $cacheControl[] = 's-maxage=' . (int) $options['s-maxage'];
        }

        $response->setHeader('Cache-Control', implode(',', $cacheControl), true);
        $response->setHeader('Pragma', 'public', true);
    }

    /**
     * Validate the cache using the If-Modified-Since request header
     *
     * @param int $modified When the file was last modified as a unix timestamp
     * @return bool
     */
    protected function notModifiedSince($modified)
    {
        if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && $modified <= strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
            // Send a 304 Not Modified header
            $response = $this->getResponse();
            $response->setHttpResponseCode(304);
            $response->sendHeaders();
            return true;
        }

        return false;
    }

    /**
     * Check whether it is range request using the HTTP_RANGE request header
     *
     * @param int $modified When the file was last modified as a unix timestamp
     * @return bool
     */
    protected function isRangeRequest()
    {
        return isset($_SERVER['HTTP_RANGE']);
    }

    /**
     * Send a file for download
     *
     * @param string $path Path to the file
     * @param string $type The mime-type of the file
     * @param array $options
     * @return bool Whether the headers and file were sent
     */
    public function sendFile($path, $type, $options = array())
    {
        while (@ob_end_clean());
        Am_Di::getInstance()->session->writeClose();

        $response = $this->getResponse();

        if (!is_readable($path))
            throw new Am_Exception_InternalError("File [$path] does not exists");

        if (!$response->canSendHeaders())
            throw new Am_Exception_InternalError("Headers are already sent");

        // Set the cache-control
        if (isset($options['cache'])) {
            $this->setCacheHeaders($options['cache']);
        }

        // Get the last modified time
        if (isset($options['modified'])) {
            $modified = (int) $options['modified'];
        } else {
            $modified = filemtime($path);
        }

        // Validate the cache
        if (!isset($options['cache']['no-store']) && $this->notModifiedSince($modified)) {
            return true;
        }

        // Set the file name
        if (isset($options['filename']) && !empty($options['filename'])) {
            $filename = $options['filename'];
        } else {
            $filename = basename($path);
        }

        // Set the content disposition
        if (isset($options['disposition']) && $options['disposition'] == 'inline') {
            $disposition = 'inline';
        } else {
            $disposition = 'attachment';
        }

        $response->setHeader('Content-Type', $type, true);
        $response->setHeader('Content-Disposition', $disposition . '; filename="' . $filename . '"', true);
        $response->setHeader('Last-Modified', gmdate('r', $modified), true);
        $response->setHeader('Accept-Ranges', 'bytes', true);

        // Do we want to use the X-Sendfile header or stream the file
        if (isset($options['xsendfile']) && $options['xsendfile']) {
            $response->setHeader('X-Sendfile', $path);
            $response->sendHeaders();
            return true;
        }

        if ($this->isRangeRequest()) {
            return $this->sendFileRange($path);
        }

        $response->setHttpResponseCode(200);
        $response->setHeader('Content-Length', filesize($path), true);
        $response->sendHeaders();

        readfile($path);
        exit();
    }

    /**
     * Send file data as a download
     *
     * @param string $path Path to the file
     * @param string $type The mime-type of the file
     * @param string $filename The filename to send the file as, if null then use the base name of the path
     * @param array $options
     * @return bool Whether the headers and file were sent
     */
    public function sendData($data, $type, $filename, $options = array())
    {
        $response = $this->getResponse();

        if (!$response->canSendHeaders()) {
            return false;
        }

        // Set the cache-control
        if (isset($options['cache'])) {
            $this->setCacheHeaders($options['cache']);
        }

        if (isset($options['modified'])) {
            // Validate the cache
            if (!isset($options['cache']['no-store']) && $this->notModifiedSince($options['modified'])) {
                return true;
            }

            $response->setHeader('Last-Modified', gmdate('r', $options['modified']), true);
        }

        // Set the content disposition
        if (isset($options['disposition']) && $options['disposition'] == 'inline') {
            $disposition = 'inline';
        } else {
            $disposition = 'attachment';
        }

        $response->setHttpResponseCode(200);
        $response->setHeader('Content-Type', $type, true);
        $response->setHeader('Content-Disposition', $disposition . '; filename="' . $filename . '"', true);
        $response->setHeader('Content-Length', strlen($data), true);
        $response->setBody($data);
    }

    /**
     * Proxy method for sendFile
     *
     * @param string $path Path to the file
     * @param string $type The mime-type of the file
     * @param array $options
     * @return bool Whether the headers and file were sent
     */
    public function direct($path, $type, $options = array())
    {
        return $this->sendFile($path, $type, $options);
    }

    /**
     * Send a file range for download
     * http://tools.ietf.org/html/rfc2616#section-14.35
     *
     * @param string $path
     */
    protected function sendFileRange($path)
    {
        $response = $this->getResponse();
        $filesize = filesize($path);

        preg_match('/bytes=(.*)/', $_SERVER['HTTP_RANGE'], $matches);
        $ranges = $this->_parseRange($matches[1], $filesize); //we process only first range now
        foreach ($ranges as $first_byte => $last_byte)
            break;

        $length = $last_byte - $first_byte + 1;

        $file = fopen($path, 'r');
        fseek($file, $first_byte);

        $response->setHttpResponseCode(206);
        $response->setHeader('Content-Range', 'bytes ' . $first_byte . '-' . $last_byte . '/' . $filesize, true);
        $response->setHeader('Content-Length', $length, true);

        $response->sendHeaders();

        $chunk = 1024*1024;
        for($i=$first_byte; $i<$last_byte; $i+=$chunk)
            print fread($file, min($chunk,$last_byte-$i+1));
        fclose($file);

        exit();
    }

    public function _parseRange($range_spec, $filesize)
    {
        $ranges = array();
        foreach (explode(',', $range_spec) as $range) {
            list($first_byte, $last_byte) = explode('-', $range);
            if ($first_byte == '') {
                //bytes=-500 *last 500 bytes
                $first_byte = $filesize - $last_byte;
                $last_byte = $filesize-1;
            } else {
                //bytes=500-999 *500 bytes range
                //bytes=9500- *from 9500 up to the end
                $first_byte = intval($first_byte);
                $last_byte = min(($filesize-1), (($last_byte == '') ? ($filesize-1) : intval($last_byte)));
            }
            if ($first_byte > $last_byte) continue;

            $ranges[$first_byte] = isset($ranges[$first_byte]) ?
                max($ranges[$first_byte], $last_byte) :
                $last_byte;
        }

        ksort($ranges);
        $collapsed = array();
        $prev = -1000; //just value that is always less
        foreach ($ranges as $first => $last) {
            if ($first <= ($prev + 1)) {
                $prev = $last;
            } else {
                $collapsed[$first] = $last;
                $prev = & $collapsed[$first];
            }
        }

        return $collapsed;
    }
}
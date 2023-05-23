<?php

/*
 *
 *     Author: Alex Scott
 *      Email: alex@cgi-central.net
 *        Web: http://www.cgi-central.net
 *    Details: Admin Info /
 *    FileName $RCSfile$
 *    Release: 5.5.4 ($Revision$)
 *
 * Please direct bug reports,suggestions or feedback to the cgi-central forums.
 * http://www.cgi-central.net/forum/
 *
 * aMember PRO is a commercial software. Any distribution is strictly prohibited.
 *
 */

class BackupController extends Am_Mvc_Controller
{
    function cronAction()
    {
        check_demo();

        if ($this->getDi()->modules->isEnabled('cc')) {
            $this->getDi()->errorLogTable->log(___('Online backup is disabled if you have CC payment plugins enabled. Use offline backup instead'));
            return;
        }

        if (!($f = $this->getDi()->config->get('email_backup_frequency'))) {
            throw new Am_Exception_InternalError('Email Backup feature is disabled at Setup/Configuration -> Advanced');
        }

        $key = $this->getParam('k');
        if ($key != $this->getDi()->security->siteHash('backup-cron', 10)) {
            throw new Am_Exception_AccessDenied('Incorrect Access Key');
        }

        $last_runned = $this->getDi()->store->get('cron-backup-last-run');
        if (!$last_runned)
            $last_runned = strtotime('-10 days');

        $d_diff = date('d') - date('d', $last_runned);
        $w_diff = date('W') - date('W', $last_runned);
        if (($d_diff && $f == 'd') ||
            ($w_diff && $f == 'w')) {
            $this->execute();
        }
    }

    function execute()
    {
        $stream = fopen('php://temp', 'w+b');
        if (!$stream)
            throw new Am_Exception_InternalError('Could not open php://temp stream');

        $bp = $this->getDi()->backupProcessor;

        $bp->run($stream);
        rewind($stream);

        $dat = date('Y_m_d-Hi');
        $host = strtolower(preg_replace('/[^a-zA-Z0-9\.]/', '',
            preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'])));
        $fn = "am-$host-$dat.sql";

        $filename = $bp->isGzip() ? "$fn.gz" : $fn;
        $mimeType = $bp->isGzip() ? 'application/x-gzip' : 'text/sql';

        $m = $this->getDi()->mail;
        $m->addTo($this->getDi()->config->get('email_backup_address'))
            ->setSubject($this->getDi()->config->get('site_title') . ': Backup ' . amDatetime('now'))
            ->setFrom($this->getDi()->config->get('admin_email'))
            ->setBodyText(sprintf(<<<CUT
aMember Database Backup
=======================
%s (%s)
Date/Time: %s

File with backup is attached.
CUT
                ,
                $this->getDi()->config->get('site_title'),
                $this->getDi()->config->get('root_url'),
                amDatetime('now')));
        $m->createAttachment($stream, $mimeType, Zend_Mime::DISPOSITION_ATTACHMENT, Zend_Mime::ENCODING_BASE64, $filename);
        $m->setPeriodic(Am_Mail::ADMIN_REQUESTED);
        $m->send();

        $this->getDi()->adminLogTable->log('Email backup to ' . $this->getDi()->config->get('email_backup_address'));
        $this->getDi()->store->set('cron-backup-last-run', $this->getDi()->time);
    }
}
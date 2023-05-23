<?php
/*
*
*
*     Author: Alex Scott
*      Email: alex@cgi-central.net
*        Web: http://www.cgi-central.net
*    Details: upgrade DB from ../amember.sql
*    FileName $RCSfile$
*    Release: 5.5.4 ($Revision$)
*
* Please direct bug reports,suggestions or feedback to the cgi-central forums.
* http://www.cgi-central.net/forum/
*
* aMember PRO is a commercial software. Any distribution is strictly prohibited.
*
*/

class AdminUpgradeDbController extends Am_Mvc_Controller
{
    protected $db_version;
    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->isSuper();
    }
    function indexAction()
    {
        $this->getDi()->db->setLogger(false);

        $t = new Am_View;
        set_time_limit(0);
        ini_set('memory_limit', AM_HEAVY_MEMORY_LIMIT);

        $this->db_version = $this->getDi()->store->get('db_version');

        if (defined('AM_DEBUG')) ob_start();
        ?><!DOCTYPE html>
        <html>
        <head>
            <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
            <title>aMember Database Upgrade</title>
            <style type="text/css">
            <!--
            body {
                font-family: Arial;
                background:#EDEDED;
                text-align: center;
            }
            h1 {font-size:1.5rem; font-weight: normal; margin:0.2em 0}
            a, a:visited {
                color: #34536e;
            }
            .am-upgrade-body {
                display:inline-block;
                text-align: left;
                max-width:800px;
            }
            -->
            </style>
        </head>
        <body>
        <div class="am-upgrade-body">
        <h1>aMember Database Upgrade</h1>
        <hr />
        <?php

        if (!Am_Di::getInstance()->db->selectCell("SELECT GET_LOCK(?, 0)", 'amember-upgrade-db')) {
            throw new Am_Exception_InternalError("Upgrade DB Process is already running");
        }

        /* ******************************************************************************* *
         *                  M A I N
         */
        $this->fixNotUniqueRecordInRebillLog();
        $this->fixNotUniquePathInPages();
        $this->getDi()->app->dbSync(true);
        $this->checkInvoiceItemTotals();
        $this->convertTax();
        $this->convertAutoresponderPrefix();
        $this->enableSkipIndexPage();
        $this->manuallyApproveInvoices();
        $this->addCountryCodes();
        $this->fillResourceAccessSort();
        $this->upgradeFlowPlayerKey();
        $this->fixCryptSavedPass();
        $this->updateStateInfo();
        $this->fixCustomFieldSortTableName();
        $this->normalizeProductSortOrder();
        $this->fixPagePath();
        $this->setupDefaultProtcetionForCustomFields();
        $this->populateInvoicePublicId();
        $this->populateCouponCode();
        $this->convert0toNull();
        $this->fixLogoutRedirectSettings();
        $this->fixUserStatusTable();
        $this->populateAffAddedField();
        $this->convertFreeWithoutAccessFoldersToLinks();
        $this->setDefaultProfileForm();
        $this->hideSignupForms();
        $this->disableUserNotesPlugin();
        $this->disableForcePasswordCachangePlugin();
        $this->enableUseCoupons();
        $this->pupulateRefundAmount();
        $this->enableAffKeywords();
        $this->enableRecurringForExpireEmailTemplates();
        $this->initFormSortOrder();
        $this->enableAllowCancel();
        $this->fixDateFormat();
        $this->updateBrowseUsersField();
        $this->convertCustomVats();
        $this->handlePdfConfig();
        $this->moveCurrencyToBp();
        $this->convertCCRecords();
        $this->setupUserMenu();
        $this->convertAgreements();
        $this->recordUserConsent();

        $this->getDi()->hook->call(new Am_Event(Am_Event::DB_UPGRADE, array('version' => $this->db_version)));
        $this->getDi()->store->set('db_version', AM_VERSION);
        
        Am_Di::getInstance()->db->query("SELECT RELEASE_LOCK(?)", 'amember-upgrade-db');

        $version = AM_VERSION;
        $year = date('Y');
        $copyright = <<<CUT
<div style="text-align:center; font-size:70%">
        aMember Pro&trade; $version by <a href="http://www.amember.com">aMember.com</a>  &copy; 2002&ndash;$year CGI-Central.Net
</div>
CUT;
        echo "
        <br/><strong>Upgrade finished successfully.
        Go to </strong><a href='".$this->getDi()->url('admin')."'>aMember Admin CP</a>.
        <hr />
        $copyright
        </div>
        </body></html>";
    }

    function fixNotUniqueRecordInRebillLog()
    {
        //to set unique index (invoice_id,rebill_date)
        if (version_compare($this->db_version, '4.2.15') < 0)
        {
            $db = $this->getDi()->db;
            try { //to handle situation when ?_cc_rebill table does not exists
                $db->query('CREATE TEMPORARY TABLE ?_cc_rebill_temp (
                    cc_rebill_id int not null,
                    tm_added datetime not null,
                    paysys_id varchar(64),
                    invoice_id int,
                    rebill_date date,
                    status smallint,
                    status_tm datetime,
                    status_msg varchar(255),
                    UNIQUE INDEX(invoice_id, rebill_date))');

                $db->query('
                    INSERT IGNORE INTO ?_cc_rebill_temp
                    SELECT * FROM ?_cc_rebill
                ');

                $db->query("TRUNCATE ?_cc_rebill");

                $db->query('
                    INSERT INTO ?_cc_rebill
                    SELECT * FROM ?_cc_rebill_temp
                ');

                $db->query("DROP TABLE ?_cc_rebill_temp");
            } catch (Exception $e) {

            }
        }
    }

    function fillResourceAccessSort()
    {
        $this->getDi()->resourceAccessTable->syncSortOrder();
    }

    function manuallyApproveInvoices(){
        if((version_compare($this->db_version, '4.2.4') <0) ||
            ((version_compare(AM_VERSION, '4.2.7')<=0) && !$this->getDi()->config->get('manually_approve_invoice'))
            )
        {
            echo "Manually approve old invoices...";
            @ob_end_flush();

            $this->getDi()->db->query("update ?_invoice set is_confirmed=1");
            echo "Done<br/>\n";
        }
    }

    function checkInvoiceItemTotals()
    {
        if (version_compare($this->db_version, '4.1.8') < 0)
        {
            echo "Update invoice_item.total columns...";
            @ob_end_flush();
            $this->getDi()->db->query("
                UPDATE ?_invoice_item
                SET
                    first_total = first_price*qty - first_discount + first_shipping + first_tax,
                    second_total = second_price*qty - second_discount + second_shipping + second_tax
                WHERE
                    ((first_total IS NULL OR first_total = 0) AND first_price > 0)
                OR
                    ((second_total IS NULL OR second_total = 0) AND second_price > 0)
                ");
            echo "Done<br>\n";
        }
    }
    function convertTax()
    {
        if (version_compare($this->db_version, '4.2.0') < 0)
        {
            echo "Move product.no_tax -> product.tax columns...";
            @ob_end_flush();
            try {
                $this->getDi()->db->query("
                UPDATE ?_product
                SET tax_group = IF(IFNULL(no_tax, 0) = 0, 0, 1)
                ");
//                $this->getDi()->db->query("ALTER TABLE ?_product DROP no_tax");
            } catch (Am_Exception_Db $e) { }

            echo "Move invoice_item.no_tax -> invoice_item.tax_group columns...";
            @ob_end_flush();
            try {
               $this->getDi()->db->query("
                UPDATE ?_invoice_item
                SET tax_group = IF(IFNULL(no_tax, 0) = 0, 0, 1)
                ");
//                $this->getDi()->db->query("ALTER TABLE ?_invoice_item DROP no_tax");
            } catch (Am_Exception_Db $e) { }
            echo "Done<br>\n";

            echo "Migrate tax settings...";
            if ($this->getDi()->config->get('use_tax'))
            {
                $config = $this->getDi()->config;
                $config->read();
                switch ($this->getDi()->config->get('tax_type'))
                {
                    case 1:
                        $config->set('plugins.tax', array('global-tax'));
                        $config->set('tax.global-tax.rate', $config->get('tax_value'));
                        break;
                    case 2:
                        $config->set('plugins.tax', array('regional'));
                        $config->set('tax.regional.taxes', $config->get('regional_taxes'));
                        break;
                }
                $arr = $config->getArray();
                unset($arr['tax_type']);
                unset($arr['regional_taxes']);
                unset($arr['tax_value']);
                unset($arr['use_tax']);
                $config->setArray($arr);
                $config->save();
            }
            echo "Done<br>\n";
        }
    }

    function convertAutoresponderPrefix()
    {
        if (version_compare($this->db_version, '4.2.0') < 0)
        {
            echo "Convert Autoresponder Prefix From [emailtemplate] to [email-messages]";
            @ob_end_flush();
            try {
                $rows = $this->getDi()->db->query("
                SELECT * FROM ?_email_template
                WHERE name IN ('autoresponder', 'expire') AND attachments IS NOT NULL
                ");

                $upload_ids = array();
                foreach ($rows as $row) {
                    $upload_ids = array_merge($upload_ids, explode(',', $row['attachments']));
                }

                if (count($upload_ids)) {
                    $templates = array();
                    foreach ($upload_ids as $id) {
                        $rows = $this->getDi()->db->query("
                            SELECT * FROM ?_email_template
                            WHERE name NOT IN ('autoresponder', 'expire')
                            AND (attachments=? OR attachments LIKE ?
                            OR attachments LIKE ? OR attachments LIKE ?)",
                            $id,
                            '%,'.$id,
                            $id.',%',
                            '%,'.$id.',%'
                            );
                        $templates = array_merge($templates, $rows);
                    }



                    if (count($templates)) {
                        $names = array();
                        foreach ($templates as $tpl) {
                            $names[] = sprintf('%s [%s]', $tpl['name'], $tpl['lang']);
                        }

                        echo sprintf(' <span style="color:#F44336;">Please reupload attachments for the following templates: %s</span><br />',
                            implode(', ', $names));
                    }

                    $this->getDi()->db->query("UPDATE ?_upload SET prefix=? WHERE upload_id IN (?a)",
                        'email-messages', $upload_ids);
                }

            } catch (Am_Exception_Db $e) { }
            echo "Done<br>\n";
        }
    }
    function checkResourceAccessEmailTemplates(){
        if (version_compare($this->db_version, '4.1.14') < 0)
        {
            echo "Update resource access table ...";
            @ob_end_flush();
            $this->getDi()->db->query("
                    UPDATE ?_resource_access
                    SET
                    start_days = (SELECT day FROM ?_email_template WHERE email_template_id=resource_id),
                    stop_days = (SELECT day FROM ?_email_template WHERE email_template_id=resource_id)
                    WHERE resource_type = 'emailtemplate' AND fn='free' and start_days IS NULL
                    ");
            echo "Done<br>\n";

        }
    }

    function enableSkipIndexPage() {
        if (version_compare($this->db_version, '4.1.16') < 0)
        {
            echo "Enable skip_index_page option...";
            if (ob_get_level()) ob_end_flush();
            $str = $this->getDi()->db->selectCell("SELECT config FROM ?_config WHERE name = ?", 'default');
            $config = unserialize($str);
            if (!isset($config['skip_index_page'])) {
                $config['skip_index_page'] = 1;
                $this->getDi()->db->selectCol("UPDATE ?_config SET config=? WHERE name = ?", serialize($config), 'default');
            }

            echo "Done<br>\n";

        }
    }

    function addCountryCodes() {
        if (version_compare($this->db_version, '4.2.10') < 0)
        {
            echo "Add country codes...";
            if (ob_get_level()) ob_end_flush();
            $query = file_get_contents($this->getDi()->root_dir . '/setup/sql-country.sql');
            $query = str_replace('@DB_MYSQL_PREFIX@', '?_', $query);
            $this->getDi()->db->query($query);
            echo "Done<br>\n";
        }
    }

    function upgradeFlowPlayerKey() {
        if (version_compare($this->db_version, '4.2.16') < 0)
        {
            echo "Update Flowplayer License Key...";
            if (ob_get_level()) ob_end_flush();
            $request = new Am_HttpRequest('https://www.amember.com/fplicense.php', Am_HttpRequest::METHOD_POST);
            $request->addPostParameter('root_url', $this->getDi()->config->get('root_url'));
            try {
                $response = $request->send();
            } catch (Exception $e) {
                echo "request failed " . $e->getMessage() . "\n<br />";
                return;
            }
            if ($response->getStatus() == 200) {
                $body = $response->getBody();
                $res = json_decode($body, true);
                if ($res['status'] == 'OK' && $res['license'])
                {
                    Am_Config::saveValue('flowplayer_license', $res['license']);
                }
            }
            echo "Done<br>\n";
        }
    }

    function fixCryptSavedPass()
    {
        if (version_compare($this->db_version, '4.2.16') < 0)
        {
            echo "Fix crypt saved pass...";
            if (ob_get_level()) ob_end_flush();
            $this->getDi()->db->query("UPDATE ?_saved_pass SET salt=pass WHERE format=?", 'crypt');
            echo "Done<br>\n";
        }
    }

    function updateStateInfo() {
        if (version_compare($this->db_version, '4.2.16') < 0)
        {
            echo "Update State Info...";
            if (ob_get_level()) ob_end_flush();
            $query = file_get_contents($this->getDi()->root_dir . '/setup/sql-state.sql');
            $query = str_replace('@DB_MYSQL_PREFIX@', '?_', $query);
            $this->getDi()->db->query($query);
            echo "Done<br>\n";
        }
    }

    function fixCustomFieldSortTableName() {
        if (version_compare($this->db_version, '4.2.20') < 0)
        {
            echo "Rename custom_fields_sort to custom_field_sort...";
            if (ob_get_level()) ob_end_flush();
            try {
                //actually we move data from old table to new one here to leave user preference
                if (!$this->getDi()->db->selectCell("SELECT COUNT(*) FROM ?_custom_field_sort")) {
                    $this->getDi()->db->query("SET @i = 0");
                    $this->getDi()->db->query("INSERT INTO ?_custom_field_sort (custom_field_table, custom_field_name, sort_order)
                        SELECT custom_field_table, custom_field_name, (@i:=@i+1) FROM ?_custom_fields_sort ORDER BY sort_order");
                }
            } catch (Exception $e) {
                //nop, handle situsation for upgrade from version where ?_custom_fields_sort is not exists yet
            }
            echo "Done<br>\n";
        }
    }

    function normalizeProductSortOrder() {
        if (version_compare($this->db_version, '4.2.20') < 0)
        {
            echo "Normalize sort order for products...";
            if (ob_get_level()) ob_end_flush();
            $this->getDi()->db->query("SET @i = 0");
            $this->getDi()->db->query("UPDATE ?_product SET sort_order=(@i:=@i+1) ORDER BY sort_order");
            echo "Done<br>\n";
        }
    }

    function fixNotUniquePathInPages()
    {
        if (version_compare($this->db_version, '4.2.20') < 0)
        {
            echo "Fix Not Unique Path in Pages...";
            if (ob_get_level()) ob_end_flush();
            $this->getDi()->db->query("UPDATE ?_page SET path = page_id WHERE path=''");
            echo "Done<br>\n";
        }
    }

    function fixPagePath() {
        if (version_compare($this->db_version, '4.2.20') < 0)
        {
            echo "Fix Page Path...";
            if (ob_get_level()) ob_end_flush();
            $this->getDi()->db->query("UPDATE ?_page SET path = NULL WHERE path=page_id");
            echo "Done<br>\n";
        }
    }

    function setupDefaultProtcetionForCustomFields() {
        if (version_compare($this->db_version, '4.2.20') < 0)
        {
            echo "Setup default protection for custom fields...";
            foreach($this->getDi()->userTable->customFields()->getAll() as $field) {
                if (isset($field->from_config) && $field->from_config)
                    $this->getDi()->resourceAccessTable->setAccess(amstrtoint($field->name), Am_CustomField::ACCESS_TYPE, array(
                        ResourceAccess::FN_FREE_WITHOUT_LOGIN => array(
                            json_encode(array(
                                'start' => null,
                                'stop' => null,
                                'text' => ___('Free Access without log-in')
                        )))
                    ));
            }
            echo "Done<br>\n";
        }
    }

    function populateInvoicePublicId()
    {
        if (version_compare($this->db_version, '4.2.20') < 0)
        {
            echo "Populate Invoice Public Id (Denormalization)...";
            foreach (array('?_access',
                '?_invoice_item',
                '?_invoice_payment',
                '?_invoice_refund') as $table) {

                $this->getDi()->db->query("UPDATE $table t SET invoice_public_id =
                    (SELECT public_id FROM ?_invoice i WHERE i.invoice_id=t.invoice_id)
                    WHERE t.invoice_id IS NOT NULL");
            }
            echo "Done<br>\n";
        }
    }

    function populateCouponCode()
    {
        if (version_compare($this->db_version, '4.2.20') < 0)
        {
            echo "Populate Coupon Code (Denormalization)...";
            $this->getDi()->db->query("UPDATE ?_invoice t SET coupon_code =
                    (SELECT code FROM ?_coupon c WHERE c.coupon_id=t.coupon_id)
                    WHERE t.coupon_id IS NOT NULL");
            echo "Done<br>\n";
        }
    }

    function convert0toNull()
    {
        if (version_compare($this->db_version, '4.2.20') < 0)
        {
            echo "Convert 0 to NULL...";
            $this->getDi()->db->query("UPDATE ?_access SET invoice_id=NULL
                    WHERE invoice_id=0");
            $this->getDi()->db->query("UPDATE ?_access SET invoice_payment_id=NULL
                    WHERE invoice_payment_id=0");
            $this->getDi()->db->query("UPDATE ?_access SET invoice_item_id=NULL
                    WHERE invoice_item_id=0");
            $this->getDi()->db->query("UPDATE ?_access SET transaction_id=NULL
                    WHERE transaction_id=''");
            echo "Done<br>\n";
        }
    }

    function fixLogoutRedirectSettings()
    {
        if (version_compare($this->db_version, '4.2.20') < 0)
        {
            if (!$this->getDi()->config->get('protect.php_include.redirect_logout') &&
                $this->getDi()->config->get('protect.php_include.redirect')) {
                Am_Config::saveValue('protect.php_include.redirect_logout', 'url');

                $this->getDi()->config->read();
            }
        }
    }

    function fixUserStatusTable()
    {
        if (version_compare($this->db_version, '4.3.3') < 0)
        {
            echo "Fix ?_user_status table...";
            $this->getDi()->db->query("DELETE FROM ?_user_status WHERE user_id NOT IN (SELECT user_id FROM ?_user)");
            echo "Done<br>\n";
        }
    }

    function populateAffAddedField()
    {
        if (version_compare($this->db_version, '4.3.3') < 0)
        {
            echo "Populate aff_added field...";
            $this->getDi()->db->query("UPDATE ?_user SET aff_added=added WHERE aff_id>0 AND aff_added IS NULL");
            echo "Done<br>\n";
        }
    }

    function convertFreeWithoutAccessFoldersToLinks()
    {
        if (version_compare($this->db_version, '4.3.4') < 0)
        {
            echo "Converst Free Without Access Folders to Links...";
            foreach($this->getDi()->resourceAccessTable->findBy(array(
                'fn' => ResourceAccess::FN_FREE_WITHOUT_LOGIN,
                'resource_type' => ResourceAccess::FOLDER
                )) as $rec) {

                try {
                    $folder = $this->getDi()->folderTable->load($rec->resource_id);
                    $link = $this->getDi()->linkRecord;
                    foreach (array('title', 'desc', 'url', 'hide') as $prop) {
                        $link->{$prop} = $folder->{$prop};
                    }
                    $link->save();
                    $link->setAccess(array(
                        ResourceAccess::FN_FREE => array(
                            0 => array(
                                'start' => null,
                                'stop' => null
                            )
                        )
                    ));

                    $sort = $folder->getSortOrder();
                    $folder->delete();
                    $this->unprotectFolder($folder);
                    $link->setSortOrder($sort);
                } catch (Exception $e) {}
            }
            echo "Done<br>\n";
        }
    }

    function setDefaultProfileForm()
    {
        if (version_compare($this->db_version, '4.6.4') < 0)
        {
            echo "Set Default Profile Form...";
            if (!$this->getDi()->savedFormTable->getDefault(SavedForm::D_PROFILE)) {
                $id = $this->getDi()->db->selectCell('SELECT saved_form_id FROM ?_saved_form WHERE type=? LIMIT 1', 'profile');
                $this->getDi()->savedFormTable->setDefault(SavedForm::D_PROFILE, $id);
            }
            echo "Done<br>\n";
        }
    }

    public function hideSignupForms()
    {
        if (version_compare($this->db_version, '4.7.0') < 0)
        {
            echo "Hide Signup Forms from Menu...";
            $form = $this->getDi()->savedFormTable->getDefault(SavedForm::D_MEMBER);
            $this->getDi()->db->query('UPDATE ?_saved_form SET hide=1
                WHERE type=? AND saved_form_id<>?',
                    SavedForm::T_SIGNUP, $form->pk());
            echo "Done<br>\n";
        }
    }

    public function disableUserNotesPlugin()
    {
        if (version_compare($this->db_version, '4.7.3') < 0)
        {
            //echo "Disable User Notes plugin (This feature is incorporated to core)...";
            $plugins = $this->getDi()->config->get('plugins.misc');
            if ($plugins)
                foreach ($plugins as $k => $pl) {
                    if ($pl == 'user-note') {
                        unset($plugins[$k]);
                        Am_Config::saveValue('plugins.misc', $plugins);
                        break;
                    }
                }
            //echo "Done<br>\n";
        }
    }

    public function disableForcePasswordCachangePlugin()
    {
        if (version_compare($this->db_version, '4.7.3') < 0)
        {
            //echo "Disable Force Password Change plugin (This feature is incorporated to core)...";
            $plugins = $this->getDi()->config->get('plugins.misc');
            if ($plugins)
                foreach ($plugins as $k => $pl) {
                    if ($pl == 'force-password-change') {
                        Am_Config::saveValue('force_change_password', 1);
                        Am_Config::saveValue('force_change_password_period', $this->getDi()->config->get('misc.force-password-change.period'));
                        unset($plugins[$k]);
                        Am_Config::saveValue('plugins.misc', $plugins);
                        break;
                    }
            }
            //echo "Done<br>\n";
        }
    }

    public function enableUseCoupons()
    {
        if (version_compare($this->db_version, '4.7.3') < 0)
        {
            echo "Enable use of coupons in Shopping Cart...";
            Am_Config::saveValue('cart.use_coupons', 1);
            echo "Done<br>\n";
        }
    }

    public function pupulateRefundAmount()
    {
        if (version_compare($this->db_version, '4.7.3') < 0)
        {
            echo "Populate Refund Amount...";
            $this->getDi()->db->query("INSERT INTO ?_invoice_payment
                        (invoice_payment_id, refund_dattm, refund_amount)
                        (SELECT invoice_payment_id, MIN(dattm), SUM(amount)
                            FROM ?_invoice_refund
                            WHERE invoice_payment_id > 0
                            GROUP BY invoice_payment_id)
                        ON DUPLICATE KEY UPDATE
                                refund_dattm = VALUES(refund_dattm),
                                refund_amount = VALUES(refund_amount);");
            echo "Done<br>\n";
        }
    }
    public function enableAffKeywords()
    {
        if (version_compare($this->db_version, '4.7.3') < 0)
        {
            echo "Enable Keywords support in Affiliate Module...";
            Am_Config::saveValue('aff.keywords', 1);
            echo "Done<br>\n";
        }
    }

    public function enableRecurringForExpireEmailTemplates()
    {
        if (version_compare($this->db_version, '4.7.3') < 0)
        {
            echo "Enable Recurring Option for Expire Email Templates...";
            $this->getDi()->db->query("UPDATE ?_email_template SET recurring=1 WHERE name=?",
                EmailTemplate::EXPIRE);
            echo "Done<br>\n";
        }
    }

    function initFormSortOrder() {
        if (version_compare($this->db_version, '5.0.1') < 0)
        {
            echo "Init sort order for saved forms...";
            if (ob_get_level()) ob_end_flush();
            $this->getDi()->db->query("SET @i = 0");
            $this->getDi()->db->query("UPDATE ?_saved_form SET sort_order=(@i:=@i+1) ORDER BY `type`='signup' DESC");
            echo "Done<br>\n";
        }
    }

    function enableAllowCancel()
    {
        if (version_compare($this->db_version, '5.0.5') <= 0)
        {
            echo "Enable allow_cancel option...";
            if (ob_get_level()) ob_end_flush();
            Am_Config::saveValue('allow_cancel', 1);
            echo "Done<br>\n";
        }
    }

    function fixDateFormat()
    {
        if (version_compare($this->db_version, '5.0.7') <= 0)
        {
            echo "Fix date_format and time_format...";
            if (ob_get_level()) ob_end_flush();
            Am_Config::saveValue('date_format', null);
            Am_Config::saveValue('time_format', null);
            echo "Done<br>\n";
        }
    }

    function updateBrowseUsersField()
    {
        if (version_compare($this->db_version, '5.1.7') < 0)
        {
            echo "Update Browse Users fields...";
            $update = array('name', 'payments_count', 'payments_sum', 'products', 'ugroup', 'expire');
            if (ob_get_level()) ob_end_flush();
            foreach($this->getDi()->adminTable->findBy() as $admin)
            {
                if(@count($pref = $admin->getPref('grid_setup_u')))
                {
                    foreach($pref as $k => $v)
                    {
                        if(in_array($v, $update))
                            $pref[$k] = '_' . $v;
                    }
                    $admin->setPref('grid_setup_u', $pref);
                }
            }
            echo "Done<br>\n";
        }
    }

    public function handlePdfConfig()
    {
        if (version_compare($this->db_version, '5.1.8') <= 0)
        {
            echo "Normalize PDF Invoice options...";
            if ($this->getDi()->config->get('send_pdf_invoice')) {
                Am_Config::saveValue('pdf_invoice_sent_user', 1);
                Am_Config::saveValue('pdf_invoice_sent_admin', 1);
                Am_Config::saveValue('pdf_invoice_link', 1);
            }
            echo "Done<br>\n";
        }
    }
    public function convertCCRecords()
    {
        if ((version_compare($this->db_version, '5.1.8') <= 0) && $this->getDi()->modules->isEnabled('cc'))
        {
            $this->error(sprintf("Default encryption method was changed. You must re-encrypt database. Please run <a href='%s'>this tool</a>\n<br/>",
                $this->getUrl('admin-convert', 'index', 'cc')));
        }
    }

    function moveCurrencyToBp()
    {
        echo "Move Currency to Billing Plans...";
        //idempotent operation
        $this->getDi()->db->query(<<<CUT
            UPDATE ?_billing_plan bp
                SET currency = (SELECT currency FROM ?_product p WHERE p.product_id = bp.product_id)
                WHERE currency IS NULL;
CUT
        );
        echo "Done<br>\n";
    }

    public function unprotectFolder(Folder $folder)
    {
        $htaccess_path = $folder->path . '/.htaccess';
        if (!is_dir($folder->path)) {
            $this->error('Could not open folder [%s] to remove .htaccess from it. Do it manually', $folder->path);
            return;
        }
        $content = file_get_contents($htaccess_path);
        if (strlen($content) && !preg_match('/^\s*\#+\sAMEMBER START.+AMEMBER FINISH\s#+\s*/s', $content)) {
            $this->error('File [%s] contains not only aMember code - remove it manually to unprotect folder', $htaccess_path);
            return;
        }
        if (!unlink($folder->path . '/.htaccess'))
            $this->error('File [%s] cannot be deleted - remove it manually to unprotect folder', $htaccess_path);
    }

    public function convertCustomVats()
    {
        $nodata = 'a:29:{s:2:"AT";s:0:"";s:2:"BE";s:0:"";s:2:"BG";s:0:"";s:2:"CY";s:0:"";s:2:"HR";s:0:"";s:2:"CZ";s:0:"";s:2:"DE";s:0:"";s:2:"DK";s:0:"";s:2:"EE";s:0:"";s:2:"GR";s:0:"";s:2:"ES";s:0:"";s:2:"FI";s:0:"";s:2:"FR";s:0:"";s:2:"GB";s:0:"";s:2:"HU";s:0:"";s:2:"IE";s:0:"";s:2:"IM";s:0:"";s:2:"IT";s:0:"";s:2:"LT";s:0:"";s:2:"LU";s:0:"";s:2:"LV";s:0:"";s:2:"MT";s:0:"";s:2:"NL";s:0:"";s:2:"PL";s:0:"";s:2:"PT";s:0:"";s:2:"RO";s:0:"";s:2:"SE";s:0:"";s:2:"SK";s:0:"";s:2:"SI";s:0:"";}';
        $i = 1;

        if ($records = $this->getDi()->db->select("SELECT * FROM ?_data WHERE `key` = 'vat_eu_rate'")) {

            $groups = $this->getConfig('tax.vat2015.tax_groups', array());
            $index = array();
            foreach ($groups as $id => $title) {
               $index[md5(serialize($this->getConfig("tax.vat2015.$id")))] = $id;
            }

            foreach ($records as $r) {
                if ($r['blob'] == $nodata) continue;

                $k = md5($r['blob']);
                if (isset($index[$k])) {
                    $tax_group = $index[$k];
                } else {
                    $d = unserialize($r['blob']);
                    $groups[$_ = uniqid()] = 'Reduced Tax Group '. $i++;
                    $index[md5($r['blob'])] = $_;
                    Am_Config::saveValue("tax.vat2015.$_", $d);
                    $tax_group = $_;
                }
                if ($p = $this->getDi()->productTable->load($r['id'], false)) {
                    $p->tax_rate_group = $tax_group;
                    $p->save();
                }
            }
            foreach ($groups as $id => $title) {
                Am_Config::saveValue('tax.vat2015.tax_groups.' . $id, $title);
            }
            $this->getDi()->db->query("DELETE FROM ?_data WHERE `key` = 'vat_eu_rate'");
        }
    }

    function setupUserMenu()
    {
        if ($this->getDi()->config->get('user_menu')) return;

        echo "Setup User Menu...";
        $items = array();
        $items[] = array('id' => 'dashboard', 'name' => 'Dashboard');

        //Signup Forms
        $forms = $this->getDi()->savedFormTable->findBy(array(
            'type' => SavedForm::T_SIGNUP,
            'hide' => 0), null, null, 'sort_order');

        if (count($forms) == 1) {
           list($f) = $forms;
           $items[] = array('id' => 'signup-form', 'name' => $f->title, 'config' => array('id' => $f->pk()));
        } elseif (count($forms) > 1) {
            $subitems = array();
            foreach ($forms as $f) {
                $subitems[] = array('id' => 'signup-form', 'name' => $f->title, 'config' => array('id' => $f->pk()));
            }
            $items[] = array(
                'id' => 'container',
                'name' => 'Add/Renew Subscription',
                'config' => array('label' => 'Add/Renew Subscription'),
                'items' => $subitems);
        }

        //Profile Forms
        $forms = $this->getDi()->savedFormTable->findBy(array(
            'type' => SavedForm::T_PROFILE,
            'hide' => 0), null, null, 'sort_order');

        if (count($forms) == 1) {
           list($f) = $forms;
           $items[] = array('id' => 'profile-form', 'name' => $f->title, 'config' => array('id' => $f->pk()));
        } elseif (count($forms) > 1) {
            $subitems = array();
            foreach ($forms as $f) {
                $subitems[] = array('id' => 'profile-form', 'name' => $f->title, 'config' => array('id' => $f->pk()));
            }
            $items[] = array(
                'id' => 'container',
                'name' => 'Profile',
                'config' => array('label' => 'Profile'),
                'items' => $subitems);
        }

        $items[] = array('id' => 'resource-categories', 'name' => 'Resource Categories Menu');

        //Pages
        foreach($this->getDi()->pageTable->findByOnmenu(1) as $page) {
            $items[] = array('id' => 'page', 'name' => $page->title, 'config' => array('id' => $page->pk()));
        }
        //Links
        foreach($this->getDi()->linkTable->findByOnmenu(1) as $link) {
            $items[] = array('id' => 'link', 'name' => $link->title, 'config' => array('id' => $link->pk()));
        }
        Am_Config::saveValue('user_menu', json_encode($items));
        echo "Done<br>\n";
    }
    
    function convertAgreements()
    {
        if($this->getDi()->db->selectCell("SELECT * FROM  ?_agreement"))
            return; 
        
        $agreements = array();
        foreach($this->getDi()->savedFormTable->selectObjects("SELECT * FROM ?_saved_form") as $form)
        {
            $update = false; 
            $fields = $form->getFields();
            foreach ($fields as $k => $row)
            {
                if ($row['id'] == 'agreement') 
                {
                    $text = @$row['config']['text'];
                    
                    $hash = $this->createAgreementHash($text);
                    
                    if(empty($agreements[$hash])){
                    
                        $agreements[$hash] = $type = sprintf('agreement-%s-%s', $form->type, $form->code?:'default');
                    
                        $text = !empty($row['config']['isHtml'])&& $row['config']['isHtml']? $text : sprintf("<pre>%s</pre>", $text);
                    
                        $this->getDi()->db->query(
                            "INSERT INTO ?_agreement "
                            . "(type, title, body, is_current) "
                            . "VALUES"
                            . "(?,?,?,?)", 
                            $type, ___('Terms & Conditions'), $text, 1);
                        
                    }else{
                        $type = $agreements[$hash];
                    }
                    $fields[$k]['config']['agreement_type']  = $type;
                    $update = true;
                    
                }
            }
            if($update)
            {
                $form->setFields($fields);
                $form->update();
            }
                    
        }
        
        if(empty($agreements))
        {
            $this->getDi()->db->query(
                "INSERT INTO ?_agreement "
                . "(type, title, body, is_current) "
                . "VALUES"
                . "(?,?,?,?)", 
                'agreement-default', ___('Terms & Conditions'), "", 1);
            
        }
    }
    
    
    function recordUserConsent()
    {
        if(version_compare($this->db_version, '5.4.3') <= 0){
            $this->getDi()->db->query("INSERT INTO ?_user_consent "
                . "(user_id, `type`, dattm, remote_addr, source) "
                . "SELECT user_id, 'imported', added, remote_addr, 'Account Registration' FROM ?_user WHERE i_agree>0");
            
            if($this->getDi()->db->selectCell("select id from ?_data where `table`='invoice' and `key`='agreement_accepted' limit 1")){
                $this->getDi()->db->query("INSERT INTO ?_user_consent "
                    . "(user_id, `type`, dattm, remote_addr, source) "
                        . "SELECT i.user_id, 'imported', i.tm_added, dip.`value` as agreement_accepted_ip, "
                        . "concat('Invoice #', i.invoice_id, '/', i.public_id) "
                        . "FROM ?_invoice i "
                        . "LEFT JOIN ?_data da "
                        . "ON da.`table`='invoice' AND  da.`key`='agreement_accepted' AND da.`id` = i.invoice_id "
                        . "LEFT JOIN ?_data dip "
                        . "ON dip.`table`='invoice' AND  dip.`key`='agreement_accepted_ip' AND dip.`id` = i.invoice_id "
                        . "LEFT JOIN ?_user u  "
                        . "ON u.user_id = i.invoice_id "
                        . "HAVING agreement_accepted_ip is not null");
            }
            
        }
    }
    
    function createAgreementHash($text){
        $text = preg_replace('/[^\da-z]/i', '', $text);
        return md5(strtoupper($text));
        
    }

    function error($msg)
    {
        echo sprintf('<span style="color:#F44336;">%s</span><br />', $msg);
    }
}

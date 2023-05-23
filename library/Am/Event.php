<?php

/**
 * All possible event objects are defined in this file
 */

/**
 * Class defines basic Am_Event class
 * it must contain all information regarding event
 * and returing information from hooks if required
 * the Am_Event object will be returned as result of Am_Di::getInstance()->hook->call(...)
 *
 * BY AGREEMENT ALL SUBCLASSES OF Am_Event must have name starting with "Am_Event..." !!!
 *
 * @package Am_Events
 */
class Am_Event
{
    /**
     * Called after db upgrade
     *
     * * string version
     *
     */
    const DB_UPGRADE = 'dbUpgrade';

    /**
     * @param User $user
     * @param string $resource_id
     * @param string $resource_type
     * @link Am_Event::getReturn()
     * @link Am_Event::setReturn()
     */
    const USER_HAS_ACCESS = 'userHasAccess';
    const GUEST_HAS_ACCESS = 'guestHasAccess';

    /** Called every hour by cronjob */
    const HOURLY = 'hourly';
    /** Called every day by cronjob */
    const DAILY  = 'daily';
    /** Called every week by cronjob */
    const WEEKLY  = 'weekly';
    /** Called 1-st day of month by cronjob */
    const MONTHLY  = 'monthly';
    /** Called 1-st day of year by cronjob */
    const YEARLY  = 'yearly';
    /** Called when aMember API stack initialization is finished */
    const INIT_FINISHED  = 'initFinished';
    /** Called when an invoice becomes active_recuirring or paid, or free trial is started
     *
     *  {@link Invoice Invoice}
     *  : invoice
     */
    const INVOICE_STARTED = 'invoiceStarted';
    const INVOICE_TERMS = 'invoiceTerms';
    /**
     * Called when invoice status is changed
     *
     * Parameters:
     *  * {@link Invoice Invoice} invoice</li>
     *  * int status - new status of invoice</li>
     *  * int oldStatus - previous status of invoice</li>
     *
     */
    const INVOICE_STATUS_CHANGE = 'invoiceStatusChange';

    /**
     * Called just before customer record deletion.
     * By triggering exception from the hook, deletion may be stopped
     *
     *  * {@link User User} user
     */
    const USER_BEFORE_DELETE = 'userBeforeDelete';
    /**
     * Called after customer record deletion.
     *
     *  * {@link User User} user
     */
    const USER_AFTER_DELETE = 'userAfterDelete';
    /**
     * Called before user record is inserted into table
     *
     *  * {@link User User} user
     */
    const USER_BEFORE_INSERT = 'userBeforeInsert';
    /**
     * Called after user record is inserted into table
     *
     *  * {@link User User} user
     */
    const USER_AFTER_INSERT = 'userAfterInsert';
    /**
     * Called before user record is updated in database
     *
     *  * {@link User User} user
     */
    const USER_BEFORE_UPDATE = 'userBeforeUpdate';
    /**
     * Called after user record is updated in database
     *
     *  * {@link User User} user
     */
    const USER_AFTER_UPDATE = 'userAfterUpdate';
    /**
     * Called once value of $user->unsubscribe field is changed
     *
     *  <li>{@link User User} user</li>
     *  <li>int unsubscribed - new value of 'unsubscribed' field</li>
     *
     */
    const USER_UNSUBSCRIBED_CHANGED = 'userUnsubscribedChanged';
    const GENERATE_LOGIN = 'generateLogin';
    /**
     * @param Am_Query $query
     * @param string $filter
     * @link Am_Event::getReturn()
     * @link Am_Event::setReturn()
     */
    const ADMIN_USERS_FILTER_INIT = "adminUsersFilterInit";

    /**
     * Can be used to customize the autocomplete query
     * @param Am_Query $query
     * @param string $term
     */
    const ADMIN_USERS_AUTOCOMPLETE = "adminUsersAutocomplete";

    /**
     * Called after admin record deletion.
     *
     *  * {@link Admin Admin} admin
     */
    const ADMIN_AFTER_DELETE = 'adminAfterDelete';

    /**
     * Called after product record deletion.
     *
     *  * {@link Product} product
     */
    const PRODUCT_AFTER_DELETE = 'productAfterDelete';

    /**
     * Called just before coupon update
     *
     * @param Coupon $coupon
     * @param Coupon $old
     */
    const COUPON_BEFORE_UPDATE = 'couponBeforeUpdate';

    /**
     * Called when customer password is changed, plain-text password
     * is available in this hook
     *
     *  * {@link User User} user
     *  * string password - plain-text password
     *
     *  Event Class: Am_Event_SetPassword
     */
    const SET_PASSWORD = 'setPassword';
    const GET_PASSWORD_FORMATS = 'getPasswordFormats';
    /** User (or affiliate) record is added after submitting signup form - before payment */
    const SIGNUP_USER_ADDED = 'signupUserAdded';
    /** User record is added after submitting signup form - before payment */
    const SIGNUP_AFF_ADDED = 'signupAffAdded';
    /** User record is updated after submitting signup form - before payment */
    const SIGNUP_USER_UPDATED  = 'signupUserUpdated';
    const SIGNUP_LOAD_USER = 'signupLoadUser';
    const SIGNUP_INVOICE_ITEMS = 'signupInvoiceItems';

    /** User record is updated after submitting profile form*/
    const PROFILE_USER_UPDATED  = 'profileUserUpdated';

    /** Called just before payment record insered into database. Is not called for free subscriptions */
    const PAYMENT_BEFORE_INSERT = 'paymentBeforeInsert';
    /** Payment record insered into database. Is not called for free subscriptions */
    const PAYMENT_AFTER_INSERT = 'paymentAfterInsert';
    /** Payment record with access insered into database. Is not called for free subscriptions. Required to get access records. */
    const PAYMENT_WITH_ACCESS_AFTER_INSERT = 'paymentWithAccessAfterInsert';

    /** Return array of objects to calculate invoice.
     *
     * @link Invoice::getCalculators()
     * @link Am_Event::getReturn()
     * @link Am_Event::setReturn()
     * <code>
     * function onInvoiceGetCalculators(Am_Event $event)
     * {
     *      $calculators = $event->getReturn();
     *      $calculators[] = new MyInvoiceCalculator($event->getInvoice());
     *      $event->setReturn($calculators);
     * }
     * </code>
     */
    const INVOICE_GET_CALCULATORS = 'invoiceGetCalculators';
    /**
     * Called when invoice calculation is finished
     * @var Invoice invoice
     */
    const INVOICE_CALCULATE = 'invoiceCalculate';
    /**
     * Check if we can authenticate user by third-party database
     * @see Am_Event_AuthCheckLoggedIn
     */
    const AUTH_CHECK_LOGGED_IN = 'authCheckLoggedIn';
    /**
     * Called upon succesful user login
     * @see Am_Event_AuthAfterLogin
     */
    const AUTH_AFTER_LOGIN = 'authAfterLogin';
    /**
     * If user login was failed through aMember users database,
     * this event allows to create aMember account on-fly and
     * login user
     * @see Am_Event_AuthTryLogin
     */
    const AUTH_TRY_LOGIN = 'authTryLogin';
    const AUTH_SESSION_REFRESH = 'authSessionRefresh';

    /**
     * Very specific ability to create user if he requests his password.
     * may be useful by plugins.
     */
    const AUTH_LOST_PASS_USER_EMPTY = 'authLostPassUserEmpty';

    /**
     * Called on user logout
     * @see Am_Event_AuthAfterLogout
     */
    const AUTH_AFTER_LOGOUT = 'authAfterLogout';

    const AUTH_ADMIN_AFTER_LOGIN = 'authAdminAfterLogin';
    const AUTH_ADMIN_AFTER_LOGOUT = 'authAdminAfterLogout';
    /**
     * Called on user check during authentification
     * You can reject valid user authentification
     * by some creteria
     *
     * <code>
     *  $user = $event->getUser();
     *  $event->setResult(new Am_Auth_Result(-100, ___('You can not login becouse of...')));
     *  $event->stop();
     * </code>
     *
     * @param User $user
     * @link Am_Event::getReturn()
     * @link Am_Event::setReturn()
     * @link Am_Event::stop()
     */
    const AUTH_CHECK_USER = 'authCheckUser';
    const AUTH_CONTROLLER_SET_USER = 'authControllerSetUser';
    const AUTH_CONTROLLER_HANDLER = 'authControllerHandler';
    const AUTH_CONTROLLER_HTML = 'authControllerHTML';
    /**
     * Called on choose redirect url after login
     *
     * @param User $user
     * @link Am_Event::getReturn()
     * @link Am_Event::setReturn()
     */
    const AUTH_GET_OK_REDIRECT = 'authGetOkRedirect';
    /**
     * Called to get list of member links
     * @see Am_Event::addReturn()
     */
    const GET_MEMBER_LINKS = 'getMemberLinks';
    /**
     * Called to get list of member links at left
     * @see Am_Event::addReturn()
     */
    const GET_LEFT_MEMBER_LINKS = 'getLeftMemberLinks';

    /**
     * @param User $user
     * @param array|null $types
     */
    const GET_ALLOWED_RESOURCES = 'getAllowedResources';

    /**
     * Called when user receives a subscription to product
     * he was not subscribed earlier
     * @see Am_Event_SubscriptionAdded
     */
    const SUBSCRIPTION_ADDED = 'subscriptionAdded';
    /**
     * Called when user subscription access is deleted
     * @see Am_Event_SubscriptionDeleted
     */
    const SUBSCRIPTION_DELETED = 'subscriptionDeleted';
    /**
     * Called once for multiple changes, provides list of
     * added and deleted products
     * @see Am_Event::SUBSCRIPTION_ADDED
     * @see Am_Event::SUBSCRIPTION_DELETED
     * @see Am_Event_SubscriptionChanged
     */
    const SUBSCRIPTION_CHANGED = 'subscriptionChanged';
    /**
     * Called when user information is changed
     * @see Am_Event_SubscriptionUpdated
     * @deprecated use Am_Event::USER_AFTER_UPDATE instead
     */
    const SUBSCRIPTION_UPDATED = 'subscriptionUpdated';
    /**
     * Called when user record is deleted
     * @see Am_Event_SubscriptionRemoved
     * @deprecated use Am_Event::USER_AFTER_DELETE instead
     */
    const SUBSCRIPTION_REMOVED = 'subscriptionRemoved';

    /**
     * Access record inserted
     * NOTE - record may be in not-active state - check dates
     * <li>{@link Access} access</li>
     * @see Am_Event::SUBSCRIPTION_ADDED
     */
    const ACCESS_AFTER_INSERT = 'accessAfterInsert';
    const ACCESS_BEFORE_INSERT = 'accessBeforeInsert';

    /**
     * Access record updated
     * <li>{@link Access} access</li>
     * @var Access old - record before changes
     */
    const ACCESS_AFTER_UPDATE = 'accessAfterUpdate';
    /**
     * Access record deleted
     * NOTE - record may be in not-active state - check dates
     * <li>{@link Access} access</li>
     */
    const ACCESS_AFTER_DELETE = 'accessAfterDelete';

    /**
     * Called before invoice insertion
     * <li>{@link Invoice} invoice</li>
     */
    const INVOICE_BEFORE_INSERT = 'invoiceBeforeInsert';
    /**
     * Called after invoice insertion
     * <li>{@link Invoice} invoice</li>
     */
    const INVOICE_AFTER_INSERT = 'invoiceAfterInsert';
    /**
     * Called before invoice deletion
     * <li>{@link Invoice} invoice</li>
     */
    const INVOICE_BEFORE_DELETE = 'invoiceBeforeDelete';
    /**
     * Called after invoice deletion
     * <li>{@link Invoice} invoice</li>
     */
    const INVOICE_AFTER_DELETE = 'invoiceAfterDelete';
    /**
     * Called after invoice cancelation
     * <li>{@link Invoice} invoice</li>
     */
    const INVOICE_AFTER_CANCEL= 'invoiceAfterCancel';
    /**
     * Called after invoice approved by admin
     * <li>{@link Invoice} invoice</li>
     */
    const INVOICE_AFTER_APPROVE= 'invoiceAfterApprove';


    /**
     * Called after invoice payment refund(or chargeback)
     * <li>{@link InvoiceRefund} refund</li>
     */
    const INVOICE_PAYMENT_REFUND= 'invoicePaymentRefund';



    /**
     * Called on Invoice validate(before redirect to payment system);
     * <li>{@link Invoice} invoice</li>
     */
    const INVOICE_VALIDATE = 'invoiceValidate';

    /**
     * Called before panding notofication sent
		<li>{@link array} sendCallback</li>
		<li>{@link Am_Mail_Template} template</li>
		<li>{@link Invoice} invoice</li>
     */
	const PENDING_NOTIFICATION_BEFORE_SEND = 'pendingNotificationBeforeSend';

    const SET_DISPLAY_INVOICE_PAYMENT_ID = 'setDisplayInvoicePaymentId';
    const SET_DISPLAY_INVOICE_REFUND_ID = 'setDisplayInvoiceRefundId';

    /**
     * Called to check for username uniquiness
     * @see Am_Event_CheckUniqLogin
     */
    const CHECK_UNIQ_LOGIN = 'checkUniqLogin';
    /**
     * Called to check for e-mail uniquiness
     * @see Am_Event_CheckUniqEmail
     */
    const CHECK_UNIQ_EMAIL = 'checkUniqEmail';
    /**
     * Called to validate signup and profile form before processing
     * @see Am_Event_ValidateSavedForm
     */
    const VALIDATE_SAVED_FORM = 'validateSavedForm';
    /**
     * Called to validate coupon before processing
     */
    const VALIDATE_COUPON = 'validateCoupon';
    /**
     * This hook is executed in global PHP scope to include external libraries
     * for example it is used by WP plugin to include WP API Stack
     */
    const GLOBAL_INCLUDES = 'globalIncludes';
    /**
     * This hook is executed after {@link Am_Event::GLOBAL_INCLUDES} is finished
     */
    const GLOBAL_INCLUDES_FINISHED = 'globalIncludesFinished';
    /**
     * This hook is called from admin-rebuild-db controller
     * it may be used to add new items into "rebuild" UI
     * @see Am_Event_Rebuild
     */
    const REBUILD = 'rebuild';
    const FOLDER_PROTECT_CODE = 'folderProtectCode';

    /** Called to get exclusions for aMember database backup
     *  If your plugin has a table that must not be backed up,
     *  call $event->addReturn('tablewithoutprefix') on this hook */
    const SKIP_BACKUP = 'skipBackup';
    /**
     * Mutliple GRID EVENTS are available for product form
     */
    const PRODUCT_FORM = 'productForm';
    /**
     * Called to create setup forms
     * @see Am_Event_SetupForms
     */
    const SETUP_FORMS = 'setupForms';
    /**
     * Called to collect Email Template Types
     * <li>{@link Am_Mail_TemplateTypes}</li>
     */
    const SETUP_EMAIL_TEMPLATE_TYPES = 'setupEmailTemplateTypes';
    /**
     * Called to collect common tag sets or alter existing
     *
     * @link Am_Mail_TemplateTypes
     * @link Am_Event::getReturn()
     * @link Am_Event::setReturn()
     */
    const EMAIL_TEMPLATE_TAG_SETS = 'emailTemplateTagSets';
    /**
     * Called to retrive tag options for specific template
     *
     * @param string $templateName
     * @link Am_Mail_TemplateTypes
     */
    const EMAIL_TEMPLATE_TAG_OPTIONS = 'emailTemplateTagOptions';

    /**
     * Can be used to check if email template is ok for user,
     * Implements ability to add additional conditions to amember CP -> Protect Content -> Emails
     *
     * @param EmailTemplate $template
     * @param User $user
     * @return true if email template is ok for user, false otherwise.
     * use $event->setReturn();
     *
     *
     */
    const EMAIL_TEMPLATE_CHECK_CONDITIONS  = 'emailTemplateCheckConditions';
    /**
     * Called on the thanks page
     *
     * <li>{@link Invoice} invoice (may be null)</li>
     * <li><i>ThanksController</i> controller</li>
     */
    const THANKS_PAGE = 'thanksPage';
    /**
     * Get list of avaialable admin permissions
     */
    const GET_PERMISSIONS_LIST = 'getPermissionsList';
    /**
     * Get list of API controllers => permissions
     */
    const GET_API_CONTROLLERS = 'getApiControllers';
    /**
     * Check permissions for remote API call
     * call $event->addReturn(true); to allow access WITHOUT any checks
     * throw exception to deny access
     */
    const API_CHECK_PERMISSIONS = 'apiCheckPermissions';
    /**
     * Get list of avaialable file upload prefixes
     */
    const GET_UPLOAD_PREFIX_LIST = 'getUploadPrefixList';
    /**
     * Get list of avaialable signup form types
     */
    const SAVED_FORM_TYPES = 'savedFormTypes';
    /**
     * Ability to chage list of bricks for form via hooks
     *
     * list of bricks can be modified depends on currently logged in user
     * and his subscription's status
     *
     * @param enum(signup, cart, profile...) $type
     * @param string $code
     * @param SavedForm $savedForm
     * @link Am_Event::getReturn()
     * @link Am_Event::setReturn()
     */
    const SAVED_FORM_GET_BRICKS = 'savedFormGetBricks';

    /**
     * @param string $code
     */
    const SIGNUP_STATE_SAVE = 'signupStateSave';
    const SIGNUP_STATE_LOAD = 'signupStateLoad';

    /**
     * Add new pages into existing "pageable" controllers like AdminContentController
     */
    const INIT_CONTROLLER_PAGES = 'initControllerPages';
    /**
     * Load available saved form bricks
     */
    const LOAD_BRICKS = 'loadBricks';
    /**
     * Called on admin menu construction
     * <li>{@link Am_Navigation_Admin} menu</li>
     */
    const ADMIN_MENU = 'adminMenu';
    /**
     * Called on admin dashboard to display warnings
     */
    const ADMIN_WARNINGS = 'adminWarnings';
    /**
     * Called on admin dashboard to display notice
     */
    const ADMIN_NOTICE = 'adminNotice';
    /**
     * Called on user menu construction
     * <li>{@link Am_Navigation_User} menu</li>
     */
    const USER_MENU = 'userMenu';
    const USER_MENU_ITEMS = 'userMenuItems';
    /**
     * Called on admin user view pages to create tabs
     * @see Am_Event_UserTabs
     */
    const USER_TABS = 'userTabs';
    /**
     * Called to get available admin user search conditions
     */
    const USER_SEARCH_CONDITIONS = 'userSearchConditions';
    /**
     * Called to load available reports
     */
    const LOAD_REPORTS = 'loadReports';
    /**
     * Called before render protected page
     */
    const PAGE_BEFORE_RENDER = 'pageBeforeRender';
    /**
     * Called before view render, you can change variables from here
     */
    const BEFORE_RENDER = 'beforeRender';
    /**
     * Called after view render, you can change output from there
     * @see Am_Event_AfterRender
     */
    const AFTER_RENDER = 'afterRender';
    /** @deprecated, use INIT_ACCESS_TABLES instead */
    const INIT_CONTENT_PAGES = 'initContentPages';
    const INIT_ACCESS_TABLES = 'initAccessTables';

    const LOAD_ADMIN_DASHBOARD_WIDGETS = 'loadAdminDashboardWidgets';

    /**
     * Add sample data to database (@link AdminBuildController)
     * $user->save() will be called after hook finished
     * <li>{@link User} user</li>
     * <li>{@link string} $demoId</li>
     * <li>{@link int} $usersCreated</li>
     * <li>{@link int} $usersTotal</li>
     */
    const BUILD_DEMO = 'buildDemo';
    /**
     * Update database structure
     * <li>{@link dbsync} Am_DbSync desired database structure</li>
     */
    const DB_SYNC = 'dbSync';
    const ET_SYNC = 'etSync';
    /**
     * Choose a signup form based on request parameters
     *
     * @param User $user
     * @param Am_Mvc_Request $request
     * @link Am_Event::getReturn()
     * @link Am_Event::setReturn()
     */
    const LOAD_SIGNUP_FORM = 'loadSignupForm';
    /**
     * Choose a profile form based on user
     *
     * @param User $user
     * @link Am_Event::getReturn()
     * @link Am_Event::setReturn()
     */
    const LOAD_PROFILE_FORM = 'loadProfileForm';
    /**
     * Called when 2 user records are merged
     *
     * @param User $target
     * @param User $source
     */
    const USER_MERGE = 'userMerge';
    /**
     * Called before 2 user records are going to be merged by admin
     *
     * @param User $target
     * @param User $source
     */
    const USER_BEFORE_MERGE = 'userBeforeMerge';

    /**
     * Insert Additional items to admin Clear Old Records controller
     *
     * to utilize, you have to call $event->addReturn($arr, 'mykey'), arr must have
     * a format like this:
     * <code>
     * array(
     *           'method' => array($this->getDi()->adminLogTable, 'clearOld'),
     *           'title'  => 'Admin Log',
     *           'desc'   => 'admin log table (used by admin only)',
     *  )
     * </code>
     */
    const CLEAR_ITEMS = 'clearItems';


    /**
     *  Add ability to set custom placeholders to mail template.
     *  Am_Mail_Template $template is passed as a parameter;
     */
    const MAIL_TEMPLATE_BEFORE_PARSE = 'mailTemplateBeforeParse';
    const MAIL_TEMPLATE_BEFORE_SEND = 'mailTemplateBeforeSend';

    /**
     *  Add ability to set custom placeholders to mail template.
     *  @param Am_SimpleTemplate $template
     *  @param string $body
     *  @param string $subject
     *  @param Am_Mail $mail
     */
    const MAIL_SIMPLE_TEMPLATE_BEFORE_PARSE = 'mailSimpleTemplateBeforeParse';

    /**
     * add input elements to form with two leading underscore
     *
     * @param Am_Form_Admin $form
     */
    const MAIL_SIMPLE_INIT_FORM = 'mailSimpleInitForm';

    /**
     * add input elements to to Email Template Form
     *
     * @param Am_Form_Admin $form
     */
    const EMAIL_TEMPLATE_INIT_FORM = 'emailTemplateInitForm';

    /**
     * To be used to customize pdf invoice totally
     * @param Am_Pdf_Invoice $amPdfInvoice
     * @param Zend_pdf $pdf
     * @param User $user
     * @param Invoice $invoice
     * @param InvoicePayment $payment
     * @link Am_Event::getReturn()
     * @link Am_Event::setReturn()
     */
    const PDF_INVOICE_BEFORE_RENDER = 'pdfInvoiceBeforeRender';

    /**
     * @param stdClass $col
     * @param User $user
     * @param Invoice $invoice
     * @param InvoicePayment $payment
     */
    const PDF_INVOICE_COL_LEFT = 'pdfInvoiceColLeft';
    const PDF_INVOICE_COL_RIGHT = 'pdfInvoiceColRight';

    /**
     * @param Am_Pdf_Page_Decorator $page
     * @param stdClass $pointer use $pointer->value to retrieve current offset and update it
     * @param User $user
     * @param Invoice $invoice
     * @param InvoicePayment $payment
     */
    const PDF_INVOICE_BEFORE_TABLE = 'pdfInvoiceBeforeTable';
    const PDF_INVOICE_AFTER_TABLE = 'pdfInvoiceAfterTable';

    /**
     * Triggered when new invoice created on product upgrade
     * you may change invoice settings before it is passed
     * to paysystem plugin
     */
    const BEFORE_PRODUCT_UPGRADE = 'beforeProductUpgrade';

    /**
     * Called immediately before form rendering
     * may be user to change element styles
     * @param Am_Form $form
     */
    const FORM_BEFORE_RENDER = 'formBeforeRender';

    /**
     * Called during signup/renewal form display
     * may be used to modify products list in order form
     * @see Am_Event::getReturn()
     * @see Am_Event::setReturn()
     */
    const SIGNUP_FORM_GET_PRODUCTS = 'signupFormGetProducts';
    const SIGNUP_FORM_DEFAULT_PRODUCT = 'signupFormDefaultProduct';
     /**
     * Called during signup/renewal form display
     * may be used to modify billing plan list in order form
     * @see Am_Event::getReturn()
     * @see Am_Event::setReturn()
     */
    const SIGNUP_FORM_GET_BILLING_PLANS = 'signupFormGetBillingPlans';
    /**
     * Called during signup/renewal form display
     * may be used to modify products list in order form
     * called AFTER products are filtered according to "require"/"disallow"
     * conditions
     * @see Am_Event::getReturn()
     * @see Am_Event::setReturn()
     */
    const SIGNUP_FORM_GET_PRODUCTS_FILTERED = 'signupFormGetProductsFiltered';

    /**
     * Called during signup/renewal form display
     * may be used to modify paysystem list in order form
     * @see Am_Event::getReturn()
     * @see Am_Event::setReturn()
     */
    const SIGNUP_FORM_GET_PAYSYSTEMS = 'signupFormGetPaysystems';

    /**
     * Triggered before signup data will be processed. (before invoice/user  will be created)
     * @param array $vars  - contains data collected from all signup forms.
     * May be usefull to make additional checks on data
     *
     */
    const SIGNUP_PAGE_BEFORE_PROCESS = 'signupPageBeforeProcess';

    /**
     * Triggered just before redirect to payment system
     * @param Invoice $invoice
     * @param mixed $controller
     */
    const INVOICE_BEFORE_PAYMENT = 'invoiceBeforePayment';

    /**
     * Triggered just before redirect to payment system (on signup/renew page)
     * @param Invoice $invoice
     * @param array $vars
     * @param Am_Form_Signup $form
     */
    const INVOICE_BEFORE_PAYMENT_SIGNUP = 'invoiceBeforePaymentSignup';

    /**
     * Triggered in payment plugins processInvoice function to catch/modify
     * actions
     * @param Invoice $invoice
     * @param Am_Paysystem_Abstract $request
     * @param Am_Mvc_Request $request
     * @return Am_Paysystem_Result
     */
    const PAYMENT_BEFORE_PROCESS = 'paymentBeforeProcess';

    /**
     * Triggered just after new invoice instance create (on signup/renew page)
     * may be used to attach some data from vars to invoice
     *
     * @param Invoice $invoice
     * @param array $vars
     * @param Am_Form_Signup $form
     */
    const INVOICE_SIGNUP = 'invoiceSignup';

    /**
     * Triggered just after new invoice instance created on cart checkout page
     * may be used to attach some data to invoice
     *
     * @param Invoice $invoice
     */
    const CART_INVOICE_CHECKOUT = 'cartInvoiceCheckout';

    /**
     * Calculation of product (access) start date on first payment and renewals
     * @param bool isFirstPayment
     * @param Invoice $invoice
     * @param InvoiceItem $item
     * @link Am_Event::getReturn()
     * @link Am_Event::setReturn()
     */
    const CALCULATE_START_DATE = 'calculateStartDate';

    /**
     * Expand types constant to list of resource types
     *
     * @param enum(ResourceAccess::USER_VISIBLE_TYPES, ResourceAccess::USER_VISIBLE_PAGES) $type
     * @link Am_Event::getReturn()
     * @link Am_Event::setReturn()
     */
    const GET_RESOURCE_TYPES = 'getResourceTypes';

    /**
     * Ability to chage login regexp
     *
     * @param bool login_disallow_spaces
     * @link Am_Event::getReturn()
     * @link Am_Event::setReturn()
     */
    const GET_LOGIN_REGEX = 'getLoginRegex';

    /**
     * Ability to chage strong pass regexp
     *
     * @link Am_Event::getReturn()
     * @link Am_Event::setReturn()
     */
    const GET_STRONG_PASSWORD_REGEX = 'getStrongPasswordRegex';

    /**
     * Ability to change affiliate redirect link, for example to add custom tracking params
     * @param User $aff
     * @link Am_Event::getReturn()
     * @link Am_Event::setReturn()
     */
    const GET_AFF_REDIRECT_LINK = 'getAffRedirectLink';

    /**
     * Translate not default resources(newsletters, directories, etc.)
     * @link Am_Event::getReturn()
     * @link Am_Event::setReturn()
     */
    const GET_BASE_TRANSLATION_DATA = 'getBaseTranslationData';
    /**
     * Called to initialize blocks by plugins
     * {@link Am_Blocks} blocks
     */
    const INIT_BLOCKS = 'initBlocks';

    /**
     * Ability to change folder for PDF invoices storage
     *
     * @param InvoicePayment $payment
     * @link Am_Event::getReturn()
     * @link Am_Event::setReturn()
     */
    const GET_PDF_FILES_DIR = 'getPdfFilesDir';


    /**
     * Ability to insert information to delete account confirmation screen;
     * @param User $user;
     * @link Am_Event::addReturn()
     * @link Am_Event::setReturn()
     *
     */

    const RENDER_DELETE_ACCOUNT_CONFIRMATION = 'renderDeleteAccountConfirmation';

    /**
     * User confirmed personal data removal.
     * Do necessary actions. Should return status: true -> success, otherwise array of errors.
     *
     * @param User $user;
     * @link Am_Event::setReturn()
     *
     */

    const DELETE_PERSONAL_DATA = 'deletePersonalData';

    /**
     * Called when user request personal data to download or on delete personal data page
     * If you store personal data in plugins, you can include it to display for user.
     * array structure is:
     * [
     *      ["name" => 'FIELD1 NAME', 'title' => 'FIELD1 TITLE', 'value' => 'FIELD1 VALUE'],
     *      ["name" => 'FIELD2 NAME', 'title' => 'FIELD2 TITLE', 'value' => 'FIELD2 VALUE'],
     *      ["name" => 'FIELD3 NAME', 'title' => 'FIELD3 TITLE', 'value' => 'FIELD3 VALUE'],
     * ]
     *
     * if value is an array or object it will be json_encoded();
     * @param User $user;
     * @link Am_Event::setReturn()
     *
     */

    const BUILD_PERSONAL_DATA_ARRAY = 'buildPersonalDataArray';

    /** @var id - if empty, will be detected automatically */
    protected $id;
    /** @var array event-specific variables */
    protected $vars = array();
    /** @var array of raised exceptions in format classname::method->exception */
    protected $raisedExceptions = array();
    /** @var bool must stop processing of ->handle(...)? */
    protected $mustStop = false;
    /** @var array collected return values from hooks */
    protected $return = array();
    /** @var Am_Di */
    private $_di;

    function __construct($id = null, array $vars = array())
    {
        $this->id = $id;
        $this->vars = $vars;
    }

    /** @access private */
    public function _setDi(Am_Di $di)
    {
        $this->_di = $di;
    }

    /** @return Am_Di */
    public function getDi()
    {
        return $this->_di;
    }

    /**
     * Do call of a single callback, it is an internal functions
     * @access protected
     */
    public function call(Am_HookCallback $callback)
    {
        try
        {
            $ret = call_user_func($callback->getCallback(), $this);
        }
        catch (Exception $e)
        {
            $this->addRaisedException($callback->getSignature(), $e);
            $e = $this->onException($e);
            if ($e)
                throw $e;
            return;
        }
        return $ret;
    }

    /**
     * Do call of passed array of callbacks here
     * @param array $hooks
     */
    public function handle(array $hooks)
    {
        foreach ($hooks as $h)
        {
            if ($h->getFile())
                include_once Am_Di::getInstance()->root_dir . DIRECTORY_SEPARATOR . $h->getFile();
            $this->call($h);
            if ($this->mustStop())
                break;
        }
    }

    /**
     * Will be called when exception raised during callback
     * @return Exception|null if Exception returned, it will be re-raised, and following handling stopped
     */
    public function onException(Exception $e)
    {
        return $e;
    }

    public function addRaisedException($sig, Exception $e)
    {
        $this->raisedExceptions[$sig] = $e;
        return $this;
    }

    public function getRaisedExceptions()
    {
        return $this->raisedExceptions;
    }

    /**
     * Stop handling $this->handle(..) cycle
     * It can be called by a callback to notify app
     * that following callbacks must not be called
     */
    public function stop()
    {
        $this->mustStop = true;
    }

    /**
     * Shall the handle(..) cycle be interrupted?
     * @see Am_Event::stop()
     * @return bool
     */
    public function mustStop()
    {
        return (bool) $this->mustStop;
    }

    public function getId()
    {
        if ($this->id === null)
        {
            $this->id = lcfirst(preg_replace('/^Am_Event_/i', '', get_class($this)));
            if ($this->id === null && get_class($this) === 'Am_Event')
                throw new Am_Exception_InternalError("Am_Event requires id");
        }
        return $this->id;
    }

    public function __call($name, $arguments)
    {
        if (strpos($name, 'get')===0)
        {
            $var = lcfirst(substr($name, 3));
            if (!array_key_exists($var, $this->vars))
            {
                $id = $this->getId();
                trigger_error("Event variable [$var] is not set for [$id]", E_USER_WARNING);
                return null;
            }
            return $this->vars[$var];
        }
        trigger_error("Method [$name] does not exists in " . __CLASS__, E_USER_ERROR);
    }

    /**
     * Add return value to be used by main program
     * @param type $val
     * @param type $key (optional)
     */
    public function addReturn($val, $key = null)
    {
        if ($key === null)
            $this->return[] = $val;
        else
            $this->return[$key] = $val;
    }
    /**
     * Set entire return values or value
     */
    public function setReturn($return)
    {
        $this->return = $return;
    }
    /**
     * Get values returned by hooks
     * @return array
     */
    public function getReturn()
    {
        return $this->return;
    }
}

////////////////// Abstract classes ///////////////////////////////////////////
/**
 * Abstract class to pass on user-change events
 *
 * @method User getUser() return user record
 */
abstract class Am_Event_User extends Am_Event
{
    function __construct(User $user)
    {
        parent::__construct(null, array('user' => $user));
    }
}

abstract class Am_Event_ValidateRequest extends Am_Event
{

    protected $form;
    protected $errors = array();
    protected $param = null; // var 'scope'
    /** @var array */
    protected $request;

    function __construct(array $request, HTML_QuickForm2 $form = null, $savedForm = null)
    {
        $this->request = $request;
        $this->form = $form;
        $this->savedForm = $savedForm;
    }

    function addError($msg)
    {
        $this->errors[] = (string) $msg;
    }

    function getErrors()
    {
        return $this->errors;
    }
    /**
     * may return null if form was not set
     * @return HTML_QuickForm2|null
     */
    function getForm()
    {
        return $this->form;
    }
    /**
     * may return null if form was not set
     * @return SavedForm|null
     */
    function getSavedForm()
    {
        return $this->savedForm;
    }
}

abstract class Am_Event_UserProduct extends Am_Event
{

    protected $user;
    protected $product;

    function __construct(User $user, Product $product)
    {
        $this->user = $user;
        $this->product = $product;
    }

    /** @return User */
    function getUser()
    {
        return $this->user;
    }

    /** @return Product */
    function getProduct()
    {
        return $this->product;
    }

}

abstract class Am_Event_AbstractUserUpdate extends Am_Event
{

    /** @var User after saving changes */
    protected $user;
    /** @var User before any changes */
    protected $oldUser;

    function __construct(User $user, User $oldUser)
    {
        $this->user = $user;
        $this->oldUser = $oldUser;
    }

    /** @return User */
    function getUser()
    {
        return $this->user;
    }

    /** @return User */
    function getOldUser()
    {
        return $this->oldUser;
    }

}

//////////// Real Am_Event classes that can be used for hooking //////////////////

/** @method InvoicePayment getPayment()
 *  @method Invoice getInvoice()
 *  @method User getUser()
 */
class Am_Event_PaymentAfterInsert extends Am_Event  { }

class Am_Event_PaymentWithAccessAfterInsert extends Am_Event  { }

/** Called when first access for invoice added
 *  @method Invoice getInvoice
 *  @method User getUser
 */
class Am_Event_InvoiceStarted extends Am_Event { }

class Am_Event_InvoiceGetCalculators extends Am_Event
{
    public function __construct(Invoice $invoice)
    {
        parent::__construct(null, array('invoice' => $invoice));
    }

    public function dump()
    {
        $s = "";
        foreach ($this->return as $k => $v)
            $s .= "$k: " . get_class($v) . "\n";
        return nl2br($s);
    }

    function insertBefore(Am_Invoice_Calc $calc, Am_Invoice_Calc $before)
    {
        foreach ($this->return as $k => $v)
            if ($v === $before)
            {
                array_splice($this->return, $k, 0, array($calc));
                return true;
            }
    }

    /**
     * Insert calculatior just before tax/shipping/totals necessary calculations
     * @param Am_Invoice_Calc $calc
     */
    function insertBeforeTax(Am_Invoice_Calc $calc)
    {
        foreach ($this->return as $k => $v)
            if ($v instanceof Am_Invoice_Calc_Tax
                   || $v instanceof Am_Invoice_Calc_Total
                   || $v instanceof Am_Invoice_Calc_Shipping)
            {
                array_splice($this->return, $k, 0, array($calc));
                return true;
            }
    }

    function insertAfter(Am_Invoice_Calc $calc, Am_Invoice_Calc $after)
    {
        foreach ($this->return as $k => $v)
            if ($v === $after)
            {
                array_splice($this->return, $k+1, 0, array($calc));
                return true;
            }
    }

    function replace(Am_Invoice_Calc $replacement, Am_Invoice_Calc $replace)
    {
        foreach ($this->return as $k => $v)
            if ($v === $replace)
                $this->return[$k] = $replacement;
    }

    function remove(Am_Invoice_Calc $calc)
    {
        foreach ($this->return as $k => $v) {
            if ($v === $calc) {
                unset($this->return[$k]);
                return true;
            }
        }
    }

    function findByClassName($className)
    {
        foreach ($this->getReturn() as $calc)
            if ($calc instanceof $className)
                return $calc;
    }
}

class Am_Event_UserBeforeInsert extends Am_Event_User {}
class Am_Event_UserAfterInsert extends Am_Event_User {}
class Am_Event_UserBeforeUpdate extends Am_Event_AbstractUserUpdate {}
class Am_Event_UserAfterUpdate extends Am_Event_AbstractUserUpdate {}
class Am_Event_UserAfterDelete extends Am_Event_User {}

class Am_Event_AuthCheckLoggedIn extends Am_Event
{

    protected $user;
    /**
     * This function must be called in a hook
     * if we have found correct auth credentials
     */
    function setSuccessAndStop(User $user)
    {
        $this->user = $user;
        $this->stop(); // no following hooks will be called
    }
    function validateSuccess($login, $pass)
    {
        $code = null;
        return $this->getDi()->userTable->getAuthenticatedRow($login, $pass, $code);
    }
    function isSuccess()
    {
        return (bool) $this->user;
    }
    /** @return User|null */
    function getUser()
    {
        return $this->user;
    }
}

class Am_Event_AuthAfterLogin extends Am_Event_User
{
    protected $plaintextPass = null;

    public function __construct(User $user, $plaintextPass = null)
    {
        parent::__construct($user);
        $this->plaintextPass = $plaintextPass;
    }

    public function getPassword()
    {
        return $this->plaintextPass;
    }

    public function setPassword($plaintextPass)
    {
        $this->plaintextPass = $plaintextPass;
        return $this;
    }
}

/**
 * After login attempt failed, plugins can try to
 * login into third-party app with the same credentials
 * If that is possible, plugin can :
 *   - create corresponding user in aMember
 *   - login user into third-party app
 *   - return status to let amember know that is ok
 * Then Am_Mvc_Controller_AuthUser will login user to aMember, too
 */
class Am_Event_AuthTryLogin extends Am_Event
{

    protected $login, $pass;
    protected $user;

    public function __construct($login, $pass)
    {
        parent::__construct();
        $this->login = $login;
        $this->pass = $pass;
    }

    public function getLogin()
    {
        return $this->login;
    }

    public function getPassword()
    {
        return $this->pass;
    }

    public function setCreated(User $user)
    {
        $this->user = $user;
    }

    public function isCreated()
    {
        return (bool) $this->user;
    }

    /** @return User|null */
    public function getCreated()
    {
        return $this->user;
    }

}

class Am_Event_AuthAfterLogout extends Am_Event_User { }
class Am_Event_AuthSessionRefresh extends Am_Event_User{ }
class Am_Event_SubscriptionAdded extends Am_Event_UserProduct {}
class Am_Event_SubscriptionDeleted extends Am_Event_UserProduct {}
/**
 * This hook is called when subscription list is changed
 * @method User getUser() user record
 * @method array getAdded() array of product# that were added to user access
 * @method array getDeleted() array of product# that were deleted from user access
 */
class Am_Event_SubscriptionChanged extends Am_Event
{
    public function __construct(User $user, array $added, array $deleted)
    {
        parent::__construct(null, array('user' => $user, 'added' => $added, 'deleted' => $deleted));
    }
}

class Am_Event_SubscriptionUpdated extends Am_Event_AbstractUserUpdate { }

class Am_Event_SubscriptionRemoved extends Am_Event_User { }

/**
 * @method string getLogin()
 */
class Am_Event_CheckUniqLogin extends Am_Event
{
    protected $failed = false;
    /**
     * Report conflicting login found
     */
    function setFailureAndStop()
    {
        $this->failed = true;
        $this->stop();
    }
    /** Return true if login is unique (no problems reported by hooks) */
    function isUnique() { return!$this->failed;  }
}

/**
 * @method string getEmail()
 * @method int|null getUserId() if null, we are checking in signup
 */
class Am_Event_CheckUniqEmail extends Am_Event
{
    protected $failed = false;
    /**
     * Report conflicting login found
     */
    function setFailureAndStop()
    {
        $this->failed = true;
        $this->stop();
    }
    /** Return true if login is unique (no problems reported by hooks) */
    function isUnique() { return!$this->failed;  }
}

class Am_Event_ValidateSavedForm extends Am_Event_ValidateRequest
{
    protected $param = 'signup';
}

class Am_Event_SetPassword extends Am_Event_User
{

    protected $plaintextPass;
    protected $saved = array();

    public function __construct(User $user, $plaintextPass)
    {
        parent::__construct($user);
        $this->plaintextPass = $plaintextPass;
    }

    /**
     *
     * @return string new plain-text password
     */
    public function getPassword()
    {
        return $this->plaintextPass;
    }

    public function addSaved(SavedPass $saved)
    {
        $this->saved[$saved->format] = $saved;
    }

    /** @return SavedPass|null */
    public function getSaved($format)
    {
        return empty($this->saved[$format]) ? null : $this->saved[$format];
    }

}

class Am_Event_GlobalIncludes extends Am_Event
{
    protected $includes = array();
    function add($fn) { $this->includes[] = $fn; }
    function get()  {   return $this->includes;   }
}

class Am_Event_Rebuild extends Am_Event
{
    protected $doneString;
    /** if plugin called this function with a status string,
     *  next iteration of the rebuild will be runned for this plugin
     */
    function setDoneString($doneString)
    {
        $this->doneString = $doneString;
    }
    function setDone()
    {
        $this->doneString = null;
    }
    function getDoneString()
    {
        return $this->doneString;
    }
    /** @return bool if another iteration is necessary */
    function needContinue() { return strlen($this->doneString); }
}

class Am_Event_SetupForms extends Am_Event
{
    /** @var AdminSetupController */
    protected $setup;
    public function __construct($setup)
    {
        parent::__construct();
        $this->setup = $setup;
    }
    public function addForm(Am_Form_Setup $form)
    {
        return $this->setup->addForm($form);
    }
    /** @return Am_Form_Setup */
    public function getForm($id)
    {
        return $this->setup->getForm($id, false);
    }
}

class Am_Event_Grid extends Am_Event
{
    protected $action;
    /** @var Am_Grid_ReadOnly $grid */
    protected $grid;
    protected $args = array();
    public function __construct($action = null, array & $args, Am_Grid_ReadOnly $grid)
    {
        parent::__construct();
        $this->action = $action;
        $this->grid = $grid;
        $this->args = $args;
    }
    function getArgs() { return $this->args; }
    function setArgs(array $args) { $this->args = $args; }
    function setArg($idx, $val) { $this->args[ $idx ] = $val; }
    function getArg($idx) { return $this->args[$idx]; }
    function getGrid() { return $this->grid; }
}

class Am_Event_PageBeforeRender extends Am_Event
{
    function setHtml($html)
    {
        $this->vars['html'] = $html;
    }
}

class Am_Event_UserTabs extends Am_Event
{
    protected $tabs;

    /** @var bool */
    protected $insert;
    protected $userId;

    public function __construct(Am_Navigation_UserTabs $tabs, $isInsert, $userId)
    {
        $this->tabs = $tabs;
        $this->insert = (bool)$isInsert;
        $this->userId = $userId;
    }
    /** @return Am_Navigation_UserTabs */
    public function getTabs()
    {
        return $this->tabs;
    }
    /** @return bool */
    public function isInsert()
    {
        return (bool)$this->insert;
    }
    public function getUserId()
    {
        return $this->userId;
    }
}

class Am_Event_AfterRender extends Am_Event
{
    /** @return int count of replaced patterns */
    public function replace($pattern, $replacement, $limit = -1)
    {
        $this->vars['output'] = preg_replace($pattern, $replacement, $this->vars['output'], $limit, $count);
        return (int) $count;
    }
    public function setOutput($output)
    {
        $this->vars['output'] = $output;
    }
}

/**
 * @method Invoice getInvoice()
 * @method InvoiceItem getItem()
 * @method bool isFirst
 */
class Am_Event_CalculateStartDate extends Am_Event
{
}


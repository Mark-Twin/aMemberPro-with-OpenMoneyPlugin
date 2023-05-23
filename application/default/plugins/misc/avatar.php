<?php

/**
 * Plugin add new form brick "Avatar".
 * User can upload some picture here.
 */

class Am_Plugin_Avatar extends Am_Plugin
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_COMM = self::COMM_COMMERCIAL;
    const PLUGIN_REVISION = '5.5.4';

    const DEFAULT_WIDTH = 80;
    const DEFAULT_HEIGHT = 80;
    const UPLOAD_PREFIX = 'avatar';
    protected $_configPrefix = 'misc.';

    static function getDbXml()
    {
        return <<<CUT
<schema version="4.0.0">
    <table name="user">
        <field name="avatar" type="int" notnull="0" />
    </table>
    <table name="admin">
        <field name="avatar" type="int" notnull="0" />
    </table>
</schema>
CUT;
    }

    function init()
    {
        parent::init();
        $this->getDi()->uploadTable->defineUsage(self::UPLOAD_PREFIX,
            'user', 'avatar',
            UploadTable::STORE_FIELD, "User Avatar [%login%, %name_f% %name_l%]", '/admin-users?_u_a=edit&_u_id=%user_id%');
    }

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->setTitle(___('Avatar'));

        $group = $form->addGroup('')
            ->setLabel(___("Avatar Size\n" .
                'width×height'));

        $group->addText('width', array('size'=>4))
            ->setValue(self::DEFAULT_WIDTH);
        $group->addStatic('')->setContent(' &times; ');
        $group->addText('height', array('size'=>4))
            ->setValue(self::DEFAULT_HEIGHT);

        $form->addUpload('default', null, array('prefix' => self::UPLOAD_PREFIX))
            ->setLabel(___('Default Avatar'));
        $form->addAdvCheckbox('rpl_block')
            ->setLabel(___('Switch default identity block with block with avatar'));
    }

    function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        switch ($request->getActionName()) {
            case 'u' :
                if (!$user = $this->getDi()->userTable->findFirstByLogin($request->getParam('login'))) {
                    throw new Am_Exception_NotFound;
                }
                $id = $user->avatar ?: $this->getConfig('default');
                break;
            default:
                $id = $request->getActionName(); //actualy it is upload_id
                $id = ($id == 'index') ? $this->getConfig('default') : $id;
        }
        $this->sendAvatar($id);
    }

    function sendAvatar($id)
    {
        while (@ob_end_clean());
        $this->getDi()->session->writeClose();

        $height = $this->getConfig('height', self::DEFAULT_HEIGHT);
        $width = $this->getConfig('width', self::DEFAULT_WIDTH);

        if ($id) {
            /* @var $upload Upload */
            $upload = $this->getDi()->uploadTable->load($id);
            if ($upload->prefix != self::UPLOAD_PREFIX)
                throw new Am_Exception_InputError(sprintf('Incorrect prefix requested [%s]', $upload->prefix));
            $cachename = floor($id / 100) . '/' . $id;
            $fullpath = $upload->getFullPath();
            $type = $upload->getType();
        } else {
            $cachename = 'default';
            $fullpath = $this->getDi()->view->_scriptPath('default-avatar.png');
            $type = 'image/png';
        }

        $filename = $this->getDi()->upload_dir . '/avatar/' . $width . '_' . $height . '/' . $cachename . '.jpeg';

        if (!file_exists($filename)) {
            while (!is_dir(dirname($filename))) {
                mkdir(dirname($filename), 0777, true);
            }

            $image = new Am_Image($fullpath, $type);
            $image->resize($width, $height)->save($filename);
        }

        header('Content-Type: image/jpeg');
        readfile($filename);
        exit;
    }

    function onGridUserInitGrid(Am_Event_Grid $e)
    {
        /* @var $grid Am_Grid_Editable */
        $grid = $e->getGrid();

        /* @var $customize Am_Grid_Action_Customize */
        $customize = $grid->actionGet('customize');
        $customize->addField(new Am_Grid_Field('avatar', ___('Avatar'), true, null, array($this, 'renderAvatar'), '1%'));
    }

    function renderAvatar(User $record, $field, $grid)
    {
        return sprintf('<td><div style="margin:0 auto; width:40px; height:40px; border-radius:50%%; overflow: hidden; box-shadow: 0 2px 4px #d0cfce;"><img src="%s" height="40px" width="40px"/></div></td>', $this->getDi()->url('misc/avatar/' . $record->avatar));
    }

    function onGetUploadPrefixList(Am_Event $event)
    {
        $event->addReturn(array(
            Am_Upload_Acl::IDENTITY_TYPE_ADMIN => Am_Upload_Acl::ACCESS_ALL,
            Am_Upload_Acl::IDENTITY_TYPE_USER => Am_Upload_Acl::ACCESS_ALL,
            Am_Upload_Acl::IDENTITY_TYPE_ANONYMOUS => Am_Upload_Acl::ACCESS_ALL
        ), self::UPLOAD_PREFIX);
    }

    function onGridAdminInitForm(Am_Event $event)
    {
        $form = $event->getGrid()->getForm();
        $fs = $form->getElementById('qfauto-0');
        $upload = new Am_Form_Element_Upload('avatar', null, array('prefix' => self::UPLOAD_PREFIX));
        list($_) = $fs->getElementsByName('login');
        $fs->insertBefore($upload, $_);
        $upload->setLabel(___('Avatar'))
            ->setAllowedMimeTypes(array(
                'image/jpeg','image/gif','image/png'
            ))
            ->setJsOptions(<<<CUT
{
   onFileDraw: function(info) {
     var \$div = jQuery('<div class="am-avatar-preview"><img src="' + escape(amUrl('/misc/avatar/' + info.upload_id)) + '" /></div>');
     jQuery(this).closest('div').prepend(\$div);
     this.data('avatar-preview-conteiner', \$div);
   },
   onChange: function(count) {
      var avatar;
      if (count == 0 && (avatar = this.data('avatar-preview-conteiner'))) {
        avatar.remove();
        this.data('avatar-preview-conteiner', false);
      }

   }
}
CUT
        );
    }

    function onGridUserInitForm(Am_Event $event)
    {
        $upload = $event->getGrid()->getForm()->addUpload('avatar', null, array('prefix' => self::UPLOAD_PREFIX))
            ->setLabel(___('Avatar'))
            ->setAllowedMimeTypes(array(
                'image/jpeg','image/gif','image/png'
            ))
            ->setJsOptions(<<<CUT
{
   fileBrowser:false,
   onFileDraw: function(info) {
     var \$div = jQuery('<div class="am-avatar-preview"><img src="' + escape(amUrl('/misc/avatar/' + info.upload_id)) + '" /></div>');
     jQuery(this).closest('div').prepend(\$div);
     this.data('avatar-preview-conteiner', \$div);
   },
   onChange: function(count) {
      var avatar;
      if (count == 0 && (avatar = this.data('avatar-preview-conteiner'))) {
        avatar.remove();
        this.data('avatar-preview-conteiner', false);
      }

   }
}
CUT
                );
    }

    function onInitFinished(Am_Event $e)
    {
        if ($this->getConfig('rpl_block')) {
            $this->getDi()->blocks->remove('member-identity');
            $this->getDi()->blocks->add('member/identity', new Am_Block_Base(null, 'member-identity-avatar', null, function(Am_View $v){
                $login = Am_Html::escape($v->di->user->login);
                $url = Am_Di::getInstance()->url('logout');
                $url_label = Am_Html::escape(___('Logout'));
                $avatar_url = Am_Di::getInstance()->url('misc/avatar/' . $v->di->user->avatar);
                return <<<CUT
<div class="am-user-identity-block-avatar">
    <div class="am-user-identity-block-avatar-pic">
        <img src="$avatar_url" />
    </div>
    <span class="am-user-identity-block_login">$login</span> <a href="$url">$url_label</a>
</div>
CUT;
            }));
        }
        $this->getDi()->front->getRouter()->addRoute("misc-{$this->getId()}-user",
            new Am_Mvc_Router_Route("/misc/{$this->getId()}/u/:login",
                    array(
                        'module' => 'default',
                        'controller' => 'direct',
                        'plugin_id' => $this->getId(),
                        'type' => 'misc',
                        'action' => 'u'
                    )
                ));
    }

    public function getReadme()
    {
        return <<<CUT
<strong>Plugin Features</strong>

1. Plugin add new Form Brick - Avatar, you can optionally add it
to signup or profile form at
aMember CP -> Configuration -> Forms Editor

2. You can upload avatar for both user and admin in admin interface at
aMember CP -> Users -> Browse Users -> (edit)
aMember CP -> Configuration -> Admin Accounts -> (edit)

3. You can add column with Avatar to users grid in admin interface
aMember CP -> Users -> Browse Users
click link 'customize' below table on right side.

4. In this configuration form above you can enable option to show user avatar
in his dashboard - Switch default identity block with block with avatar.

5. In event of you use Helpdesk module then you can show user/admin
avatars in ticket conversion. You can enable it in Heldpesk module configuration
aMember CP -> Configuration -> Setup/Configuration -> Helpdesk (Show Avatars in Ticket Conversation)

6. In event of you use Directory module then you can show user
avatars in your directories. You can enable option to show this column in
directory configuration
aMember CP -> Protect Content -> Members Directory -> (edit)
CUT;
    }
    function onDeletePersonalData(Am_Event $event){
        $user = $event->getUser();
        if($user->avatar){
            $height = $this->getConfig('height', self::DEFAULT_HEIGHT);
            $width = $this->getConfig('width', self::DEFAULT_WIDTH);
            
            $upload = $this->getDi()->uploadTable->load($user->avatar);
            
            if(is_file($filename = $upload->getFullPath())){
                unlink($filename);
            }
            
            $cachename = floor($user->avatar / 100) . '/' . $user->avatar;
            $fullpath = $upload->getFullPath();
            $type = $upload->getType();

            $filename = $this->getDi()->upload_dir . '/avatar/' . $width . '_' . $height . '/' . $cachename . '.jpeg';
            
            if(is_file($filename)){
                unlink($filename);
            }
            $user->avatar = null; 
            $user->save();
        }
    }
}

Am_Di::getInstance()->hook->add(Am_Event::LOAD_BRICKS, function(Am_Event $e) {

    class Am_Form_Brick_Avatar extends Am_Form_Brick
    {
        protected $labels = array("Avatar\nwill be resized to %dx%d. PNG, JPG and GIF formats is allowed");

        protected $hideIfLoggedInPossible = self::HIDE_DESIRED;

        public function __construct($id = null, $config = null)
        {
            $this->name = ___('Avatar');
            parent::__construct($id, $config);
        }

        public function insertBrick(HTML_QuickForm2_Container $form)
        {
            $plugin = Am_Di::getInstance()->plugins_misc->loadGet('avatar');

            $form->addUpload('avatar', null, array('prefix' => Am_Plugin_Avatar::UPLOAD_PREFIX))
                ->setLabel($this->___("Avatar\nwill be resized to %dx%d. PNG, JPG and GIF formats is allowed",
                        $plugin->getConfig('width', Am_Plugin_Avatar::DEFAULT_WIDTH),
                        $plugin->getConfig('height', Am_Plugin_Avatar::DEFAULT_HEIGHT)))
                ->setAllowedMimeTypes(array(
                    'image/jpeg','image/gif','image/png'
                ))
                ->setJsOptions(<<<CUT
{
   fileBrowser:false,
   onFileDraw: function(info) {
     var \$div = jQuery('<div class="am-avatar-preview"><img src="' + escape(amUrl('/misc/avatar/' + info.upload_id)) + '" /></div>');
     jQuery(this).closest('div').prepend(\$div);
     this.data('avatar-preview-conteiner', \$div);
   },
   onChange: function(count) {
      var avatar;
      if (count == 0 && (avatar = this.data('avatar-preview-conteiner'))) {
        avatar.remove();
        this.data('avatar-preview-conteiner', false);
      }

   },
   urlUpload : '/upload/upload',
   urlGet : '/upload/get'
}
CUT
                    );
        }
    }

});
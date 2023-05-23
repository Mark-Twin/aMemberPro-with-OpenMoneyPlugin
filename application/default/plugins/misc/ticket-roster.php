<?php

/**
 * Plugin allow to set up ticket roster for admin users.
 * New tickets in helpdesk will be assigned to admin users
 * depends on plugin configuration
 *
 */
class Am_Plugin_TicketRoster extends Am_Plugin
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '5.5.4';

    const STORE_FIELD_NAME = 'ticket_roster_queue_last';

    protected $_configPrefix = 'misc.';

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->setTitle(___('Ticket Roster'));

        $options = array();
        foreach ($this->getDi()->adminTable->findBy() as $admin) {
            $options[$admin->pk()] = sprintf('%s (%s %s)', $admin->login, $admin->name_f, $admin->name_l);
        }

        $form->addSortableMagicSelect('roster')
            ->setLabel(___("Choose admins to include to roster\n" .
                    'You can choose same admin several times to balance tickets accordingly'))
            ->loadOptions($options)
            ->setJsOptions('{allowSameValue:true, sortable:true}');
    }

    function onHelpdeskTicketAfterInsert(Am_Event $event)
    {
        /* @var $ticket HelpdeskTicket */
        $ticket = $event->getTicket();
        if (@!$ticket->owner_id) {
            $queue_last = $this->getDi()->store->get(self::STORE_FIELD_NAME);
            if ($queue_last == '')
                $queue_last = -1;
            $roster = $this->getConfig('roster');
            if ($roster) {
                $queue_last++;
                if (!isset($roster[$queue_last]))
                    $queue_last = 0;
                $this->getDi()->store->set(self::STORE_FIELD_NAME, $queue_last);
                $ticket->updateQuick('owner_id', $roster[$queue_last]);
            }
        }
    }

}
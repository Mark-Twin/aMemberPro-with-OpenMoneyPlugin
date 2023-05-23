<?php

class Am_Plugin_ThanksRedirect extends Am_Plugin
{

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->setTitle(___('Thanks Redirect'));
        $form->addText('url', array('class' => 'el-wide'))
            ->setLabel(___("After Purchase Redirect User to this URL\ninstead of thanks page\n" .
                'You can use %root_url%, %root_surl%, %invoice.%, %product.% and %user.% variables in url eg: %user.login%, %user.email%, %invoice.public_id% etc.'));
    }

    function onGridProductInitForm(Am_Event_Grid $e)
    {
        $e->getGrid()->getForm()->getAdditionalFieldSet()
            ->addText('_thanks_redirect_url', array('class' => 'el-wide'))
            ->setLabel(___("After Purchase Redirect User to this URL\ninstead of thanks page\n" .
                'You can use %root_url%, %root_surl%, %invoice.%, %product.% and %user.% variables in url eg: %user.login%, %user.email%, %invoice.public_id% etc.'));
    }

    function onGridProductValuesFromForm(Am_Event_Grid $e)
    {
        $args = $e->getArgs();
        $product = $args[1];
        $product->data()->set('thanks_redirect_url', @$args[0]['_thanks_redirect_url']);
    }

    function onGridProductValuesToForm(Am_Event_Grid $e)
    {
        $args = $e->getArgs();
        $product = $args[1];
        $args[0]['_thanks_redirect_url'] = $product->data()->get('thanks_redirect_url');
    }

    function onThanksPage(Am_Event $e)
    {
        if(!$e->getInvoice()) return;

        $url = $this->getConfig('url');
        foreach ($e->getInvoice()->getProducts() as $pr) {
            if ($url = $pr->data()->get('thanks_redirect_url'))
                break;
        }

        $t = new Am_SimpleTemplate();
        $t->assignStdVars();
        $t->assign('invoice', $e->getInvoice());
        $t->assign('user', $e->getInvoice()->getUser());
        if ($product = $e->getInvoice()->getItem(0)->tryLoadProduct()) {
            $t->assign('product', $product);
        }

        if ($url = $t->render($url)) {
            Am_Mvc_Response::redirectLocation($url);
        }
    }
}
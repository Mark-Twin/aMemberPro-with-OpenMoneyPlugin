<?php

class Am_ShoppingCart
{
    /** Invoice */
    protected $invoice;
    protected $stick = array();

    public function __construct(Invoice $invoice)
    {
        $this->invoice = $invoice;
    }

    function addItem($product, $qty = 1, $options = array())
    {
        $this->invoice->add($product, $qty, $options);
    }

    function deleteItem($product) {
        if ($this->isStick($productOrItem))
            throw new Am_Exception_InputError("This item is stick and cannot be removed");
        if ($item = $this->getInvoice()->findItem('product', $product->pk()))
                $this->getInvoice()->deleteItem($item);
    }

    /** @return Invoice */
    function getInvoice()
    {
        return $this->invoice;
    }

    /**
     * @return array of InvoiceItem
     */
    function getItems()
    {
        return $this->invoice->getItems();
    }

    /**
     * @return Am_Currency
     */
    function getCurrency($amount)
    {
        return $this->invoice->getCurrency($amount);
    }

    function hasItem($product)
    {
        foreach($this->getItems() as $item) {
            if ($item->item_id == $product->pk()) return true;
        }
        return false;
    }

    function getItem($product)
    {
        foreach($this->getItems() as $item) {
            if ($item->item_id == $product->pk()) return $item;
        }
        return null;
    }
    
    /**
     * Item for this product_id cannot be removed from the cart
     */
    function stickItem($product)
    {
        $this->stick[$product->pk()] = true;
    }
    function unstickItem($product)
    {
        unset($this->stick[$product->pk()]);
    }
    function isStick($productOrItem)
    {
        if ($productOrItem instanceof Product)
            $k = $productOrItem->pk();
        elseif ($productOrItem instanceof InvoiceItem)
            $k = $productOrItem->item_id;
        else
            return false;
        return !empty($this->stick[$k]);
    }

    function getText()
    {
        $items = $this->invoice->getItems();
        if (!$this->invoice->getItems())
            return ___('You have no items in shopping cart');
        $c = count($items);
        return ___('You have %d items in shopping cart', $c);
    }

    /**
     * @param string $code
     * @return null|string null if ok, or error message
     */
    function setCouponCode($code)
    {
        $this->invoice->setCouponCode($code);
        $errors = $this->invoice->validateCoupon();
        if($errors) $this->invoice->setCouponCode (null);
        return $errors;
    }

    function getCouponCode()
    {
        $coupon = $this->invoice->getCoupon();
        if ($coupon) return $coupon->code;
    }

    function setUser(User $user)
    {
        $this->invoice->setUser($user);
    }

    function calculate()
    {
        $this->invoice->calculate();
    }

    function clear()
    {
        $this->invoice = null;
        $this->stick = array();
    }
}
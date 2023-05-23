/* Shopping cart javascript code */

/**
 * Browser compatibility
 * https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/JSON
 */
if (!window.JSON) {
    window.JSON = {
        parse: function (sJSON) { return eval("(" + sJSON + ")"); },
        stringify: function (vContent) {
            if (vContent instanceof Object) {
                var sOutput = "";
                if (vContent.constructor === Array) {
                    for (var nId = 0; nId < vContent.length; sOutput += this.stringify(vContent[nId]) + ",", nId++);
                    return "[" + sOutput.substr(0, sOutput.length - 1) + "]";
                }
                if (vContent.toString !== Object.prototype.toString) { return "\"" + vContent.toString().replace(/"/g, "\\$&") + "\""; }
                for (var sProp in vContent) { sOutput += "\"" + sProp.replace(/"/g, "\\$&") + "\":" + this.stringify(vContent[sProp]) + ","; }
                return "{" + sOutput.substr(0, sOutput.length - 1) + "}";
            }
            return typeof vContent === "string" ? "\"" + vContent.replace(/"/g, "\\$&") + "\"" : String(vContent);
        }
    };
}

var cart = {
    items: {}
    ,_detectRootUrl : function()
    {
        var js = document.querySelector('script[src*="/application/cart/views/public/js/cart.js"]');
        if (js)
        {
            return js.getAttribute('src').replace(/\?.*$/, '').replace(new RegExp('/application/cart/views/public/js/cart.js'), '');
        }
    }
    ,_goCategory: function(category_id)
    {
        window.location =
            this._getUrl('') + '?c=' + category_id;
    }
    ,_initCategorySelect : function() {
        jQuery(".am-cart-category-list select").change(function(){
           cart._goCategory(jQuery(this).val());
        });
    }
    ,_getUrl: function(action, return_path, addBackParam)
    {
        var ret = amUrl('/cart' + (action ? ('/index/' + action) : ''), return_path||0);
        if (addBackParam) {
            ret += ret.match(/\?/) ? '&' : '?';
            ret += 'b=' + encodeURIComponent(window.location.protocol + '//' + window.location.host + window.location.pathname + window.location.search)
        }
        return ret;
    }
    ,_getBillingPlanId: function(item_id)
    {
        return jQuery(":input[name='plan["+item_id+"]']").val();
    }
    ,_getProductOptions: function(item_id)
    {
        return jQuery("#product-options-" + item_id).serialize();
    }
    ,_getQty: function(item_id)
    {
        return jQuery(":input[name='qty["+item_id+"]']").val();
    }
    ,_addOnly: function(data, callback, element)
    {
        this._showError(); // hide error message
        var url = cart._getUrl('ajax-add-only', 1);
        jQuery.post(
            url[0], jQuery.merge(url[1],
            [{name:'data', value:JSON.stringify(data)}]),
            function(response){
                if (response.status != 'ok') {
                    cart._showError(element, response.message);
                    cart.loadOnly();
                }
                else if (typeof callback == "function")
                        callback();
            }
        );
        return this;
    }
    ,_removeOnly: function(data, callback, element)
    {
        this._showError(); // hide error message
        var url = cart._getUrl('ajax-remove-only', 1);
        jQuery.post(
            url[0], jQuery.merge(url[1],
            [{name:'data', value:JSON.stringify(data)}]),
            function(response){
                if (response.status != 'ok') {
                    cart._showError(element, response.message);
                    cart.loadOnly();
                }
                else if (typeof callback == "function")
                        callback();
            }
        );
        return this;
    }

    ,_getObj: function(args)
    {
        var data = [];
        var elem = (args[0].nodeType) ? true : false;

        for (var key in args) {
            if (elem){
                elem = false;
                continue;
            }
            switch (typeof args[key]){
                case 'object':
                    if (typeof args[key][0] != 'undefined'){  // it's simple array
                        for (var k in args[key])
                            data.push({id:args[key][k]});
                    } else {    // it's object
                        data.push(args[key]);
                    }
                    break;
                case 'number':
                    // item_id only
                    data.push({id:args[key]});
                    break;
                case 'string':
                    // item_id only
                    var arr = args[0].split(',');
                    for (var k in arr)
                        data.push({id:arr[k]});
                    break;
            }
        }

        return data;
    }
    ,_showError: function(element, mess)
    {
        if (element && mess && mess.length > 0){
            jQuery(element).parent().last().after('<div class="error" id="am-cart-message-error">' + mess + '</div>');
        } else {
            jQuery("#am-cart-message-error").remove();
        }
    }
    ,init : function()
    {
        if (!window.hasOwnProperty('amUrl'))
        {
            var rootUrl = cart._detectRootUrl();
            window.rootUrl = rootUrl; // for compat only
            window.amUrl = function (u, return_path) { 
                var ret = rootUrl + u; 
                return (return_path || 0) ? [ret, []] : ret;
            } ;
        }
        var loadRunned = 0;
        if (typeof(jQuery) == 'undefined') {
            var jqueryUrl = '//code.jquery.com/jquery-2.2.4.min.js';
            if (! loadRunned++)
            {
                document.write("<scr" + "ipt type=\"text/javascript\" src=\""+jqueryUrl+"\"></scr" + "ipt>");
                document.write("<scr" + "ipt type=\"text/javascript\">cart.init()</scr" + "ipt>");
            }
        } else {
            jQuery(function() {
                jQuery.ajaxSetup({
                    xhrFields : {
                        withCredentials: true
                    }
                });
                cart._initCategorySelect();
            });
        }
    }
    ,popupBasket : function()
    {
        jQuery("#ajax-basket").remove();
        jQuery.get(cart._getUrl('view-basket'), {}, function(html){
            jQuery('body').append('<div id="ajax-basket" style="display:none"></div>');
            jQuery("#ajax-basket").html(html).amPopup({
                width : '600px',
                title: 'Your Order'
            });
        });
    }
    ,loadOnly: function()
    {
        //#block-cart-basket - backward compatability, markup: <div class="am-block block"><div id="block-cart-basket"></div></div>
        var block = jQuery(".am-basket-preview, #block-cart-basket").wrap('<div>').parent();
        if (!block.length)
            return this;
        block.load(this._getUrl('ajax-load-only'), function(){
            block.children().unwrap().not('script').fadeTo('slow', 0.1).fadeTo('slow', 1.0)});
        return this;
    }
    ,add: function(th, item_id, qty, item_type)
    {
        if (arguments.length == 0)
            return cart.loadOnly();
        var callback = function(){
            cart.loadOnly();
        };
        var elem = (th.nodeType) ? th : false;
        cart._addOnly([{
            id : item_id
            ,qty : qty ? qty : (cart._getQty(item_id) || 1)
            ,type : item_type ? item_type : 'product'
            ,plan : cart._getBillingPlanId(item_id)
            ,options : cart._getProductOptions(item_id)
        }]
        , callback
        , elem);
        return this;
    }
    ,addAndPopupBasket: function(th, item_id, qty, item_type)
    {
        if (arguments.length == 0)
            return cart.loadOnly();
        var callback = function(){
            cart.popupBasket();
            cart.loadOnly();
        };
        var elem = (th.nodeType) ? th : false;
        cart._addOnly([{
            id : item_id
            ,qty : qty ? qty : (cart._getQty(item_id) || 1)
            ,type : item_type ? item_type : 'product'
            ,plan : cart._getBillingPlanId(item_id)
            ,options : cart._getProductOptions(item_id)
        }]
        , callback
        , elem);
        return this;
    }
    ,addAndCheckout: function(th, item_id, qty, item_type)
    {
        var callback = function(){
            window.location = cart._getUrl('add-and-checkout',0,1);
        };
        var elem = (th.nodeType) ? th : false;
        cart._addOnly([{
            id : item_id
            ,qty : qty ? qty : (cart._getQty(item_id) || 1)
            ,type : item_type ? item_type : 'product'
            ,plan : cart._getBillingPlanId(item_id)
            ,options : cart._getProductOptions(item_id)
        }]
        , callback
        , elem);
        return this;
    }
    ,addExternal: function()
    {
        var callback = function(){
            cart.loadOnly();
        };
        var elem = (arguments[0].nodeType) ? arguments[0] : false;
        cart._addOnly(cart._getObj(jQuery.makeArray(arguments)), callback, elem);
        return this;
    }
    ,remove: function()
    {
        var callback = function(){
            cart.loadOnly();
        };
        var elem = (arguments[0].nodeType) ? arguments[0] : false;
        cart._removeOnly(cart._getObj(jQuery.makeArray(arguments)), callback, elem);
        return this;
    }

    ,addBasketExternal: function()
    {
        var callback = function(){
            window.location = cart._getUrl('view-basket',0,1);
        };
        var elem = (arguments[0].nodeType) ? arguments[0] : false;
        cart._addOnly(cart._getObj(jQuery.makeArray(arguments)), callback, elem);
        return this;
    }
    ,addCheckoutExternal: function()
    {
        var callback = function(){
            window.location = cart._getUrl('add-and-checkout');
        };
        var elem = (arguments[0].nodeType) ? arguments[0] : false;
        cart._addOnly(cart._getObj(jQuery.makeArray(arguments)), callback, elem);
        return this;
    }
    ,addExternalPlan: function(th, product_id, plan_id)
    {
        var callback = function(){
            cart.loadOnly();
        };
        var elem = (arguments[0].nodeType) ? arguments[0] : false;
        cart._addOnly([{
            id : product_id
            ,qty : 1
            ,type : 'product'
            ,plan : plan_id
        }], callback, elem);
        return this;
    }
    ,addBasketExternalPlan: function(th, product_id, plan_id)
    {
        var callback = function(){
            window.location = cart._getUrl('view-basket',0,1);
        };
        var elem = (arguments[0].nodeType) ? arguments[0] : false;
        cart._addOnly([{
            id : product_id
            ,qty : 1
            ,type : 'product'
            ,plan : plan_id
        }], callback, elem);
        return this;
    }
    ,addCheckoutExternalPlan: function(th, product_id, plan_id)
    {
        var callback = function(){
            window.location = cart._getUrl('add-and-checkout');
        };
        var elem = (arguments[0].nodeType) ? arguments[0] : false;
        cart._addOnly([{
            id : product_id
            ,qty : 1
            ,type : 'product'
            ,plan : plan_id
        }], callback, elem);
        return this;
    }
};

cart.init();
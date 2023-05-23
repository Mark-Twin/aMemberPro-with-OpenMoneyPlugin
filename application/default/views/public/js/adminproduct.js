(function($) {

window.onBillingTitleKeypressHandler = function(event)
{
    if (event.keyCode == 13) {
        event.stopImmediatePropagation();
        event.preventDefault();
        $(this).closest('legend').find('.plan-title-text').text($(this).val());
        $(this).hide();
        $(this).closest('legend').find('.plan-title-text').show();
        $("body").unbind("click.inplace-edit");
        var edit = $(this);
        var id = edit.closest(".billing-plan").attr('id');
        $("#billing-plan-wrap ul a[href='#"+id+"']").text(edit.val());
    }
};

jQuery.fn.billingPlan = function () {
    return this.each(function () {
        var plan = this; // fieldset
        // get id
        var id = $(this).attr("id").replace(/^plan-/, '');
        if (id === 'TPL')
            return;
        $(this).find('.magicselect').restoreMagicSelect();
        $(this).data("id", id);

        $('legend', this).append(' : <span class="terms-text"></span>');

        var currency = $("select[name$='[currency]']", this);
        var first_price = $("input[name$='[first_price]']", this);
        var first_period_c = $("input[name$='[first_period][c]']", this);
        var first_period_u = $("select[name$='[first_period][u]']", this);
        var t_rebill_times = $("input[name$='[rebill_times]']", this);
        var s_rebill_times = $("select[name$='[_rebill_times]']", this);
        var second_price = $("input[name$='[second_price]']", this);
        var second_period_c = $("input[name$='[second_period][c]']", this);
        var second_period_u = $("select[name$='[second_period][u]']", this);

        currency.change(function(){
            $('.billing-plan-currency', $(this).closest('fieldset')).text($(this).val());
        }).change();

        currency.select2({
            minimumResultsForSearch : 10,
            width: "resolve"
        });

        t_rebill_times.change(function () {
            if ($(this).val() >= 1)
                $(second_period_c).add(second_price).parents(".row").show();
            else
                $(second_period_c).add(second_price).parents(".row").hide();
        });
        s_rebill_times.change(function () {
            var sel = $(this);
            var val = sel.val();
            var txt = t_rebill_times;
            if (val == "x") {
                txt.show();
                if (txt.data("saved_value") != null)
                    txt.val(txt.data("saved_value"));
            } else {
                if (sel.data("saved_value") == "x")
                    txt.data("saved_value", txt.val());
                txt.val(val);
                txt.hide();
            }
            sel.data("saved_value", sel.val());
            txt.change();
        }).change();
        first_period_u.change(function () {
            var val = $(this).val();
            val == "lifetime" ? first_period_c.hide().val('2037-12-31') : first_period_c.show();
            if (val != 'lifetime') // if reverting from lifetime, reset "count"
            {
                if (first_period_c.val() == '2037-12-31')
                    first_period_c.val('');
            }
            var showSecond = (val == "lifetime" || val == "fixed");
            if (showSecond) {
                s_rebill_times.val("0").change().parents(".row").hide();
            } else {
                s_rebill_times.parents(".row").show();
                if (second_price.val() == "" && second_period_c.val() == "")
                {
                    second_price.val(first_price.val());
                    second_period_c.val(first_period_c.val());
                    second_period_u.val(first_period_u.val());
                }
            }
        }).change();
        second_period_u.change(function () {
            var val = $(this).val();
            val == "lifetime" ? second_period_c.hide() : second_period_c.show();
            if (val != 'lifetime') // if reverting from lifetime, reset "count"
            {
                s_rebill_times.find('option[value="x"],option[value="99999"]').prop("disabled", false);
                if (second_period_c.val() == '2037-12-31')
                    second_period_c.val('');
            } else {
                // only allow to second price be charged once if lifetime
                s_rebill_times.val('1').change();
                s_rebill_times.find('option[value="x"],option[value="99999"]').prop("disabled", true);
            }
        }).change();

        $(".plan-title-edit", this).bind('keypress', window.onBillingTitleKeypressHandler);

        plan.getPeriodText = function (c, u, skip_one_c)
        {
            var uu;
            switch (u) {
                case 'd':
                    uu = c == 1 ? 'day' : 'days';
                    break;
                case 'm':
                    uu = c == 1 ? 'month' : 'months';
                    break;
                case 'y':
                    uu = c == 1 ? 'year' : 'years';
                    break;
                case 'fixed':
                    return " up to " + c;
            }
            var cc = c;
            if (c == 1)
                cc = skip_one_c ? '' : 'one';
            return cc + ' ' + uu;
        };

        plan.calculateTerms = function ()
        {
            var undef = "&ndash;";
            var vals = this.getValues();

            if (!vals.first_price.length && !vals.first_period_c.length)
                return undef;

            var first_price = parseFloat(vals.first_price);
            var first_period_c = parseInt(vals.first_period_c);
            var first_period_u = vals.first_period_u;
            var rebill_times = parseInt(vals._rebill_times == 'x' ? vals.rebill_times : vals._rebill_times);
            var second_price = parseFloat(vals.second_price);
            var second_period_c = parseInt(vals.second_period_c);
            var second_period_u = vals.second_period_u;
            var currency = vals.currency;

            var c1 = first_price + ' ' + currency;
            if (first_price <= 0)
                c1 = 'Free';
            var c2 = second_price + ' ' + currency;
            if (second_price <= 0)
                c2 = 'free';

            var ret = c1;
            if (first_period_u != 'lifetime')
                if (rebill_times)
                    ret += " for first " + this.getPeriodText(first_period_c, first_period_u, true)
                else
                    ret += " for " + this.getPeriodText(first_period_c, first_period_u)
            if (rebill_times)
            {
                if (second_period_u == 'lifetime')
                {
                    ret += ", then " + c2 + " for lifetime";
                } else {
                    ret += ", then " + c2 + " for every " + this.getPeriodText(second_period_c, second_period_u);
                    if (rebill_times < 9999)
                        ret += ", for " + (rebill_times) + " installments";
                }
            }
            return ret.replace(/[ ]+/g, ' ');
        };

        plan.getValues = function ()
        {
            var vals = {};
            $("[name]:input", this).each(function () {
                var el = $(this);
                vals[
                        el.attr("name")
                        .replace(/_plan\[.+?\]\[/, '')
                        .replace(/\]$/, '')
                        .replace(/\]\[/, '_')
                ] = typeof (el.val()) == 'string' ? el.val().replace(/^[ ]+/, '').replace(/[ ]+$/, '') : el.val();
            });
            return vals;
        };

        $([currency[0], first_price[0], first_period_c[0], first_period_u[0], t_rebill_times[0], s_rebill_times[0],
            second_price[0], second_period_c[0], second_period_u[0]]).change(function () {
            var text = plan.calculateTerms();
            $(".terms-text", plan).html(text);
            $("#billing-plan-wrap ul a[href='#"+$(plan).prop('id')+"']").prop('title', text);
        }).change();
    });
};

$(document).on("click", "a.billing-plan-del", function (event) {
    var id = $(this).closest("li").data("plan_id");
    if ($(".billing-plan").length <= 2)
    {
        alert("You cannot delete last billing plan. Please add another billing plan first");
        return;
    }
    if (!confirm("Are you sure you want to remove this billing plan?"))
        return;
    $("#billing-plan-wrap").tabs("option", "active", 0);
    $("#plan-" + id).remove();
    $("#billing-plan-wrap a[href='#plan-"+id+"']").closest('li').remove();
    $("#billing-plan-wrap").tabs("refresh");
    event.stopPropagation();
});

$(document).on("click", ".plan-add", function (event) {
    var d = new Date();
    var newId = d.getTime();
    var html = $("#plan-TPL").html()
            .replace(/TPL/g, newId)
            .replace(/TEMPLATE/g, 'New Billing Plan');
    $("#billing-plan-wrap .plan-add").closest("li").before("<li id='tab-plan-" + newId + "' data-plan_id='"+newId +
            "'><a href='#plan-"+newId+"'><span>New Billing Plan</span></a><a href='javascript:;' class='billing-plan-del' title='Delete Billing Plan'>&#10005;</a></li>");
    $("#billing-plan-wrap").tabs('refresh');
    $("#plan-TPL").after('<fieldset class="billing-plan" id="plan-' + newId + '">' + html + '</fieldset>');
    $("#plan-" + newId + " .plan-title-text").click();
    $("#plan-" + newId + " .plan-title-edit").focus().select();
    $("#plan-" + newId + " .plan-title-edit").bind('keypress', window.onBillingTitleKeypressHandler);
    $("#plan-" + newId).billingPlan();
    $("#billing-plan-wrap").tabs('option', 'active', -2);
    $('#billing-plan-wrap .ui-tabs-nav').sortable('refresh');
    $('#billing-plan-wrap .ui-tabs-nav').sortable('option','update')();
    event.stopPropagation();
});

$(function () {
    $(document).on("change", ".billing-plan .variable_qty", function () {
        var txt = $(this).parents(".element").find("input[type='text']");
        if (this.checked)
            txt.val(1).prop("readonly", "readonly").css("color", "gray");
        else
            txt.prop("readonly", null).css("color", "black");
    });
    $(".billing-plan .variable_qty").change();
});

$(document).on('click', ".plan-title-text", function (event) {
    var txt = $(this);
    var edit = txt.parents("legend").find(".plan-title-edit");
    txt.hide();
    edit.show().focus().select();
    event.stopPropagation();
    // bind outerclick event
    $("body").bind("click.inplace-edit", function (event) {
        if (!$(event.target).is(".plan-title-edit"))
        {
            txt.text(edit.val());
            edit.hide();
            txt.show();
            $("body").unbind("click.inplace-edit");
            var id = txt.closest(".billing-plan").attr('id');
            $("#billing-plan-wrap ul a[href='#"+id+"']").text(edit.val());
        }
    });
});

/**
 * list of billing plans currently defined on the page
 * @returns [ {}, {} ]
 */
function getBillingPlans()
{
    var plans = [];
    $(".billing-plan").each(function () {
        $this = $(this);
        var id = $this.prop('id').replace(/^plan-/, '');
        if (id == 'TPL') return;
        var p = {
            'id': id,
            'title': $this.find(".plan-title-edit").val(),
            'first_price': $this.find(':input[name*="first_price"]').val(),
            'second_price': $this.find(':input[name*="second_price"]').val(),
            'rebill_times': $this.find('select[name*="_rebill_times"]').val(),
            'currency': $this.find("select[name$='[currency]']").val()
        };
        plans.push(p);
    });
    return plans;
}

function getRenewalOptions()
{
    return "<option>xx</option><option>yy</option>";
}

jQuery(document).ready(function ($) {
    $(".billing-plan").billingPlan();
    $("#row-am-product-option-group-TPL").productOptions();
    $(".am-product-option-add").click(function () {
        $(this).productOptions("add");
    });

    $("input[name='start_date_fixed']").prop("disabled", "disabled");
    $("select[name='renewal_group']").prop("id", "renewal_group").after($("<span> <a href='javascript:' id='add-renewal-group' class='local'>add group</a></span>"));

    $("select[name='renewal_group']").change(function () {
        $(this).toggle($(this).find('option').length > 1);
    }).change();

    $("#start-date-edit").magicSelect({
        callbackTitle: function (option) {
            var ret = option.text;
            if (option.value == 'fixed')
            {
                var el = $("input[name='start_date_fixed']");
                var html = $("<p></p>").append(el.clone()
                        .prop("disabled", "").show()
                        .prop("id", "start_date_fixed")
                        .removeClass('hasDatepicker')
                        ).html();
                ret += "&nbsp;" + html;
            }
            return ret;
        }
    });

    $(document).on('click', "a#add-renewal-group", function () {
        var ret = prompt("Enter title for your new renewal group, for example: group#1", "");
        if (!ret)
            return;
        var $sel = $("select#renewal_group").append(
                $("<option></option>").val(ret).html(ret));
        $sel.val(ret).change();
    });

    $(document).on('focus', "input[name='start_date_fixed']", function () {
        if ($(this).hasClass('hasDatepicker'))
            return;
        $(this).datepicker({
            defaultDate: window.uiDefaultDate,
            dateFormat: window.uiDateFormat,
            changeMonth: true,
            changeYear: true
        });
    });
});

{
    var productOptionTpl;
    var productOptionMethods = {
        init: function () {
            this.each(function () {
                var $this = $(this),
                        data = $this.data('product-option');
                $this.addClass("am-product-option");

                $this.closest("fieldset").sortable({
                    items: '.am-product-option'
                });

                $this.find("a.am-product-option-delete").click(function () {
                    if (confirm('Really delete?'))
                        $(this).closest(".am-product-option").remove();
                });
                $this.find("select.option-type").change(function () {
                    var type = $(this).val();
                    var has_options = (type == 'select' || type == 'multi_select' || type == 'radio' || type == 'checkbox');
                    $(this).closest(".am-product-option").find(".edit-options").toggle(has_options);
                });
                $this.find(".edit-options").click(function () {
                    $(this).closest(".am-product-option").productOptions("editOptions");
                });

                if (this.id == 'row-am-product-option-group-TPL')
                {
                    if (productOptionTpl)
                        return; // already called!
                    productOptionTpl = $this;
                    $this.closest("form").submit(function () {
                        return $(this).productOptions("beforeSubmit");
                    });
                    $this.hide();
                    $this.productOptions("expandValues", $this);
                }
            });

            return this;
        },
        add: function ()
        {
            var n = productOptionTpl.clone(true).attr('id', null);
            $(this).closest("fieldset").find(".am-product-option:last").after(n);
            n.show();
            return this;
        },
        beforeSubmit: function () {
            // collect values from all elements into hidden
            // validate inputs
            $(this).productOptions("collectValues");
            var error = $(this).productOptions("validate");
            return !error;
        },
        validate: function () { // this=form
            var errors = false;
            $(this).find(".am-product-option").
                    not('#row-am-product-option-group-TPL').each(function () {
                var $inp = $(this).find('input[data-field="title"]');
                if ($inp.val() == "")
                {
                    $inp.prop('placeholder','Title is required!').get(0).focus();
                    errors = true;
                }
                var $sel = $(this).find('select[data-field="type"]');
                if ($sel.val() == "")
                {
                    $sel.get(0).focus();
                    errors = true;
                }
            });
            return errors;
        },
        // collect values from inputs into a hidden field
        collectValues: function () { // this=form
            var $hid = $("input#am-product-options-hidden");
            var val = {"options": [], };
            $(this).find(".am-product-option").
                    not('#row-am-product-option-group-TPL').each(function () {
                var $opt = $(this);
                var optval = {};
                $opt.find(":input").each(function () {
                    optval[$(this).data('field')] = this.type == 'checkbox' ?
                                (this.checked ? 1 : 0) :
                                $(this).val();
                });
                val.options.push(optval);
            });
            $hid.val($.toJSON(val));
        },
        expandValues: function ($this) { // set values from hidden to form this=form
            var $hid = $("input#am-product-options-hidden");
            var opts = $.parseJSON($hid.val());
            for (i in opts.options)
            {
                var opt = opts.options[i];
                $this.productOptions("add");
                $opt = $(".am-product-option").last();
                $opt.find(":input").each(function () {
                    if (this.type == 'checkbox') {
                        if (opt [ $(this).data('field') ] == 1)
                            $(this).prop('checked', 'checked');
                    } else {
                        $(this).val(opt [ $(this).data('field') ]);
                    }
                    $(this).change();
                });
            }
        },
        editOptions: function () { // this=option
            var $valInput = $(this).find(".option-options"); // hidden el in form
            $inp = $("<input type='hidden'>");
            $("#am-product-options-options").append($inp); // el in dialog
            var dialog = $("#am-product-options-options").dialog({
                autoOpen: true,
                height: 400,
                width: '80%',
                modal: true,
                title: "Edit Options",
                buttons: {
                    "OK": function () {
                        $valInput.val($inp.productOptionsEditor('updateValue').val());
                        dialog.dialog("close");
                    },
                    "Cancel": function () {
                        if (confirm("Discard changes?"))
                            dialog.dialog("close");
                    }
                },
                close: function () {
                    $inp.remove();
                    $(this).find(".options-editor").remove();
                },
                open: function () {
                    $inp.val($valInput.val());
                    if (!$inp.val())
                        $inp.val($.toJSON({options: {}, default: []}));
                    $inp.addClass("options-editor").productOptionsEditor();
                }
            });
        }
    }

    jQuery.fn.productOptions = function (method) {
        if (productOptionMethods[method]) {
            return productOptionMethods[method].apply(this, Array.prototype.slice.call(arguments, 1));
        } else if (typeof method === 'object' || !method) {
            return productOptionMethods.init.apply(this, arguments);
        } else {
            jQuery.error('Method ' + method + ' does not exist on jQuery.ngrid');
        }
    };

}

;
(function ($) {
    $.fn.productOptionsEditor = function (action)
    {
        return this.each(function(){
            var productOptionsEditor = this;
            var $productOptionsEditor = $(this);
            var $table;
            var $billingPlans = {};
            var Options;

            if (action == 'updateValue') // get result
            {
                var Options = {options:[],default:[],prices:{}};
                var $table = $(this).data('table');
                $table.find("tr.option").each(function(){
                    var key = $(this).find('span[data-field="key"]').text();
                    var label = $(this).find('span[data-field="label"]').text();
                    Options.options.push([key, label]);
                    if ($(this).find('[data-field="default"]').prop('checked'))
                        Options.default.push(key);
                    Options.prices[key] = {};
                    $(this).find("span[data-field$='_price'],a[data-field$='_price']").each(function(){
                        var plan = $(this).data('plan');
                        if (!Options.prices[key][plan])
                            Options.prices[key][plan] = [];
                        var val = parseFloat($(this).text());
                        if (isNaN(val)) return;
                        Options.prices[key][plan][ $(this).data('field')=='first_price'?0:1 ] =
                            val;
                    });
                });
                $(this).val($.toJSON(Options));
                return this;
            }

            if (($productOptionsEditor).data('product-options-editor-init')) return;
            if (this.type != 'hidden')
                throw new Error('Element should be hidden in order to use productOptionsEditor for it. [' + this.type + '] given.');
            ($productOptionsEditor).data('product-options-editor-init', 1);
            // init
            Options = $.parseJSON($(productOptionsEditor).val());
            Options = $.extend({options:[], default:[], prices:{}}, Options);
            $(productOptionsEditor).data('billing-plans', getBillingPlans());

            $('head').append("\
            <style type='text/css' id='hide-option-price'>\n\
                .no-price .option-option-price { display: none; }\n\
                .option-price-zero { font-size: 70%; opacity:.4; padding-left:.2em; }\n\
                .option-price-delimeter { opacity:.3; padding: 0 .2em; }\n\
            </style>");

            Options['default'] = Options['default'] || [];

            // create headers
            $table = $("<table>\n\
            <thead><tr>\n\
                <th title='Is Default?'>Def</th>\n\
                <th>Value</th>\n\
                <th>Label</th>\n\
                <th class='admin-product-option-option-add-wrapper'>&nbsp;</th>\n\
            </tr></thead><tbody>\n\
            \n\
            <tr class='new-option'>\n\
                <td><input type='checkbox' class='option-def' value=1></td>\n\
                <td><input type=text class='option-key' size=5></td>\n\
                <td><input type='text' class='option-label'></td>\n\
                <td class='admin-product-option-option-add-wrapper'><a href='javascript:;' class='button admin-product-option-option-add'>+</a></td>\n\
            </tr>\n\
            <tr class=''>\n\
                <td colspan=4><a href='javascript:;' class='admin-option-option-show-prices local'>Click to edit surcharge amounts (will be added if user selects the option)</a></td>\n\
            </tr>\n\
            </tbody></table>");

            $productOptionsEditor.before($table);
            var $div = $('<div class="options-editor no-price">');
            $table.wrap($div);
            $table.find(".admin-product-option-option-add").click(optionOptionAddClick);
            $productOptionsEditor.data('table', $table);

            var showPrices = $table.find(".admin-option-option-show-prices");
            showPrices.click(function(){
                $table.closest(".options-editor").removeClass('no-price');
                $(this).closest("tr").remove();
            });
            var hasPrices = 0;
            $.each(Options.prices, function(k,v){
                var kk; for (kk in v)
                    if (v[kk][0] || v[kk][1]) hasPrices++;
            });
            if (hasPrices) {
                $table.closest(".options-editor").removeClass('no-price');
                showPrices.closest("tr").remove();
            }

            // deal with billing plans
            $productOptionsEditor.data('billing-plans', getBillingPlans());
            // fill-in prices with empty values
            // now add necessary th td
            $.each($(productOptionsEditor).data('billing-plans'), function (k, plan) {
                var has_second = plan.rebill_times != '0';
                var title = (plan.title.length > 20) ? plan.title.substr(0, 25 - 1) + '&hellip;' : plan.title;
                $table.find('thead tr th.admin-product-option-option-add-wrapper').before("<th class='option-option-price'>" + title + "</th>");
                $table.find('tr.new-option td.admin-product-option-option-add-wrapper').before("<td class='option-option-price' align='right'>"
                        + "<input type='text' placeholder='+First' size='5' class='option-price' data-plan='" + plan.id + "' data-field=first_price>"
                        + (has_second ? "/<input type='text' placeholder='+Rebills' class='option-price' data-plan='" + plan.id + "' data-field=second_price size='5'" : '')
                        + "</td>");
            });

            for (i in Options.options)
            {
                var key = Options.options[i][0];
                var o = Options.options[i][1];
                var prices = Options.prices[key];
                if (!prices) prices = {};
                var def = false;
                for (i in Options.default)
                    if (Options.default[i]==key)
                        def = true;
                renderOptionOptionItem(key, o, def, prices);
            }

            $table.find('tbody').sortable({
                items: 'tr.option'
            });


            // end of init
            function renderOptionOptionItem(key, label, def, prices)
            {
                var $tr = $("<tr class='option'>\n\
                            <td><input type='checkbox' data-field=default value=1></td>\n\
                            <td><span data-field=key></span></td>\n\
                            <td><span data-field=label></span></td>\n\
                            <td align=center class='admin-product-option-option-add-wrapper'><a href='javascript:;'  class='remove-option-option am-link-del' title='Delete'>&#10005;</a></td>\n\
                        </tr>");
                $table.find('.new-option').before($tr);
                $tr.find('span[data-field="key"]').text(key);
                $tr.find('span[data-field="label"]').text(label).liveEdit();
                $tr.find('input[data-field="default"]').prop('checked', def ? true : false);
                $tr.find('.remove-option-option').click(function(){ $(this).closest('tr').remove(); });

                var resetZeroCss = function(newVal,oldVal){
                    var fl = parseFloat(newVal);
                    if (isNaN(fl))
                    {
                        $(this).text(oldVal);
                        return;
                    }
                    var display;
                    if (fl == 0) {
                        display = 0;
                    } else {
                        if (fl == Math.round(fl)) {
                            display = Math.round(fl);
                        } else {
                            display = fl.toFixed(2);
                        }
                    }
                    $(this).toggleClass('option-price-zero', fl == 0.0).text(display);
                };
                var billingPlans = $productOptionsEditor.data('billing-plans');
                for (var i in billingPlans)
                {
                    var plan = billingPlans[i];
                    var has_second = plan.rebill_times != '0';
                    var bpId = plan.id;
                    var $td = $("<td class='option-option-price' style='font-size:1.1rem' align='right'>");
                    var pr = prices[bpId] ? prices[bpId] : [0,0];
                    var $fs = $("<a class='local' href='javascript:;' data-field='first_price'>").text(pr[0]).data('plan', bpId);
                    $td.append($fs);
                    $td.append('<span class=option-price-zero>' + plan.currency + '</span>');
                    $fs.liveEdit({divEditable:false,input:"<input size=5>"}, resetZeroCss);
                    resetZeroCss.call($fs[0], $fs.text(), 0);
                    if (has_second)
                    {
                        var $ss = $("<a class='local' href='javascript:;' data-field='second_price'>").text(pr[1]).data('plan', bpId);
                        $td.append('<span class=option-price-delimeter>/</span>');
                        $td.append($ss);
                        $td.append('<span class=option-price-zero>' + plan.currency + '</span>');
                        $ss.liveEdit({divEditable:false,input:"<input size=5>"}, resetZeroCss);
                        resetZeroCss.call($ss[0], $ss.text(), 0);
                    }
                    $tr.find('.admin-product-option-option-add-wrapper').before($td);
                }

            }
            function optionOptionAddClick()
            {
                // get values
                var $tr = $table.find('.new-option');
                var key = $tr.find('input.option-key').val();
                var label = $tr.find('input.option-label').val();
                var prices = {};
                var def = $tr.find('input.option-def').prop('checked') ? true : false;
                var err = false;
                $tr.find("input[data-plan]").each(function(){
                    var plan = $(this).data('plan');
                    var field = $(this).data('field');
                    var val = (this.value != '') ? parseFloat(this.value) : 0;
                    if ((this.value!='') && isNaN(val))
                    {
                        alert('Incorrect amount');
                        this.focus();
                        err = true;
                    }
                    //
                    if (!prices[plan]) prices[plan] = [0,0];
                    prices[plan][field == 'first_price' ? 0 : 1] = val;
                });
                if (err) return;
                // validate
                if (key.length<1)
                    return $tr.find('input.option-key').prop('placeholder', 'Enter key').get(0).focus();
                if (label.length<1)
                    //alert("Label field is required");
                    return $tr.find('input.option-label').prop('placeholder', 'Label is required').get(0).focus();
                // check key for uniqueness
                var found = 0;
                $table.find('span[data-field="key"]').each(function(){
                    if ($(this).text() == key)
                        found = 1;
                });
                if (found) {
                    alert("Key is not unqiue");
                    $tr.find('input.option-key').get(0).focus();
                    return;
                }
                // renderItem
                renderOptionOptionItem(key,label,def,prices);
                // reset form
                $tr.find(".option-key,.option-label").val("").prop('placeholder', '');
                $tr.find(".option-def").prop('checked', false);
                $tr.find("input[data-plan]").val("");
            }
        });
    };
})(jQuery);

})(jQuery);
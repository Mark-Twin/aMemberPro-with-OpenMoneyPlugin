<?php
/* 
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */
class am4View{
    private $_name;
    private $_fname;
    private $_v;
    private $_controller;
    private $_type;

    const TYPE_HTML =   'phtml';
    const TYPE_CSS  =   'css';
    const TYPE_JS   =   'js';

    const ESC_HTML      =   'html';
    const ESC_HTMLALL   =   'htmlall';
    const ESC_URL       =   'url';
    const ESC_QUOTES    =   'quotes';
    const ESC_HEX       =   'hex';
    const ESC_HEXENTITY =   'hexentity';
    const ESC_JAVASCRIPT=   'javascript';
    const RESOURCE_ACCESS_SKIP_PERIOD = true;

    function __construct($name, am4PageController $controller=null, $type=self::TYPE_HTML){
        
        if(!preg_match('/^[a-z0-9_]+$/', $name)) 
            throw new Exception("Incorrect view name passed!");
        if(!is_file($fname = AM4_PLUGIN_DIR.'/views/'.$name.'.'.$type)){
            throw new Exception("View not found!");
        }
        $this->_fname = $fname;
        $this->_controller = $controller;
        $this->_type = $type;
    }

    static function init($name, am4PageController $controller=null, $type = self::TYPE_HTML){
        return new self($name, $controller,$type);
    }

    function showErrors(){
        if($this->errors){
            foreach($this->errors as $e){
                ?><div class="amember_error"><?php echo $e;?></div><?php
            }
        }
    }

    function assign($name, $value){
        $this->$name = $value;
    }
    
    function get($name){
        return $this->$name;
    }
    function __get($name){
        if(!array_key_exists($name, $this->_v)) return null; 
        return $this->_v[$name];
    }
    function __set($name,$value){
        $this->_v[$name] = $value;
    }
    function fetch(){
        foreach((array)$this->_v as $k=>$v){
            $$k = $v;
        }
        ob_start();
        $_info = pathinfo($this->_fname);
        $_fname = $_info['basename'];
        $templates = new am4_Settings_Templates();
        
        if($templates->{$_fname}){
            try{
                eval("?>".$templates->{$_fname}); 
            }catch(Exception $e){
                print "Error in template: ".$e->getMessage();
            }
        }else{
            require($this->_fname);
        }

        $ret = ob_get_contents();
        ob_end_clean();
        switch($this->_type){
            case self::TYPE_CSS :
                $ret = "<style>\n".$ret."\n</style>";
                break;
            case self::TYPE_JS  :
                $ret = "<script type=\"text/javascript\">\n".$ret."\n</script>";
                break;
            case self::TYPE_HTML :
            default :
                    break;
        }
        return $ret;
    }
    function render(){
        echo  $this->fetch();
    }

    function fetchTemplateCode($path){
        $info = pathinfo($path);
        $fname = $info['basename'];
        $templates = new am4_Settings_Templates();
        if($templates->{$fname}){
            eval("?>".$templates->{$fname}); 
        }else{
            require($path);
        }
        
    }
    function escape($subj, $esc_type=self::ESC_HTML){
        if(is_array($subj)){
            foreach($subj as $k=>$v){
                $subj[$k] = $this->escape($v);
            }
            return $subj;
        }
        switch ($esc_type) {
            case self::ESC_HTML :
                return htmlspecialchars($subj, ENT_QUOTES);

            case self::HTMLALL :
                return htmlentities($subj, ENT_QUOTES);

            case self::URL :
                return urlencode($subj);

            case self::ESC_QUOTES :
                // escape unescaped single quotes
                return preg_replace("%(?<!\\\\)'%", "\\'", $subj);

            case self::ESC_HEX :
                // escape every character into hex
                $return = '';
                for ($x=0; $x < strlen($subj); $x++) {
                    $return .= '%' . bin2hex($subj[$x]);
                }
                return $return;

            case self::ESC_HEXENTITY :
                $return = '';
                for ($x=0; $x < strlen($subj); $x++) {
                    $return .= '&#x' . bin2hex($subj[$x]) . ';';
                }
                return $return;

            case self::ESC_JAVASCRIPT :
                // escape quotes and backslashes and newlines
                return strtr($subj, array('\\'=>'\\\\',"'"=>"\\'",'"'=>'\\"',"\r"=>'\\r',"\n"=>'\\n'));

            default:
            return $subj;
        }

    }


    function e($string, $esc_type=self::ESC_HTML){
        echo $this->escape($string, $esc_type);
    }


    function getController(){
        return $this->_controller;
    }

    function _action($ret=false){
        if($ret) return $this->getController()->actionField();
        print $this->getController()->actionField();
    }
    
    function options(Array $options, $selected=false, $ret = false){
        $o = '';
        foreach($options as $k=>$v){
            $o .= "<option value='$k'".(($selected !== false) && ($k==$selected) ? "selected" : "").">$v</option>";
        }
        if($ret) return $o; 
        print $o;
    }
    function pagesOptions($selected, $ret=false){
        $pages = get_pages();
        $o = array();
        foreach($pages as $p){
            $o[$p->ID] = $p->post_title;
            
        }
        return $this->options($o, $selected, $ret);
        
    }
    
    function checkboxes($name, Array $values, $selected, $ret=false){
        if(!is_array($selected)) $selected = array($selected);
        $o = '';
        foreach($values as $k=>$v){
            $o .= "<label><input type='checkbox' name='".$name."' value='".$k."' ".(in_array($k, $selected)? "checked" : "").">".$v."</label><br/>";
        }
        if($ret) return $o; 
        print $o;
        
    }
    
    function addProductTitle($access){
        $products = am4PluginsManager::getAMProducts();
        $categories = am4PluginsManager::getAMCategories();
        if($access){
            foreach((array)$access as $t => $l){
                foreach((array)$l as $id => $a){
                    $name  = ($t == am4Access::CATEGORY ? @$categories[$id] : @$products[$id]);
                    if($name) $access[$t][$id]['title'] = $name; 
                }
            }
        }
        return $access;
    }
    function resourceAccess($id, $access=array(), $varname='access',$text=null, $without_period=false){
            if(!$text) $text = __('Choose Products and/or Product Categories that allows access', 'am4-plugin');
            $uniqid  = uniqid('amw-');
        ?>
                
                <div class="resourceaccess <?php echo $uniqid;?>" id="<?php echo $id;?>" data-varname='<?php echo $varname;?>'><?php _e($text);?><br/>
                                <input type='hidden' class='resourceaccess-init' value='<?php  if($access){$this->e(aMemberJson::init($this->addProductTitle($access))); }else{print '{}';}?>' />
                                <select class='category' size=1>
                                    <option value='' ><?php _e('Please select an item...', 'am4-plugin');?></option>
                                    <optgroup class='resourceaccess-category' label='<?php _e('Product Categories', 'am4-plugin');?>'>
                                    <option value='-1' style='font-weight: bold'><?php _e('Any Product', 'am4-plugin');?></option>
                                        <?php $this->options(am4PluginsManager::getAMCategories()); ?>
                                    </optgroup>
                                    <optgroup class='resourceaccess-product' label='<?php _e('Products', 'am4-plugin');?>'>
                                        <?php $this->options(am4PluginsManager::getAMProducts()); ?>
                                    </optgroup>
                                </select>
                            <div class='category-list'></div>
                            <div class='product-list'></div>
                            <br />                        
                </div>        
                <script type="text/javascript">
                    jQuery(document).ready(function ($){
                        jQuery('.<?php echo $uniqid;?>').resourceAccess({without_period: <?php echo ($without_period ? 'true' : 'false'); ?>});
                    });
                </script>
                <?php
    }
    function errorMessageSelect($value, $name){
                $id = substr(md5(uniqid()), rand(0,9), rand(10,20));
        ?>
                <ul><?php _e('Please select error message:', 'am4-plugin');?>
                    <?php $errors = new am4_Settings_Error(); foreach($errors  as $e) : ?>
                        <li> <input  type="radio" name="<?php echo $name;?>" value="<?php $this->e($e['name']);?>" <?php checked($value, $e['name']);?>> <?php $this->e($e['name']);?><a href="#" class ="am4-text-show-<?php echo $id;?>" > &gt;&gt; </a>
                            <div class="am4-error-text-container" style='overflow:auto; height:300px; width:800px; border: 1px solid #7F9DB9; padding: 5px; display: none;'><?php echo $e['text'];?></div>
                        </li>
                    <?php endforeach;?>
                    
                </ul>
                <script type="text/javascript">
                    jQuery(document).ready(function(){
                        jQuery(".am4-text-show-<?php echo $id;?>").click(function(e){
                            e.preventDefault();
                            jQuery(".am4-error-text-container").hide(200);
                            jQuery(this).next("div").show(200);
                            
                        });
                    });
                </script>
        <?php
    }   
}
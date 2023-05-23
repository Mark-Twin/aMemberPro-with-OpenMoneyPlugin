/*
 * HTMLReg
 * By Gareth Heyes
 * Version: 0.2.1
 * http://code.google.com/p/htmlreg/
 */			
window.HTMLReg = function() {
	var appID = '',
	imageProxy = 'http://www.gmodules.com/ig/proxy?url=',
	debug = {},
	parseTree = '',
	attributeLength = 1000,
	maxAttributes = 20,
	textNodeLength = 1000,
	selfClosing = /^(?:input|br|hr|img|image)$/i,
	allowedTags = /(?:canvas|form|optgroup|button|legend|fieldset|label|option|select|textarea|input|audio|aside|article|a|abbr|acronym|address|area|b|bdo|big|br|canvas|caption|center|cite|code|col|dd|del|dfn|dir|div|dl|dt|em|font|h[1-6]|hr|i|img|ins|kbd|li|map|ol|p|pre|q|s|samp|small|span|strike|strong|sub|sup|table|tbody|td|tfoot|th|thead|tr|tt|u|ul|blockquote|image|video|xmp)/,
	allowedAttributes = /(?:type|accesskey|align|alink|alt|background|bgcolor|border|cellpadding|cellspacing|class|color|cols|colspan|coords|dir|face|height|href|hspace|id|ismap|lang|marginheight|marginwidth|multiple|name|nohref|noresize|noshade|nowrap|ref|rel|rev|rows|rowspan|scrolling|shape|span|src|style|summary|tabindex|target|title|usemap|valign|value|vlink|vspace|width)/,		
	attributeValues = new RegExp("(?:\"[^\"]{0,"+attributeLength+"}\"|[^\\s'\"`>]{1,"+attributeLength+"}|'[^']{0,"+attributeLength+"}')"),
	invalidAttributeValues = new RegExp("(?:\"[^\"]{0,"+attributeLength+"}\"|[^\\s>]{1,"+attributeLength+"}|'[^>]{0,"+attributeLength+"}')"),
	attributes = new RegExp('\\s+'+allowedAttributes.source+'\\s*='+attributeValues.source),				
	urls = /^(?:https?:\/\/.+|\/.+|\w[^:]+|#[\w=?]*)$/,				
	text = new RegExp('[^<>]{1,'+textNodeLength+'}'),
	styleTag = /(?:<style>[^<>]+<\/style>)/,	
	invalidTags = new RegExp('<[^>]+(?:(?:[\\s\\/]+\\w+\\s*='+invalidAttributeValues.source+')+)>'),	
	mainRegExp = new RegExp('('+styleTag.source+')|(<\\\/?[a-z0-9]{1,10}(?:'+attributes.source+'){0,'+maxAttributes+'}(?:\\s*\\\/?)>)|('+text.source+')|('+invalidTags.source+')','ig'),							
	StringtoXML = function (text){		
		try {
			if(window.DOMParser) {
			  var parser=new DOMParser();
		      var doc=parser.parseFromString(text,'text/xml');		      
		      var xml = (new XMLSerializer()).serializeToString(doc);
		      xml = xml.replace(/^<\?[^?]+\?>\s*/,'');		      
		      if(/<parsererror[^>]+>/.test(xml)) {
		    	  return 'Invalid HTML markup';
		      } else {
		    	  return xml;
		      }
			} else if(window.ActiveXObject){
	          var doc=new ActiveXObject('Microsoft.XMLDOM');
	          doc.async='false';
	          doc.loadXML(text);
	          if(!doc.xml) {
	        	  throw {};
	          }
	          return doc.xml;
			} else {
				return text;
			}	
		} catch(e) {
			return 'Invalid HTML markup';
		}
	},
	executeHTML = function(html) {
		var frag = document.createDocumentFragment();
		frag.innerHTML = html;		
		html = frag.innerHTML;
		var attributes = new RegExp('\\s+(?:sandbox-style|'+allowedAttributes.source+')\\s*='+attributeValues.source);
		var attributesParens = new RegExp('(?:\\s'+allowedAttributes.source+'\\s*='+attributeValues.source+')|(?:\\s+(sandbox-style)\\s*=('+attributeValues.source+'))','gi');
		html = html.replace(new RegExp('(?:<[a-z0-9]{1,10}(?:'+attributes.source+'){0,'+maxAttributes+'}(?:\\s*\\\/?)>)','ig'), function($tag) {									
			$tag = $tag.replace(attributesParens, function($0, $attributeName, $attributeValue) {
				if($attributeName !== undefined && $attributeName.length) {
					return ' style='+$attributeValue+'';
				} else {
					return $0;
				}
			});
			return $tag;
		});		
		frag = null;
		if(HTMLReg.validateHTML) {
			return StringtoXML(html);
		} else {
			return html;
		}
	},	
	parseURL = function(name, element) {
		var value = '';	
		if(!element) {
			return '';
		}
		if(element[name] === '') {
			return '';
		}				
		
		if(urls.test(element.getAttribute(name))) {
			var value = element.getAttribute(name);
		} else {
			var value = "#";
		}
		return value;
	},
	parseAttrValues = function(tag) {		
		var tagName = '';
		tag = tag.replace(new RegExp('^(<\\\/?)('+allowedTags.source+')(\\s|\\/)','i'), function($0, $start, $tagName, $end) {
			tagName = $tagName;
			return $start + 'div' + $end;
		})
		if(tagName === '') {
			return '';
		}						
		var div = document.createElement("div");
		var html = '';		
		div.style.display = 'none';			
		div.innerHTML = tag;		
		var element = div.firstChild;
		if(!element) {
			return '';
		}		
		var HTMLhref = parseURL('href',element);
		var HTMLsrc = parseURL('src',element);		
		var HTMLbackground = parseURL('background',element);			
		var HTMLaction = parseURL('action',element);				
		if(element.id !== '') {				
			var id = element.id+'';
			id = id.replace(/[^\w]/g,'');				
			var HTMLID = appID+'_'+id+'_';				
		} else {
			var HTMLID = '';
		}
		if(element.className !== '') {
			var classList = element.className+'';
			classList = classList.replace(/[^ \w]/g,'');
			if(classList === '') {
				classList = 'invalid'
			}
			classList = classList.split(" ")				
			var HTMLClass = '';
			var len = classList.length;
			if(len > 10) {
				len = 10;
			}
			for(var i=0;i<len;i++) {
				if(/^[\w]+$/.test(classList[i])) {
					HTMLClass += appID+'_'+classList[i]+'_ ';
				}
			}
			HTMLClass = HTMLClass.replace(/\s$/,'');
		} else {
			var HTMLClass = '';
		}			
		if(element.getAttribute('name') !== '' && element.getAttribute('name') !== null) {
			var name = element.getAttribute('name');
			name = name.replace(/[^\w]/g,'');
			element.setAttribute('name','$'+appID+'_'+name+'$');
		}	
		if(element.getAttribute("style") !== '' && element.getAttribute("style") !== null && element.style.cssText !== '') {	
			var css = element.style.cssText;
			element.style.cssText = null;
			element.setAttribute("style","");			
			element.removeAttribute('style');
			if(element.style.cssText !== '') {
				return '';
			}	
			CSSReg.setAppID(appID);
			css = CSSReg.parse(css);			
			element.setAttribute('sandbox-style',css);			
		} else {
			element.style.cssText = null;
			element.setAttribute("style","");
			element.removeAttribute('style');
		}
		try {
			if(/^a$/i.test(tagName)) {
				element.setAttribute('rel','nofollow');
			}
			if (HTMLhref !== '' && typeof HTMLhref != 'undefined' && HTMLhref !== null) {				
				if(/^#/.test(HTMLhref)) {
					element.setAttribute('href', HTMLhref);
				} else {					
					element.setAttribute('href', imageProxy + encodeURIComponent(HTMLhref));
				}
			}
			if (HTMLsrc !== '' && typeof HTMLsrc != 'undefined' && HTMLsrc !== null) {
				element.setAttribute('src', imageProxy + encodeURIComponent(HTMLsrc));
			}
			if (HTMLbackground !== '' && typeof HTMLbackground != 'undefined' && HTMLbackground !== null) {
				element.setAttribute('background', imageProxy + encodeURIComponent(HTMLbackground));
			}
			if (HTMLaction !== '' && typeof HTMLaction != 'undefined' && HTMLaction !== null) {
				element.setAttribute('action', imageProxy + encodeURIComponent(HTMLaction));
			}
			if (HTMLID !== '' && typeof HTMLID != 'undefined' && HTMLID !== null) {
				element.id = HTMLID;
			}
			if (HTMLClass !== '' && typeof HTMLClass != 'undefined' && HTMLClass !== null) {
				element.className = HTMLClass;
			}
		} catch(e) {}										
		html += '<' + tagName;
		for(var i=0;i<element.attributes.length;i++) {
			var nodeValue = element.attributes[i].nodeValue;			
			if(nodeValue == null || nodeValue === '' || nodeValue == false || /contentEditable/i.test(element.attributes[i].nodeName)) {
				continue;
			}
			html += ' ' + element.attributes[i].nodeName + '=' + '"'+escapeHTML(nodeValue)+'"';
		}
		if(selfClosing.test(tagName)) {
			html += ' /';
		}
		html += '>';		
		div = null;		
		return html;
	},
	escapeHTML = function(html) {
		html = html + '';
		html = html.replace(/[^\w ;&=\/():]/gi,function(c) {
			return '&#' + c.charCodeAt(0) + ';';
		});
		return html;
	},
	parseStyleTag = function(tag) {
		var html = '<style>\n';
		tag.replace(/^<style>([^<>]+)<\/style>$/, function($0, $css) {
			CSSReg.setAppID(appID);
			$css = CSSReg.parse($css);
			html += $css;
		});
		html += '\n<\/style>';
		return html;
	},
	parse = function(html) {	
		if(HTMLReg.disablePositioning) {
			CSSReg.disablePositioning = true;
		} else {	
			CSSReg.disablePositioning = false;
		}
		var output = '';
		parseTree = '';
		html.replace(mainRegExp, function($0, $styleTag, $tag, $text, $invalidTags) {
			if($tag !== undefined && $tag.length) {					
				if(!new RegExp('^<\\\/?'+allowedTags.source+'\/?[\\s>]','i').test($tag)) {
					return '';
				}				
				parseTree+='tag('+$tag+')\n';
				if(!/^<\/?[a-z0-9]+>$/i.test($tag)) {						
					$tag = parseAttrValues($tag);
				}
				output += $tag;									
			} else if($styleTag !== undefined && $styleTag.length) {
				parseTree+='styleTag('+$styleTag+')\n';
				$styleTag= parseStyleTag($styleTag);
				output += $styleTag;									
			} else if($text !== undefined && $text.length) {
				output += $text;					
				parseTree+='text('+$text+')\n';						
			} else if($invalidTags !== undefined && $invalidTags.length) {								
				parseTree+='invalidTags('+$invalidTags+')\n';									
			} 
		});
		
		if(debug['rawOutput']) {
			debug['rawOutput'](output);			
		}
		if(debug['parseTree']) {
			debug['parseTree'](parseTree);			
		}					
		return executeHTML(output);
	};		
	return {
		parse: parse,
		setAppID: function (id) {				
			appID = id;
		},
		setDebugObjs: function(obj) {
			debug = obj;
		}		
	};
}();

/*
 * CSSReg
 * By Gareth Heyes
 * Version: 0.1.1
 * http://code.google.com/p/cssreg/
 */			
window.CSSReg = function() {
	var appID = '',
	debug = {},
	parseTree = '',
	urlValue = new RegExp('(?:http:\\\/{2}|\\\/)(?:[^)]+)'),
	url = new RegExp('(?:url[(](?:'+urlValue.source+'|["]'+urlValue.source+'["]|[\']'+urlValue.source+'[\'])[)])'),
	colon = new RegExp('\\s*:\\s*'),
	selectorStart = new RegExp('((?:(?:[.#]\\w{1,20}|form|optgroup|button|legend|fieldset|label|option|select|textarea|input|audio|aside|article|a|abbr|acronym|address|area|b|bdo|big|br|canvas|caption|center|cite|code|col|dd|del|dfn|dir|div|dl|dt|em|font|h[1-6]|hr|i|img|ins|kbd|li|map|ol|p|pre|q|s|samp|small|span|strike|strong|sub|sup|table|tbody|td|tfoot|th|thead|tr|tt|u|ul|blockquote|image|video|xmp|[*])(?:[:](?:visited|link|hover|active|focus))?\\s*[,]?\\s*){1,10}[{])'),
	selectorEnd = new RegExp('([}])'),
	units = new RegExp('(?:(?:normal|auto|(?:[+-]?[\\\/.\\d]{1,8}\\s*){1,4}(?:px|%|pt|pc|em|mm|ex|in|cm)?))'),
	colourValues = new RegExp('(?:(?:transparent|aqua|black|blue|fuchsia|gray|grey|green|lime|maroon|navy|olive|purple|red|silver|teal|white|yellow)|(?:rgb\\(\\s*\\d{1,3}%?\\s*,\\s*\\d{1,3}%?\\s*,\\s*\\d{1,3}%?\\))|(?:#(?:[0-9a-f]{6}|[0-9a-f]{3})))'), 
	colours = new RegExp('((?:(?:background-)?color'+colon.source+')'+colourValues.source+')'),
	decoration = new RegExp('((?:text-decoration'+colon.source+')(?:none|underline|overline|line-through|blink))'),
	alignment = new RegExp('((?:(?:position|whitespace|display|clear|float|(?:text|vertical)-align)'+colon.source+')(?:inherit|relative|static|absolute|normal|pre|nowrap|block|inline|list-item|both|none|left|right|center|justify|baseline|sub|super|top|text-top|middle|bottom|text-bottom|[+-]?\\d+%))'),
	sizes = new RegExp('((?:(?:line-height|text-ident|letter-spacing|word-spacing|width|height|top|left|right|bottom|margin(?:-(?:left|right|top|bottom))?|padding(?:-(?:left|right|top|bottom))?)'+colon.source+')'+units.source+')'),
	fontValues = new RegExp('(?:'+units.source+'|serif|arial|["]lucida console["]|[\']lucida console[\']|serif|times|sans-serif|cursive|verdana|fantasy|monospace|normal|oblique|italic|small-caps|bolder|bold|lighter|[xx]{1,2}-small|smaller|small|medium|larger|large|[x]{1,2}-large|[1-9]00)'),
	font = new RegExp('((?:font(?:-family|-style-|-variant|-weight|-size)?)'+colon.source+fontValues.source+'(?:[,\\s\\\/]+'+fontValues.source+')*)'),
	backgroundValues = new RegExp('(?:'+url.source+'|'+units.source+'|none|top|center|bottom|left|center|right|scroll|fixed|repeat|repeat-x|repeat-y|no-repeat|'+colourValues.source+')'),
	background = new RegExp('((?:background(?:-color|-image|-repeat|-attachment|-position)?'+colon.source+backgroundValues.source+'(?:[\\s]+'+backgroundValues.source+')*))'),
	transform = new RegExp('((?:text-transform)'+colon.source+'(?:none|capitalize|uppercase|lowercase))'),
	borderValues = new RegExp('(?:'+units.source+'|thick|medium|thinnone|dotted|dashed|solid|double|groove|ridge|inset|outset|'+colourValues.source+')'),
	border = new RegExp('((?:(?:top-|right-|bottom-|left-)border(?:-width)?|(?:border(?:-width|-color|-style)?))'+colon.source+borderValues.source+'(?:[\\s]+'+borderValues.source+')*'+')'),
	listValues = new RegExp('(?:'+url.source+'|inside|outside|disc|circle|square|decimal|lower-roman|upper-roman|lower-alpha|upper-alpha|none)'),
	list = new RegExp('((?:list-style(?:-type|-image|-position)?)'+colon.source+listValues.source+'(?:[\\s]+'+listValues.source+')*'+')'),	
	mainRegExp = new RegExp(selectorStart.source+'|'+selectorEnd.source+'|'+border.source+'|'+colours.source+'|'+sizes.source+'|'+font.source+'|'+decoration.source+'|'+alignment.source+'|'+background.source+'|'+transform.source+'|'+list.source,'ig'),							
	parseURL = function(url) {
		
		var entityToCode = {
				apos:0x0027,quot:0x0022,amp:0x0026,lt:0x003C,gt:0x003E,nbsp:0x00A0,iexcl:0x00A1,cent:0x00A2,pound:0x00A3,
				curren:0x00A4,yen:0x00A5,brvbar:0x00A6,sect:0x00A7,uml:0x00A8,copy:0x00A9,ordf:0x00AA,laquo:0x00AB,
				not:0x00AC,shy:0x00AD,reg:0x00AE,macr:0x00AF,deg:0x00B0,plusmn:0x00B1,sup2:0x00B2,sup3:0x00B3,
				acute:0x00B4,micro:0x00B5,para:0x00B6,middot:0x00B7,cedil:0x00B8,sup1:0x00B9,ordm:0x00BA,raquo:0x00BB,
				frac14:0x00BC,frac12:0x00BD,frac34:0x00BE,iquest:0x00BF,Agrave:0x00C0,Aacute:0x00C1,Acirc:0x00C2,Atilde:0x00C3,
				Auml:0x00C4,Aring:0x00C5,AElig:0x00C6,Ccedil:0x00C7,Egrave:0x00C8,Eacute:0x00C9,Ecirc:0x00CA,Euml:0x00CB,
				Igrave:0x00CC,Iacute:0x00CD,Icirc:0x00CE,Iuml:0x00CF,ETH:0x00D0,Ntilde:0x00D1,Ograve:0x00D2,Oacute:0x00D3,
				Ocirc:0x00D4,Otilde:0x00D5,Ouml:0x00D6,times:0x00D7,Oslash:0x00D8,Ugrave:0x00D9,Uacute:0x00DA,Ucirc:0x00DB,
				Uuml:0x00DC,Yacute:0x00DD,THORN:0x00DE,szlig:0x00DF,agrave:0x00E0,aacute:0x00E1,acirc:0x00E2,atilde:0x00E3,
				auml:0x00E4,aring:0x00E5,aelig:0x00E6,ccedil:0x00E7,egrave:0x00E8,eacute:0x00E9,ecirc:0x00EA,euml:0x00EB,
				igrave:0x00EC,iacute:0x00ED,icirc:0x00EE,iuml:0x00EF,eth:0x00F0,ntilde:0x00F1,ograve:0x00F2,oacute:0x00F3,
				ocirc:0x00F4,otilde:0x00F5,ouml:0x00F6,divide:0x00F7,oslash:0x00F8,ugrave:0x00F9,uacute:0x00FA,ucirc:0x00FB,
				uuml:0x00FC,yacute:0x00FD,thorn:0x00FE,yuml:0x00FF,OElig:0x0152,oelig:0x0153,Scaron:0x0160,scaron:0x0161,
				Yuml:0x0178,fnof:0x0192,circ:0x02C6,tilde:0x02DC,Alpha:0x0391,Beta:0x0392,Gamma:0x0393,Delta:0x0394,
				Epsilon:0x0395,Zeta:0x0396,Eta:0x0397,Theta:0x0398,Iota:0x0399,Kappa:0x039A,Lambda:0x039B,Mu:0x039C,
				Nu:0x039D,Xi:0x039E,Omicron:0x039F,Pi:0x03A0,Rho:0x03A1,Sigma:0x03A3,Tau:0x03A4,Upsilon:0x03A5,
				Phi:0x03A6,Chi:0x03A7,Psi:0x03A8,Omega:0x03A9,alpha:0x03B1,beta:0x03B2,gamma:0x03B3,delta:0x03B4,
				epsilon:0x03B5,zeta:0x03B6,eta:0x03B7,theta:0x03B8,iota:0x03B9,kappa:0x03BA,lambda:0x03BB,mu:0x03BC,
				nu:0x03BD,xi:0x03BE,omicron:0x03BF,pi:0x03C0,rho:0x03C1,sigmaf:0x03C2,sigma:0x03C3,tau:0x03C4,
				upsilon:0x03C5,phi:0x03C6,chi:0x03C7,psi:0x03C8,omega:0x03C9,thetasym:0x03D1,upsih:0x03D2,piv:0x03D6,
				ensp:0x2002,emsp:0x2003,thinsp:0x2009,zwnj:0x200C,zwj:0x200D,lrm:0x200E,rlm:0x200F,ndash:0x2013,
				mdash:0x2014,lsquo:0x2018,rsquo:0x2019,sbquo:0x201A,ldquo:0x201C,rdquo:0x201D,bdquo:0x201E,dagger:0x2020,
				Dagger:0x2021,bull:0x2022,hellip:0x2026,permil:0x2030,prime:0x2032,Prime:0x2033,lsaquo:0x2039,rsaquo:0x203A,
				oline:0x203E,frasl:0x2044,euro:0x20AC,image:0x2111,weierp:0x2118,real:0x211C,trade:0x2122,alefsym:0x2135,
				larr:0x2190,uarr:0x2191,rarr:0x2192,darr:0x2193,harr:0x2194,crarr:0x21B5,lArr:0x21D0,uArr:0x21D1,
				rArr:0x21D2,dArr:0x21D3,hArr:0x21D4,forall:0x2200,part:0x2202,exist:0x2203,empty:0x2205,nabla:0x2207,
				isin:0x2208,notin:0x2209,ni:0x220B,prod:0x220F,sum:0x2211,minus:0x2212,lowast:0x2217,radic:0x221A,
				prop:0x221D,infin:0x221E,ang:0x2220,and:0x2227,or:0x2228,cap:0x2229,cup:0x222A,int:0x222B,
				there4:0x2234,sim:0x223C,cong:0x2245,asymp:0x2248,ne:0x2260,equiv:0x2261,le:0x2264,ge:0x2265,
				sub:0x2282,sup:0x2283,nsub:0x2284,sube:0x2286,supe:0x2287,oplus:0x2295,otimes:0x2297,perp:0x22A5,
				sdot:0x22C5,lceil:0x2308,rceil:0x2309,lfloor:0x230A,rfloor:0x230B,lang:0x2329,rang:0x232A,loz:0x25CA,
				spades:0x2660,clubs:0x2663,hearts:0x2665,diams:0x2666
				};

				var charToEntity = {};
				for ( var entityName in entityToCode ) {
				        charToEntity[String.fromCharCode(entityToCode[entityName])] = entityName;
				}
				function UnescapeEntities(str) { return str.replace(/&(.+?);/g,
				        function(str, ent) {
				            return String.fromCharCode( ent[0]!='#' ? entityToCode[ent] : ent[1]=='x' ? parseInt(ent.substr(2),16): parseInt(ent.substr(1)) )
				            });        
				}

				function EscapeEntities(str) {
				    return str.replace(/[\x20-\x7E]/g, function(str) {
				                                            return charToEntity[str] ? '&'+charToEntity[str]+';' : str
				    });                                        
				}    
		
		url = url.replace(/url[(]([^)]+)[)]/g,function($0, $url) {					
			$url = $url.replace(/^['"]|['"]$/g,'');
			$url = $url.replace(/&[a-z]{1,10};|&#x[a-f0-9]{1,3};|&#\d{1,3};|[^\w\/. -]/gi,function(c) {
				if(c.length > 1) {
					if(/^&#x/.test(c)) {
						c  = c.replace(/[&#x;]/g,'');
						c = parseInt(c, 16);
						return '\\'+ c.toString(16) + ' ';
					} else if(/^&[a-z]/i.test(c)) {
						c = UnescapeEntities(c);
						return '\\'+ c.charCodeAt(0).toString(16) + ' ';
					} else {
						c  = c.replace(/[&#;]/g,'');
						c = parseInt(c, 10);
						return '\\'+ c.toString(16) + ' ';						
					}
				}
				return '\\'+ c.charCodeAt(0).toString(16) + ' ';
			});			
			return "url('http://www.gmodules.com/ig/proxy?url=" + $url + "')";
		});
		return url;
	},	
	parse = function(css) {
		var output = '';
		parseTree = '';
		var selectorOpen = false;
		css.replace(mainRegExp, function($0, $selectorStart, $selectorEnd, $border, $colour, $sizes, $font, $decoration, $alignment, $background, $transform, $list) {
			if($colour !== undefined && $colour.length) {													
				parseTree+='colour('+$colour+')\n';				
				output += $colour + ';';									
			} else if($selectorStart !== undefined && $selectorStart.length) {													
				parseTree+='selectorStart('+$selectorStart+')\n';				
				selectorOpen = true;
				
				$selectorStart = $selectorStart.replace(/{/,'');
				$selectorStart = $selectorStart.split(",");
				for(var i=0;i<$selectorStart.length;i++) {
					$selectorStart[i] = $selectorStart[i].replace(/#(\w+)/, "#"+appID+"_$1_");
					$selectorStart[i] = $selectorStart[i].replace(/\.(\w+)/, "."+appID+"_$1_");
					$selectorStart[i] = '#'+appID+' ' + $selectorStart[i];
				}
				output += $selectorStart + " { \n";
			} else if($selectorEnd !== undefined && $selectorEnd.length) {													
				if(selectorOpen) {
					parseTree+='selectorEnd('+$selectorEnd+')\n';				
					selectorOpen = false;
					output += "\n" + $selectorEnd + '\n';
				}
			} else if($sizes !== undefined && $sizes.length) {
				if(CSSReg.disablePositioning) {
					return '';
				}
				parseTree+='sizes('+$sizes+')\n';				
				output += $sizes + ';';									
			} else if($font !== undefined && $font.length) {													
				parseTree+='font('+$font+')\n';
				$font = $font.replace(/"/g,"'");
				output += $font + ';';									
			} else if($transform !== undefined && $transform.length) {													
				parseTree+='transform('+$transform+')\n';				
				output += $transform + ';';
			} else if($decoration !== undefined && $decoration.length) {													
				parseTree+='decoration('+$decoration+')\n';				
				output += $decoration + ';';					
			} else if($alignment !== undefined && $alignment.length) {													
				if(CSSReg.disablePositioning) {
					return '';
				}
				parseTree+='alignment('+$alignment+')\n';				
				output += $alignment + ';';	
			} else if($border !== undefined && $border.length) {													
				parseTree+='border('+$border+')\n';				
				output += $border + ';';					
			} else if($background !== undefined && $background.length) {													
				parseTree+='background('+$background+')\n';
				$background = parseURL($background);
				output += $background + ';';									
			} else if($list !== undefined && $list.length) {													
				parseTree+='list('+$list+')\n';
				$list = parseURL($list);
				output += $list + ';';									
			}    
		});
		if(debug['rawOutput']) {
			debug['rawOutput'](output);			
		}
		if(debug['parseTree']) {
			debug['parseTree'](parseTree);			
		}		
		return output;
	};		
	return {
		parse: parse,
		setAppID: function (id) {				
			appID = id;
		},
		setDebugObjs: function(obj) {
			debug = obj;
		}
	};
}();

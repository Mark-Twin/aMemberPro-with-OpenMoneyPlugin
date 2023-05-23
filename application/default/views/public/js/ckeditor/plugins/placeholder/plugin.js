/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

/**
 * @fileOverview The "placeholder" plugin.
 *
 */

'use strict';

( function() {
	CKEDITOR.plugins.add( 'placeholder', {
		requires: 'widget,dialog',
		lang: 'af,ar,az,bg,ca,cs,cy,da,de,de-ch,el,en,en-gb,eo,es,es-mx,et,eu,fa,fi,fr,fr-ca,gl,he,hr,hu,id,it,ja,km,ko,ku,lv,nb,nl,no,oc,pl,pt,pt-br,ru,si,sk,sl,sq,sv,th,tr,tt,ug,uk,vi,zh,zh-cn', // %REMOVE_LINE_CORE%
		icons: 'placeholder', // %REMOVE_LINE_CORE%
		hidpi: true, // %REMOVE_LINE_CORE%

		onLoad: function() {
			// Register styles for placeholder widget frame.
			CKEDITOR.addCss( '.cke_placeholder{background-color:#ff0}' );
		},

		init: function( editor ) {

			var lang = editor.lang.placeholder;

			// Register dialog.
			CKEDITOR.dialog.add( 'placeholder', this.path + 'dialogs/placeholder.js' );

			// Put ur init code here.
			editor.widgets.add( 'placeholder', {
				// Widget code.
				dialog: 'placeholder',
				pathName: lang.pathName,
				// We need to have wrapping element, otherwise there are issues in
				// add dialog.
				template: '<span class="cke_placeholder">%%</span>',

				downcast: function() {
					return new CKEDITOR.htmlParser.text( '%' + this.data.name + '%' );
				},

				init: function() {
					// Note that placeholder markup characters are stripped for the name.
					this.setData( 'name', this.element.getText().slice( 1, -1 ) );
				},

				data: function() {
					this.element.setText( '%' + this.data.name + '%' );
				},

				getLabel: function() {
					return this.editor.lang.widget.label.replace( /%1/, this.data.name + ' ' + this.pathName );
				}
			} );

			editor.ui.addButton && editor.ui.addButton( 'CreatePlaceholder', {
				label: lang.toolbar,
				command: 'placeholder',
				toolbar: 'insert,5',
				icon: 'placeholder'
			} );
		},

		afterInit: function( editor ) {
			var placeholderReplaceRegex = /%[^%\s]+%/g;

			editor.dataProcessor.dataFilter.addRules( {
				text: function( text, node ) {
					var dtd = node.parent && CKEDITOR.dtd[ node.parent.name ];

					// Skip the case when placeholder is in elements like <title> or <textarea>
					// but upcast placeholder in custom elements (no DTD).
					if ( dtd && !dtd.span )
						return;

					return text.replace( placeholderReplaceRegex, function( match ) {
						// Creating widget code.
						var widgetWrapper = null,
							innerElement = new CKEDITOR.htmlParser.element( 'span', {
								'class': 'cke_placeholder'
							} );

						// Adds placeholder identifier as innertext.
						innerElement.add( new CKEDITOR.htmlParser.text( match ) );
						widgetWrapper = editor.widgets.wrapElement( innerElement, 'placeholder' );

						// Return outerhtml of widget wrapper so it will be placed
						// as replacement.
						return widgetWrapper.getOuterHtml();
					} );
				}
			} );
		}
	} );

} )();

/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'af', {
	title: 'Plekhouer eienskappe',
	toolbar: 'Plekhouer',
	name: 'Plekhouer naam',
	invalidName: 'Die plekhouer mag nie leeg wees nie, en kan geen van die volgende karakters bevat nie.  [, ], <, >',
	pathName: 'plekhouer'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'ar', {
	title: 'خصائص الربط الموضعي',
	toolbar: 'الربط الموضعي',
	name: 'اسم الربط الموضعي',
	invalidName: 'لا يمكن ترك الربط الموضعي فارغا و لا أن يحتوي على الرموز التالية  [, ], <, >',
	pathName: 'الربط الموضعي'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'az', {
	title: 'Yertutanın xüsusiyyətləri',
	toolbar: 'Yertutan',
	name: 'Yertutanın adı',
	invalidName: 'Yertutan boş ola bilməz, həm də [, ], <, > işarələrdən ehtiva edə bilməz',
	pathName: 'yertutan'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'bg', {
	title: 'Настройки на контейнера',
	toolbar: 'Нов контейнер',
	name: 'Placeholder Name', // MISSING
	invalidName: 'The placeholder can not be empty and can not contain any of following characters: [, ], <, >', // MISSING
	pathName: 'placeholder' // MISSING
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'ca', {
	title: 'Propietats del marcador de posició',
	toolbar: 'Marcador de posició',
	name: 'Nom del marcador de posició',
	invalidName: 'El marcador de posició no pot estar en blanc ni pot contenir cap dels caràcters següents: [,],<,>',
	pathName: 'marcador de posició'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'cs', {
	title: 'Vlastnosti vyhrazeného prostoru',
	toolbar: 'Vytvořit vyhrazený prostor',
	name: 'Název vyhrazeného prostoru',
	invalidName: 'Vyhrazený prostor nesmí být prázdný či obsahovat následující znaky: [, ], <, >',
	pathName: 'Vyhrazený prostor'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'cy', {
	title: 'Priodweddau\'r Daliwr Geiriau',
	toolbar: 'Daliwr Geiriau',
	name: 'Enw\'r Daliwr Geiriau',
	invalidName: 'Dyw\'r daliwr geiriau methu â bod yn wag ac na all gynnyws y nodau [, ], <, > ',
	pathName: 'daliwr geiriau'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'da', {
	title: 'Egenskaber for pladsholder',
	toolbar: 'Opret pladsholder',
	name: 'Navn på pladsholder',
	invalidName: 'Pladsholderen kan ikke være tom og må ikke indeholde nogen af følgende tegn: [, ], <, >',
	pathName: 'pladsholder'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'de-ch', {
	title: 'Platzhaltereinstellungen',
	toolbar: 'Platzhalter',
	name: 'Platzhaltername',
	invalidName: 'Der Platzhalter darf nicht leer sein und folgende Zeichen nicht enthalten: [, ], <, >',
	pathName: 'Platzhalter'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'de', {
	title: 'Platzhaltereinstellungen',
	toolbar: 'Platzhalter',
	name: 'Platzhaltername',
	invalidName: 'Der Platzhalter darf nicht leer sein und folgende Zeichen nicht enthalten: [, ], <, >',
	pathName: 'Platzhalter'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'el', {
	title: 'Ιδιότητες Υποκαθιστόμενου Κειμένου',
	toolbar: 'Δημιουργία Υποκαθιστόμενου Κειμένου',
	name: 'Όνομα Υποκαθιστόμενου Κειμένου',
	invalidName: 'Το υποκαθιστόμενου κειμένο πρέπει να μην είναι κενό και να μην έχει κανέναν από τους ακόλουθους χαρακτήρες: [, ], <, >',
	pathName: 'υποκαθιστόμενο κείμενο'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'en-gb', {
	title: 'Placeholder Properties',
	toolbar: 'Placeholder',
	name: 'Placeholder Name',
	invalidName: 'The placeholder can not be empty and can not contain any of the following characters: [, ], <, >',
	pathName: 'placeholder'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'en', {
	title: 'Placeholder Properties',
	toolbar: 'Placeholder',
	name: 'Placeholder Name',
	invalidName: 'The placeholder can not be empty and can not contain any of following characters: [, ], <, >',
	pathName: 'placeholder'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'eo', {
	title: 'Atributoj de la rezervita spaco',
	toolbar: 'Rezervita Spaco',
	name: 'Nomo de la rezervita spaco',
	invalidName: 'La rezervita spaco ne povas esti malplena kaj ne povas enteni la sekvajn signojn : [, ], <, >',
	pathName: 'rezervita spaco'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'es', {
	title: 'Propiedades del Marcador de Posición',
	toolbar: 'Crear Marcador de Posición',
	name: 'Nombre del Marcador de Posición',
	invalidName: 'El marcador de posición no puede estar vacío y no puede contener ninguno de los siguientes caracteres: [, ], <, >',
	pathName: 'marcador de posición'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'es-mx', {
	title: 'Propiedades del marcador de posición',
	toolbar: 'Marcador de posición',
	name: 'Nombre del marcador de posición',
	invalidName: 'El marcador de posición no puede estar vacío y no puede contener alguno de los siguientes caracteres: [, ], <, >',
	pathName: 'marcador de posición'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'et', {
	title: 'Kohahoidja omadused',
	toolbar: 'Kohahoidja loomine',
	name: 'Placeholder Name', // MISSING
	invalidName: 'The placeholder can not be empty and can not contain any of following characters: [, ], <, >', // MISSING
	pathName: 'placeholder' // MISSING
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'eu', {
	title: 'Leku-marka propietateak',
	toolbar: 'Leku-marka',
	name: 'Leku-markaren izena',
	invalidName: 'Leku-markak ezin du hutsik egon eta ezin ditu karaktere hauek eduki: [, ], <, >',
	pathName: 'leku-marka'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'fa', {
	title: 'ویژگی‌های محل نگهداری',
	toolbar: 'ایجاد یک محل نگهداری',
	name: 'نام مکان نگهداری',
	invalidName: 'مکان نگهداری نمی‌تواند خالی باشد و همچنین نمی‌تواند محتوی نویسه‌های مقابل باشد: [, ], <, >',
	pathName: 'مکان نگهداری'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'fi', {
	title: 'Paikkamerkin ominaisuudet',
	toolbar: 'Luo paikkamerkki',
	name: 'Paikkamerkin nimi',
	invalidName: 'Paikkamerkki ei voi olla tyhjä eikä sisältää seuraavia merkkejä: [, ], <, >',
	pathName: 'paikkamerkki'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'fr-ca', {
	title: 'Propriétés de l\'espace réservé',
	toolbar: 'Créer un espace réservé',
	name: 'Placeholder Name', // MISSING
	invalidName: 'The placeholder can not be empty and can not contain any of following characters: [, ], <, >', // MISSING
	pathName: 'placeholder' // MISSING
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'fr', {
	title: 'Propriétés de l\'espace réservé',
	toolbar: 'Espace réservé',
	name: 'Nom de l\'espace réservé',
	invalidName: 'L\'espace réservé ne peut pas être vide ni contenir l\'un de ces caractères : [, ], <, >',
	pathName: 'espace réservé'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'gl', {
	title: 'Propiedades do marcador de posición',
	toolbar: 'Crear un marcador de posición',
	name: 'Nome do marcador de posición',
	invalidName: 'O marcador de posición non pode estar baleiro e non pode conter ningún dos caracteres seguintes: [, ], <, >',
	pathName: 'marcador de posición'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'he', {
	title: 'מאפייני שומר מקום',
	toolbar: 'צור שומר מקום',
	name: 'שם שומר מקום',
	invalidName: 'שומר מקום לא יכול להיות ריק ולא יכול להכיל את הסימנים: [, ], <, >',
	pathName: 'שומר מקום'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'hr', {
	title: 'Svojstva rezerviranog mjesta',
	toolbar: 'Napravi rezervirano mjesto',
	name: 'Ime rezerviranog mjesta',
	invalidName: 'Rezervirano mjesto ne može biti prazno niti može sadržavati ijedan od sljedećih znakova: [, ], <, >',
	pathName: 'rezervirano mjesto'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'hu', {
	title: 'Helytartó beállítások',
	toolbar: 'Helytartó készítése',
	name: 'Helytartó neve',
	invalidName: 'A helytartó nem lehet üres, és nem tartalmazhatja a következő karaktereket:[, ], <, > ',
	pathName: 'helytartó'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'id', {
	title: 'Properti isian sementara',
	toolbar: 'Buat isian sementara',
	name: 'Nama Isian Sementara',
	invalidName: 'Isian sementara tidak boleh kosong dan tidak boleh mengandung karakter berikut: [, ], <, >',
	pathName: 'isian sementara'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'it', {
	title: 'Proprietà segnaposto',
	toolbar: 'Crea segnaposto',
	name: 'Nome segnaposto',
	invalidName: 'Il segnaposto non può essere vuoto e non può contenere nessuno dei seguenti caratteri: [, ], <, >',
	pathName: 'segnaposto'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'ja', {
	title: 'プレースホルダのプロパティ',
	toolbar: 'プレースホルダを作成',
	name: 'プレースホルダ名',
	invalidName: 'プレースホルダは空欄にできません。また、[, ], <, > の文字は使用できません。',
	pathName: 'placeholder'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'km', {
	title: 'លក្ខណៈ Placeholder',
	toolbar: 'បង្កើត Placeholder',
	name: 'ឈ្មោះ Placeholder',
	invalidName: 'Placeholder មិន​អាច​ទទេរ ហើយក៏​មិន​អាច​មាន​តួ​អក្សរ​ទាំង​នេះ​ទេ៖ [, ], <, >',
	pathName: 'placeholder'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'ko', {
	title: '플레이스홀더 속성',
	toolbar: '플레이스홀더',
	name: '플레이스홀더 이름',
	invalidName: '플레이스홀더는 빈칸이거나 다음 문자열을 포함할 수 없습니다: [, ], <, >',
	pathName: '플레이스홀더'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'ku', {
	title: 'خاسیەتی شوێن هەڵگر',
	toolbar: 'درووستکردنی شوێن هەڵگر',
	name: 'ناوی شوێنگر',
	invalidName: 'شوێنگر نابێت بەتاڵ بێت یان هەریەکێک لەم نووسانەی خوارەوەی تێدابێت: [, ], <, >',
	pathName: 'شوێنگر'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'lv', {
	title: 'Viettura uzstādījumi',
	toolbar: 'Izveidot vietturi',
	name: 'Placeholder Name', // MISSING
	invalidName: 'The placeholder can not be empty and can not contain any of following characters: [, ], <, >', // MISSING
	pathName: 'placeholder' // MISSING
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'nb', {
	title: 'Egenskaper for plassholder',
	toolbar: 'Opprett plassholder',
	name: 'Navn på plassholder',
	invalidName: 'Plassholderen kan ikke være tom, og kan ikke inneholde følgende tegn: [, ], <, >',
	pathName: 'plassholder'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'nl', {
	title: 'Eigenschappen placeholder',
	toolbar: 'Placeholder aanmaken',
	name: 'Naam placeholder',
	invalidName: 'De placeholder mag niet leeg zijn, en mag niet een van de volgende tekens bevatten: [, ], <, >',
	pathName: 'placeholder'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'no', {
	title: 'Egenskaper for plassholder',
	toolbar: 'Opprett plassholder',
	name: 'Navn på plassholder',
	invalidName: 'Plassholderen kan ikke være tom, og kan ikke inneholde følgende tegn: [, ], <, >',
	pathName: 'plassholder'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'oc', {
	title: 'Proprietats de l\'espaci reservat',
	toolbar: 'Espaci reservat',
	name: 'Nom de l\'espaci reservat',
	invalidName: 'L\'espaci reservat pòt pas èsser void ni conténer un d\'aquestes caractèrs : [, ], <, >',
	pathName: 'espaci reservat'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'pl', {
	title: 'Właściwości wypełniacza',
	toolbar: 'Utwórz wypełniacz',
	name: 'Nazwa wypełniacza',
	invalidName: 'Wypełniacz nie może być pusty ani nie może zawierać żadnego z następujących znaków: [, ], < oraz >',
	pathName: 'wypełniacz'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'pt-br', {
	title: 'Propriedades do Espaço Reservado',
	toolbar: 'Criar Espaço Reservado',
	name: 'Nome do Espaço Reservado',
	invalidName: 'O espaço reservado não pode estar vazio e não pode conter nenhum dos seguintes caracteres:  [, ], <, >',
	pathName: 'Espaço Reservado'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'pt', {
	title: 'Propriedades dos marcadores',
	toolbar: 'Marcador',
	name: 'Nome do marcador',
	invalidName: 'O marcador não pode estar em branco e não pode conter qualquer dos seguintes carateres: [, ], <, >',
	pathName: 'marcador'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'ru', {
	title: 'Свойства плейсхолдера',
	toolbar: 'Создать плейсхолдер',
	name: 'Имя плейсхолдера',
	invalidName: 'Плейсхолдер не может быть пустым и содержать один из следующих символов: "[, ], <, >"',
	pathName: 'плейсхолдер'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'si', {
	title: 'ස්ථාන හීම්කරුගේ ',
	toolbar: 'ස්ථාන හීම්කරු නිර්මාණය කිරීම',
	name: 'Placeholder Name', // MISSING
	invalidName: 'The placeholder can not be empty and can not contain any of following characters: [, ], <, >', // MISSING
	pathName: 'placeholder' // MISSING
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'sk', {
	title: 'Vlastnosti placeholdera',
	toolbar: 'Vytvoriť placeholder',
	name: 'Názov placeholdera',
	invalidName: 'Placeholder nemôže byť prázdny a nemôže obsahovať žiadny z nasledujúcich znakov: [,],<,>',
	pathName: 'placeholder'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'sl', {
	title: 'Lastnosti ograde',
	toolbar: 'Ograda',
	name: 'Ime ograde',
	invalidName: 'Ograda ne more biti prazna in ne sme vsebovati katerega od naslednjih znakov: [, ], <, >',
	pathName: 'ograda'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'sq', {
	title: 'Karakteristikat e Mbajtësit të Vendit',
	toolbar: 'Krijo Mabjtës Vendi',
	name: 'Placeholder Name', // MISSING
	invalidName: 'The placeholder can not be empty and can not contain any of following characters: [, ], <, >', // MISSING
	pathName: 'placeholder' // MISSING
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'sv', {
	title: 'Innehållsrutans egenskaper',
	toolbar: 'Skapa innehållsruta',
	name: 'Innehållsrutans namn',
	invalidName: 'Innehållsrutan får inte vara tom och får inte innehålla någon av följande tecken: [, ], <, >',
	pathName: 'innehållsruta'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'th', {
	title: 'คุณสมบัติเกี่ยวกับตัวยึด',
	toolbar: 'สร้างตัวยึด',
	name: 'Placeholder Name', // MISSING
	invalidName: 'The placeholder can not be empty and can not contain any of following characters: [, ], <, >', // MISSING
	pathName: 'placeholder' // MISSING
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'tr', {
	title: 'Yer tutucu özellikleri',
	toolbar: 'Yer tutucu oluşturun',
	name: 'Yer Tutucu Adı',
	invalidName: 'Yer tutucu adı boş bırakılamaz ve şu karakterleri içeremez: [, ], <, >',
	pathName: 'yertutucu'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'tt', {
	title: 'Тутырма үзлекләре',
	toolbar: 'Тутырма',
	name: 'Тутырма исеме',
	invalidName: 'Тутырма буш булмаска тиеш һәм эчендә алдагы символлар булмаска тиеш: [, ], <, >',
	pathName: 'тутырма'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'ug', {
	title: 'ئورۇن بەلگە خاسلىقى',
	toolbar: 'ئورۇن بەلگە قۇر',
	name: 'ئورۇن بەلگە ئىسمى',
	invalidName: 'The placeholder can not be empty and can not contain any of following characters: [, ], <, >', // MISSING
	pathName: 'placeholder' // MISSING
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'uk', {
	title: 'Налаштування Заповнювача',
	toolbar: 'Створити Заповнювач',
	name: 'Назва заповнювача',
	invalidName: 'Заповнювач не може бути порожнім і не може містити наступні символи: [, ], <, >',
	pathName: 'заповнювач'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'vi', {
	title: 'Thuộc tính đặt chỗ',
	toolbar: 'Tạo đặt chỗ',
	name: 'Tên giữ chỗ',
	invalidName: 'Giữ chỗ không thể để trống và không thể chứa bất kỳ ký tự sau: [,], <, >',
	pathName: 'giữ chỗ'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'zh-cn', {
	title: '占位符属性',
	toolbar: '占位符',
	name: '占位符名称',
	invalidName: '占位符名称不能为空，并且不能包含以下字符：[、]、<、>',
	pathName: '占位符'
} );
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.plugins.setLang( 'placeholder', 'zh', {
	title: '預留位置屬性',
	toolbar: '建立預留位置',
	name: 'Placeholder 名稱',
	invalidName: '「預留位置」不可為空白且不可包含以下字元：[, ], <, >',
	pathName: '預留位置'
} );
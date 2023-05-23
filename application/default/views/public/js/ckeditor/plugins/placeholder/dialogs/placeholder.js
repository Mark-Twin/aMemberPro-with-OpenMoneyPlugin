
/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

/**
 * @fileOverview Definition for placeholder plugin dialog.
 *
 */

'use strict';

CKEDITOR.dialog.add( 'placeholder', function( editor ) {
    var lang = editor.lang.placeholder,
            generalLabel = editor.lang.common.generalTab;

    return {
        title: lang.title,
        minWidth: 300,
        minHeight: 80,
        contents: [
            {
                id: 'info',
                label: generalLabel,
                title: generalLabel,
                elements: [
                    {
                        id: 'select',
                        type: 'select',
                        label: lang.text,
                        items: editor.config.placeholder_items,
                        'default': '',
                        required: true,
                        validate: CKEDITOR.dialog.validate.notEmpty( lang.textMissing ),
                        setup: function( widget ) {
                            this.setValue( '%' + widget.data.name + '%' );
                        },
                        commit: function( widget ) {
                            widget.setData( 'name', this.getValue().slice( 1, -1 ) );
                        }
                    }
                ]
            }
        ]
    };
});
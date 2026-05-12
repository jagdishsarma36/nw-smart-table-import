(function() {

    tinymce.PluginManager.add(
        'smart_table_import',
        function(editor) {

            editor.addButton(
                'smart_table_import',
                {
                    text: 'Import Table',

                    icon: 'table',

                    onclick: function() {

                        editor.windowManager.open({

                            title: 'Import Table',

                            width: 700,

                            height: 550,

                            body: [

                                {
                                    type: 'textbox',

                                    name: 'tabledata',

                                    multiline: true,

                                    minWidth: 650,

                                    minHeight: 320,

                                    value: ''
                                },

                                {
                                    type: 'container',

                                    html:
                                        '<div id="sti-error-message" ' +
                                        'style="' +
                                        'display:none;' +
                                        'margin-top:10px;' +
                                        'padding:10px;' +
                                        'background:#ffeaea;' +
                                        'color:#cc0000;' +
                                        'border:1px solid #cc0000;' +
                                        'border-radius:4px;' +
                                        '">' +
                                        '</div>'
                                }

                            ],

                            onsubmit: function(e) {

                                let input =
                                    e.data.tabledata;

                                const errorBox =
                                    document.getElementById(
                                        'sti-error-message'
                                    );

                                errorBox.style.display = 'none';
                                errorBox.innerHTML = '';

                                if (!input.trim()) {

                                    errorBox.innerHTML =
                                        'Please paste table data.';

                                    errorBox.style.display =
                                        'block';

                                    return false;
                                }

                                let finalHTML =
                                    stiDetectAndConvert(
                                        input
                                    );

                                if (
                                    !finalHTML ||
                                    finalHTML === false
                                ) {

                                    errorBox.innerHTML =
                                        'No valid table data detected.';

                                    errorBox.style.display =
                                        'block';

                                    return false;
                                }

                                if (
                                    finalHTML.includes(
                                        'No valid table data detected.'
                                    )
                                ) {

                                    errorBox.innerHTML =
                                        'No valid table data detected.';

                                    errorBox.style.display =
                                        'block';

                                    return false;
                                }

                                editor.insertContent(
                                    finalHTML
                                );

                            }

                        });

                    }

                }
            );

            editor.on('init', function () {

                setTimeout(function () {

                    const tableButton = editor.editorContainer.querySelector(
                        '.mce-btn[aria-label*="Table"]'
                    );

                    if (tableButton) {

                        // prevent duplicate text
                        if (!tableButton.querySelector('.sti-edit-table-label')) {

                            const label = document.createElement('span');

                            label.className = 'sti-edit-table-label';

                            //label.style.marginLeft = '5px';
                            label.style.lineHeight = '24px';

                            label.innerHTML = 'Edit Table';

                            tableButton.appendChild(label);
                        }
                    }

                }, 300);

            });

        }
    );

})();
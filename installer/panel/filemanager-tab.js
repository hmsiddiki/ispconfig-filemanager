/*
 * "File Manager" tab on a website's page in the ISPConfig panel:   Sites > Website > [Domain] [Redirect] [SSL] ... [File Manager]
 *
 * ISPConfig has no official way to add a tab: the list is fixed in its own form definition, which every ISPConfig update
 * overwrites. This script adds the tab in the browser instead. ISPConfig loads every *.js file placed in interface/web/js/js.d/
 * and fires the "onAfterContentLoad" hook after each page it shows, which is all that is needed, so no ISPConfig file is edited.
 *
 * Installed by install.sh to interface/web/js/js.d/filemanager-tab.js.
 */
(function () {
    'use strict';

    if (!window.ISPConfig || typeof ISPConfig.registerHook !== 'function') {
        return;
    }

    var TAB_ID = 'fm-tab-link';
    var PANE_ID = 'fm-tab-pane';

    function isWebsitePage(url) {
        return /(^|\/)sites\/web_vhost_domain_edit\.php/.test(url || '');
    }

    // the id of the website being edited (empty while a new website has not been saved yet)
    function websiteId() {
        var field = document.querySelector('form#pageForm [name="id"]');
        var id = field ? parseInt(field.value, 10) : 0;
        return id > 0 ? id : 0;
    }

    function openTab(id, tab, nav) {
        var content = document.getElementById('tabbox_content');
        if (!content) {
            return;
        }

        Array.prototype.forEach.call(nav.children, function (li) {
            li.classList.remove('active');
        });
        tab.classList.add('active');

        // hide (not remove) the website's own form: nothing typed into it is lost, and the other tabs work as usual
        Array.prototype.forEach.call(content.children, function (child) {
            if (child.id !== PANE_ID) {
                child.style.display = 'none';
            }
        });

        var pane = document.getElementById(PANE_ID);
        if (!pane) {
            pane = document.createElement('div');
            pane.id = PANE_ID;
            pane.className = 'tab-pane active fade in';
            content.appendChild(pane);
        }
        pane.style.display = '';
        pane.innerHTML = '<p style="padding:1em;opacity:.7">Loading the file manager...</p>';

        // the panel page makes the temporary login and shows the file manager; "embed" = no heading and no website picker
        ISPConfig.loadContentInto(PANE_ID, 'filemanager/index.php?embed=1&auto=' + id);
    }

    function addTab(params) {
        try {
            if (!params || !isWebsitePage(params.url)) {
                return;
            }
            var nav = document.querySelector('.content-tab-wrapper > ul.nav-tabs');
            var id = websiteId();
            if (!nav || !id || document.getElementById(TAB_ID)) {
                return;
            }

            var tab = document.createElement('li');
            var link = document.createElement('a');
            link.href = '#';
            link.id = TAB_ID;
            link.textContent = 'File Manager';
            link.addEventListener('click', function (event) {
                event.preventDefault();
                openTab(id, tab, nav);
            });
            tab.appendChild(link);
            nav.appendChild(tab);
        } catch (e) {
            if (window.console) {
                console.warn('File Manager tab could not be added:', e);
            }
        }
    }

    ISPConfig.registerHook('onAfterContentLoad', function (name, params) {
        addTab(params);
    });
})();

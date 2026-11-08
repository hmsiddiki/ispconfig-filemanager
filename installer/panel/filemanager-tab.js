/*
 * "File Manager" tab on a website's page in the ISPConfig panel:   Sites > Website > [Domain] [Redirect] [SSL] ... [File Manager]
 *
 * ISPConfig has no official way to add a tab: the list is fixed in its own form definition, which every ISPConfig update
 * overwrites. This script adds the tab in the browser instead. ISPConfig loads every *.js file placed in interface/web/js/js.d/
 * and fires the "onAfterContentLoad" hook after each page it shows, which is all that is needed, so no ISPConfig file is edited.
 *
 * Uploads keep going when you leave the tab. An upload is sent by the page that holds the file manager; the panel replaces its whole
 * content area when another tab or page is opened, and that used to throw the file manager away with the upload in it. So the file manager
 * does not live inside the tab: it sits on <body> and is laid over an empty placeholder in the tab. When the tab goes away while uploads
 * are running, the file manager is kept (out of sight) and a small card in the corner shows the progress, with a button to go back to it.
 * Without running uploads it is closed as before (zip, unzip and delete run on the server and need no open page).
 *
 * Installed by install.sh to interface/web/js/js.d/filemanager-tab.js.
 */
(function () {
    'use strict';

    if (!window.ISPConfig || typeof ISPConfig.registerHook !== 'function') {
        return;
    }

    var TAB_ID = 'fm-tab-link';
    var SLOT_ID = 'fm-tab-slot';   // the empty placeholder inside the website's tab area
    var PANE_ID = 'fm-tab-pane';   // the file manager itself, on <body> (the panel page loads its content into this id)
    var DOCK_ID = 'fm-tab-dock';   // the progress card shown while uploads run with the tab closed

    // site: the website the open file manager belongs to; page: the panel page of that website (to go back to it)
    var state = { site: 0, page: '', busy: false, text: '', reopen: 0 };
    var timer = null;

    function isWebsitePage(url) {
        return /(^|\/)sites\/web_vhost_domain_edit\.php/.test(url || '');
    }

    // the id of the website being edited (empty while a new website has not been saved yet)
    function websiteId() {
        var field = document.querySelector('form#pageForm [name="id"]');
        var id = field ? parseInt(field.value, 10) : 0;
        return id > 0 ? id : 0;
    }

    function byId(id) {
        return document.getElementById(id);
    }

    // ---- the file manager on <body> --------------------------------------------------------------------------------------------

    function createPane(id) {
        var pane = document.createElement('div');
        pane.id = PANE_ID;
        // no z-index: the panel's own menus and dialogs stay above it, and the editor's full screen mode is not trapped below them
        pane.style.cssText = 'position:absolute;left:-10000px;top:0;width:1000px;box-sizing:border-box';
        pane.innerHTML = '<p style="padding:1em;opacity:.7">Loading the file manager...</p>';
        document.body.appendChild(pane);
        state.site = id;
        state.busy = false;
        state.text = '';

        // the panel page makes the temporary login and shows the file manager; "embed" = no heading and no website picker
        ISPConfig.loadContentInto(PANE_ID, 'filemanager/index.php?embed=1&auto=' + id);
        startSync();
        return pane;
    }

    function destroyPane() {
        var pane = byId(PANE_ID);
        if (pane) {
            pane.parentNode.removeChild(pane);
        }
        state.site = 0;
        state.busy = false;
        state.text = '';
        hideDock();
        stopSync();
    }

    function place(pane, slot) {
        var r = slot.getBoundingClientRect();
        // the pane is placed relative to <body> when a theme positions it, otherwise relative to the page itself
        var base = window.getComputedStyle(document.body).position === 'static' ? document.documentElement : document.body;
        var b = base.getBoundingClientRect();
        pane.style.visibility = '';
        pane.style.left = (r.left - b.left) + 'px';
        pane.style.top = (r.top - b.top) + 'px';
        pane.style.width = r.width + 'px';
        // the placeholder takes the file manager's height, so the page around it is laid out as if it were inside
        var h = pane.offsetHeight;
        if (Math.abs(slot.offsetHeight - h) > 1) {
            slot.style.height = h + 'px';
        }
    }

    // out of sight but still running: the frame and its uploads go on, and it keeps its size for when it comes back
    function park(pane) {
        pane.style.visibility = 'hidden';
        pane.style.left = '-10000px';
        pane.style.top = '0';
    }

    // keep the file manager over its placeholder, or park it, or close it, depending on what the panel shows now
    function sync() {
        var pane = byId(PANE_ID);
        if (!pane) {
            stopSync();
            return;
        }
        var slot = byId(SLOT_ID);
        if (slot && slot.offsetParent !== null) {
            place(pane, slot);
            hideDock();
            return;
        }
        if (state.busy) {
            park(pane);
            showDock();
            return;
        }
        if (slot) {
            park(pane);   // still on the website's page, another of its tabs is shown
            hideDock();
            return;
        }
        destroyPane();    // another page of the panel and nothing running: close it, as before
    }

    function startSync() {
        if (!timer) {
            timer = setInterval(sync, 250);
            window.addEventListener('scroll', sync, true);
            window.addEventListener('resize', sync);
        }
    }

    function stopSync() {
        if (timer) {
            clearInterval(timer);
            timer = null;
            window.removeEventListener('scroll', sync, true);
            window.removeEventListener('resize', sync);
        }
    }

    // ---- the progress card while the tab is closed ---------------------------------------------------------------------------------

    function showDock() {
        var dock = byId(DOCK_ID);
        if (!dock) {
            dock = document.createElement('div');
            dock.id = DOCK_ID;
            dock.setAttribute('role', 'status');
            dock.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:1050;max-width:340px;padding:12px 14px;border-radius:8px;'
                + 'background:#1f2937;color:#fff;box-shadow:0 6px 24px rgba(0,0,0,.25);font-size:13px;line-height:1.4';
            var title = document.createElement('div');
            title.style.cssText = 'font-weight:600;margin-bottom:4px';
            title.textContent = 'File Manager';
            var text = document.createElement('div');
            text.className = 'fm-dock-text';
            var open = document.createElement('button');
            open.type = 'button';
            open.className = 'btn btn-primary btn-xs';
            open.style.marginTop = '8px';
            open.textContent = 'Show';
            open.addEventListener('click', reopen);
            dock.appendChild(title);
            dock.appendChild(text);
            dock.appendChild(open);
            document.body.appendChild(dock);
        }
        dock.querySelector('.fm-dock-text').textContent = (state.text || 'Uploading files') + '. Keep this browser tab open until it is done.';
    }

    function hideDock() {
        var dock = byId(DOCK_ID);
        if (dock) {
            dock.parentNode.removeChild(dock);
        }
    }

    // back to the website the uploads go to, with its File Manager tab open (the running file manager is shown again, not reloaded)
    function reopen() {
        var link = byId(TAB_ID);
        if (link && websiteId() === state.site) {
            link.click();
            return;
        }
        state.reopen = state.site;
        if (typeof ISPConfig.loadContent === 'function') {
            ISPConfig.loadContent(state.page || ('sites/web_vhost_domain_edit.php?id=' + state.site));
        }
    }

    // the file manager reports whether uploads are running (only our own frame, from the same address)
    window.addEventListener('message', function (event) {
        var pane = byId(PANE_ID);
        var frame = pane ? pane.querySelector('iframe') : null;
        if (!frame || event.origin !== location.origin || event.source !== frame.contentWindow) {
            return;
        }
        if (event.data && event.data.type === 'filemanager-busy') {
            state.busy = !!event.data.busy;
            state.text = String(event.data.text || '');
            sync();
            if (byId(DOCK_ID)) {
                showDock();
            }
        }
    });

    // ---- the tab ---------------------------------------------------------------------------------------------------------------

    function openTab(id, page, tab, nav) {
        var content = document.getElementById('tabbox_content');
        if (!content) {
            return;
        }

        var pane = byId(PANE_ID);
        if (pane && state.site !== id && state.busy
            && !window.confirm('Files are still being uploaded to another website. Stop those uploads and open the file manager of this website?')) {
            return;
        }

        Array.prototype.forEach.call(nav.children, function (li) {
            li.classList.remove('active');
        });
        tab.classList.add('active');

        // hide (not remove) the website's own form: nothing typed into it is lost, and the other tabs work as usual
        Array.prototype.forEach.call(content.children, function (child) {
            if (child.id !== SLOT_ID) {
                child.style.display = 'none';
            }
        });

        var slot = byId(SLOT_ID);
        if (!slot) {
            slot = document.createElement('div');
            slot.id = SLOT_ID;
            slot.className = 'tab-pane active fade in';
            slot.style.minHeight = '200px';
            content.appendChild(slot);
        }
        slot.style.display = '';

        // a file manager of another website is closed; this website's own one (kept alive by its uploads) is shown again as it is
        if (pane && state.site !== id) {
            destroyPane();
            pane = null;
        }
        if (!pane) {
            createPane(id);
        }
        state.page = page;
        sync();
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
            var page = String(params.url).replace(/^.*?(sites\/web_vhost_domain_edit\.php)/, '$1');

            var tab = document.createElement('li');
            var link = document.createElement('a');
            link.href = '#';
            link.id = TAB_ID;
            link.textContent = 'File Manager';
            link.addEventListener('click', function (event) {
                event.preventDefault();
                openTab(id, page, tab, nav);
            });
            tab.appendChild(link);
            nav.appendChild(tab);

            // "Show" on the progress card brought us here: open the tab straight away
            if (state.reopen && state.reopen === id) {
                state.reopen = 0;
                openTab(id, page, tab, nav);
            }
        } catch (e) {
            if (window.console) {
                console.warn('File Manager tab could not be added:', e);
            }
        }
    }

    ISPConfig.registerHook('onAfterContentLoad', function (name, params) {
        addTab(params);
        sync();
    });
})();

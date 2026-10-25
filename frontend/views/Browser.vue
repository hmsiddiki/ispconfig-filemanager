<template>
  <div id="dropzone" class="container"
       @dragover="onFileDrag"
       @dragleave="dropZone = false"
       @drop="dropZone = false"
  >
    <div v-if="isLoading" id="loading" />

    <Upload v-if="can('upload')" v-show="dropZone == false" :files="files" :drop-zone="dropZone" />

    <b-upload v-if="dropZone && ! isLoading" multiple drag-drop>
      <b class="drop-info">{{ lang('Drop files to upload') }}</b>
    </b-upload>

    <div v-if="!dropZone" class="container">
      <Menu />

      <div id="browser">
        <div v-if="can('read')" class="is-flex is-justify-between">
          <div class="breadcrumb" aria-label="breadcrumbs">
            <ul>
              <li v-for="(item, index) in breadcrumbs" :key="index">
                <a @click="goTo(item.path)">{{ item.name }}</a>
              </li>
            </ul>
          </div>
          <div class="fs-tools">
            <a id="search" class="search-btn" :title="lang('Search')" @click="search">
              <b-icon icon="search" size="is-small" />
            </a>
            <a id="sitemap" class="is-paddingless" :title="lang('Move')" @click="selectDir">
              <b-icon icon="sitemap" size="is-small" />
            </a>
            <a id="view-toggle" class="view-toggle" :title="viewMode === 'grid' ? 'List view' : 'Grid view'" @click="toggleView">
              <b-icon :icon="viewMode === 'grid' ? 'list' : 'th-large'" size="is-small" />
            </a>
          </div>
        </div>

        <section id="multi-actions" class="is-flex is-justify-between">
          <div>
            <b-field v-if="can('upload') && ! checked.length" class="file is-inline-block">
              <b-upload multiple native @input="files = $event">
                <a v-if="! checked.length" class="is-inline-block">
                  <b-icon icon="upload" size="is-small" /> {{ lang('Add files') }}
                </a>
              </b-upload>
            </b-field>
            <a v-if="can(['read', 'write']) && ! checked.length" class="add-new is-inline-block">
              <b-dropdown :mobile-modal="false" :disabled="checked.length > 0" aria-role="list">
                <span slot="trigger">
                  <b-icon icon="plus" size="is-small" /> {{ lang('New') }}
                </span>

                <b-dropdown-item aria-role="listitem" @click="create('dir')">
                  <b-icon icon="folder" size="is-small" /> {{ lang('Folder') }}
                </b-dropdown-item>
                <b-dropdown-item aria-role="listitem" @click="create('file')">
                  <b-icon icon="file" size="is-small" /> {{ lang('File') }}
                </b-dropdown-item>
                <b-dropdown-item v-if="can('upload') && $store.state.config.url_upload_enabled" aria-role="listitem" @click="fromUrl">
                  <b-icon icon="link" size="is-small" /> {{ lang('From URL') }}
                </b-dropdown-item>

              </b-dropdown>
            </a>
            <a v-if="can('batchdownload') && checked.length" class="is-inline-block" @click="batchDownload">
              <b-icon icon="download" size="is-small" /> {{ lang('Download') }}
            </a>
            <a v-if="can('write') && checked.length" class="is-inline-block" @click="copy">
              <b-icon icon="copy" size="is-small" /> {{ lang('Copy') }}
            </a>
            <a v-if="can('write') && checked.length" class="is-inline-block" @click="move">
              <b-icon icon="external-link-square-alt" size="is-small" /> {{ lang('Move') }}
            </a>
            <a v-if="can(['write', 'zip']) && checked.length" class="is-inline-block" @click="zip">
              <b-icon icon="file-archive" size="is-small" /> {{ lang('Zip') }}
            </a>
            <a v-if="can('write') && checked.length" class="is-inline-block" @click="remove">
              <b-icon icon="trash-alt" size="is-small" /> {{ lang('Delete') }}
            </a>
          </div>
          <div id="pagination" v-if="can('read')">
            <Pagination :perpage="perPage" @selected="perPage = $event" />
          </div>
        </section>

        <b-table v-if="can('read') && viewMode === 'list'"
                 :data="content"
                 :default-sort="defaultSort"
                 :paginated="perPage > 0"
                 :per-page="perPage"
                 :current-page.sync="currentPage"
                 :hoverable="true"
                 :is-row-checkable="(row) => row.type != 'back'"
                 :row-class="(row) => 'file-row type-'+row.type"
                 :checked-rows.sync="checked"
                 :loading="isLoading"
                 :checkable="can('batchdownload') || can('write') || can('zip')"
                 @contextmenu="rightClick"
        >
          <template slot-scope="props">
            <b-table-column :label="lang('Name')" :custom-sort="sortByName" field="data.name" sortable>
              <a class="is-block name" :data-path="props.row.path" :data-type="props.row.type" @click="itemClick(props.row)">
                <b-icon :icon="fileIcon(props.row)" :class="'fs-ico fs-ico-' + fileKind(props.row)" size="is-small" />
                <span>{{ props.row.name }}</span>
              </a>
            </b-table-column>

            <b-table-column v-if="can(['write', 'chmod'])" :label="lang('Permissions')" field="data.permissions" sortable width="130">
            <span @click="togglePermissionsView" :title="showSymbolic ? lang('Hide symbolic format') : lang('Show symbolic format')" style="font-family: monospace;cursor: pointer;">
              {{ formatPermissions(props.row.permissions, props.row.type) }}
            </span>
            </b-table-column>

            <b-table-column :label="lang('Size')" :custom-sort="sortBySize" field="data.size" sortable numeric width="150">
              {{ props.row.type == 'back' || props.row.type == 'dir' ? lang('Folder') : formatBytes(props.row.size) }}
            </b-table-column>

            <b-table-column :label="lang('Time')" :custom-sort="sortByTime" field="data.time" sortable numeric width="200">
              {{ props.row.time ? formatDate(props.row.time) : '' }}
            </b-table-column>

            <b-table-column id="single-actions" width="51">
              <b-dropdown :mobile-modal="false" v-if="props.row.type != 'back'" :disabled="checked.length > 0" aria-role="list" position="is-bottom-left">
                <button :ref="'ref-single-action-button-'+props.row.path" slot="trigger" class="button is-small">
                  <b-icon icon="ellipsis-h" size="is-small" />
                </button>

                <b-dropdown-item v-if="props.row.type == 'file' && can('download')" aria-role="listitem" @click="download(props.row)">
                  <b-icon icon="download" size="is-small" /> {{ lang('Download') }}
                </b-dropdown-item>
                <b-dropdown-item v-if="props.row.type == 'file' && can(['download']) && hasPreview(props.row.path)" aria-role="listitem" @click="preview(props.row)">
                  <b-icon icon="file-alt" size="is-small" /> {{ lang('View') }}
                </b-dropdown-item>
                <b-dropdown-item v-if="can('write')" aria-role="listitem" @click="copy($event, props.row)">
                  <b-icon icon="copy" size="is-small" /> {{ lang('Copy') }}
                </b-dropdown-item>
                <b-dropdown-item v-if="can('write')" aria-role="listitem" @click="move($event, props.row)">
                  <b-icon icon="external-link-square-alt" size="is-small" /> {{ lang('Move') }}
                </b-dropdown-item>
                <b-dropdown-item v-if="can('write')" aria-role="listitem" @click="rename($event, props.row)">
                  <b-icon icon="file-signature" size="is-small" /> {{ lang('Rename') }}
                </b-dropdown-item>
                <b-dropdown-item v-if="can(['write', 'zip']) && isArchive(props.row)" aria-role="listitem" @click="unzip($event, props.row)">
                  <b-icon icon="file-archive" size="is-small" /> {{ lang('Unzip') }}
                </b-dropdown-item>
                <b-dropdown-item v-if="can(['write', 'zip']) && ! isArchive(props.row)" aria-role="listitem" @click="zip($event, props.row)">
                  <b-icon icon="file-archive" size="is-small" /> {{ lang('Zip') }}
                </b-dropdown-item>
                <b-dropdown-item v-if="can(['write', 'chmod']) && props.row.permissions !== -1" aria-role="listitem" @click="chmod($event, props.row)">
                  <b-icon icon="lock" size="is-small" /> {{ lang('Permissions') }} ({{ props.row.permissions }})
                </b-dropdown-item>
                <b-dropdown-item v-if="can('write')" aria-role="listitem" @click="remove($event, props.row)">
                  <b-icon icon="trash-alt" size="is-small" /> {{ lang('Delete') }}
                </b-dropdown-item>
                <b-dropdown-item v-if="props.row.type == 'file' && can('download')" v-clipboard:copy="getDownloadLink(props.row.path)" aria-role="listitem">
                  <b-icon icon="clipboard" size="is-small" /> {{ lang('Copy link') }}
                </b-dropdown-item>
              </b-dropdown>
            </b-table-column>
          </template>
        </b-table>

        <div v-if="can('read') && viewMode === 'grid'" class="fs-grid">
          <div v-for="row in gridRows" :key="row.path + row.type"
               :class="['fs-card', 'type-' + row.type, { 'is-checked': isChecked(row) }]"
               @contextmenu="rightClick(row, $event)"
          >
            <label v-if="row.type != 'back' && (can('batchdownload') || can('write') || can('zip'))" class="fs-check" @click.stop>
              <input type="checkbox" :checked="isChecked(row)" @change="toggleChecked(row)">
            </label>

            <b-dropdown :mobile-modal="false" v-if="row.type != 'back'" class="fs-more" :disabled="checked.length > 0" aria-role="list" position="is-bottom-left">
              <button :ref="'ref-single-action-button-'+row.path" slot="trigger" class="button is-small">
                <b-icon icon="ellipsis-h" size="is-small" />
              </button>

              <b-dropdown-item v-if="row.type == 'file' && can('download')" aria-role="listitem" @click="download(row)">
                <b-icon icon="download" size="is-small" /> {{ lang('Download') }}
              </b-dropdown-item>
              <b-dropdown-item v-if="row.type == 'file' && can(['download']) && hasPreview(row.path)" aria-role="listitem" @click="preview(row)">
                <b-icon icon="file-alt" size="is-small" /> {{ lang('View') }}
              </b-dropdown-item>
              <b-dropdown-item v-if="can('write')" aria-role="listitem" @click="copy($event, row)">
                <b-icon icon="copy" size="is-small" /> {{ lang('Copy') }}
              </b-dropdown-item>
              <b-dropdown-item v-if="can('write')" aria-role="listitem" @click="move($event, row)">
                <b-icon icon="external-link-square-alt" size="is-small" /> {{ lang('Move') }}
              </b-dropdown-item>
              <b-dropdown-item v-if="can('write')" aria-role="listitem" @click="rename($event, row)">
                <b-icon icon="file-signature" size="is-small" /> {{ lang('Rename') }}
              </b-dropdown-item>
              <b-dropdown-item v-if="can(['write', 'zip']) && isArchive(row)" aria-role="listitem" @click="unzip($event, row)">
                <b-icon icon="file-archive" size="is-small" /> {{ lang('Unzip') }}
              </b-dropdown-item>
              <b-dropdown-item v-if="can(['write', 'zip']) && ! isArchive(row)" aria-role="listitem" @click="zip($event, row)">
                <b-icon icon="file-archive" size="is-small" /> {{ lang('Zip') }}
              </b-dropdown-item>
              <b-dropdown-item v-if="can(['write', 'chmod']) && row.permissions !== -1" aria-role="listitem" @click="chmod($event, row)">
                <b-icon icon="lock" size="is-small" /> {{ lang('Permissions') }} ({{ row.permissions }})
              </b-dropdown-item>
              <b-dropdown-item v-if="can('write')" aria-role="listitem" @click="remove($event, row)">
                <b-icon icon="trash-alt" size="is-small" /> {{ lang('Delete') }}
              </b-dropdown-item>
              <b-dropdown-item v-if="row.type == 'file' && can('download')" v-clipboard:copy="getDownloadLink(row.path)" aria-role="listitem">
                <b-icon icon="clipboard" size="is-small" /> {{ lang('Copy link') }}
              </b-dropdown-item>
            </b-dropdown>

            <a class="fs-body" :data-path="row.path" :data-type="row.type" @click="itemClick(row)">
              <div class="fs-thumb">
                <img v-if="row.type == 'file' && can('download') && isImage(row.path)" v-lazy="getDownloadLink(row.path)" :alt="row.name">
                <b-icon v-else :icon="fileIcon(row)" :class="'fs-ico fs-ico-' + fileKind(row)" size="is-large" />
              </div>
              <div class="fs-name" :title="row.name">{{ row.type == 'back' ? '..' : row.name }}</div>
              <div class="fs-meta">
                <span v-if="row.type == 'file'">{{ formatBytes(row.size) }}</span>
                <span v-else-if="row.type == 'dir'">{{ lang('Folder') }}</span>
                <span v-if="row.time"> · {{ formatDate(row.time) }}</span>
              </div>
            </a>
          </div>
        </div>

        <section id="bottom-info" class="is-flex is-justify-between">
          <div>
            <span>{{ lang('Selected', checked.length, totalCount) }}</span>
          </div>
          <div v-if="(showAllEntries || hasFilteredEntries) ">
            <input type="checkbox" id="checkbox" @click="toggleHidden">
            <label for="checkbox"> {{ lang('Show hidden') }}</label>
          </div>
        </section>
      </div>
    </div>
  </div>
</template>

<script>
import Vue from 'vue'
import Menu from './partials/Menu'
import Tree from './partials/Tree'
import Permissions from './partials/Permissions'
import Editor from './partials/Editor'
import Gallery from './partials/Gallery'
import Search from './partials/Search'
import Pagination from './partials/Pagination'
import Upload from './partials/Upload'
import UrlUpload from './partials/UrlUpload'
import api from '../api/api'
import VueClipboard from 'vue-clipboard2'
import _ from 'lodash'

Vue.use(VueClipboard)

export default {
  name: 'Browser',
  components: { Menu, Pagination, Upload },
  data() {
    return {
      dropZone: false,
      perPage: this.$store.state.config.pagination[0],
      currentPage: 1,
      checked: [],
      dragItems: null,
      ctxPoint: null,
      isLoading: false,
      defaultSort: ['data.name', 'asc'],
      files: [],
      hasFilteredEntries: false,
      showAllEntries: false,
      showSymbolic: false,
      viewMode: this.readViewMode(),
    }
  },
  computed: {
    gridRows() {
      return this.$store.state.cwd.content.slice().sort((a, b) => this.sortByName(a, b, true))
    },
    breadcrumbs() {
      let path = ''
      let breadcrumbs = [{name: this.lang('Home'), path: '/'}]

      _.forEach(_.split(this.$store.state.cwd.location, '/'), (dir) => {
        path += dir + '/'
        breadcrumbs.push({
          name: dir,
          path: path,
        })
      })

      return _.filter(breadcrumbs, o => o.name)
    },
    content() {
      return this.$store.state.cwd.content
    },
    totalCount() {
      return Number(_.sumBy(this.$store.state.cwd.content, (o) => {
        return o.type == 'file' || o.type == 'dir'
      }))
    },
  },
  watch: {
    '$route' (to) {
      this.isLoading = true
      this.checked = []
      this.currentPage = 1
      api.changeDir({
        to: to.query.cd
      })
        .then(ret => {
          this.$store.commit('setCwd', {
            content: this.filterEntries(ret.files),
            location: ret.location,
          })
          this.isLoading = false
        })
        .catch(error => {
          this.isLoading = false
          this.handleError(error)
        })
    },
  },
  mounted() {
    if (this.can('read')) {
      this.loadFiles()
    }
    document.addEventListener('click', this.flipMenu, true)
    document.addEventListener('dragstart', this.onDragStart, true)
    document.addEventListener('dragover', this.onDragOver, true)
    document.addEventListener('drop', this.onDrop, true)
    document.addEventListener('dragend', this.onDragEnd, true)
    window.addEventListener('scroll', this.placeMenus, true)
    window.addEventListener('resize', this.placeMenus)
  },
  updated() {
    // rows and cards can be dragged onto a folder (or the ".." card) to move them there
    if (this.can('write')) {
      document.querySelectorAll('tr.file-row:not(.type-back), .fs-card:not(.type-back)').forEach(el => el.setAttribute('draggable', 'true'))
    }
  },
  beforeDestroy() {
    document.removeEventListener('click', this.flipMenu, true)
    document.removeEventListener('dragstart', this.onDragStart, true)
    document.removeEventListener('dragover', this.onDragOver, true)
    document.removeEventListener('drop', this.onDrop, true)
    document.removeEventListener('dragend', this.onDragEnd, true)
    window.removeEventListener('scroll', this.placeMenus, true)
    window.removeEventListener('resize', this.placeMenus)
  },
  methods: {
    // An opened "..." menu is placed like a native context menu: fixed to the window, so no card or table can clip it,
    // below the button when there is room, above it when not, and always kept inside the window.
    placeMenus() {
      const menus = document.querySelectorAll('.dropdown.is-active .dropdown-menu')
      if (! menus.length) this.ctxPoint = null
      menus.forEach((menu) => {
        const t = menu.parentNode.getBoundingClientRect()
        const vw = window.innerWidth
        const vh = window.innerHeight
        const s = menu.style
        s.position = 'fixed'
        s.right = 'auto'
        s.bottom = 'auto'
        s.maxHeight = (vh - 16) + 'px'
        s.overflowY = 'auto'
        const w = menu.offsetWidth
        const h = menu.offsetHeight
        const c = this.ctxPoint
        let top = c ? c.y : t.bottom + 4
        if (top + h > vh - 8) top = (c ? c.y : t.top - 4) - h
        s.left = Math.max(8, Math.min(c ? c.x : t.right - w, vw - w - 8)) + 'px'
        s.top = Math.max(8, Math.min(top, vh - h - 8)) + 'px'
      })
    },
    flipMenu(e) {
      if (e && e.isTrusted) this.ctxPoint = null // a real click: menus belong to their button again
      setTimeout(this.placeMenus, 0)
    },
    onFileDrag(e) {
      // the "drop files to upload" overlay is for files from the computer, not for rows being moved
      const files = e.dataTransfer && Array.from(e.dataTransfer.types || []).indexOf('Files') > -1
      this.dropZone = ! this.dragItems && files && this.can('upload') && ! this.isLoading
    },
    dragEntry(el) {
      const row = el && el.closest ? el.closest('tr.file-row, .fs-card') : null
      const a = row ? row.querySelector('[data-path]') : null
      return a ? { row, path: a.dataset.path, type: a.dataset.type } : null
    },
    dropTarget(e) {
      const ent = this.dragItems ? this.dragEntry(e.target) : null
      if (! ent || (ent.type !== 'dir' && ent.type !== 'back')) return null
      // not onto itself or into its own subfolder
      if (this.dragItems.some(i => i.path === ent.path || ent.path.indexOf(i.path + '/') === 0)) return null
      return ent
    },
    clearDropMarks() {
      document.querySelectorAll('.fs-drop-target').forEach(el => el.classList.remove('fs-drop-target'))
    },
    onDragStart(e) {
      this.dragItems = null
      if (! this.can('write')) return
      const ent = this.dragEntry(e.target)
      if (! ent || ent.type === 'back') return
      const item = this.content.find(r => r.path === ent.path && r.type === ent.type)
      if (! item) return
      this.dragItems = this.isChecked(item) && this.checked.length ? this.checked.slice() : [item]
      e.dataTransfer.effectAllowed = 'move'
      e.dataTransfer.setData('text/plain', this.dragItems.map(i => i.name).join(', '))
    },
    onDragOver(e) {
      if (! this.dragItems) return
      this.clearDropMarks()
      const t = this.dropTarget(e)
      if (t) {
        e.preventDefault()
        e.dataTransfer.dropEffect = 'move'
        t.row.classList.add('fs-drop-target')
      }
    },
    onDrop(e) {
      if (! this.dragItems) return
      const t = this.dropTarget(e)
      const items = this.dragItems
      this.dragItems = null
      this.clearDropMarks()
      if (! t) return
      e.preventDefault()
      e.stopPropagation() // not an upload
      this.isLoading = true
      api.moveItems({ destination: t.path, items })
        .then(() => {
          this.isLoading = false
          this.checked = []
          this.loadFiles()
        })
        .catch(error => {
          this.isLoading = false
          this.handleError(error)
        })
    },
    onDragEnd() {
      this.dragItems = null
      this.clearDropMarks()
    },
    readViewMode() {
      // default comes from the config ('default_view'), grid unless told otherwise
      const fallback = this.$store.state.config.default_view === 'list' ? 'list' : 'grid'
      try {
        // 'fg-view2', not the old 'fg-view': the old key held whatever was clicked while list used to be the default,
        // which would keep showing the list to people who never chose it
        const saved = window.localStorage.getItem('fg-view2')
        return saved === 'list' || saved === 'grid' ? saved : fallback
      } catch (e) {
        return fallback
      }
    },
    toggleView() {
      this.viewMode = this.viewMode === 'grid' ? 'list' : 'grid'
      try {
        window.localStorage.setItem('fg-view2', this.viewMode)
      } catch (e) {
        /* storage unavailable: the choice just won't be remembered */
      }
    },
    isChecked(row) {
      return this.checked.indexOf(row) > -1
    },
    toggleChecked(row) {
      this.checked = this.isChecked(row)
        ? this.checked.filter(r => r !== row)
        : this.checked.concat([row])
    },
    fileKind(row) {
      if (row.type == 'back') return 'back'
      if (row.type == 'dir') return 'dir'
      const ext = (row.name.split('.').pop() || '').toLowerCase()
      if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico', 'avif'].includes(ext)) return 'image'
      if (ext == 'pdf') return 'pdf'
      if (['zip', 'rar', '7z', 'tar', 'gz', 'tgz', 'bz2', 'xz'].includes(ext)) return 'archive'
      if (['mp4', 'mkv', 'webm', 'mov', 'avi', 'm4v'].includes(ext)) return 'video'
      if (['mp3', 'wav', 'flac', 'ogg', 'm4a', 'aac'].includes(ext)) return 'audio'
      if (['php', 'js', 'ts', 'css', 'scss', 'html', 'htm', 'json', 'xml', 'yml', 'yaml', 'sh', 'py', 'sql', 'vue', 'go', 'java', 'c', 'cpp', 'rb', 'ini', 'conf'].includes(ext)) return 'code'
      if (['txt', 'md', 'log', 'csv', 'doc', 'docx', 'rtf'].includes(ext)) return 'text'
      return 'file'
    },
    fileIcon(row) {
      const icons = {
        back: 'level-up-alt', dir: 'folder', image: 'file-image', pdf: 'file-pdf', archive: 'file-archive',
        video: 'file-video', audio: 'file-audio', code: 'file-code', text: 'file-alt', file: 'file',
      }
      return icons[this.fileKind(row)]
    },
    toggleHidden() {
      this.showAllEntries = !this.showAllEntries
      this.loadFiles()
      this.checked = []
    },
    togglePermissionsView() {
    this.showSymbolic = !this.showSymbolic
  },
  formatPermissions(permissions, type) {
    if (permissions === -1) return
    const numeric = permissions.toString()
    if (this.showSymbolic) {
      const symbolic = this.convertToSymbolic(permissions, type)
      return `[${symbolic}]`
    }
    return numeric
  },
    convertToSymbolic(permissions, type) {
      if (permissions === -1) return ''
        const symbols = ['---', '--x', '-w-', '-wx', 'r--', 'r-x', 'rw-', 'rwx']
        const owner = symbols[Math.floor(permissions / 100) % 10]
        const group = symbols[Math.floor(permissions / 10) % 10]
        const others = symbols[permissions % 10]
        const prefix = type === 'dir' ? 'd' : '-'
      return `${prefix}${owner}${group}${others}`
    },
    filterEntries(files){
      var filter_entries = this.$store.state.config.filter_entries
      this.hasFilteredEntries = false
      if (!this.showAllEntries && typeof filter_entries !== 'undefined' && filter_entries.length > 0){
        let filteredFiles = []
        _.forEach(files, (file) => {
          let filterContinue = false
          _.forEach(filter_entries, (ffilter_Entry) => {
            if (typeof ffilter_Entry !== 'undefined' && ffilter_Entry.length > 0){
              let filter_Entry = ffilter_Entry
              let filterEntry_type = filter_Entry.endsWith('/')? 'dir':'file'
              filter_Entry = filter_Entry.replace(/\/$/, '')
              let filterEntry_isFullPath = filter_Entry.startsWith('/')
              let filterEntry_tmpName  = filterEntry_isFullPath? '/'+file.path : file.name
              filter_Entry             = filterEntry_isFullPath? '/'+filter_Entry : filter_Entry
              filter_Entry = filter_Entry.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.$&')
              let thisRegex = new RegExp('^'+filter_Entry+'$', 'iu')
              if(file.type == filterEntry_type && thisRegex.test(filterEntry_tmpName))
              {
                filterContinue = true
                this.hasFilteredEntries = true
                return false
              }
            }
          })
          if(!filterContinue){
            filteredFiles.push(file)
          }
        })
        return filteredFiles
      }
      return files
    },
    loadFiles() {
      api.getDir({
        to: '',
      })
        .then(ret => {
          this.$store.commit('setCwd', {
            content: this.filterEntries(ret.files),
            location: ret.location,
          })
        })
        .catch(error => this.handleError(error))
    },
    goTo(path) {
      this.$router.push({ name: 'browser', query: { 'cd': path }}).catch(() => {})
    },
    getSelected() {
      return _.reduce(this.checked, function(result, value) {
        result.push(value)
        return result
      }, [])
    },
    itemClick(item) {
      if (item.type == 'dir' || item.type == 'back') {
        this.goTo(item.path)
      } else if (this.can(['download']) && this.hasPreview(item.path)) {
        this.preview(item)
      } else if (this.can(['download'])) {
        this.download(item)
      }
    },
    rightClick(row, event) {
      if (! row || row.type == 'back') {
        return
      }
      event.preventDefault()
      const ref = this.$refs['ref-single-action-button-'+row.path]
      const button = Array.isArray(ref) ? ref[0] : ref
      if (! button) return
      this.ctxPoint = { x: event.clientX, y: event.clientY } // the menu opens at the pointer, like a native context menu
      button.click()
    },
    selectDir() {
      this.$modal.open({
        parent: this,
        hasModalCard: true,
        component: Tree,
        events: {
          selected: dir => {
            this.goTo(dir.path)
          }
        },
      })
    },
    copy(event, item) {
      this.$modal.open({
        parent: this,
        hasModalCard: true,
        component: Tree,
        events: {
          selected: dir => {
            this.isLoading = true
            api.copyItems({
              destination: dir.path,
              items: item ? [item] : this.getSelected(),
            })
              .then(() => {
                this.isLoading = false
                this.loadFiles()
              })
              .catch(error => {
                this.isLoading = false
                this.handleError(error)
              })
            this.checked = []
          }
        },
      })
    },
    move(event, item) {
      this.$modal.open({
        parent: this,
        hasModalCard: true,
        component: Tree,
        events: {
          selected: dir => {
            this.isLoading = true
            api.moveItems({
              destination: dir.path,
              items: item ? [item] : this.getSelected(),
            })
              .then(() => {
                this.isLoading = false
                this.loadFiles()
              })
              .catch(error => {
                this.isLoading = false
                this.handleError(error)
              })
            this.checked = []
          }
        },
      })
    },
    batchDownload() {
      let items = this.getSelected()

      this.isLoading = true
      api.batchDownload({
        items: items,
      })
        .then(ret => {
          this.isLoading = false
          this.$dialog.alert({
            message: this.lang('Your file is ready'),
            confirmText: this.lang('Download'),
            onConfirm: () => {
              window.open(Vue.config.baseURL+'/batchdownload&uniqid='+ret.uniqid, '_blank')
            }
          })
        })
        .catch(error => {
          this.isLoading = false
          this.handleError(error)
        })
    },
    download(item) {
      window.open(this.getDownloadLink(item.path), '_blank')
    },
    search() {
      this.$modal.open({
        parent: this,
        hasModalCard: true,
        component: Search,
        events: {
          selected: item => {
            this.goTo(item.dir)
          }
        },
      })
    },
    preview(item) {
      let modal = null
      if (this.isImage(item.path)) {
        modal = Gallery
      }
      if (this.isText(item.path)) {
        modal = Editor
      }
      this.$modal.open({
        parent: this,
        props: { item: item },
        hasModalCard: true,
        component: modal,
        // the editor asks before throwing away unsaved changes, so clicking outside must not close it
        canCancel: modal !== Editor,
        customClass: modal === Editor ? 'fs-editor-modal' : '',
      })
    },
    isArchive(item) {
      return item.type == 'file' && item.name.split('.').pop() == 'zip'
    },
    unzip(event, item) {
      this.$dialog.confirm({
        message: this.lang('Are you sure you want to do this?'),
        type: 'is-danger',
        cancelText: this.lang('Cancel'),
        confirmText: this.lang('Unzip'),
        onConfirm: () => {
          this.isLoading = true
          api.unzipItem({
            item: item.path,
            destination: this.$store.state.cwd.location,
          })
            .then(() => {
              this.isLoading = false
              this.loadFiles()
            })
            .catch(error => {
              this.isLoading = false
              this.handleError(error)
            })
          this.checked = []
        }
      })
    },
    zip(event, item) {
      this.$dialog.prompt({
        message: this.lang('Name'),
        cancelText: this.lang('Cancel'),
        confirmText: this.lang('Create'),
        inputAttrs: {
          value: this.$store.state.config.default_archive_name,
          placeholder: this.$store.state.config.default_archive_name,
          maxlength: 100,
          required: false,
        },
        onConfirm: (value) => {
          if (! value) {
            return
          }
          this.isLoading = true
          api.zipItems({
            name: value,
            items: item ? [item] : this.getSelected(),
            destination: this.$store.state.cwd.location,
          })
            .then(() => {
              this.isLoading = false
              this.loadFiles()
            })
            .catch(error => {
              this.isLoading = false
              this.handleError(error)
            })
          this.checked = []
        }
      })
    },
    chmod(event, item) {
      this.$modal.open({
        parent: this,
        hasModalCard: true,
        component: Permissions,
        props: {
          name: item.name,
          permissions: item.permissions,
          isDir: item.type == 'dir',
        },
        events: {
          saved: (permissions, recursive = null) => {
            this.isLoading = true
            api.chmodItems({
              items: item ? [item] : this.getSelected(),
              permissions: permissions,
              recursive: recursive,
            })
              .then(() => {
                this.isLoading = false
                this.loadFiles()
              })
              .catch(error => {
                this.isLoading = false
                this.handleError(error)
              })
            this.checked = []
          }
        },
      })
    },
    rename(event, item) {
      this.$dialog.prompt({
        message: this.lang('New name'),
        cancelText: this.lang('Cancel'),
        confirmText: this.lang('Rename'),
        inputAttrs: {
          value: item ? item.name : this.getSelected()[0].name,
          maxlength: 100,
          required: false,
        },
        onConfirm: (value) => {
          this.isLoading = true
          api.renameItem({
            from: item.name,
            to: value,
            destination: this.$store.state.cwd.location,
          })
            .then(() => {
              this.isLoading = false
              this.loadFiles()
            })
            .catch(error => {
              this.isLoading = false
              this.handleError(error)
            })
          this.checked = []
        }
      })
    },
    fromUrl() {
      this.$modal.open({
        parent: this,
        hasModalCard: true,
        component: UrlUpload,
        events: {
          done: () => this.loadFiles(),
        },
      })
    },
    create(type) {
      this.$dialog.prompt({
        cancelText: this.lang('Cancel'),
        confirmText: this.lang('Create'),
        inputAttrs: {
          placeholder: type == 'dir' ? 'MyFolder' : 'file.txt',
          maxlength: 100,
          required: false,
        },
        onConfirm: (value) => {
          this.isLoading = true
          api.createNew({
            type: type,
            name: value,
            destination: this.$store.state.cwd.location,
          })
          // TODO: cors is triggering this too early?
            .then(() => {
              this.isLoading = false
              this.loadFiles()
            })
            .catch(error => {
              this.isLoading = false
              this.handleError(error)
            })
          this.checked = []
        }
      })
    },
    remove(event, item) {
      this.$dialog.confirm({
        message: this.lang('Are you sure you want to do this?'),
        type: 'is-danger',
        cancelText: this.lang('Cancel'),
        confirmText: this.lang('Delete'),
        onConfirm: () => {
          this.isLoading = true
          api.removeItems({
            items: item ? [item] : this.getSelected(),
          })
            .then((result) => {
              this.isLoading = false
              this.loadFiles()
              if (result !== 'Done') {
                this.handleError(this.lang('Unable to complete the action'))
              }
            })
            .catch(error => {
              this.isLoading = false
              this.handleError(error)
            })
          this.checked = []
        }
      })
    },
    sortByName(a, b, order) {
      return this.customSort(a, b, !order, 'name')
    },
    sortBySize(a, b, order) {
      return this.customSort(a, b, !order, 'size')
    },
    sortByTime(a, b, order) {
      return this.customSort(a, b, !order, 'time')
    },
    customSort(a, b, order, param) {
      if (a.type == 'back') return -1
      if (b.type == 'back') return 1

      if (a.type == 'dir' && b.type != 'dir') return -1
      if (b.type == 'dir' && a.type != 'dir') return 1

      if (b.type == a.type) {
        if (a[param] === b[param]) return this.customSort(a, b, false, 'name')

        if (_.isString(a[param])) return (a[param].localeCompare(b[param])) * (order ? -1 : 1)
        else return ((a[param] < b[param]) ? -1 : 1) * (order ? -1 : 1)
      }
    },
  }
}
</script>

<style scoped>
#loading {
  width: 100%;
  height: 100%;
  position: fixed;
  z-index: 1000;
  top: 0;
  left: 0;
  user-drag: none;
  user-select: none;
  -moz-user-select: none;
  -webkit-user-drag: none;
  -webkit-user-select: none;
  -ms-user-select: none;
}
#dropzone {
  padding: 0;
}
#browser {
  margin: 50px auto 100px auto;
}
.breadcrumb a {
  font-weight: bold;
}
#multi-actions {
  min-height: 55px;
}
#multi-actions a {
  margin: 0 15px 15px 0;
}
#bottom-info {
  padding: 15px 0;
}
.file-row a {
  color: #373737;
}
.file-row a.name {
  word-break: break-all;
}
.file-row.type-dir a.name {
  font-weight: bold
}
#single-actions {
  padding: 6px 12px;
}
.drop-info {
  margin: 20% auto;
}
.search-btn {
  margin-right: 10px;
}
</style>

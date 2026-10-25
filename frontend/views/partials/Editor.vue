<template>
  <div class="modal-card fs-editor">
    <header class="modal-card-head fs-ed-head">
      <p class="modal-card-title fs-ed-title">
        <b-icon icon="file-code" size="is-small" />
        <span class="fs-ed-name">{{ item.name }}</span>
        <span v-if="dirty" class="fs-dirty" :title="lang('Unsaved changes')">&#9679;</span>
      </p>
      <div class="fs-ed-tools">
        <span class="fs-ed-lang">{{ modeLabel }}</span>
        <a :title="lang('Find') + ' (Ctrl+F)'" :class="{ 'is-on': findOpen && !replaceOpen }" @click="openFind(false)"><b-icon icon="search" size="is-small" /></a>
        <a v-if="can('write')" :title="lang('Find and replace') + ' (Ctrl+H)'" :class="{ 'is-on': replaceOpen }" @click="openFind(true)"><b-icon icon="exchange-alt" size="is-small" /></a>
        <a :title="lang('Go to line')" @click="run('jumpToLine')"><b-icon icon="sort-numeric-down" size="is-small" /></a>
        <a :title="wrap ? 'Wrap: on' : 'Wrap: off'" :class="{ 'is-on': wrap }" @click="toggleWrap"><b-icon icon="paragraph" size="is-small" /></a>
        <a :title="lang('Smaller')" @click="zoom(-1)"><b-icon icon="search-minus" size="is-small" /></a>
        <a :title="lang('Larger')" @click="zoom(1)"><b-icon icon="search-plus" size="is-small" /></a>
        <a :title="full ? lang('Exit full screen') + ' (Esc)' : lang('Full screen')" :class="{ 'is-on': full }" @click="toggleFull"><b-icon :icon="full ? 'compress' : 'expand'" size="is-small" /></a>
      </div>
    </header>

    <section class="modal-card-body fs-ed-body">
      <div v-if="findOpen" class="fs-find" @keydown.esc.stop.prevent="closeFind">
        <div class="fs-find-row">
          <input
            ref="findInput"
            v-model="findText"
            type="text"
            class="fs-find-input"
            :class="{ 'is-danger': findError }"
            :placeholder="lang('Find')"
            spellcheck="false"
            autocomplete="off"
            @input="scheduleHighlight"
            @keydown.enter.prevent="findNext($event.shiftKey)"
          >
          <button type="button" class="fs-find-btn fs-find-opt" :class="{ 'is-on': caseSensitive }" :title="lang('Match case')" @click="toggleOpt('caseSensitive')">
            Aa
          </button>
          <button type="button" class="fs-find-btn fs-find-opt" :class="{ 'is-on': useRegex }" :title="lang('Regular expression')" @click="toggleOpt('useRegex')">
            .*
          </button>
          <span class="fs-find-count" :class="{ 'is-danger': findError || (findText && !total) }">{{ countLabel }}</span>
          <button type="button" class="fs-find-btn" :title="lang('Previous') + ' (Shift+Enter)'" @click="findNext(true)">
            <b-icon icon="chevron-up" size="is-small" />
          </button>
          <button type="button" class="fs-find-btn" :title="lang('Next') + ' (Enter)'" @click="findNext(false)">
            <b-icon icon="chevron-down" size="is-small" />
          </button>
          <button type="button" class="fs-find-btn" :title="lang('Close') + ' (Esc)'" @click="closeFind">
            <b-icon icon="times" size="is-small" />
          </button>
        </div>
        <div v-if="replaceOpen && can('write')" class="fs-find-row">
          <input
            ref="replaceInput"
            v-model="replaceText"
            type="text"
            class="fs-find-input"
            :placeholder="lang('Replace with')"
            spellcheck="false"
            autocomplete="off"
            @keydown.enter.prevent="replaceOne"
          >
          <button type="button" class="button is-small" @click="replaceOne">
            {{ lang('Replace') }}
          </button>
          <button type="button" class="button is-small" @click="replaceAll">
            {{ lang('Replace all') }}
          </button>
        </div>
      </div>
      <div v-if="loading" class="fs-ed-msg">{{ lang('Loading') }}...</div>
      <div v-else-if="blocked" class="fs-ed-msg">{{ blocked }}</div>
      <div ref="host" class="fs-ed-host" />
    </section>

    <footer class="modal-card-foot fs-ed-foot">
      <span class="fs-ed-status">Ln {{ cursor.line }}, Col {{ cursor.col }}</span>
      <span class="fs-ed-status">{{ eol }}</span>
      <span class="fs-ed-status">UTF-8</span>
      <span class="fs-ed-spacer" />
      <button v-if="can('write') && !blocked" class="button is-primary" type="button" :disabled="!dirty || saving" @click="saveFile()">
        {{ lang('Save') }}
      </button>
      <button class="button" type="button" @click="requestClose()">
        {{ lang('Close') }}
      </button>
    </footer>
  </div>
</template>

<script>
import api from '../../api/api'

import CodeMirror from 'codemirror'
import 'codemirror/lib/codemirror.css'
import 'codemirror/addon/dialog/dialog.css'
import 'codemirror/addon/fold/foldgutter.css'

import 'codemirror/mode/javascript/javascript'
import 'codemirror/mode/css/css'
import 'codemirror/mode/xml/xml'
import 'codemirror/mode/htmlmixed/htmlmixed'
import 'codemirror/mode/clike/clike'
import 'codemirror/mode/php/php'
import 'codemirror/mode/markdown/markdown'
import 'codemirror/mode/yaml/yaml'
import 'codemirror/mode/shell/shell'
import 'codemirror/mode/sql/sql'
import 'codemirror/mode/python/python'
import 'codemirror/mode/go/go'
import 'codemirror/mode/nginx/nginx'
import 'codemirror/mode/properties/properties'
import 'codemirror/mode/toml/toml'
import 'codemirror/mode/diff/diff'
import 'codemirror/mode/dockerfile/dockerfile'

import 'codemirror/addon/edit/matchbrackets'
import 'codemirror/addon/edit/closebrackets'
import 'codemirror/addon/edit/closetag'
import 'codemirror/addon/comment/comment'
import 'codemirror/addon/selection/active-line'
import 'codemirror/addon/fold/foldcode'
import 'codemirror/addon/fold/foldgutter'
import 'codemirror/addon/fold/brace-fold'
import 'codemirror/addon/fold/indent-fold'
import 'codemirror/addon/fold/xml-fold'
import 'codemirror/addon/fold/comment-fold'
import 'codemirror/addon/dialog/dialog'
import 'codemirror/addon/search/searchcursor'
import 'codemirror/addon/search/search'
import 'codemirror/addon/search/jump-to-line'

const MAX_BYTES = 2 * 1024 * 1024

// file extension (or whole file name) -> [CodeMirror mode, label]
const MODES = {
  js: ['text/javascript', 'JavaScript'], mjs: ['text/javascript', 'JavaScript'], cjs: ['text/javascript', 'JavaScript'],
  jsx: ['text/jsx', 'JSX'], ts: ['text/typescript', 'TypeScript'], tsx: ['text/typescript-jsx', 'TSX'],
  json: ['application/json', 'JSON'], css: ['text/css', 'CSS'], scss: ['text/x-scss', 'SCSS'], less: ['text/x-less', 'Less'],
  html: ['htmlmixed', 'HTML'], htm: ['htmlmixed', 'HTML'], vue: ['htmlmixed', 'Vue'],
  xml: ['application/xml', 'XML'], svg: ['application/xml', 'SVG'],
  php: ['application/x-httpd-php', 'PHP'], md: ['text/x-markdown', 'Markdown'],
  yml: ['text/x-yaml', 'YAML'], yaml: ['text/x-yaml', 'YAML'],
  sh: ['text/x-sh', 'Shell'], bash: ['text/x-sh', 'Shell'], env: ['text/x-properties', 'Env'],
  sql: ['text/x-sql', 'SQL'], py: ['text/x-python', 'Python'], go: ['text/x-go', 'Go'],
  c: ['text/x-csrc', 'C'], h: ['text/x-csrc', 'C'], cpp: ['text/x-c++src', 'C++'], java: ['text/x-java', 'Java'],
  conf: ['text/x-nginx-conf', 'Config'], ini: ['text/x-properties', 'INI'], properties: ['text/x-properties', 'Properties'],
  toml: ['text/x-toml', 'TOML'], diff: ['text/x-diff', 'Diff'], patch: ['text/x-diff', 'Diff'],
  dockerfile: ['text/x-dockerfile', 'Dockerfile'], htaccess: ['text/x-nginx-conf', 'htaccess'],
}

export default {
  name: 'Editor',
  props: ['item'],
  data() {
    return {
      loading: true,
      saving: false,
      blocked: '',
      dirty: false,
      wrap: this.read('fg-ed-wrap', '0') === '1',
      full: this.read('fg-ed-full', '0') === '1',
      findOpen: false,
      replaceOpen: false,
      findText: '',
      replaceText: '',
      caseSensitive: false,
      useRegex: false,
      findError: '',
      total: 0,
      current: 0,
      capped: false,
      fontSize: parseInt(this.read('fg-ed-font', '14'), 10) || 14,
      cursor: { line: 1, col: 1 },
      eol: 'LF',
      modeLabel: 'Text',
    }
  },
  computed: {
    countLabel() {
      if (this.findError) return this.lang(this.findError)
      if (!this.findText) return ''
      if (!this.total) return this.lang('No results')
      return (this.current || '-') + ' ' + this.lang('of') + ' ' + this.total + (this.capped ? '+' : '')
    },
  },
  mounted() {
    // one tick later: while this component is mounting, the modal wrapper around it has no element yet
    this.$nextTick(() => this.applyFull())
    window.addEventListener('beforeunload', this.guardUnload)
    if (this.item.size > MAX_BYTES) {
      this.fail(this.lang('File is too large to edit here') + ' (' + this.formatBytes(this.item.size) + ')')
      return
    }
    api.downloadItem({ path: this.item.path })
      .then(text => this.start(String(text)))
      .catch(error => {
        this.loading = false
        this.handleError(error)
      })
  },
  beforeDestroy() {
    window.removeEventListener('beforeunload', this.guardUnload)
    window.clearTimeout(this.highlightTimer)
    this.notifyParent(false) // closing the editor must give the page its window back
  },
  methods: {
    read(key, fallback) {
      try {
        return window.localStorage.getItem(key) || fallback
      } catch (e) {
        return fallback
      }
    },
    write(key, value) {
      try {
        window.localStorage.setItem(key, value)
      } catch (e) {
        /* storage unavailable: the setting just isn't remembered */
      }
    },
    fail(message) {
      this.loading = false
      this.blocked = message
    },
    modeFor(name) {
      const lower = name.toLowerCase()
      const ext = lower.indexOf('.') === -1 ? lower : lower.split('.').pop()
      return MODES[ext] || [null, 'Text']
    },
    start(text) {
      // a NUL byte means this is not a text file: saving it back would corrupt it
      if (text.indexOf('\u0000') !== -1) {
        this.fail(this.lang('This looks like a binary file, so it is not opened in the editor'))
        return
      }

      const crlf = text.indexOf('\r\n') !== -1
      this.eol = crlf ? 'CRLF' : 'LF'
      const [mode, label] = this.modeFor(this.item.name)
      this.modeLabel = label

      this.loading = false
      this.$nextTick(() => {
        this.cm = CodeMirror(this.$refs.host, {
          value: text,
          mode,
          theme: 'fs',
          lineNumbers: true,
          lineWrapping: this.wrap,
          // keep Windows line endings as they were instead of silently turning them into LF
          lineSeparator: crlf ? '\r\n' : null,
          indentUnit: 4,
          tabSize: 4,
          indentWithTabs: false,
          matchBrackets: true,
          autoCloseBrackets: true,
          autoCloseTags: true,
          styleActiveLine: true,
          foldGutter: true,
          gutters: ['CodeMirror-linenumbers', 'CodeMirror-foldgutter'],
          readOnly: !this.can('write'),
          extraKeys: {
            'Ctrl-S': () => this.saveFile(),
            'Cmd-S': () => this.saveFile(),
            'Ctrl-/': 'toggleComment',
            'Cmd-/': 'toggleComment',
            'Ctrl-F': () => this.openFind(false),
            'Cmd-F': () => this.openFind(false),
            'Ctrl-H': () => this.openFind(true),
            'Cmd-Alt-F': () => this.openFind(true),
            'F3': () => this.findNext(false),
            'Shift-F3': () => this.findNext(true),
            'Tab': cm => (cm.somethingSelected() ? cm.indentSelection('add') : cm.replaceSelection('    ', 'end')),
            'Shift-Tab': cm => cm.indentSelection('subtract'),
            'Esc': () => {
              // one Esc undoes one thing: the find bar first, then full screen, then the editor itself
              if (this.findOpen) this.closeFind()
              else if (this.full) this.toggleFull()
              else this.requestClose()
            },
            'F11': () => this.toggleFull(),
          },
        })
        this.cm.on('change', () => {
          this.dirty = !this.cm.isClean()
          if (this.findOpen) this.scheduleHighlight() // keep the match count honest while editing
        })
        this.cm.on('cursorActivity', () => {
          const c = this.cm.getCursor()
          this.cursor = { line: c.line + 1, col: c.ch + 1 }
        })
        this.applyFont()
        this.cm.refresh()
        this.cm.focus()
      })
    },
    applyFont() {
      if (this.$refs.host) this.$refs.host.style.fontSize = this.fontSize + 'px'
      if (this.cm) this.cm.refresh()
    },
    zoom(step) {
      this.fontSize = Math.min(24, Math.max(10, this.fontSize + step))
      this.write('fg-ed-font', String(this.fontSize))
      this.applyFont()
    },
    // ---- find & replace -------------------------------------------------
    openFind(withReplace) {
      if (!this.cm) return
      this.findOpen = true
      this.replaceOpen = withReplace && this.can('write')

      // start from what is selected (one line only), like every other editor
      const selected = this.cm.getSelection()
      if (selected && selected.indexOf('\n') === -1 && selected.length < 200) this.findText = selected

      this.$nextTick(() => {
        const input = this.$refs.findInput
        if (input) {
          input.focus()
          input.select()
        }
        this.cm.refresh()
        this.highlight()
      })
    },
    closeFind() {
      this.findOpen = false
      this.replaceOpen = false
      window.clearTimeout(this.highlightTimer)
      this.clearMarks()
      this.$nextTick(() => {
        if (this.cm) {
          this.cm.refresh()
          this.cm.focus()
        }
      })
    },
    toggleOpt(name) {
      this[name] = !this[name]
      this.highlight()
      if (this.$refs.findInput) this.$refs.findInput.focus()
    },
    scheduleHighlight() {
      this.highlightPending = true
      window.clearTimeout(this.highlightTimer)
      this.highlightTimer = window.setTimeout(() => this.highlight(), 120)
    },
    /** The text or pattern to look for, or null when there is nothing (valid) to look for. */
    buildQuery() {
      this.findError = ''
      if (!this.findText) return null
      if (!this.useRegex) return this.findText
      try {
        const re = new RegExp(this.findText, this.caseSensitive ? '' : 'i')
        if (re.test('')) {
          // something like a* matches nothing everywhere: it would "find" an endless stream of empty matches
          this.findError = 'Pattern matches empty text'
          return null
        }
        return re
      } catch (e) {
        this.findError = 'Invalid pattern'
        return null
      }
    },
    cursorFor(query, pos) {
      return this.cm.getSearchCursor(query, pos, this.useRegex ? undefined : { caseFold: !this.caseSensitive })
    },
    clearMarks() {
      (this.marks || []).forEach(mark => mark.clear())
      this.marks = []
      this.total = this.current = 0
      this.capped = false
    },
    /** Mark every match (up to a limit) and count them. */
    highlight() {
      if (!this.cm) return
      this.highlightPending = false
      this.clearMarks()
      const query = this.buildQuery()
      if (!query) return

      const LIMIT = 2000
      const cursor = this.cursorFor(query, { line: 0, ch: 0 })
      this.cm.operation(() => {
        while (this.marks.length < LIMIT && cursor.findNext()) {
          this.marks.push(this.cm.markText(cursor.from(), cursor.to(), { className: 'cm-searching' }))
        }
      })
      this.total = this.marks.length
      this.capped = this.marks.length >= LIMIT
      this.updateCurrent()
    },
    /** Which match, if any, is the current selection ("3 of 12"). */
    updateCurrent() {
      const from = this.cm.getCursor('from')
      const to = this.cm.getCursor('to')
      this.current = 0
      for (let i = 0; i < this.marks.length; i++) {
        const found = this.marks[i].find()
        if (found && found.from.line === from.line && found.from.ch === from.ch && found.to.line === to.line && found.to.ch === to.ch) {
          this.current = i + 1
          return
        }
      }
    },
    findNext(backwards) {
      const query = this.buildQuery()
      if (!query || !this.cm) return
      if (this.highlightPending) {
        // typing just changed what is being searched for: bring the marks up to date before stepping
        window.clearTimeout(this.highlightTimer)
        this.highlight()
      }

      let cursor = this.cursorFor(query, backwards ? this.cm.getCursor('from') : this.cm.getCursor('to'))
      let found = cursor.find(backwards)
      if (!found) {
        // wrap round to the other end of the file
        const last = this.cm.lastLine()
        cursor = this.cursorFor(query, backwards ? { line: last, ch: this.cm.getLine(last).length } : { line: 0, ch: 0 })
        found = cursor.find(backwards)
      }
      if (!found) return

      this.cm.setSelection(cursor.from(), cursor.to())
      this.cm.scrollIntoView({ from: cursor.from(), to: cursor.to() }, 80)
      this.updateCurrent()
    },
    /** $1..$9 and $& in the replacement text only mean something for regular expressions. */
    expand(match) {
      if (!this.useRegex || !match) return this.replaceText
      return this.replaceText.replace(/\$([0-9&])/g, (all, key) => (key === '&' ? match[0] : (match[key] === undefined ? '' : match[key])))
    },
    replaceOne() {
      const query = this.buildQuery()
      if (!query || !this.cm || !this.can('write')) return

      // replace the match that is selected right now (if the selection is one), then move on to the next
      const from = this.cm.getCursor('from')
      const to = this.cm.getCursor('to')
      const cursor = this.cursorFor(query, from)
      if (cursor.find(false) && cursor.from().line === from.line && cursor.from().ch === from.ch && cursor.to().line === to.line && cursor.to().ch === to.ch) {
        cursor.replace(this.expand(cursor.match))
        this.cm.setCursor(cursor.to())
      }
      this.findNext(false)
      this.scheduleHighlight()
    },
    replaceAll() {
      const query = this.buildQuery()
      if (!query || !this.cm || !this.can('write')) return

      let count = 0
      const cursor = this.cursorFor(query, { line: 0, ch: 0 })
      this.cm.operation(() => {
        while (count < 100000 && cursor.findNext()) {
          cursor.replace(this.expand(cursor.match))
          count++
        }
      })
      this.highlight()
      this.$toast.open({
        message: count ? count + ' ' + this.lang('replaced') : this.lang('No results'),
        type: count ? 'is-success' : 'is-warning',
      })
    },
    /**
     * Inside another page (a control panel's iframe) this window cannot grow past its own frame, so ask that page to stretch the
     * frame over the whole browser window. Same-origin only: nothing is sent to a page on another address.
     */
    notifyParent(on) {
      try {
        if (window.parent && window.parent !== window) {
          window.parent.postMessage({ type: 'filemanager-fullscreen', on: !!on }, window.location.origin)
        }
      } catch (e) {
        /* no parent to tell, or not allowed to: the editor simply fills its own frame */
      }
    },
    toggleFull() {
      this.full = !this.full
      this.write('fg-ed-full', this.full ? '1' : '0')
      this.applyFull()
    },
    applyFull(attempt = 0) {
      // the class goes on the modal wrapper (a parent component's element) so the whole window can grow, not just this card
      const modal = this.$parent && this.$parent.$el
      if (modal && modal.classList) {
        modal.classList.toggle('is-editor-full', this.full)
        this.notifyParent(this.full)
      } else if (attempt < 20) {
        // first paint: the wrapper is still a placeholder comment node, so try again in a moment
        window.setTimeout(() => this.applyFull(attempt + 1), 30)
        return
      }
      this.$nextTick(() => {
        if (this.cm) {
          this.cm.refresh()
          this.cm.focus()
        }
      })
    },
    toggleWrap() {
      this.wrap = !this.wrap
      this.write('fg-ed-wrap', this.wrap ? '1' : '0')
      if (this.cm) this.cm.setOption('lineWrapping', this.wrap)
    },
    run(command) {
      if (this.cm) {
        this.cm.focus()
        this.cm.execCommand(command)
      }
    },
    guardUnload(event) {
      if (this.dirty) {
        event.preventDefault()
        event.returnValue = ''
      }
    },
    requestClose() {
      if (!this.dirty) {
        this.$parent.close()
        return
      }
      this.$dialog.confirm({
        message: this.lang('You have unsaved changes. Close without saving?'),
        type: 'is-danger',
        confirmText: this.lang('Close'),
        cancelText: this.lang('Cancel'),
        onConfirm: () => {
          this.dirty = false
          this.$parent.close()
        },
      })
    },
    saveFile() {
      if (!this.cm || !this.can('write') || this.saving || !this.dirty) return
      this.saving = true
      const generation = this.cm.changeGeneration()
      api.saveContent({
        name: this.item.name,
        // the folder the file really lives in, not whichever folder happens to be open behind this window
        dir: this.item.path.replace(/\/[^/]*$/, '') || '/',
        content: this.cm.getValue(),
      })
        .then(result => {
          this.saving = false
          if (result.data !== 'Done') {
            this.handleError(this.lang('Unable to save file'))
            return
          }
          this.cm.markClean(generation)
          this.dirty = !this.cm.isClean()
          this.$toast.open({ message: this.lang('Updated'), type: 'is-success' })
        })
        .catch(error => {
          this.saving = false
          this.handleError(error)
        })
    },
  },
}
</script>

<style lang="scss">
// Whole-window editor: the stock modal card is narrow, this one uses almost the full screen.
// .modal.fs-editor-modal (not just .fs-editor-modal): Buefy caps .animation-content at 960px with a rule of equal
// weight that can load after this one, so this needs to be more specific to win
// !important: Buefy writes max-width: 960px straight onto the element as an inline style, which only !important can beat
.modal.fs-editor-modal .animation-content {
  width: min(1180px, 96vw);
  max-width: none !important;
  margin: 0 auto;
}

.modal-card.fs-editor {
  width: 100%;
  height: min(86vh, 900px);
  max-height: none;
  margin: 0;

  .fs-ed-head { padding: 0.6rem 1rem; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
  .fs-ed-title { display: flex; align-items: center; gap: 0.5rem; font-size: 1rem; min-width: 0; flex: 1 1 auto; }
  .fs-ed-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .fs-dirty { color: #e8a317; font-size: 0.9rem; }
  .fs-ed-tools { display: flex; align-items: center; gap: 0.15rem; }
  .fs-ed-tools a { display: inline-flex; align-items: center; justify-content: center; width: 2rem; height: 2rem; border-radius: 8px; opacity: 0.75; }
  .fs-ed-tools a:hover, .fs-ed-tools a.is-on { opacity: 1; background: rgba(127, 127, 127, 0.18); }
  .fs-ed-lang { font-size: 0.75rem; opacity: 0.7; margin-right: 0.5rem; padding: 0.1rem 0.5rem; border: 1px solid rgba(127, 127, 127, 0.35); border-radius: 999px; }

  .fs-ed-body { padding: 0; flex: 1 1 auto; display: flex; flex-direction: column; min-height: 0; overflow: hidden; position: relative; }
  .fs-ed-host { flex: 1 1 auto; min-height: 0; }
  .fs-ed-msg { padding: 1.5rem; opacity: 0.8; }
  .CodeMirror { height: 100%; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; line-height: 1.5; }

  // find / replace bar, sits above the code
  .fs-find { flex: 0 0 auto; padding: 0.5rem 0.75rem; display: flex; flex-direction: column; gap: 0.4rem; background: var(--fs-surface); border-bottom: 1px solid var(--fs-border); }
  .fs-find-row { display: flex; align-items: center; gap: 0.35rem; }
  .fs-find-input {
    flex: 1 1 auto; min-width: 0; height: 2rem; padding: 0 0.65rem; font: inherit; font-size: 0.9rem;
    color: var(--fs-text); background: var(--fs-surface-2); border: 1px solid var(--fs-border); border-radius: 8px; outline: none;
    &::placeholder { color: var(--fs-muted); }
    &:focus { border-color: var(--fs-accent); box-shadow: 0 0 0 3px var(--fs-accent-soft); }
    &.is-danger { border-color: #e0483e; }
  }
  .fs-find-btn {
    display: inline-flex; align-items: center; justify-content: center; flex: 0 0 auto;
    min-width: 2rem; height: 2rem; padding: 0 0.4rem; font: inherit; font-size: 0.8rem; font-weight: 600; line-height: 1;
    color: var(--fs-muted); background: transparent; border: 1px solid transparent; border-radius: 8px; cursor: pointer;
    &:hover { background: var(--fs-accent-soft); color: var(--fs-accent); }
    &.is-on { color: var(--fs-accent); border-color: var(--fs-accent); background: var(--fs-accent-soft); }
  }
  .fs-find-count { flex: 0 0 auto; min-width: 5rem; padding: 0 0.3rem; text-align: right; font-size: 0.78rem; color: var(--fs-muted); white-space: nowrap; &.is-danger { color: #e0483e; } }

  .fs-ed-foot { padding: 0.5rem 1rem; gap: 0.5rem; align-items: center; justify-content: flex-start; }
  .fs-ed-status { font-size: 0.78rem; opacity: 0.7; margin-right: 0.6rem; }
  .fs-ed-spacer { flex: 1 1 auto; }
}

// full screen: the whole window, no margins, no rounded corners
.modal.fs-editor-modal.is-editor-full {
  .animation-content { width: 100vw; max-width: none !important; }
  .modal-card.fs-editor { height: 100vh; border-radius: 0; box-shadow: none; }
}

@media (max-width: 768px) {
  .modal-card.fs-editor { width: 100vw; height: 100vh; border-radius: 0; }
}
</style>

<template>
  <form class="modal-card fs-url-upload" @submit.prevent="submit">
    <header class="modal-card-head">
      <p class="modal-card-title">
        {{ lang('Upload from URL') }}
      </p>
    </header>
    <section class="modal-card-body">
      <b-field :label="lang('File address')">
        <b-input
          ref="url"
          v-model="url"
          type="url"
          placeholder="https://example.com/file.zip"
          required
          :disabled="busy"
          @input="error = ''"
        />
      </b-field>
      <b-field :label="lang('Save as (optional)')">
        <b-input v-model="name" maxlength="200" :placeholder="lang('Taken from the address if left empty')" :disabled="busy" />
      </b-field>
      <p class="help">
        {{ lang('The server downloads the file and saves it in the current folder. Public web addresses only.') }}
        <template v-if="maxSize"> {{ lang('Up to') }} {{ formatBytes(maxSize, 0) }}.</template>
      </p>

      <div v-if="busy" class="fs-url-progress">
        <!-- no value attribute = Bulma's moving "indeterminate" bar (size unknown, or saving to the server) -->
        <progress
          class="progress is-primary is-medium"
          max="100"
          :value="phase === 'downloading' && total > 0 ? percent : undefined"
        >
          {{ percent }}%
        </progress>
        <p class="fs-url-line">
          <template v-if="cancelling">
            {{ lang('Cancelling...') }}
          </template>
          <template v-else-if="phase === 'saving'">
            {{ lang('Saving to the server...') }}
          </template>
          <template v-else-if="phase === 'downloading' && bytes > 0">
            <strong v-if="total > 0">{{ percent }}%</strong> {{ formatBytes(bytes) }}<template v-if="total > 0"> {{ lang('of') }} {{ formatBytes(total) }}</template>
            <template v-if="speed > 0"> &middot; {{ formatBytes(speed) }}/s</template>
            <template v-if="eta > 0"> &middot; {{ formatEta(eta) }}</template>
          </template>
          <template v-else>
            {{ lang('Connecting...') }}
          </template>
        </p>
      </div>

      <p v-if="error" class="help is-danger">
        {{ error }}
      </p>
    </section>
    <footer class="modal-card-foot">
      <button v-if="!busy" class="button" type="button" @click="$parent.close()">
        {{ lang('Cancel') }}
      </button>
      <button v-else class="button" type="button" :disabled="cancelling || phase === 'saving'" @click="cancel">
        {{ lang('Cancel download') }}
      </button>
      <button class="button is-primary" :class="{ 'is-loading': busy }" :disabled="busy || !url">
        {{ lang('Download') }}
      </button>
    </footer>
  </form>
</template>

<script>
import api from '../../api/api'

const POLL_MS = 700

export default {
  name: 'UrlUpload',
  data() {
    return {
      url: '',
      name: '',
      busy: false,
      cancelling: false,
      error: '',
      phase: 'connecting', // connecting | downloading | saving
      bytes: 0,
      total: 0,
      speed: 0,
      eta: 0,
    }
  },
  computed: {
    maxSize() {
      return this.$store.state.config.url_upload_max_size || 0
    },
    percent() {
      return this.total > 0 ? Math.min(100, Math.round((this.bytes / this.total) * 100)) : 0
    },
  },
  mounted() {
    this.$nextTick(() => this.$refs.url && this.$refs.url.focus())
  },
  beforeDestroy() {
    this.stopPolling()
  },
  methods: {
    newId() {
      const bytes = new Uint8Array(12)
      window.crypto.getRandomValues(bytes)
      return Array.from(bytes, b => ('0' + b.toString(16)).slice(-2)).join('')
    },
    formatEta(seconds) {
      if (seconds >= 3600) return Math.round(seconds / 3600) + ' h left'
      if (seconds >= 60) return Math.round(seconds / 60) + ' min left'
      return Math.max(1, Math.round(seconds)) + ' s left'
    },
    submit() {
      if (this.busy || !this.url) return
      this.busy = true
      this.cancelling = false
      this.error = ''
      this.phase = 'connecting'
      this.bytes = this.total = this.speed = this.eta = 0
      this.finished = false
      this.lostSince = 0
      this.sample = null
      this.jobId = this.newId()

      this.timer = window.setInterval(() => this.poll(), POLL_MS)

      api.fetchUrl({
        id: this.jobId,
        url: this.url.trim(),
        name: this.name.trim(),
        dir: this.$store.state.cwd.location,
      })
        .then(result => {
          if (result.data === 'Done') this.succeed()
          else this.fail(this.lang('Unable to save file'))
        })
        .catch(error => {
          const data = error.response && error.response.data && error.response.data.data
          if (typeof data === 'string') {
            this.fail(this.lang(data))
          } else if (error.response && error.response.status !== 504 && error.response.status !== 502) {
            this.fail(this.lang('Unknown error'))
          }
          // no answer or a proxy timeout (502/504): the server keeps going, so let the progress poll report the outcome
          this.lostSince = this.lostSince || Date.now()
        })
    },
    poll() {
      // one poll at a time: with a single PHP worker they would queue behind the download and all fire at once afterwards
      if (this.finished || this.polling) return
      this.polling = true
      api.urlProgress({ id: this.jobId })
        .then(res => {
          this.polling = false
          const s = res.data || {}
          if (this.finished) return
          if (s.state === 'downloading') {
            this.phase = 'downloading'
            this.updateSpeed(s.bytes || 0)
            this.bytes = s.bytes || 0
            this.total = s.total || 0
          } else if (s.state === 'saving') {
            this.phase = 'saving'
            this.bytes = this.total = s.bytes || 0
          } else if (s.state === 'done') {
            this.succeed()
          } else if (s.state === 'error' || s.state === 'cancelled') {
            this.fail(this.lang(s.message || 'Download failed'))
          } else if (this.lostSince && Date.now() - this.lostSince > 20000) {
            // the request is gone and the server knows nothing about the job
            this.fail(this.lang('Download failed'))
          }
        })
        .catch(() => {
          // a missed poll is harmless: the next one catches up
          this.polling = false
        })
    },
    updateSpeed(bytes) {
      const now = Date.now()
      if (this.sample && now > this.sample.t) {
        const instant = ((bytes - this.sample.b) / (now - this.sample.t)) * 1000
        this.speed = this.speed > 0 ? this.speed * 0.6 + instant * 0.4 : instant // smoothed so the number does not jump
        this.eta = this.total > bytes && this.speed > 0 ? (this.total - bytes) / this.speed : 0
      }
      this.sample = { t: now, b: bytes }
    },
    cancel() {
      if (!this.busy || this.cancelling) return
      this.cancelling = true
      api.urlCancel({ id: this.jobId }).catch(() => { this.cancelling = false })
    },
    stopPolling() {
      if (this.timer) window.clearInterval(this.timer)
      this.timer = null
    },
    succeed() {
      if (this.finished) return
      this.finished = true
      this.stopPolling()
      this.busy = false
      this.$toast.open({ message: this.lang('Downloaded'), type: 'is-success' })
      this.$emit('done')
      this.$parent.close()
    },
    fail(message) {
      if (this.finished) return
      this.finished = true
      this.stopPolling()
      this.busy = false
      this.cancelling = false
      this.error = message
    },
  },
}
</script>

<style scoped>
.modal-card.fs-url-upload {
  width: min(540px, 94vw);
}
.fs-url-progress {
  margin-top: 1rem;
}
.fs-url-line {
  margin-top: 0.4rem;
  font-size: 0.85rem;
  opacity: 0.8;
}
</style>

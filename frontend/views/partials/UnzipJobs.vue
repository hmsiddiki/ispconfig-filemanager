<template>
  <div v-if="jobs.length" class="fs-unzip-jobs">
    <div v-for="job in jobs" :key="job.id" class="fs-unzip box" :class="'is-' + job.state">
      <div class="fs-unzip-head">
        <b class="fs-unzip-title">{{ lang('Unzip') }}: {{ job.name }}</b>
        <button v-if="isActive(job)" type="button" class="button is-small is-light" :disabled="cancelling[job.id]" @click="cancel(job)">
          {{ cancelling[job.id] ? lang('Cancelling...') : lang('Cancel') }}
        </button>
        <button v-else type="button" class="delete" :aria-label="lang('Close')" @click="dismiss(job)" />
      </div>

      <!-- no value attribute = Bulma's moving "indeterminate" bar (queued, or the size is not known yet) -->
      <progress
        v-if="isActive(job)"
        class="progress is-primary"
        max="100"
        :value="percent(job) || undefined"
      >
        {{ percent(job) }}%
      </progress>

      <p class="fs-unzip-line" :class="{ 'has-text-danger': job.state === 'error' }">
        <template v-if="job.state === 'queued'">
          {{ lang('Starting...') }}
        </template>
        <template v-else-if="job.state === 'copying' && job.phase === 'unpacking'">
          {{ lang('Unpacking the archive') }}...
        </template>
        <template v-else-if="job.state === 'copying'">
          {{ lang('Reading the archive') }}: {{ formatBytes(job.bytes || 0, 0) }}<template v-if="job.total"> / {{ formatBytes(job.total, 0) }}</template>
        </template>
        <template v-else-if="job.state === 'extracting'">
          {{ lang('Extracting') }}: {{ (job.done || 0).toLocaleString() }} / {{ (job.total || 0).toLocaleString() }} {{ lang('files') }}
          <span v-if="job.failed"> ({{ job.failed }} {{ lang('failed') }})</span>
        </template>
        <template v-else-if="job.state === 'done'">
          {{ lang('Unzipped') }}: {{ (job.files || 0).toLocaleString() }} {{ lang('files') }}<span v-if="job.failed"> ({{ job.failed }} {{ lang('failed') }})</span>
        </template>
        <template v-else-if="job.state === 'cancelled'">
          {{ lang('Unzip cancelled') }}. {{ lang('Files extracted so far were kept.') }}
        </template>
        <template v-else>
          {{ lang(job.message || 'Unknown error') }}
        </template>
      </p>
      <p v-if="job.state === 'extracting' && job.current" class="fs-unzip-current">
        {{ job.current }}
      </p>
      <p v-if="job.state === 'done' && job.errors && job.errors.length" class="fs-unzip-current">
        {{ job.errors.join(', ') }}
      </p>
    </div>
  </div>
</template>

<script>
import api from '../../api/api'

const POLL_MS = 1500
const AUTO_CLOSE_MS = 10000
const MISSING_LIMIT = 4

/**
 * Shows the user's unzip jobs and keeps polling them while any is running. The job itself runs on the server in a process of its own, so this
 * component can be created at any time (also after a page reload): it asks the server for the jobs and picks up where they are.
 */
export default {
  name: 'UnzipJobs',
  data() {
    return {
      jobs: [],
      cancelling: {},
      watching: {}, // ids this page has seen running: only those cause a "done" message and a file list refresh
      missing: {}, // how many answers in a row did not list a job that is being followed
      toDismiss: [],
      timer: null,
      loading: false,
      closeTimers: {},
    }
  },
  mounted() {
    this.refresh()
  },
  beforeDestroy() {
    this.stop()
    Object.values(this.closeTimers).forEach(t => window.clearTimeout(t))
  },
  methods: {
    isActive(job) {
      return ['queued', 'copying', 'extracting'].indexOf(job.state) !== -1
    },
    percent(job) {
      if (job.state === 'copying' && job.total > 0) return Math.min(99, Math.floor(((job.bytes || 0) / job.total) * 100))
      if (job.state === 'extracting' && job.total > 0) return Math.min(99, Math.floor(((job.done || 0) / job.total) * 100))
      return 0
    },
    /** Ask the server now (also called by the file list right after it starts a job). */
    refresh() {
      if (this.loading) return
      this.loading = true
      const dismiss = this.toDismiss
      this.toDismiss = []
      api.unzipStatus({ dismiss })
        .then(res => {
          this.loading = false
          this.apply(Array.isArray(res.data) ? res.data : [])
        })
        .catch(() => {
          // a missed poll is harmless: the next one catches up
          this.loading = false
          this.toDismiss = this.toDismiss.concat(dismiss)
        })
    },
    apply(list) {
      let active = false
      // a followed job that is not in the answer is most likely a missed read, not a finished job: keep it on screen and ask again
      const carried = {}
      this.jobs.forEach(job => {
        if (this.watching[job.id] && this.isActive(job) && !list.some(j => j.id === job.id)) {
          const n = (this.missing[job.id] || 0) + 1
          this.$set(this.missing, job.id, n)
          if (n < MISSING_LIMIT) {
            carried[job.id] = true
            list = list.concat([job])
          } else {
            // gone for good (finished and cleared elsewhere): stop following it and show what it left behind
            this.$delete(this.watching, job.id)
            this.$delete(this.missing, job.id)
            this.$emit('finished', job)
          }
        }
      })
      list.forEach(job => {
        if (!carried[job.id]) this.$delete(this.missing, job.id)
        if (this.isActive(job)) {
          active = true
          this.$set(this.watching, job.id, true)
        } else if (this.watching[job.id]) {
          this.$delete(this.watching, job.id)
          this.finished(job)
        }
      })
      this.jobs = list.filter(job => !this.hidden(job))
      if (active) this.start()
      else this.stop()
    },
    /** A job that was already finished when this page loaded stays out of the way: it is shown only if it ended with a problem. */
    hidden(job) {
      return this.toDismiss.indexOf(job.id) !== -1 || (!this.isActive(job) && !this.closeTimers[job.id] && job.state === 'done' && !this.seen(job.id))
    },
    seen(id) {
      return Object.prototype.hasOwnProperty.call(this.closeTimers, id)
    },
    finished(job) {
      if (job.state === 'done') {
        this.$toast.open({ message: this.lang('Unzipped'), type: 'is-success' })
        this.closeTimers[job.id] = window.setTimeout(() => this.dismiss(job), AUTO_CLOSE_MS)
      } else {
        this.closeTimers[job.id] = 0
      }
      this.$emit('finished', job)
    },
    start() {
      if (!this.timer) this.timer = window.setInterval(() => this.refresh(), POLL_MS)
    },
    stop() {
      if (this.timer) window.clearInterval(this.timer)
      this.timer = null
    },
    cancel(job) {
      this.$set(this.cancelling, job.id, true)
      api.unzipCancel({ id: job.id }).catch(() => this.$set(this.cancelling, job.id, false))
    },
    dismiss(job) {
      if (this.closeTimers[job.id]) window.clearTimeout(this.closeTimers[job.id])
      this.toDismiss.push(job.id)
      this.jobs = this.jobs.filter(j => j.id !== job.id)
      this.refresh()
    },
  },
}
</script>

<style scoped>
.fs-unzip-jobs {
  position: fixed;
  right: 1rem;
  bottom: 1rem;
  z-index: 40;
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  width: min(380px, calc(100vw - 2rem));
}
.fs-unzip.box {
  margin: 0;
  padding: 0.8rem 1rem;
}
.fs-unzip-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.6rem;
  margin-bottom: 0.5rem;
}
.fs-unzip-title {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.fs-unzip .progress {
  margin-bottom: 0.4rem;
}
.fs-unzip-line {
  font-size: 0.85rem;
}
.fs-unzip-current {
  font-size: 0.75rem;
  opacity: 0.65;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>

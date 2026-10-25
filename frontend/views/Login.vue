<template>
  <div v-if="!$store.state.config.guest_redirection">
    <a v-if="can('read')" id="back-arrow" @click="$router.push('/').catch(() => {})">
      <b-icon icon="times" />
    </a>

    <div id="login" class="columns is-centered">
      <div class="column is-narrow">
        <form @submit.prevent="login">
          <div class="box">
            <div class="has-text-centered login-brand">
              <img :src="$store.state.config.logo" class="logo">
              <h1>{{ $store.state.config.app_name }}</h1>
              <p v-if="!sso">{{ lang('Login') }}</p>
            </div>

            <!-- panel mode: the panel signs people in, so there is nothing to type here -->
            <p v-if="sso" class="fs-sso-msg">
              {{ lang('This session has ended, or the page was opened directly. Open the file manager again from the control panel.') }}
            </p>

            <template v-if="!sso && remote.enabled">
              <b-field :label="lang('Protocol')">
                <b-select v-model="protocol" expanded @input="onProtocol">
                  <option v-for="p in remote.protocols" :key="p" :value="p">{{ protocolLabels[p] || p }}</option>
                </b-select>
              </b-field>
              <div v-if="!remote.lock_host" class="fs-host-row">
                <b-field :label="lang('Host')" class="fs-host">
                  <b-input v-model="host" name="host" required @input="error = ''" />
                </b-field>
                <b-field :label="lang('Port')" class="fs-port">
                  <b-input v-model="port" name="port" type="number" min="1" max="65535" />
                </b-field>
              </div>
            </template>
            <template v-if="!sso">
              <b-field :label="lang('Username')">
                <b-input v-model="username" name="username" required @input="error = ''" ref="username" />
              </b-field>
              <b-field :label="lang('Password')">
                <b-input v-model="password" type="password" name="password" required @input="error = ''" password-reveal />
              </b-field>

              <div class="is-flex is-justify-end login-actions">
                <button class="button is-primary is-fullwidth">
                  {{ lang('Login') }}
                </button>
              </div>

              <div v-if="error">
                <code>{{ error }}</code>
              </div>
            </template>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script>
import api from '../api/api'

export default {
  name: 'Login',
  data() {
    const remote = Object.assign(
      { enabled: false, protocols: ['sftp'], host: '', lock_host: false },
      this.$store.state.config.remote_login || {}
    )
    const saved = this.readSaved()
    return {
      sso: !!this.$store.state.config.sso_only,
      remote,
      protocolLabels: { sftp: 'SFTP (SSH)', ftp: 'FTP', ftps: 'FTPS (FTP over TLS)' },
      protocol: remote.protocols.includes(saved.protocol) ? saved.protocol : remote.protocols[0],
      host: saved.host || remote.host,
      port: saved.port || '',
      username: saved.username || '',
      password: '',
      error: '',
    }
  },
  mounted() {
    if (this.$store.state.config.guest_redirection) {
      window.location.href = this.$store.state.config.guest_redirection
      return
    }
    if (this.$refs.username) this.$refs.username.focus()
  },
  methods: {
    readSaved() {
      try {
        return JSON.parse(window.localStorage.getItem('fg-last-connection')) || {}
      } catch (e) {
        return {}
      }
    },
    saveLast() {
      // never the password
      try {
        window.localStorage.setItem('fg-last-connection', JSON.stringify({
          protocol: this.protocol, host: this.host, port: this.port, username: this.username,
        }))
      } catch (e) {
        /* storage unavailable: nothing to remember */
      }
    },
    onProtocol() {
      this.port = ''
    },
    login() {
      api.login({
        username: this.username,
        password: this.password,
        protocol: this.remote.enabled ? this.protocol : undefined,
        host: this.remote.enabled ? this.host : undefined,
        port: this.remote.enabled ? this.port : undefined,
      })
        .then(user => {
          if (this.remote.enabled) this.saveLast()
          this.$store.commit('setUser', user)
          api.changeDir({
            to: '/'
          }).then(() => this.$router.push('/').catch(() => {}))
        })
        .catch(error => {
          if (error.response && error.response.data) {
            this.error = this.lang(error.response.data.data)
          } else {
            this.handleError(error)
          }
          this.password = ''
        })
    },
  }
}
</script>

<style scoped>
.logo {
  width: 300px;
  display: inline-block;
}

.box {
  padding: 30px;
}

#login {
  padding: 120px 20px;
}
#back-arrow {
  position: fixed;
  top: 0;
  right: 0;
  margin: 20px;
}
</style>

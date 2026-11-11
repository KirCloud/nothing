/* Editable admin settings component; no admin vendor rebuild is required. */
(() => {
  'use strict';
  class GoogleOAuthSettings extends HTMLElement {
    connectedCallback() {
      if (this.initialized) return;
      this.initialized = true;
      this.className = 'google-oauth-settings';
      this.innerHTML = `
        <form class="google-oauth-form" autocomplete="off">
          <div class="google-oauth-heading">
            <div><h2>Google 登录</h2><p>允许用户通过 Google 账号登录，使用本站现有的注册规则。</p></div>
            <label class="google-oauth-toggle"><input name="google_oauth_enable" type="checkbox"><span>启用 Google 登录</span></label>
          </div>
          <div class="google-oauth-instructions">
            <strong>连接 Google OAuth</strong>
            <p>在 <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer">Google Cloud Console</a> 创建 Web 应用 OAuth 客户端，将下方回调地址登记为“已获授权的重定向 URI”，再填写客户端凭据。</p>
          </div>
          <div class="google-oauth-grid">
            <label class="google-oauth-field">Client ID
              <input class="form-control" name="google_client_id" type="text" maxlength="512" placeholder="…apps.googleusercontent.com" spellcheck="false" autocomplete="off">
            </label>
            <label class="google-oauth-field">Client Secret
              <input class="form-control" name="google_client_secret" type="password" maxlength="2048" placeholder="填写 Google 客户端密钥" autocomplete="new-password">
              <small class="google-oauth-secret-status">保存后不回显密钥；之后留空可保留原值。</small>
            </label>
            <label class="google-oauth-field google-oauth-wide">Google 回调地址
              <div class="google-oauth-copy-row"><input class="form-control" name="google_redirect_uri" type="url" maxlength="2048" spellcheck="false" required><button class="btn btn-alt-secondary" type="button" data-copy>复制</button></div>
              <small>与 Google 控制台登记的重定向 URI 完全一致。</small>
            </label>
            <label class="google-oauth-field google-oauth-wide">用户前端地址
              <input class="form-control" name="oauth_frontend_url" type="url" maxlength="2048" spellcheck="false" required>
              <small>例如 https://kirssr.com，可带子目录；不包含 # 或查询参数。登录成功后返回此站点。</small>
            </label>
          </div>
          <div class="google-oauth-footer"><button class="btn btn-primary" type="submit">保存配置</button><p class="google-oauth-status" role="status" aria-live="polite"></p><button class="btn btn-alt-secondary" type="button" data-retry hidden>重新加载</button></div>
        </form>`;
      this.form = this.querySelector('form');
      this.status = this.querySelector('[role="status"]');
      this.form.addEventListener('submit', event => this.save(event));
      this.querySelector('[data-copy]').addEventListener('click', () => this.copyCallback());
      this.querySelector('[data-retry]').addEventListener('click', () => this.load());
      this.load();
    }

    disconnectedCallback() {
      this.controller?.abort();
      this.initialized = false;
    }

    field(name) { return this.form.elements.namedItem(name); }

    setBusy(busy) {
      for (const control of this.form.elements) control.disabled = busy;
      this.form.setAttribute('aria-busy', String(busy));
    }

    message(text, error = false) {
      this.status.textContent = text;
      this.status.dataset.error = String(error);
    }

    async api(path, data) {
      const token = localStorage.getItem('authorization');
      if (!token) throw new Error('登录已过期，请重新登录管理后台。');
      const base = (window.settings.host || '').replace(/\/$/, '');
      const securePath = encodeURIComponent(window.settings.secure_path);
      const response = await fetch(`${base}/api/v1/${securePath}/config/${path}`, {
        method: data ? 'POST' : 'GET',
        headers: { Accept: 'application/json', Authorization: token, ...(data ? { 'Content-Type': 'application/json' } : {}) },
        ...(data ? { body: JSON.stringify(data) } : {}),
        signal: this.controller.signal,
        cache: 'no-store'
      });
      const result = await response.json().catch(() => ({}));
      if (!response.ok) {
        const detail = result.errors && Object.values(result.errors).flat()[0];
        throw new Error(response.status === 403 ? '登录已过期或没有管理员权限，请重新登录。' : detail || result.message || '请求失败，请稍后重试。');
      }
      return result.data;
    }

    updateSecretStatus() {
      this.field('google_client_secret').value = '';
      this.field('google_client_secret').placeholder = this.secretConfigured ? '已配置；留空保留，填写新值替换' : '填写 Google 客户端密钥';
      this.querySelector('.google-oauth-secret-status').textContent = this.secretConfigured ? '密钥已配置。保存后不回显，留空将保留原值。' : '尚未配置密钥。保存后不回显，之后留空可保留原值。';
    }

    async load() {
      this.controller?.abort();
      this.controller = new AbortController();
      this.setBusy(true);
      this.message('正在读取配置…');
      this.querySelector('[data-retry]').hidden = true;
      try {
        const data = (await this.api('fetch?key=google')).google;
        this.field('google_oauth_enable').checked = Boolean(Number(data.google_oauth_enable));
        for (const name of ['google_client_id', 'google_redirect_uri', 'oauth_frontend_url']) this.field(name).value = data[name] || '';
        this.secretConfigured = Boolean(data.google_client_secret_configured);
        this.updateSecretStatus();
        this.message('');
        this.setBusy(false);
      } catch (error) {
        if (error.name === 'AbortError') return;
        this.message(error.message, true);
        const retry = this.querySelector('[data-retry]'); retry.hidden = false; retry.disabled = false;
      }
    }

    async save(event) {
      event.preventDefault();
      const data = { google_oauth_enable: this.field('google_oauth_enable').checked ? 1 : 0 };
      for (const name of ['google_client_id', 'google_redirect_uri', 'oauth_frontend_url']) data[name] = this.field(name).value.trim();
      const secret = this.field('google_client_secret').value.trim();
      if (secret) data.google_client_secret = secret;
      this.setBusy(true);
      this.message('正在保存…');
      try {
        await this.api('save', data);
        this.secretConfigured = this.secretConfigured || Boolean(secret);
        this.updateSecretStatus();
        this.message('配置已保存并生效。');
      } catch (error) {
        if (error.name !== 'AbortError') this.message(error.message, true);
      } finally {
        if (this.isConnected) this.setBusy(false);
      }
    }

    async copyCallback() {
      const input = this.field('google_redirect_uri');
      try {
        await navigator.clipboard.writeText(input.value);
        this.message('回调地址已复制。');
      } catch {
        input.focus(); input.select();
        this.message('请复制已选中的回调地址。');
      }
    }
  }
  if (!customElements.get('google-oauth-settings')) customElements.define('google-oauth-settings', GoogleOAuthSettings);
})();

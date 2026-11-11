/* Editable admin settings component; no admin vendor rebuild is required. */
(() => {
  'use strict';
  const TEXT_FIELDS = ['google_client_id', 'google_redirect_uri', 'oauth_frontend_url', 'oauth_app_scheme'];
  // Mirrors the bundled config page: a title and description on the left, the control on the right.
  const row = (title, description, control) => `
    <div class="row google-oauth-row">
      <div class="col-lg-6"><div class="google-oauth-title">${title}</div><div class="google-oauth-description">${description}</div></div>
      <div class="col-lg-6 text-right">${control}</div>
    </div>`;

  class GoogleOAuthSettings extends HTMLElement {
    connectedCallback() {
      if (this.initialized) return;
      this.initialized = true;
      this.className = 'google-oauth-settings';
      this.innerHTML = `
        <form class="google-oauth-form" autocomplete="off" novalidate>
          ${row('启用 Google 登录', '允许用户通过 Google 账号登录，沿用本站现有的注册规则。启用前需填写 Client ID 与 Client Secret。',
            '<button type="button" role="switch" aria-checked="false" aria-label="启用 Google 登录" class="ant-switch" data-switch><span class="ant-switch-inner"></span></button>')}
          ${row('Client ID', '在 <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer">Google Cloud Console</a> 创建 Web 应用 OAuth 客户端后获得。',
            '<input class="form-control" name="google_client_id" type="text" maxlength="512" placeholder="…apps.googleusercontent.com" spellcheck="false" autocomplete="off" aria-label="Client ID">')}
          ${row('Client Secret', '<span data-secret-status>保存后不回显密钥，留空可保留原值。</span>',
            '<input class="form-control" name="google_client_secret" type="password" maxlength="2048" placeholder="请输入 Google 客户端密钥" autocomplete="new-password" aria-label="Client Secret">')}
          ${row('Google 回调地址', '需与 Google 控制台中登记的“已获授权的重定向 URI”完全一致。',
            '<div class="input-group"><input class="form-control" name="google_redirect_uri" type="url" maxlength="2048" placeholder="https://api.example.com/api/v1/passport/oauth/google/callback" spellcheck="false" aria-label="Google 回调地址"><div class="input-group-append"><button class="btn btn-alt-primary" type="button" data-copy>复制</button></div></div>')}
          ${row('用户前端地址', '网页登录成功后返回的站点，可带子目录，不包含 # 或查询参数。',
            '<input class="form-control" name="oauth_frontend_url" type="url" maxlength="2048" placeholder="https://panel.example.com" spellcheck="false" aria-label="用户前端地址">')}
          ${row('App URL Scheme', '客户端 App 的 URL scheme（OAUTH_APP_SCHEME）。填写后，App 内发起的 Google 与邮箱链接登录会回到 <code data-scheme-preview>scheme://oauth-callback</code>；留空则沿用 .env 中的值，两处都未设置时不允许 App 回跳。',
            '<input class="form-control" name="oauth_app_scheme" type="text" maxlength="32" placeholder="kirssr" spellcheck="false" autocapitalize="off" autocomplete="off" aria-label="App URL Scheme">')}
          <div class="google-oauth-status-row"><span class="google-oauth-status" role="status" aria-live="polite"></span><button class="btn btn-sm btn-alt-secondary" type="button" data-retry hidden>重新加载</button></div>
        </form>`;
      this.form = this.querySelector('form');
      this.status = this.querySelector('[role="status"]');
      this.toggle = this.querySelector('[data-switch]');
      this.form.addEventListener('submit', event => { event.preventDefault(); this.save(); });
      this.form.addEventListener('input', () => { this.updateSchemePreview(); this.scheduleSave(); });
      this.toggle.addEventListener('click', () => { this.setEnabled(!this.enabled); this.scheduleSave(0); });
      this.querySelector('[data-copy]').addEventListener('click', () => this.copyCallback());
      this.querySelector('[data-retry]').addEventListener('click', () => this.load());
      this.load();
    }

    disconnectedCallback() {
      clearTimeout(this.saveTimer);
      this.controller?.abort();
      this.initialized = false;
    }

    field(name) { return this.form.elements.namedItem(name); }

    setEnabled(enabled) {
      this.enabled = enabled;
      this.toggle.classList.toggle('ant-switch-checked', enabled);
      this.toggle.setAttribute('aria-checked', String(enabled));
    }

    setLocked(locked) {
      for (const control of this.form.elements) if (!control.hasAttribute('data-retry')) control.disabled = locked;
      this.toggle.classList.toggle('ant-switch-disabled', locked);
    }

    message(text, error = false) {
      this.status.textContent = text;
      this.status.dataset.error = String(error);
    }

    updateSchemePreview() {
      const scheme = this.field('oauth_app_scheme').value.trim() || 'scheme';
      this.querySelector('[data-scheme-preview]').textContent = `${scheme}://oauth-callback`;
    }

    updateSecretStatus() {
      this.field('google_client_secret').placeholder = this.secretConfigured ? '已配置；留空保留，填写新值替换' : '请输入 Google 客户端密钥';
      this.querySelector('[data-secret-status]').textContent = this.secretConfigured
        ? '密钥已配置。保存后不回显，留空将保留原值。' : '尚未配置密钥。保存后不回显，之后留空可保留原值。';
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

    async load() {
      this.controller?.abort();
      this.controller = new AbortController();
      this.setLocked(true);
      this.message('正在读取配置…');
      this.querySelector('[data-retry]').hidden = true;
      try {
        const data = (await this.api('fetch?key=google')).google;
        this.setEnabled(Boolean(Number(data.google_oauth_enable)));
        for (const name of TEXT_FIELDS) this.field(name).value = data[name] || '';
        this.field('google_client_secret').value = '';
        this.secretConfigured = Boolean(data.google_client_secret_configured);
        this.updateSecretStatus();
        this.updateSchemePreview();
        this.message('');
        this.setLocked(false);
      } catch (error) {
        if (error.name === 'AbortError') return;
        this.message(error.message, true);
        this.querySelector('[data-retry]').hidden = false;
      }
    }

    // The bundled config page saves 1.5s after the last edit; this tab does the same.
    scheduleSave(delay = 1500) {
      clearTimeout(this.saveTimer);
      this.saveTimer = setTimeout(() => this.save(), delay);
    }

    async save() {
      clearTimeout(this.saveTimer);
      if (this.saving) { this.saveAgain = true; return; }
      const data = { google_oauth_enable: this.enabled ? 1 : 0 };
      for (const name of TEXT_FIELDS) data[name] = this.field(name).value.trim();
      const secretField = this.field('google_client_secret');
      const secret = secretField.value.trim();
      if (secret) data.google_client_secret = secret;
      this.saving = true;
      this.message('正在保存…');
      try {
        await this.api('save', data);
        if (secret) {
          this.secretConfigured = true;
          if (secretField.value.trim() === secret) secretField.value = '';
          this.updateSecretStatus();
        }
        this.message('配置已保存并生效。');
      } catch (error) {
        if (error.name !== 'AbortError') this.message(error.message, true);
      } finally {
        this.saving = false;
        if (this.saveAgain && this.isConnected) { this.saveAgain = false; this.save(); }
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

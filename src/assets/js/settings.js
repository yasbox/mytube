// 設定ページ用のJavaScript
document.addEventListener('DOMContentLoaded', function() {
    const publicModeToggle = document.getElementById('public-mode-toggle');
    const userPasswordBlock = document.getElementById('user-password-block');
    const saveSettingsBtn = document.getElementById('save-settings-btn');
    const likesUniqueToggle = document.getElementById('likes-unique-toggle');
    const viewsUniqueToggle = document.getElementById('views-unique-toggle');
    const autoplayToggle = document.getElementById('autoplay-toggle');
    const siteNameInput = document.getElementById('site-name-input');
    const saveSiteNameBtn = document.getElementById('save-site-name-btn');
    const themeSelect = document.getElementById('theme-select');
    const siteDescriptionInput = document.getElementById('site-description-input');
    const saveSiteDescriptionBtn = document.getElementById('save-site-description-btn');
    const brandLogoFile = document.getElementById('brand-logo-file');
    const uploadBrandLogoBtn = document.getElementById('upload-brand-logo-btn');
    const selectBrandLogoBtn = document.getElementById('select-brand-logo-btn');
    const resetBrandLogoBtn = document.getElementById('reset-brand-logo-btn');
    const brandLogoPreview = document.getElementById('brand-logo-preview');
    const brandLogoPreviewPlaceholder = document.getElementById('brand-logo-preview-placeholder');
    const adminCurrentPw = document.getElementById('admin-current-pw');
    const adminNewPw = document.getElementById('admin-new-pw');
    const changeAdminPwBtn = document.getElementById('change-admin-pw-btn');
    const toggleAdminPwVisibilityBtn = document.getElementById('toggle-admin-pw-visibility');
    

    // 初期化
    initializeSettings();

    // パスワード保護の即時反映
    if (publicModeToggle) {
        publicModeToggle.addEventListener('change', async function(e) {
            const originalState = e.target.checked;
            const isProtected = originalState ? '1' : '0';
            const success = await updateSettingsPartial({ password_protection: isProtected });
            
            // 失敗時は元の状態に戻す
            if (!success) {
                e.target.checked = !originalState;
                applyPasswordBlockState(!e.target.checked);
            } else {
                // 成功時はパスワードブロックの状態を更新
                applyPasswordBlockState(!e.target.checked);
            }
        });
        // 初期適用（ロード時の状態反映）
        applyPasswordBlockState(!publicModeToggle.checked);
    }

    // いいね設定の即時反映
    if (likesUniqueToggle) {
        likesUniqueToggle.addEventListener('change', async function(e) {
            const originalState = e.target.checked;
            const uniqueCountup = originalState ? '1' : '0';
            const success = await updateSettingsPartial({ 'features.likes.unique_countup': uniqueCountup });
            
            // 失敗時は元の状態に戻す
            if (!success) {
                e.target.checked = !originalState;
                // トグルボタンの見た目も元に戻す
                const toggleSlider = e.target.nextElementSibling;
                if (toggleSlider) {
                    if (!originalState) {
                        toggleSlider.classList.add('peer-checked:bg-blue-600');
                        toggleSlider.classList.remove('bg-gray-200');
                    } else {
                        toggleSlider.classList.remove('peer-checked:bg-blue-600');
                        toggleSlider.classList.add('bg-gray-200');
                    }
                }
            }
        });
    }

    // 自動再生設定の即時反映
    if (autoplayToggle) {
        autoplayToggle.addEventListener('change', async function(e) {
            const originalState = e.target.checked;
            const enabled = originalState ? '1' : '0';
            const success = await updateSettingsPartial({ 'features.autoplay': enabled });
            if (!success) {
                e.target.checked = !originalState;
                const toggleSlider = e.target.nextElementSibling;
                if (toggleSlider) {
                    if (!originalState) {
                        toggleSlider.classList.add('peer-checked:bg-blue-600');
                        toggleSlider.classList.remove('bg-gray-200');
                    } else {
                        toggleSlider.classList.remove('peer-checked:bg-blue-600');
                        toggleSlider.classList.add('bg-gray-200');
                    }
                }
            }
        });
    }

    // 再生数設定の即時反映
    if (viewsUniqueToggle) {
        viewsUniqueToggle.addEventListener('change', async function(e) {
            const originalState = e.target.checked;
            const uniqueCountup = originalState ? '1' : '0';
            const success = await updateSettingsPartial({ 'features.views.unique_countup': uniqueCountup });
            
            // 失敗時は元の状態に戻す
            if (!success) {
                e.target.checked = !originalState;
                // トグルボタンの見た目も元に戻す
                const toggleSlider = e.target.nextElementSibling;
                if (toggleSlider) {
                    if (!originalState) {
                        toggleSlider.classList.add('peer-checked:bg-blue-600');
                        toggleSlider.classList.remove('bg-gray-200');
                    } else {
                        toggleSlider.classList.remove('peer-checked:bg-blue-600');
                        toggleSlider.classList.add('bg-gray-200');
                    }
                }
            }
        });
    }

    // 設定保存ボタン
    if (saveSettingsBtn) {
        saveSettingsBtn.addEventListener('click', updateSiteSettings);
    }
    if (saveSiteNameBtn && siteNameInput) {
        saveSiteNameBtn.addEventListener('click', async function() {
            const name = siteNameInput.value.trim();
            // 空の場合はフォールバックにするため空文字を送る
            const ok = await updateSettingsPartial({ 'app.name': name });
            if (!ok) {
                // 失敗時は最新値を読み直す
                await loadSiteSettings();
            } else {
                // サイト名変更のみページ全体を更新して即時反映
                window.location.reload();
            }
        });
    }

    // 管理者パスワード表示/非表示
    if (toggleAdminPwVisibilityBtn) {
        toggleAdminPwVisibilityBtn.addEventListener('click', function() {
            [adminCurrentPw, adminNewPw].forEach(function(i){
                if (i) i.type = (i.type === 'password' ? 'text' : 'password');
            });
        });
    }

    // 管理者パスワード変更
    if (changeAdminPwBtn) {
        changeAdminPwBtn.addEventListener('click', async function() {
            if (!adminCurrentPw || !adminNewPw) return;
            const current = adminCurrentPw.value.trim();
            const next = adminNewPw.value.trim();
            if (!current || !next) { showError('現在のパスワードと新しいパスワードを入力してください'); return; }
            if (!isStrong(next)) { showError('パスワードは8文字以上で入力してください'); return; }

            const body = new URLSearchParams();
            body.append('action', 'change_admin_password');
            body.append('current_password', current);
            body.append('new_password', next);
            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
                const resp = await fetch('admin_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    credentials: 'same-origin',
                    body: body.toString() + `&csrf_token=${encodeURIComponent(csrf)}`
                });
                const data = await resp.json();
                if (data.success) {
                    showSuccess('管理者パスワードを変更しました。再ログインが必要な場合があります');
                    // 入力をクリア
                    adminCurrentPw.value = '';
                    adminNewPw.value = '';
                } else {
                    showError(data.message || '変更に失敗しました');
                }
            } catch (e) {
                showError('変更に失敗しました');
            }
        });
    }

    

    function isStrong(v) {
        return !!v && v.length >= 8;
    }

    if (saveSiteDescriptionBtn && siteDescriptionInput) {
        saveSiteDescriptionBtn.addEventListener('click', async function() {
            const description = siteDescriptionInput.value.trim();
            const ok = await updateSettingsPartial({ 'app.description': description });
            if (!ok) {
                await loadSiteSettings();
            } else {
                // メタに反映させるためリロード
                window.location.reload();
            }
        });
    }

    // デフォルトテーマの即時反映
    if (themeSelect) {
        themeSelect.addEventListener('change', async function(e) {
            const val = (e.target.value || '').toLowerCase();
            if (!['light', 'dark'].includes(val)) {
                showError('無効なテーマです');
                return;
            }
            // デフォルト値のみ更新（現在のページのテーマや localStorage は変更しない）
            await updateSettingsPartial({ 'ui.theme': val });
        });
    }

    // サイトロゴ: ファイル選択時プレビュー（正方形トリミング見た目）
    if (brandLogoFile) {
        brandLogoFile.addEventListener('change', function(e) {
            const file = e.target.files && e.target.files[0];
            if (!file) {
                if (brandLogoPreview) brandLogoPreview.style.display = 'none';
                if (brandLogoPreviewPlaceholder) brandLogoPreviewPlaceholder.style.display = '';
                return;
            }
            const allowed = ['image/png','image/jpeg','image/webp'];
            if (!allowed.includes(file.type)) {
                showError('PNG/JPEG/WebP の画像を選択してください');
                e.target.value = '';
                return;
            }
            const reader = new FileReader();
            reader.onload = function(ev) {
                const img = new Image();
                img.onload = function() {
                    const size = 256; // プレビュー用
                    const canvas = document.createElement('canvas');
                    canvas.width = size; canvas.height = size;
                    const ctx = canvas.getContext('2d');
                    const minSide = Math.min(img.width, img.height);
                    const sx = (img.width - minSide) / 2;
                    const sy = (img.height - minSide) / 2;
                    ctx.imageSmoothingQuality = 'high';
                    ctx.drawImage(img, sx, sy, minSide, minSide, 0, 0, size, size);
                    const dataUrl = canvas.toDataURL('image/png');
                    if (brandLogoPreview) {
                        brandLogoPreview.src = dataUrl;
                        brandLogoPreview.style.display = '';
                    }
                    if (brandLogoPreviewPlaceholder) brandLogoPreviewPlaceholder.style.display = 'none';
                };
                img.src = ev.target.result;
            };
            reader.readAsDataURL(file);
        });
    }

    // カスタム参照ボタン
    if (selectBrandLogoBtn && brandLogoFile) {
        selectBrandLogoBtn.addEventListener('click', function() {
            brandLogoFile.click();
        });
    }

    // サイトロゴ: アップロード（ffmpeg処理はサーバ側）
    if (uploadBrandLogoBtn) {
        uploadBrandLogoBtn.addEventListener('click', async function() {
            if (!brandLogoFile || !brandLogoFile.files || !brandLogoFile.files[0]) {
                showError('画像ファイルを選択してください');
                return;
            }
            const file = brandLogoFile.files[0];
            const form = new FormData();
            form.append('action', 'upload_brand_logo');
            form.append('brand_logo', file);
            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
                form.append('csrf_token', csrf);
                const resp = await fetch('admin_api.php', { method: 'POST', credentials: 'same-origin', body: form });
                const data = await resp.json();
                if (data.success) {
                    showSuccess('サイトロゴを更新しました');
                    // 画像キャッシュを確実に更新するためリロード
                    window.location.reload();
                } else {
                    showError(data.message || '更新に失敗しました');
                }
            } catch (e) {
                console.error(e);
                showError('更新に失敗しました');
            }
        });
    }

    // サイトロゴ: リセット（ユーザー生成画像の削除）
    if (resetBrandLogoBtn) {
        resetBrandLogoBtn.addEventListener('click', async function() {
            const ok = confirm('ユーザー設定のロゴ/ファビコン画像を削除してデフォルトに戻します。よろしいですか？');
            if (!ok) return;
            try {
                const body = new URLSearchParams();
                body.append('action', 'reset_brand_logo');
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
                const resp = await fetch('admin_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    credentials: 'same-origin',
                    body: body.toString() + `&csrf_token=${encodeURIComponent(csrf)}`
                });
                const data = await resp.json();
                if (data.success) {
                    showSuccess('デフォルトに戻しました');
                    window.location.reload();
                } else {
                    showError(data.message || 'リセットに失敗しました');
                }
            } catch (e) {
                console.error(e);
                showError('リセットに失敗しました');
            }
        });
    }

    // 設定の初期化
    function initializeSettings() {
        loadSiteSettings();
    }

    // 設定の読み込み
    async function loadSiteSettings() {
        try {
            const response = await fetch('admin_api.php?action=get_settings', { credentials: 'same-origin' });
            const data = await response.json();
            if (data.success) {
                const s = data.settings;
                
                // パスワード保護の設定を反映
                if (publicModeToggle) {
                    const isProtected = !!s.password_protection;
                    publicModeToggle.checked = isProtected;
                    applyPasswordBlockState(!isProtected);
                }
                
                // サーバの最新パスワードを可視入力欄に反映
                const userPw = document.getElementById('user-password-input');
                if (userPw && typeof s.user_password === 'string') {
                    userPw.value = s.user_password;
                }
                
                // サイト名の反映
                if (siteNameInput && s.app && typeof s.app.name === 'string') {
                    siteNameInput.value = s.app.name;
                }
                // サイト説明の反映
                if (siteDescriptionInput && s.app && typeof s.app.description === 'string') {
                    siteDescriptionInput.value = s.app.description;
                }

                // いいね設定の反映
                if (likesUniqueToggle && s.features && s.features.likes) {
                    likesUniqueToggle.checked = !!s.features.likes.unique_countup;
                }
                
                // 再生数設定の反映
                if (viewsUniqueToggle && s.features && s.features.views) {
                    viewsUniqueToggle.checked = !!s.features.views.unique_countup;
                }

                // 自動再生設定の反映
                if (autoplayToggle && s.features) {
                    autoplayToggle.checked = s.features.autoplay !== false;
                }

                // テーマの反映
                if (themeSelect && s.ui && typeof s.ui.theme === 'string') {
                    const t = ['light','dark'].includes((s.ui.theme||'').toLowerCase()) ? (s.ui.theme||'').toLowerCase() : 'light';
                    themeSelect.value = t;
                }
            }
        } catch (e) {
            console.error('設定の読み込みに失敗', e);
            showError('設定の読み込みに失敗しました');
        }
    }

    // パスワードブロックの状態を適用
    function applyPasswordBlockState(isPublicOn) {
        if (!userPasswordBlock) return;
        if (isPublicOn) {
            userPasswordBlock.classList.add('blocked-section');
        } else {
            userPasswordBlock.classList.remove('blocked-section');
        }
    }

    // 設定の更新（パスワード変更用）
    async function updateSiteSettings() {
        const userPw = document.getElementById('user-password-input');
        const body = new URLSearchParams();
        body.append('action', 'update_settings');
        
        // パスワードが空の場合はエラー
        if (!userPw || !userPw.value.trim()) {
            showError('パスワードを入力してください');
            // 直前のパスワードにフォールバック
            await loadSiteSettings();
            return;
        }
        
        // パスワードを送信
        body.append('user_password', userPw.value.trim());
        
        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const resp = await fetch('admin_api.php', { 
                method: 'POST', 
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, 
                credentials: 'same-origin',
                body: body.toString() + `&csrf_token=${encodeURIComponent(csrf)}` 
            });
            const data = await resp.json();
            if (data.success) {
                showSuccess(data.message || '設定を更新しました');
            } else {
                showError(data.message || '更新に失敗しました');
                // 失敗時も直前のパスワードにフォールバック
                await loadSiteSettings();
            }
        } catch (e) {
            showError('更新に失敗しました');
            // エラー時も直前のパスワードにフォールバック
            await loadSiteSettings();
        }
    }

    // 一部更新（トグル即時反映用）
    async function updateSettingsPartial(partial) {
        const body = new URLSearchParams();
        body.append('action', 'update_settings');
        
        if (partial.password_protection !== undefined) {
            body.append('password_protection', partial.password_protection);
        }
        if (partial['features.likes.unique_countup'] !== undefined) {
            body.append('features.likes.unique_countup', partial['features.likes.unique_countup']);
        }
        if (partial['features.views.unique_countup'] !== undefined) {
            body.append('features.views.unique_countup', partial['features.views.unique_countup']);
        }
        if (partial['features.autoplay'] !== undefined) {
            body.append('features.autoplay', partial['features.autoplay']);
        }
        if (partial['app.name'] !== undefined) {
            body.append('app.name', partial['app.name']);
        }
        if (partial['ui.theme'] !== undefined) {
            body.append('ui.theme', partial['ui.theme']);
        }
        if (partial['app.description'] !== undefined) {
            body.append('app.description', partial['app.description']);
        }
        
        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const resp = await fetch('admin_api.php', { 
                method: 'POST', 
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, 
                credentials: 'same-origin',
                body: body.toString() + `&csrf_token=${encodeURIComponent(csrf)}` 
            });
            const data = await resp.json();
            
            if (data.success) {
                showSuccess(data.message || '設定を更新しました');
                // 更新された設定値でUIを更新
                if (data.updated_settings) {
                    updateUIFromSettings(data.updated_settings);
                }
                return true; // 成功
            } else {
                showError(data.message || '設定の更新に失敗しました');
                return false; // 失敗
            }
        } catch (e) {
            console.error('Error in updateSettingsPartial:', e);
            showError('設定の更新に失敗しました');
            return false; // 失敗
        }
    }

    // 設定値に基づいてUIを更新する関数
    function updateUIFromSettings(updatedSettings) {
        // いいねの重複カウント制限の設定を更新
        if (updatedSettings['features.likes.unique_countup'] !== undefined) {
            if (likesUniqueToggle) {
                const isChecked = updatedSettings['features.likes.unique_countup'] === '1' || updatedSettings['features.likes.unique_countup'] === true;
                likesUniqueToggle.checked = isChecked;
                
                // トグルボタンの見た目も更新
                const toggleSlider = likesUniqueToggle.nextElementSibling;
                if (toggleSlider) {
                    if (isChecked) {
                        toggleSlider.classList.add('peer-checked:bg-blue-600');
                        toggleSlider.classList.remove('bg-gray-200');
                    } else {
                        toggleSlider.classList.remove('peer-checked:bg-blue-600');
                        toggleSlider.classList.add('bg-gray-200');
                    }
                }
            }
        }
        
        // デフォルトテーマの更新
        if (updatedSettings['ui.theme'] !== undefined && themeSelect) {
            const val = String(updatedSettings['ui.theme']).toLowerCase();
            if (['light','dark'].includes(val)) {
                themeSelect.value = val;
                // head の meta も即時更新（次回初期描画用）
                const meta = document.querySelector('meta[name="default-theme"]');
                if (meta) meta.setAttribute('content', val);
            }
        }

        // 視聴回数の重複カウント制限の設定を更新
        if (updatedSettings['features.views.unique_countup'] !== undefined) {
            if (viewsUniqueToggle) {
                const isChecked = updatedSettings['features.views.unique_countup'] === '1' || updatedSettings['features.views.unique_countup'] === true;
                viewsUniqueToggle.checked = isChecked;
                
                // トグルボタンの見た目も更新
                const toggleSlider = viewsUniqueToggle.nextElementSibling;
                if (toggleSlider) {
                    if (isChecked) {
                        toggleSlider.classList.add('peer-checked:bg-blue-600');
                        toggleSlider.classList.remove('bg-gray-200');
                    } else {
                        toggleSlider.classList.remove('peer-checked:bg-blue-600');
                        toggleSlider.classList.add('bg-gray-200');
                    }
                }
            }
        }
        
        // パスワード保護の設定を更新
        if (updatedSettings['password_protection'] !== undefined || updatedSettings['security.password_protection'] !== undefined) {
            const passwordProtectionValue = updatedSettings['password_protection'] ?? updatedSettings['security.password_protection'];
            if (publicModeToggle) {
                const isChecked = passwordProtectionValue === '1' || passwordProtectionValue === true;
                publicModeToggle.checked = isChecked;
                
                // パスワード入力フィールドの表示状態も更新
                applyPasswordBlockState(!isChecked);
            }
        }
    }

    // 成功メッセージの表示
    function showSuccess(message = '設定を保存しました') {
        showNotification(message, 'success');
    }

    // エラーメッセージの表示
    function showError(message) {
        showNotification(message, 'error');
    }
});

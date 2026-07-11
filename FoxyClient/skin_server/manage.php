<?php
$uuid = $_GET['uuid'] ?? '';
$username = $_GET['username'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Skin Manager - FoxyClient</title>
<link rel="stylesheet" href="/app.css">
</head>
<body>
<div class="layout">
<div class="sidebar">
    <h1>SKIN MANAGER</h1>
    <p>Manage your local skins & capes</p>
    <div class="account-list" id="accountList"></div>
</div>
<div class="main">
    <div id="emptyState" class="empty-state">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 3c-1.5 0-3 .5-3 2v3c0 1.5 1.5 2 3 2s3-.5 3-2V5c0-1.5-1.5-2-3-2z"/><path d="M5 14c0-2 2-3 4-3h6c2 0 4 1 4 3v2H5v-2z"/><circle cx="12" cy="4" r="1"/><path d="M9 18h6"/></svg>
        <p>Select an account from the sidebar<br>or add one via the launcher.</p>
    </div>
    <div id="skinPanel" class="hidden">
        <div class="canvas-wrap">
            <canvas id="skinCanvas"></canvas>
        </div>
        <div class="info">
            <h2 id="displayName">Player</h2>
            <p id="displayModel">Classic (Steve)</p>
        </div>
        <div class="actions">
            <label class="btn btn-primary" id="uploadSkinBtn">Upload Skin</label>
            <input type="file" id="skinFileInput" accept="image/png">
            <label class="btn" id="uploadCapeBtn">Upload Cape</label>
            <input type="file" id="capeFileInput" accept="image/png">
            <button class="btn btn-danger btn-sm hidden" id="removeCapeBtn">Remove Cape</button>
        </div>
        <label class="slim-toggle">
            <input type="checkbox" id="slimToggle">
            Slim Model (Alex)
        </label>
        <span class="status" id="statusText">Ready</span>
    </div>
</div>
</div>
<div class="toast" id="toast"></div>
<script src="/assets/js/jquery-3.7.1.min.js"></script>
<script src="/assets/js/skinview3d.bundle.js"></script>
<script>
let state = { currentUuid: '', currentUsername: '', accounts: [], isSlim: false, hasSkin: false, hasCape: false };
let viewer = null;
let controls = null;
let toastTimer = null;

function showToast(msg, type) {
    $('#toast').text(msg).attr('class', 'toast ' + type + ' show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => $('#toast').removeClass('show'), 3000);
}

function initViewer() {
    const canvas = document.getElementById('skinCanvas');
    if (!canvas) return;
    viewer = new skinview3d.SkinViewer({ canvas: canvas, width: 300, height: 400, renderPaused: false });
    viewer.autoRotate = true;
    viewer.autoRotateSpeed = 1;
    controls = viewer.controls;
    controls.enableRotate = true;
    controls.enableZoom = true;
    controls.enablePan = false;
}

function loadAccounts() {
    $.getJSON('/manage/accounts', function(list) {
        state.accounts = list;
        renderAccountList();
        if (list.length > 0 && !state.currentUuid) {
            selectAccount(list[0].uuid, list[0].username);
        }
    });
}

function renderAccountList() {
    const $list = $('#accountList').empty();
    if (state.accounts.length === 0) {
        $list.html('<div class="empty-msg">No accounts yet. Add one in the launcher.</div>');
        return;
    }
    $.each(state.accounts, function(i, acc) {
        const $item = $('<div class="account-item' + (acc.uuid === state.currentUuid ? ' active' : '') + '"><span class="acc-dot"></span>' + $('<span>').text(acc.username).html() + '</div>');
        $item.on('click', function() { selectAccount(acc.uuid, acc.username); });
        $list.append($item);
    });
}

function selectAccount(uuid, username) {
    state.currentUuid = uuid;
    state.currentUsername = username;
    renderAccountList();

    $.getJSON('/manage/profile?uuid=' + encodeURIComponent(uuid), function(profile) {
        state.hasSkin = profile.has_skin;
        state.hasCape = profile.has_cape;
        state.isSlim = profile.is_slim;

        $('#displayName').text(profile.username);
        $('#displayModel').text(profile.is_slim ? 'Slim (Alex)' : 'Classic (Steve)');
        $('#slimToggle').prop('checked', profile.is_slim);

        const defaultSuffix = profile.is_slim ? '_slim' : '';
        const skinUrl = (profile.has_skin ? '/textures/skins/' + uuid.replace(/-/g,'') : '/textures/skins/default' + defaultSuffix) + '_skin.png?' + Date.now();
        if (viewer) {
            viewer.loadSkin(skinUrl);
            if (profile.has_cape) {
                viewer.loadCape('/textures/capes/' + uuid.replace(/-/g,'') + '_cape.png?' + Date.now());
            } else {
                viewer.loadCape(null);
            }
            viewer.autoRotate = true;
            viewer.autoRotateSpeed = 1;
            viewer.width = 300;
            viewer.height = 400;
        }

        $('#removeCapeBtn').toggleClass('hidden', !profile.has_cape);
        $('#skinPanel').removeClass('hidden');
        $('#emptyState').addClass('hidden');
        $('#statusText').text('Loaded');
    });
}

$(function() {
    initViewer();
    loadAccounts();

    $('#uploadSkinBtn').on('click', function(e) { e.preventDefault(); $('#skinFileInput').click(); });

    $('#skinFileInput').on('change', function() {
        const file = this.files[0];
        if (!file) return;
        if (file.size > 20480) { showToast('File exceeds 20KB limit', 'error'); return; }
        if (file.type !== 'image/png') { showToast('Must be PNG', 'error'); return; }

        const img = new Image();
        img.onload = function() {
            if (!((img.width === 64 && (img.height === 64 || img.height === 32)) || (img.width === 32 && img.height === 32))) {
                showToast('Dimensions must be 64x64, 64x32, or 32x32', 'error');
                return;
            }
            const fd = new FormData();
            fd.append('uuid', state.currentUuid);
            fd.append('skin', file);
            fd.append('is_slim', state.isSlim ? '1' : '0');
            $('#statusText').text('Uploading skin...');
            $.ajax({ url: '/manage/upload/skin', method: 'POST', data: fd, processData: false, contentType: false,
                success: function(res) {
                    if (res.success) { showToast('Skin uploaded!', 'success'); selectAccount(state.currentUuid, state.currentUsername); }
                    else { showToast(res.error || 'Upload failed', 'error'); }
                },
                error: function() { showToast('Upload failed', 'error'); }
            });
        };
        img.src = URL.createObjectURL(file);
    });

    $('#uploadCapeBtn').on('click', function(e) { e.preventDefault(); $('#capeFileInput').click(); });

    $('#capeFileInput').on('change', function() {
        const file = this.files[0];
        if (!file) return;
        if (file.size > 5120) { showToast('File exceeds 5KB limit', 'error'); return; }
        if (file.type !== 'image/png') { showToast('Must be PNG', 'error'); return; }

        const fd = new FormData();
        fd.append('uuid', state.currentUuid);
        fd.append('cape', file);
        $('#statusText').text('Uploading cape...');
        $.ajax({ url: '/manage/upload/cape', method: 'POST', data: fd, processData: false, contentType: false,
            success: function(res) {
                if (res.success) { showToast('Cape uploaded!', 'success'); selectAccount(state.currentUuid, state.currentUsername); }
                else { showToast(res.error || 'Upload failed', 'error'); }
            },
            error: function() { showToast('Upload failed', 'error'); }
        });
    });

    $('#removeCapeBtn').on('click', function() {
        $.ajax({ url: '/manage/remove/cape', method: 'POST', contentType: 'application/json', data: JSON.stringify({ uuid: state.currentUuid }),
            success: function(res) {
                if (res.success) { showToast('Cape removed', 'success'); selectAccount(state.currentUuid, state.currentUsername); }
            }
        });
    });

    $('#slimToggle').on('change', function() {
        $.ajax({ url: '/manage/toggle/slim', method: 'POST', contentType: 'application/json', data: JSON.stringify({ uuid: state.currentUuid }),
            success: function(res) {
                if (res.success) {
                    state.isSlim = res.is_slim;
                    $('#displayModel').text(res.is_slim ? 'Slim (Alex)' : 'Classic (Steve)');
                    showToast('Model: ' + (res.is_slim ? 'Slim' : 'Classic'), 'success');
                    if (!state.hasSkin && viewer) {
                        const suffix = res.is_slim ? '_slim' : '';
                        viewer.loadSkin('/textures/skins/default' + suffix + '_skin.png?' + Date.now());
                        viewer.autoRotate = true;
                        viewer.autoRotateSpeed = 1;
                    }
                }
            }
        });
    });

    $(document).on('keydown', function(e) { if (e.key === 'Escape') window.close(); });
});
</script>
</body>
</html>

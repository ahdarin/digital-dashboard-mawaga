/**
 * SETTINGS/ANALYTICS SYNC UX CONSISTENCY FIX - satu-satunya tempat rendering
 * progress/hasil sync terstruktur (Pass 1 AnalyticsSyncRun/Task/Failure)
 * dipertahankan. Dipakai BARENG oleh resources/views/analytics/index.blade.php
 * (tombol "Perbarui Data" global) DAN resources/views/settings/partials/
 * integrations-panel.blade.php (tombol "Perbarui Data" per platform) - kalau
 * semantik progress berubah nanti, cukup ubah SATU file ini, dua halaman
 * otomatis tetap konsisten (Langkah 11, "do not maintain two independently-
 * diverging implementations").
 *
 * File statis biasa (BUKAN modul Vite/bundler - proyek ini belum punya
 * pipeline JS module apapun, resources/js/app.js kosong & @vite tidak
 * dipakai di manapun) - dimuat lewat <script src="{{ asset('js/...') }}">
 * biasa, pola yang SAMA dengan CDN script tag lain di layouts.app.
 *
 * TIDAK PERNAH menerima/menyimpan token/secret - hanya konsumsi field aman
 * yang SUDAH diekspos AnalyticsSyncOrchestrator::statusForClient()/
 * latestRunProgress() (angka/status/timestamp publik ke user yang sudah
 * authorized).
 */
(function (window) {
    'use strict';

    function esc(text) {
        var div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    function isBusy(status) {
        return status === 'queued' || status === 'running';
    }

    function formatElapsed(startIso) {
        if (!startIso) return '';
        var totalSeconds = Math.max(0, Math.floor((Date.now() - new Date(startIso).getTime()) / 1000));
        var m = Math.floor(totalSeconds / 60);
        var s = totalSeconds % 60;
        return m > 0 ? (m + 'm ' + s + 'd') : (s + 'd');
    }

    function secondsSince(iso) {
        if (!iso) return null;
        return Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 1000));
    }

    // "Data diperbarui hari ini, 20:42" style (Langkah 10, "freshness" -
    // TIDAK PERNAH raw timestamp/sync-log ID teknis).
    function formatFreshness(iso) {
        var date = new Date(iso);
        var now = new Date();
        var isToday = date.toDateString() === now.toDateString();
        var yesterday = new Date(now); yesterday.setDate(now.getDate() - 1);
        var isYesterday = date.toDateString() === yesterday.toDateString();
        var time = date.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });

        if (isToday) return 'Data diperbarui hari ini, ' + time;
        if (isYesterday) return 'Data diperbarui kemarin, ' + time;
        // CROSS-PAGE CONSISTENCY AUDIT (Part 6/14) - HARUS sama persis
        // kata-katanya dengan App\Services\FreshnessPresenter (versi PHP,
        // dipakai Content Detail) - dulu 2 implementasi ini berbeda
        // (versi JS ini pernah pakai "Data diperbarui X, HH:MM" dengan jam
        // bahkan buat tanggal lama; versi PHP "Data terakhir diperbarui X"
        // tanpa jam) - SEKARANG satu kosakata, satu-satunya perbedaan
        // adalah bahasa implementasi (JS di sini buat sync panel yang
        // genuinely live-polling, PHP buat halaman server-rendered biasa).
        return 'Data terakhir diperbarui ' + date.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
    }

    // DISCOVERING_STAGES - fase paginasi provider (Instagram getMedia()/
    // TikTok getVideoList()) SEDANG berjalan, AnalyticsSyncTask.discovered_
    // count di fase ini adalah "ditemukan sejauh ini" (bertambah per
    // halaman, lihat AnalyticsSyncTask::touchDiscoveryProgress()) - BUKAN
    // total definitif, jadi TIDAK PERNAH boleh dipakai menghitung
    // persentase (SYNC PROGRESS UX pass, "Do NOT fake progress
    // percentages").
    var DISCOVERING_STAGES = { discovering_media: true, discovering_videos: true };

    var STAGE_LABELS = {
        discovering_media: 'Mencari konten 90 hari terakhir...',
        fetching_insights: 'Memproses insight konten',
        refreshing_known_media: 'Memperbarui konten yang sudah tercatat',
        discovering_videos: 'Mencari video 90 hari terakhir...',
        processing_videos: 'Memproses insight video',
        refreshing_known_videos: 'Memperbarui video yang sudah tercatat',
        fetching_audience_metrics: 'Mengambil data audiens',
        // PROGRESSIVE 90-DAY SYNC ENGINE (Langkah 13/15) - stage progresif
        // Instagram/TikTok, dipetakan dari AnalyticsSyncTask.stage yang
        // di-set tiap chunk job jalan (ProcessInstagramSyncChunkJob::
        // stageLabelFor()/ProcessTikTokSyncChunkJob::stageLabelFor()).
        // Backend TIDAK PERNAH mengirim istilah "chunk"/"queue"/"job"/
        // "worker" ke sini - label ini SATU-SATUNYA yang user lihat.
        // SYNC PROGRESS UX pass - rentang hari eksplisit (SyncStageBoundary::
        // STAGE_RECENT/MID/OLDER = 0-29/30-59/60-89 hari) menggantikan
        // wording "sebelumnya"/"hingga 90 hari" yang lebih kabur.
        processing_recent: 'Memperbarui performa konten 30 hari terakhir',
        processing_previous: 'Memperbarui performa konten 30-59 hari yang lalu',
        processing_older: 'Memperbarui performa konten 60-89 hari yang lalu',
        // FINAL CLOSURE GATE (Langkah 1) - AnalyticsSyncTask::finish() SELALU
        // menyetel stage ke 'completed' begitu task terminal (lihat model).
        // Panel TIDAK PERNAH benar-benar merender ini (isBusy() sudah false
        // duluan begitu status terminal, lihat cabang "task.finished_at" di
        // bawah yang pakai reconciliationLines()/LAST_RESULT_MESSAGES,
        // BUKAN STAGE_LABELS) - entry ini murni jaring pengaman defensif
        // buat konsumen lain yang mungkin baca task.stage mentah.
        completed: 'Selesai',
    };
    var SECONDARY_LABELS = { instagram_audience: 'Audiens' };
    var LAST_RESULT_MESSAGES = {
        success: 'Data berhasil diperbarui.',
        partial: 'Pembaruan selesai sebagian.',
        failed: 'Pembaruan gagal.',
        needs_reconnect: 'Ada koneksi yang butuh dihubungkan ulang.',
        not_connected: 'Belum ada platform yang terhubung untuk client ini.',
        idle: '',
    };
    // Default grup platform (Instagram: content+audience jadi SATU
    // pengalaman, Langkah 4; TikTok: content saja) - caller boleh override
    // (mis. Settings hanya butuh 1 grup per instance controller).
    var DEFAULT_PLATFORM_GROUPS = [
        { key: 'instagram', label: 'Instagram', primary: 'instagram_content', unit: 'konten', secondary: ['instagram_audience'] },
        { key: 'tiktok', label: 'TikTok', primary: 'tiktok_content', unit: 'video', secondary: [] },
    ];
    // Ambang "terasa lebih lama dari biasanya" - SENGAJA jauh lebih pendek
    // dari ambang backend "job dianggap mati" (AnalyticsSyncOrchestrator::
    // staleThresholdSecondsFor(), 360-660 detik) - ini cuma SOFT warning,
    // backend tetap satu-satunya yang berwenang bilang job benar2 berhenti.
    var SLOW_PROGRESS_SECONDS = 45;

    function reconciliationLines(task, unit) {
        if (!task || !task.discovered_count) return [];
        var lines = [];
        lines.push({ tone: 'success', text: task.success_count + ' dari ' + task.discovered_count + ' ' + unit + ' diperbarui' });
        if (task.unavailable_count > 0) {
            lines.push({ tone: 'muted', text: task.unavailable_count + ' ' + unit + ' tidak tersedia dari provider' });
        }
        if (task.skipped_count > 0) {
            lines.push({ tone: 'muted', text: task.skipped_count + ' ' + unit + ' dilewati' });
        }
        if (task.failed_count > 0) {
            lines.push({ tone: 'danger', text: task.failed_count + ' ' + unit + ' belum berhasil diperbarui' });
        }
        if (task.discovered_count > 0 && task.reconciled === false) {
            lines.push({ tone: 'muted', text: 'Sebagian hasil belum bisa dipastikan statusnya.' });
        }
        return lines;
    }

    function toneClass(tone) {
        if (tone === 'danger') return 'text-[var(--danger-text)]';
        if (tone === 'success') return 'text-[var(--success-text)]';
        return 'text-[var(--text-muted)]';
    }

    function detailChecklist(task, unit) {
        if (!task) return [];
        var items = [];
        if (task.discovered_count > 0) {
            items.push({ ok: true, text: task.discovered_count + ' ' + unit + ' ditemukan' });
            items.push({ ok: task.processed_count >= task.discovered_count, text: task.processed_count + ' dari ' + task.discovered_count + ' ' + unit + ' diproses' });
        }
        if (task.finished_at && task.reconciled) {
            items.push({ ok: true, text: 'Data ' + unit + ' tersimpan' });
        }
        if (task.unavailable_count > 0) {
            items.push({ ok: null, text: task.unavailable_count + ' ' + unit + ' tidak tersedia dari provider (bukan kegagalan teknis)' });
        }
        if (task.failed_count > 0) {
            items.push({ ok: false, text: task.failed_count + ' ' + unit + ' gagal diperbarui' });
        }
        return items;
    }

    function checklistIcon(ok) {
        if (ok === true) return '<span class="material-symbols-outlined text-[15px] text-[var(--success-text)]">check_circle</span>';
        if (ok === false) return '<span class="material-symbols-outlined text-[15px] text-[var(--danger-text)]">warning</span>';
        return '<span class="material-symbols-outlined text-[15px] text-[var(--text-muted)]">info</span>';
    }

    // Subjob sekunder (mis. Instagram Audiens) hanya boleh ikut checklist
    // kalau run_id-nya SAMA PERSIS dengan task primary (dispatch()/
    // retryTask() selalu 1 AnalyticsSyncRun per panggilan, dipakai bareng
    // subjob yang genuinely didispatch bersamaan) - task dari run lain
    // TIDAK diklaim sebagai bagian hasil operasi yang sedang ditampilkan.
    // needs_reconnect dikecualikan dari gating ini - itu fakta koneksi yang
    // berlaku SEKARANG (live dari status integrasi), bukan milik 1 task/run.
    function secondaryChecklistLines(group, subjobs, progressTasks, primaryTask, reconnectUrl) {
        var lines = [];
        group.secondary.forEach(function (secKey) {
            var secState = subjobs[secKey];
            var secTask = progressTasks ? progressTasks[secKey] : null;
            var secLabel = SECONDARY_LABELS[secKey] || secKey;
            if (!secState) return;

            if (secState.status === 'needs_reconnect') {
                lines.push({ ok: false, text: esc(secLabel) + ' butuh dihubungkan ulang' });
                lines.push({ ok: null, html: '<a href="' + reconnectUrl + '" class="text-[11px] font-medium text-[var(--brand)] hover:underline">Hubungkan kembali ' + esc(group.label) + '</a>' });
                return;
            }

            if (!primaryTask || !secTask || secTask.run_id !== primaryTask.run_id) return;

            if (secState.status === 'failed' && secTask.failed_count > 0) {
                lines.push({ ok: false, text: 'Data ' + esc(secLabel).toLowerCase() + ' belum berhasil diperbarui' });
                if (secTask.id) {
                    lines.push({ ok: null, html: '<button type="button" class="text-[11px] font-medium text-[var(--brand)] hover:underline analytics-retry-btn" data-task-id="' + secTask.id + '" data-action="retry-task">Coba lagi data ' + esc(secLabel) + '</button>' });
                }
                return;
            }

            if (secTask.unavailable_count > 0) {
                lines.push({ ok: null, text: 'Sebagian data ' + esc(secLabel).toLowerCase() + ' belum tersedia dari ' + esc(group.label) });
                return;
            }

            if (secState.status === 'success') {
                lines.push({ ok: true, text: 'Data ' + esc(secLabel).toLowerCase() + ' diperbarui' });
            }
        });
        return lines;
    }

    function secondaryPrimaryWarning(group, subjobs, progressTasks, primaryTask) {
        var html = '';
        group.secondary.forEach(function (secKey) {
            var secState = subjobs[secKey];
            var secTask = progressTasks ? progressTasks[secKey] : null;
            if (!secState) return;

            if (secState.status === 'needs_reconnect') {
                html += '<p class="text-xs text-[var(--warning-text)] mt-1.5 flex items-center gap-1.5">'
                    + '<span class="material-symbols-outlined text-[15px]">link_off</span> Koneksi butuh dihubungkan ulang untuk sebagian data</p>';
                return;
            }

            if (!primaryTask || !secTask || secTask.run_id !== primaryTask.run_id) return;

            if (secState.status === 'failed' && secTask.failed_count > 0) {
                html += '<p class="text-xs text-[var(--danger-text)] mt-1.5 flex items-center gap-1.5">'
                    + '<span class="material-symbols-outlined text-[15px]">warning</span> Sebagian data belum berhasil diperbarui</p>';
            }
        });
        return html;
    }

    // Targeted retry - HANYA menyasar scope yang backend TAHU gagal (item-
    // level buat subjob yang punya AnalyticsSyncFailure retryable, task-
    // level kalau seluruh subjob gagal) - TIDAK PERNAH dispatch sync
    // lengkap kalau backend sudah tahu persis apa yang perlu dicoba ulang.
    function retryButtonHtml(subjobKey, task, groupLabel, unit) {
        if (!task || !task.id || task.status !== 'failed' || !task.failed_count) return '';

        var canItemRetry = subjobKey === 'instagram_content' || subjobKey === 'tiktok_content';
        var text = canItemRetry
            ? 'Coba lagi ' + task.failed_count + ' ' + unit
            : (subjobKey === 'instagram_audience' ? 'Coba lagi data Audiens' : 'Coba lagi ' + groupLabel);
        var action = canItemRetry ? 'retry-items' : 'retry-task';

        return '<button type="button" class="text-xs font-medium text-[var(--brand)] hover:underline analytics-retry-btn" '
            + 'data-task-id="' + task.id + '" data-action="' + action + '">' + esc(text) + '</button>';
    }

    // Bangun 1 kartu platform (progress aktif / hasil terminal / needs_
    // reconnect / belum pernah sync) - SATU-SATUNYA fungsi yang menentukan
    // bagaimana progress/hasil sync platform manapun ditampilkan, dipakai
    // identik oleh Analytics (1+ grup dalam 1 panel) dan Settings (1 grup
    // per kartu platform).
    function renderGroup(group, subjobs, progressTasks, reconnectUrl) {
        var relevantKeys = [group.primary].concat(group.secondary).filter(function (k) { return subjobs[k]; });
        if (!relevantKeys.length) return '';

        var primaryState = subjobs[group.primary];
        if (relevantKeys.every(function (k) { return subjobs[k].status === 'not_connected'; })) return '';

        var task = progressTasks ? progressTasks[group.primary] : null;
        var body = '';

        if (primaryState && primaryState.status === 'needs_reconnect') {
            body = '<p class="text-xs text-[var(--warning-text)] flex items-center gap-1.5">'
                + '<span class="material-symbols-outlined text-[15px]">link_off</span> Koneksi ' + esc(group.label) + ' butuh dihubungkan ulang'
                + '</p><a href="' + reconnectUrl + '" class="text-xs font-medium text-[var(--brand)] hover:underline mt-1 inline-block">Hubungkan kembali ' + esc(group.label) + '</a>';
        } else if (primaryState && isBusy(primaryState.status)) {
            var stage = task ? task.stage : null;
            var discovering = !!DISCOVERING_STAGES[stage];
            var spinnerSvg = '<svg class="animate-spin h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>';

            if (discovering) {
                // FASE 1/3 (discovery) - discovered_count di sini TUMBUH per
                // halaman provider (touchDiscoveryProgress()), total belum
                // diketahui - TAMPILKAN ANGKA MENTAH, JANGAN PERNAH mengarang
                // persentase dari angka yang belum final.
                var foundText = (task && task.discovered_count > 0)
                    ? (' · ' + task.discovered_count + ' ' + group.unit + ' ditemukan sejauh ini')
                    : '';
                body = '<div class="flex items-center gap-2 text-xs text-[var(--text-secondary)]">'
                    + spinnerSvg
                    + '<span>' + esc(STAGE_LABELS[stage] || ('Mencari ' + group.unit + '...')) + esc(foundText) + '</span>'
                    + '</div>';
            } else if (task && task.discovered_count > 0) {
                // FASE 2 (processing) - discovered_count SEKARANG total
                // definitif (di-set absolut sekali begitu discovery+known-
                // refresh selesai direncanakan), aman dipakai penyebut
                // persentase real.
                var pct = Math.min(100, Math.round((task.processed_count / task.discovered_count) * 100));
                var finishing = task.processed_count >= task.discovered_count;
                var countParts = [];
                if (task.success_count > 0) countParts.push(task.success_count + ' berhasil');
                if (task.failed_count > 0) countParts.push(task.failed_count + ' gagal');
                if (task.unavailable_count > 0) countParts.push(task.unavailable_count + ' tidak tersedia');

                body = '<div class="flex items-center justify-between text-xs mb-1.5">'
                    + '<span class="text-[var(--text-secondary)]">' + task.processed_count + ' dari ' + task.discovered_count + ' ' + group.unit + ' selesai · ' + pct + '%</span>'
                    + '<span class="text-[var(--text-muted)]">' + formatElapsed(task.started_at) + '</span>'
                    + '</div>'
                    + '<div class="w-full h-1.5 rounded-full bg-[var(--surface-muted)] overflow-hidden mb-1" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + pct + '" aria-label="Progres pembaruan ' + esc(group.label) + '">'
                    + '<div class="h-full rounded-full bg-[var(--brand)] transition-[width]" style="width:' + pct + '%"></div></div>'
                    // FASE 3 - "Menyelesaikan pembaruan..." begitu seluruh
                    // item TERPROSES (bukan cuma persen visual 100%) tapi
                    // task belum finished_at (window finalisasi singkat
                    // antara chunk terakhir & finish()).
                    + '<p class="text-[11px] text-[var(--text-muted)]">' + (finishing
                        ? 'Menyelesaikan pembaruan...'
                        : esc((countParts.length ? countParts.join(', ') + ' · ' : '') + (STAGE_LABELS[stage] || 'Memperbarui performa konten...'))) + '</p>';
            } else {
                // Task ada tapi discovered_count=0 (genuinely 0 item buat
                // diproses, mis. akun tanpa konten baru) - TIDAK PERNAH
                // 0/0 dipaksa jadi persentase, indeterminate spinner biasa.
                body = '<div class="flex items-center gap-2 text-xs text-[var(--text-secondary)]">'
                    + spinnerSvg
                    + '<span>' + esc((task && STAGE_LABELS[stage]) || ('Menyiapkan ' + group.label + '...')) + '</span>'
                    + '</div>';
            }

            var idleSeconds = task ? secondsSince(task.last_progress_at) : null;
            if (idleSeconds !== null && idleSeconds > SLOW_PROGRESS_SECONDS) {
                body += '<p class="text-[11px] text-[var(--warning-text)] mt-1.5">Pembaruan membutuhkan waktu lebih lama dari biasanya.</p>';
            }
        } else if (task && task.finished_at) {
            var lines = reconciliationLines(task, group.unit);
            if (lines.length) {
                body = lines.map(function (l) {
                    return '<p class="text-xs ' + toneClass(l.tone) + '">' + esc(l.text) + '</p>';
                }).join('');
            } else if (primaryState) {
                body = '<p class="text-xs text-[var(--text-muted)]">' + esc(LAST_RESULT_MESSAGES[primaryState.status] || '') + '</p>';
            }

            body += secondaryPrimaryWarning(group, subjobs, progressTasks, task);

            var retryHtml = retryButtonHtml(group.primary, task, group.label, group.unit);
            if (retryHtml) body += '<div class="mt-1.5">' + retryHtml + '</div>';
        } else if (primaryState) {
            body = '<p class="text-xs text-[var(--text-muted)]">' + esc(LAST_RESULT_MESSAGES[primaryState.status] || (primaryState.message || '')) + '</p>';
        }

        var checklist = detailChecklist(task, group.unit).concat(secondaryChecklistLines(group, subjobs, progressTasks, task, reconnectUrl));
        var detailHtml = checklist.length
            ? '<details class="mt-2"><summary class="text-[11px] font-medium text-[var(--brand)] cursor-pointer select-none">Lihat detail</summary>'
                + '<div class="mt-2 space-y-1">' + checklist.map(function (c) {
                    return '<p class="text-[11px] text-[var(--text-secondary)] flex items-center gap-1.5">' + checklistIcon(c.ok) + ' ' + (c.html || esc(c.text)) + '</p>';
                }).join('') + '</div></details>'
            : '';

        return '<div class="py-3 first:pt-0 last:pb-0 border-b last:border-0 border-[var(--surface-muted)]">'
            + '<p class="text-xs font-semibold text-[var(--text-primary)] mb-1.5">' + esc(group.label) + '</p>'
            + body + detailHtml
            + '</div>';
    }

    function busyButtonLabel(subjobs) {
        var keys = Object.keys(subjobs || {});
        if (keys.length === 1 && keys[0] === 'tiktok_content') return 'Memperbarui TikTok...';
        if (keys.length >= 1 && keys.every(function (k) { return k.indexOf('instagram') === 0; })) return 'Memperbarui Instagram...';
        return 'Memperbarui...';
    }

    function query(params) {
        return Object.keys(params)
            .filter(function (k) { return params[k] !== null && params[k] !== undefined; })
            .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); })
            .join('&');
    }

    /**
     * Controller reusable penuh: dispatch + poll + render + retry buat
     * SATU tombol "Perbarui Data" yang men-scope ke SATU ATAU LEBIH grup
     * platform (Analytics: 1+ grup tergantung filter platform_id global;
     * Settings: SELALU 1 grup per instance, 1 instance per kartu platform
     * - Langkah 1 "ONE update action per platform").
     *
     * @param {Object} config
     * @param {number} config.clientId
     * @param {?number} config.platformId - null = All Platforms (Analytics saja).
     * @param {Array} config.groups - subset DEFAULT_PLATFORM_GROUPS yang relevan buat instance ini.
     * @param {Object} config.urls - {dispatch, status, retryTask, retryFailedItems}
     * @param {string} config.reconnectUrl
     * @param {string} config.csrfToken
     * @param {Object} config.elements - {button, icon, label, freshness, panel} (DOM element atau null)
     * @param {boolean} [config.reloadOnTerminal=true]
     * @param {string} [config.idleButtonLabel='Perbarui Data']
     */
    function createSyncController(config) {
        var els = config.elements || {};
        var groups = config.groups || DEFAULT_PLATFORM_GROUPS;
        var reloadOnTerminal = config.reloadOnTerminal !== false;
        var idleLabel = config.idleButtonLabel || 'Perbarui Data';

        if (!els.button) return null;

        var pollTimer = null;
        var isTracking = false;
        var consecutivePollFailures = 0;
        var MAX_POLL_FAILURES = 3;

        function applyNeedsReconnectButtonState() {
            els.button.onclick = function () { window.location.href = config.reconnectUrl; };
            if (els.icon) { els.icon.classList.remove('animate-spin'); els.icon.textContent = 'link_off'; }
            if (els.label) els.label.textContent = 'Hubungkan Ulang';
            els.button.disabled = false;
        }

        function applyNormalButtonState(busy, subjobs) {
            els.button.onclick = dispatchSync;
            if (els.icon) { els.icon.classList.toggle('animate-spin', busy); els.icon.textContent = 'sync'; }
            if (els.label) els.label.textContent = busy ? busyButtonLabel(subjobs) : idleLabel;
            els.button.disabled = busy;
        }

        function renderPanel(data) {
            if (!els.panel) return;
            var subjobs = data.subjobs || {};
            var progressTasks = (data.progress && data.progress.tasks) || null;
            var html = groups.map(function (g) { return renderGroup(g, subjobs, progressTasks, config.reconnectUrl); }).join('');

            if (!html) { els.panel.hidden = true; els.panel.innerHTML = ''; return; }

            els.panel.innerHTML = '<div class="card p-4">' + html + '</div>';
            els.panel.hidden = false;

            els.panel.querySelectorAll('.analytics-retry-btn').forEach(function (btn) {
                btn.addEventListener('click', function () { handleRetryClick(btn); });
            });
        }

        function handleRetryClick(btn) {
            var taskId = btn.getAttribute('data-task-id');
            var action = btn.getAttribute('data-action');
            var url = action === 'retry-items' ? config.urls.retryFailedItems : config.urls.retryTask;
            btn.disabled = true;
            btn.textContent = 'Mencoba lagi...';

            fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': config.csrfToken, 'Accept': 'application/json' },
                body: JSON.stringify({ task_id: taskId }),
            })
                .then(function (res) { return res.json(); })
                .then(function () {
                    isTracking = true;
                    startPolling();
                })
                .catch(function () {
                    btn.disabled = false;
                });
        }

        function showSafeError(text) {
            if (els.freshness) { els.freshness.textContent = text; els.freshness.hidden = false; }
        }

        function applyStatus(data) {
            var busy = isBusy(data.overall_status);
            if (busy) isTracking = true;

            if (els.freshness) {
                // FRESHNESS AUDIT ADDENDUM - "Data diperbarui hari ini,
                // HH:MM" claims the WHOLE platform refresh is done.
                // last_observation_at can legitimately advance mid-run
                // (progressive engine persists each chunk's observations
                // immediately, not held until the end) - showing that
                // wording while busy=true would misleadingly imply a
                // partial run (e.g. "42 dari 137 konten") had already
                // finished entirely. While busy, this line defers to the
                // panel's own progress display (renderPanel() below)
                // instead of claiming completion; the accurate freshness
                // timestamp is only shown once the run reaches a genuine
                // terminal state (not busy).
                if (busy) {
                    els.freshness.textContent = 'Sedang memperbarui data...';
                } else if (data.last_observation_at) {
                    els.freshness.textContent = formatFreshness(data.last_observation_at);
                } else if (!isTracking) {
                    els.freshness.textContent = LAST_RESULT_MESSAGES[data.overall_status] || 'Belum ada data yang tersinkronkan.';
                }
                els.freshness.hidden = false;
            }

            renderPanel(data);

            if (!busy && data.overall_status === 'needs_reconnect') {
                applyNeedsReconnectButtonState();
            } else {
                applyNormalButtonState(busy, data.subjobs);
            }

            if (!busy) {
                stopPolling();
                if (reloadOnTerminal && isTracking && (data.overall_status === 'success' || data.overall_status === 'partial' || data.overall_status === 'failed')) {
                    setTimeout(function () { window.location.reload(); }, 900);
                }
                isTracking = false;
            }
        }

        function poll() {
            fetch(config.urls.status + '?' + query({ client_id: config.clientId, platform_id: config.platformId }), { headers: { Accept: 'application/json' }, cache: 'no-store' })
                .then(function (res) {
                    if (res.status === 401 || res.status === 419) {
                        throw { safeStop: true, message: 'Sesi Anda berakhir. Muat ulang halaman dan login kembali untuk melanjutkan.' };
                    }
                    if (!res.ok) { throw { safeStop: false }; }
                    return res.json();
                })
                .then(function (data) {
                    consecutivePollFailures = 0;
                    applyStatus(data);
                })
                .catch(function (err) {
                    if (err && err.safeStop) {
                        stopPolling();
                        isTracking = false;
                        showSafeError(err.message || 'Terjadi kesalahan. Muat ulang halaman.');
                        applyNormalButtonState(false, null);
                        return;
                    }
                    consecutivePollFailures++;
                    if (consecutivePollFailures >= MAX_POLL_FAILURES) {
                        stopPolling();
                        isTracking = false;
                        showSafeError('Gagal memuat status sinkronisasi. Coba muat ulang halaman.');
                        applyNormalButtonState(false, null);
                    }
                });
        }

        function startPolling() {
            if (pollTimer) return;
            poll();
            pollTimer = setInterval(poll, 2500);
        }

        function stopPolling() {
            if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
        }

        // SYNC UI STALE TERMINAL STATE BUG FIX (Langkah 9, "button + panel
        // must be coherent") - dispatchSync() sebelumnya membuat tombol
        // langsung busy TAPI TIDAK menyentuh els.panel sama sekali, jadi
        // hasil terminal RUN LAMA (mis. "Pembaruan gagal.") tetap ter-render
        // apa adanya sampai poll() PERTAMA selesai (network round-trip) -
        // sebentar, tapi genuinely ada momen tombol bilang "Memperbarui..."
        // sementara panel yang SAMA masih bilang gagal. Panel SEKARANG
        // langsung diganti placeholder netral di sini juga, SEBELUM
        // fetch() apapun selesai - begitu poll() pertama benar2 landing,
        // renderPanel(data) menimpanya lagi dengan progress genuine.
        function showQueuedPlaceholder() {
            if (!els.panel) return;
            els.panel.innerHTML = '<div class="card p-4"><div class="flex items-center gap-2 text-xs text-[var(--text-secondary)]">'
                + '<svg class="animate-spin h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>'
                + '<span>Menunggu proses...</span></div></div>';
            els.panel.hidden = false;
        }

        function dispatchSync() {
            isTracking = true;
            applyNormalButtonState(true, null);
            showQueuedPlaceholder();

            fetch(config.urls.dispatch, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': config.csrfToken, 'Accept': 'application/json' },
                body: JSON.stringify({ client_id: config.clientId, platform_id: config.platformId }),
            })
                .then(function (res) { return res.json().then(function (body) { return { ok: res.ok, body: body }; }); })
                .then(function (result) {
                    if (!result.ok) {
                        isTracking = false;
                        showSafeError(result.body.message || 'Pembaruan data gagal dimulai.');
                        applyNormalButtonState(false, null);
                        return;
                    }
                    startPolling();
                })
                .catch(function () {
                    isTracking = false;
                    showSafeError('Pembaruan data gagal dimulai.');
                    applyNormalButtonState(false, null);
                });
        }

        applyNormalButtonState(false, null);
        els.button.onclick = dispatchSync;
        // Rediscovery on load (Langkah 5/6) - SELALU cek status begitu
        // controller dibuat, terlepas siapa yang terakhir men-trigger
        // (halaman ini sendiri, Analytics, atau scheduled sync) - server-
        // side state SATU-SATUNYA sumber kebenaran, bukan session/DOM.
        poll();

        return { poll: poll, startPolling: startPolling, stopPolling: stopPolling };
    }

    window.AnalyticsSyncPanel = {
        DEFAULT_PLATFORM_GROUPS: DEFAULT_PLATFORM_GROUPS,
        createSyncController: createSyncController,
        renderGroup: renderGroup,
        formatFreshness: formatFreshness,
        formatElapsed: formatElapsed,
        esc: esc,
        isBusy: isBusy,
    };
})(window);

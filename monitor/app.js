(function () {
    const CONFIG = {
        storageKey: 'ledone.monitor.stationCode',
        reconnectDelayMs: 3000,
        fallbackPollMs: 8000
    };

    const rememberedStationCode = localStorage.getItem(CONFIG.storageKey) || '';

    const state = {
        stationCode: '',
        screen: 'station-select',
        connection: 'connecting', // connecting | online | offline
        current: null,
        eventSource: null,
        reconnectTimer: null,
        pollTimer: null,
        isLoadingCurrent: false
    };

    const el = {
        stationSelectView: document.getElementById('stationSelectView'),
    if (el.stationInput) {
        el.stationInput.value = rememberedStationCode;
    }
    if (el.adminStationInput) {
        el.adminStationInput.value = rememberedStationCode;
    }

        idleView: document.getElementById('idleView'),
        packingView: document.getElementById('packingView'),

        stationForm: document.getElementById('stationForm'),
        stationInput: document.getElementById('stationInput'),

        idleStationCode: document.getElementById('idleStationCode'),
        idleConnectionText: document.getElementById('idleConnectionText'),
        idleUpdatedAt: document.getElementById('idleUpdatedAt'),
        idleMessage: document.getElementById('idleMessage'),
        idleOfflineBanner: document.getElementById('idleOfflineBanner'),

        packingStationCode: document.getElementById('packingStationCode'),
        packingConnectionText: document.getElementById('packingConnectionText'),
        packingUpdatedAt: document.getElementById('packingUpdatedAt'),
        packingOfflineBanner: document.getElementById('packingOfflineBanner'),

        orderCode: document.getElementById('orderCode'),
        courierName: document.getElementById('courierName'),
        itemsList: document.getElementById('itemsList'),

        adminHotspot: document.getElementById('adminHotspot'),
        adminDialog: document.getElementById('adminDialog'),
        adminStationInput: document.getElementById('adminStationInput'),
        adminSaveBtn: document.getElementById('adminSaveBtn'),
        adminClearBtn: document.getElementById('adminClearBtn'),
        adminCloseBtn: document.getElementById('adminCloseBtn')
    };

    function currentUrl(stationCode) {
        return '/api/v1/screens/' + encodeURIComponent(stationCode) + '/current';
    }

    function streamUrl(stationCode) {
        return '/api/v1/screens/' + encodeURIComponent(stationCode) + '/stream';
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function setStationCode(stationCode) {
        const value = String(stationCode || '').trim();
        state.stationCode = value;
        localStorage.setItem(CONFIG.storageKey, value);
    }

    function clearStationCode() {
        state.stationCode = '';
        localStorage.removeItem(CONFIG.storageKey);
    }

    function setConnection(status) {
        state.connection = status;
        renderMeta();
        renderOfflineBanners();
    }

    function connectionLabel() {
        if (state.connection === 'online') return 'Połączono';
        if (state.connection === 'offline') return 'Brak połączenia';
        return 'Łączenie';
    }

    function show(viewName) {
        el.stationSelectView.classList.toggle('hidden', viewName !== 'station-select');
        el.idleView.classList.toggle('hidden', viewName !== 'idle');
        el.packingView.classList.toggle('hidden', viewName !== 'packing');
    }

    function renderMeta() {
        const updatedAt = state.current && state.current.updated_at
            ? state.current.updated_at
            : '—';

        el.idleStationCode.textContent = state.stationCode || '—';
        el.packingStationCode.textContent = state.stationCode || '—';

        el.idleConnectionText.textContent = connectionLabel();
        el.packingConnectionText.textContent = connectionLabel();

        el.idleUpdatedAt.textContent = updatedAt;
        el.packingUpdatedAt.textContent = updatedAt;
    }

    function renderOfflineBanners() {
        const offline = state.connection === 'offline';
        el.idleOfflineBanner.classList.toggle('hidden', !offline);
        el.packingOfflineBanner.classList.toggle('hidden', !offline);
    }

    function resolveImageUrl(url) {
        if (!url) return '';
        try {
            return new URL(url, window.location.origin).href;
        } catch (e) {
            return '';
        }
    }

    function getOrderCode(packing) {
        return (
            packing.order_code ||
            (packing.order && (packing.order.order_code || packing.order.code || packing.order.number)) ||
            (packing.session && packing.session.order_code) ||
            '—'
        );
    }

    function getCourier(packing) {
        return (
            packing.courier ||
            (packing.shipping && (
                packing.shipping.courier ||
                packing.shipping.courier_name ||
                packing.shipping.carrier
            )) ||
            (packing.label && (packing.label.courier || packing.label.carrier)) ||
            '—'
        );
    }

    function getItems(packing) {
        if (Array.isArray(packing.items)) return packing.items;
        if (packing.order && Array.isArray(packing.order.items)) return packing.order.items;
        return [];
    }

    function normalizeItem(item) {
        return {
            name: item.name || item.product_name || item.title || '—',
            code: item.code || item.product_code || item.sku || item.ean || '—',
            qty: item.qty != null ? item.qty : (
                item.quantity != null ? item.quantity : (
                    item.amount != null ? item.amount : '—'
                )
            ),
            image: resolveImageUrl(
                item.image ||
                item.image_url ||
                item.photo ||
                item.photo_url ||
                item.product_image ||
                ''
            )
        };
    }

    function renderIdle(current) {
        el.idleMessage.textContent = current.message || 'CZEKAM NA ZEBRANIE TOWARU';
        show('idle');
    }

    function renderPacking(current) {
        const packing = current.packing || {};
        const orderCode = getOrderCode(packing);
        const courier = getCourier(packing);
        const items = getItems(packing).map(normalizeItem);

        el.orderCode.textContent = orderCode;
        el.courierName.textContent = courier;

        if (!items.length) {
            el.itemsList.innerHTML = '<div class="item-card"><div class="item-name">Brak pozycji do wyświetlenia</div></div>';
        } else {
            el.itemsList.innerHTML = items.map(function (item) {
                const imageHtml = item.image
                    ? '<img class="item-image" src="' + escapeHtml(item.image) + '" alt="" loading="lazy" decoding="async" />'
                    : '<div class="item-image-placeholder">Brak zdjęcia</div>';

                return (
                    '<article class="item-card">' +
                    imageHtml +
                    '<div>' +
                    '<div class="item-name">' + escapeHtml(item.name) + '</div>' +
                    '<div class="item-meta">' +
                    '<div>Kod: <strong>' + escapeHtml(item.code) + '</strong></div>' +
                    '<div>Ilość: <strong>' + escapeHtml(item.qty) + '</strong></div>' +
                    '</div>' +
                    '</div>' +
                    '</article>'
                );
            }).join('');
        }

        show('packing');
    }

    function render() {
        renderMeta();
        renderOfflineBanners();

        if (!state.stationCode) {
            show('station-select');
            return;
        }

        if (!state.current) {
            show('station-select');
            return;
        }

        if (state.current.state === 'packing') {
            renderPacking(state.current);
            return;
        }

        renderIdle(state.current);
    }

    async function loadCurrent() {
        if (!state.stationCode || state.isLoadingCurrent) return;

        state.isLoadingCurrent = true;

        try {
            const response = await fetch(currentUrl(state.stationCode), {
                method: 'GET',
                cache: 'no-store',
                headers: {
                    'Accept': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            const json = await response.json();

            if (!json || json.ok !== true || !json.data) {
                throw new Error('Nieprawidłowa odpowiedź current');
            }

            state.current = json.data;
            state.screen = json.data.state === 'packing' ? 'packing' : 'idle';
            setConnection('online');
            render();
        } catch (error) {
            setConnection('offline');
            render();
            console.error(error);
        } finally {
            state.isLoadingCurrent = false;
        }
    }

    function clearReconnectTimer() {
        if (state.reconnectTimer) {
            clearTimeout(state.reconnectTimer);
            state.reconnectTimer = null;
        }
    }

    function disconnectStream() {
        if (state.eventSource) {
            state.eventSource.close();
            state.eventSource = null;
        }
        clearReconnectTimer();
    }

    function scheduleReconnect() {
        clearReconnectTimer();
        state.reconnectTimer = setTimeout(function () {
            connectStream();
        }, CONFIG.reconnectDelayMs);
    }

    function connectStream() {
        if (!state.stationCode) return;

        disconnectStream();

        try {
            setConnection('connecting');

            const es = new EventSource(streamUrl(state.stationCode));
            state.eventSource = es;

            es.addEventListener('open', function () {
                setConnection('online');
            });

            es.addEventListener('screen_state_changed', function () {
                loadCurrent();
            });

            es.onerror = function () {
                setConnection('offline');
                disconnectStream();
                scheduleReconnect();
            };
        } catch (error) {
            setConnection('offline');
            scheduleReconnect();
            console.error(error);
        }
    }

    function startFallbackPolling() {
        stopFallbackPolling();

        state.pollTimer = setInterval(function () {
            if (state.connection !== 'online') {
                loadCurrent();
            }

            if (!state.eventSource && state.stationCode) {
                connectStream();
            }
        }, CONFIG.fallbackPollMs);
    }

    function stopFallbackPolling() {
        if (state.pollTimer) {
            clearInterval(state.pollTimer);
            state.pollTimer = null;
        }
    }

    function bootStation(stationCode) {
        setStationCode(stationCode);
        disconnectStream();
        startFallbackPolling();
        loadCurrent().then(function () {
            connectStream();
        });
        render();
    }

    function resetToStationSelect() {
        disconnectStream();
        stopFallbackPolling();
        clearStationCode();
        state.current = null;
        state.screen = 'station-select';
        setConnection('connecting');
        render();
    }

    function openAdminDialog() {
        el.adminStationInput.value = state.stationCode || '';
        el.adminDialog.showModal();
        el.adminStationInput.focus();
        el.adminStationInput.select();
    }

    function closeAdminDialog() {
        el.adminDialog.close();
    }

    function initEvents() {
        el.stationForm.addEventListener('submit', function (event) {
            event.preventDefault();
            const value = el.stationInput.value.trim();
            if (!value) return;
            bootStation(value);
        });

        el.adminHotspot.addEventListener('click', openAdminDialog);

        window.addEventListener('keydown', function (event) {
            if (event.ctrlKey && event.altKey && event.key.toLowerCase() === 's') {
                event.preventDefault();
                openAdminDialog();
            }
        });

        el.adminSaveBtn.addEventListener('click', function () {
            const value = el.adminStationInput.value.trim();
            if (!value) return;
            closeAdminDialog();
            bootStation(value);
        });

        el.adminClearBtn.addEventListener('click', function () {
            closeAdminDialog();
            resetToStationSelect();
        });

        el.adminCloseBtn.addEventListener('click', function () {
            closeAdminDialog();
        });
    }

    function init() {
        initEvents();

        if (state.stationCode) {
            bootStation(state.stationCode);
        } else {
            render();
            el.stationInput.focus();
        }
    }

    init();
})();